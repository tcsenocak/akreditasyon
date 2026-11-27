<?php

namespace Tests\Feature;

use App\Http\Controllers\Sinav\StudentController;
use App\Models\Sinav\AccSection;
use App\Models\Sinav\Course;
use App\Models\Sinav\Enrollment;
use App\Models\Sinav\Exam;
use App\Models\Sinav\ExamFile;
use App\Models\Sinav\Group;
use App\Models\Sinav\Release;
use App\Models\Sinav\ReleaseFile;
use App\Models\Sinav\Student;
use App\Models\Sinav\Term;
use App\Models\User;
use App\Services\Sinav\Files;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Grup PIN'iyle öğrenci girişi ve öğretim elemanının yalnızca kendi derslerini yönetmesi.
 */
class SinavPinVeHocaTest extends TestCase
{
    use RefreshDatabase;

    private User $commission;

    private User $instructor;

    private Term $term;

    /** @var array<string, array{course: Course, group: Group, release: Release, file: ReleaseFile, exam: Exam}> */
    private array $courses = [];

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        RateLimiter::clear('sinav-login-no:220101001');
        $this->commission = User::factory()->create(['name' => 'Komisyon']);
        $this->instructor = User::factory()->create(['name' => 'Dr. Hoca']);
        $this->instructor->forceFill(['role' => 'instructor'])->save();

        $this->term = Term::query()->create([
            'name' => '2026-2027 Güz', 'academic_year' => '2026-2027', 'status' => 'active',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonths(2), 'access_ends_at' => now()->addMonths(3),
        ]);
        $this->student = Student::query()->create(['term_id' => $this->term->id, 'student_no' => '220101001', 'name' => 'Ayşe']);
        foreach (['MUH104' => $this->instructor->id, 'FIZ101' => null] as $code => $owner) {
            $course = Course::query()->create(['term_id' => $this->term->id, 'code' => $code, 'code_slug' => strtolower($code), 'name' => $code.' dersi', 'active' => true, 'instructor_user_id' => $owner]);
            $group = Group::query()->create(['term_id' => $this->term->id, 'course_id' => $course->id, 'name' => 'A', 'name_slug' => 'a']);
            Enrollment::query()->create(['student_id' => $this->student->id, 'course_id' => $course->id, 'group_id' => $group->id]);
            $exam = Exam::query()->create(['term_id' => $this->term->id, 'course_id' => $course->id, 'type' => 'vize', 'title' => $code.' Vize']);
            $release = Release::query()->create([
                'exam_id' => $exam->id, 'term_id' => $this->term->id, 'course_id' => $course->id, 'group_id' => $group->id,
                'exam_starts_at' => now()->subDay(), 'exam_ends_at' => now()->subDay()->addHour(),
                'publish_at' => now()->subHour(), 'publish_until' => now()->addDays(2), 'approved' => true, 'version' => 1,
            ]);
            Files::disk()->put('sinav/test/'.$code.'.pdf', '%PDF-1.4 deneme');
            $examFile = ExamFile::query()->create(['exam_id' => $exam->id, 'name' => $code.'.pdf', 'mime' => 'application/pdf', 'size' => 15, 'kind' => 'key', 'group_ids' => [], 'path' => 'sinav/test/'.$code.'.pdf']);
            $file = ReleaseFile::query()->create(['release_id' => $release->id, 'exam_file_id' => $examFile->id, 'name' => $code.'.pdf', 'mime' => 'application/pdf', 'size' => 15, 'kind' => 'key', 'path' => 'sinav/test/'.$code.'.pdf']);
            $this->courses[$code] = compact('course', 'group', 'release', 'file', 'exam');
        }
    }

    private function setPin(string $code, string $pin, ?User $as = null): void
    {
        $this->actingAs($as ?? $this->commission)->post(route('sinav.admin.groups.pin', $this->courses[$code]['group']), ['pin' => $pin])->assertSessionHas('success');
    }

    private function login(string $pin, string $no = '220101001')
    {
        return $this->post(route('sinav.login'), ['student_no' => $no, 'pin' => $pin]);
    }

    public function test_pin_belirlenmemis_dersi_ogrenci_goremez(): void
    {
        $this->login('482913')->assertSessionHas('error');
        $this->get(route('sinav.home'))->assertSee('Öğrenci Girişi')->assertDontSee('Derslerim');
    }

    public function test_dogru_pin_yalnizca_o_dersi_acar_ve_ikinci_pin_eklenir(): void
    {
        $this->setPin('MUH104', '482913');
        $this->setPin('FIZ101', '573086');

        $this->login('111999')->assertSessionHas('error');
        $this->login('482913')->assertRedirect(route('sinav.home'));
        $this->get(route('sinav.home'))->assertSee('MUH104')->assertDontSee('FIZ101 dersi')->assertSee('PIN\'ini girmediğiniz 1 dersiniz', false);
        $this->get(route('sinav.file', $this->courses['MUH104']['file']->id))->assertOk();
        $this->get(route('sinav.file', $this->courses['FIZ101']['file']->id))->assertForbidden();
        $this->get(route('sinav.exam', $this->courses['FIZ101']['release']->id))->assertRedirect(route('sinav.home'));

        $this->post(route('sinav.pin'), ['pin' => '999111'])->assertSessionHas('error');
        $this->post(route('sinav.pin'), ['pin' => '573086'])->assertSessionHas('success');
        $this->get(route('sinav.home'))->assertSee('FIZ101 dersi');
        $this->get(route('sinav.file', $this->courses['FIZ101']['file']->id))->assertOk();
    }

    public function test_pin_degisince_eski_oturum_dersi_kaybeder(): void
    {
        $this->setPin('MUH104', '482913');
        $this->login('482913');
        $this->get(route('sinav.file', $this->courses['MUH104']['file']->id))->assertOk();

        $this->setPin('MUH104', '640275');
        $this->get(route('sinav.file', $this->courses['MUH104']['file']->id))->assertForbidden();
        $this->get(route('sinav.home'))->assertSee('PIN\'i değiştirildi');
        $this->login('482913')->assertSessionHas('error');
        $this->login('640275')->assertRedirect(route('sinav.home'));
    }

    public function test_ayni_numaraya_pin_tahmini_sinirlanir(): void
    {
        $this->setPin('MUH104', '482913');
        for ($i = 0; $i < 8; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$i])->post(route('sinav.login'), ['student_no' => '220101001', 'pin' => (string) (100000 + $i)]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->post(route('sinav.login'), ['student_no' => '220101001', 'pin' => '482913'])
            ->assertSessionHas('error', 'Çok fazla hatalı deneme yapıldı. Lütfen 15 dakika sonra tekrar deneyin.');
    }

    public function test_sinifca_pin_tahmini_grubu_gecici_kilitler(): void
    {
        $this->setPin('MUH104', '482913');
        auth()->logout();
        $key = 'sinav-login-group:'.$this->courses['MUH104']['group']->id;
        // Sınıftaki farklı numaralarla yapılan denemeler numara başına sınırı aşsa da grup sınırına takılır
        for ($i = 0; $i < 39; $i++) {
            RateLimiter::hit($key, 3600);
        }
        $this->login('111222')->assertSessionHas('error', StudentController::GROUP_LOCKED);
        $this->login('482913')->assertSessionHas('error', StudentController::GROUP_LOCKED);
        $this->get(route('sinav.home'))->assertDontSee('MUH104');
        $this->assertDatabaseHas('sinav_audit_logs', ['action' => 'group.pin_lock', 'result' => 'blocked']);

        RateLimiter::clear($key);
        RateLimiter::clear('sinav-login-no:220101001');
        $this->login('482913')->assertSessionHasNoErrors();
        $this->get(route('sinav.home'))->assertSee('MUH104');
    }

    public function test_pin_oturumu_ogrenciyi_sistemin_icine_sokmaz(): void
    {
        $this->setPin('MUH104', '482913');
        $this->setPin('FIZ101', '573086');
        auth()->logout();
        $this->login('482913')->assertRedirect(route('sinav.home'));
        $this->assertGuest();

        // Öğrenci yalnız kendi yayınını görür; sayfada yönetim paneline bağlantı yoktur
        $this->get(route('sinav.home'))->assertOk()->assertDontSee('yonetim', false)->assertDontSee('Yönetici girişi');
        $this->get(route('sinav.exam', $this->courses['MUH104']['release']->id))->assertOk()->assertDontSee('yonetim', false);
        $this->get(route('sinav.file', $this->courses['MUH104']['file']->id))->assertOk();

        // PIN'ini girmediği dersin yayını ve dosyası kapalıdır
        $this->get(route('sinav.exam', $this->courses['FIZ101']['release']->id))->assertRedirect(route('sinav.home'));
        $this->get(route('sinav.file', $this->courses['FIZ101']['file']->id))->assertForbidden();
        $this->get(route('sinav.file', 999999))->assertForbidden();

        // Yönetim paneli, özgün dosyalar ve sistemin diğer bölümleri PIN oturumuyla açılmaz
        foreach ([
            route('sinav.admin.dashboard'), route('sinav.admin.exams'), route('sinav.admin.keys'), route('sinav.admin.students'),
            route('sinav.admin.groups'), route('sinav.admin.acc'), route('sinav.admin.files.show', $this->courses['MUH104']['file']->exam_file_id),
            route('dashboard'), route('students.index'), route('applications.index'), route('files.index'), route('portal.home'), route('portal.password'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->post(route('sinav.admin.groups.pin', $this->courses['MUH104']['group']), ['pin' => '654987'])->assertRedirect(route('login'));
        $this->post(route('sinav.admin.chunk'))->assertRedirect(route('login'));
        $this->assertSame('482913', $this->courses['MUH104']['group']->fresh()->pin());
    }

    public function test_pin_kurallari(): void
    {
        $group = $this->courses['MUH104']['group'];
        foreach (['1111', '1234', '98765', '12a4', '123', '4829', '48291', '111111', '123456'] as $weak) {
            $this->actingAs($this->commission)->post(route('sinav.admin.groups.pin', $group), ['pin' => $weak])->assertSessionHas('error');
        }
        $this->post(route('sinav.admin.groups.pin', $group), ['pin' => ''])->assertSessionHas('success');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $group->fresh()->pin());
        $this->assertStringNotContainsString($group->fresh()->pin(), (string) $group->fresh()->getRawOriginal('pin_encrypted'));
    }

    public function test_hoca_yalnizca_kendi_dersini_gorur_ve_pin_belirler(): void
    {
        $own = $this->courses['MUH104'];
        $other = $this->courses['FIZ101'];
        $this->actingAs($this->instructor);

        foreach (['courses', 'groups', 'students', 'exams', 'publish', 'acc'] as $page) {
            $this->get(route('sinav.admin.'.$page))->assertOk()->assertSee('MUH104')->assertDontSee('FIZ101');
        }
        $this->get(route('sinav.admin.keys', ['sinav' => $other['exam']->id]))->assertOk()->assertDontSee('FIZ101 Vize');

        $this->setPin('MUH104', '482913', $this->instructor);
        $this->post(route('sinav.admin.groups.pin', $other['group']), ['pin' => '573086'])->assertForbidden();
        $this->get(route('sinav.admin.exams.edit', $other['exam']))->assertForbidden();
        $this->get(route('sinav.admin.files.show', ExamFile::query()->where('exam_id', $other['exam']->id)->first()))->assertForbidden();
        $this->post(route('sinav.admin.releases.halt', $other['release']))->assertForbidden();
        $this->post(route('sinav.admin.releases.halt', $own['release']))->assertSessionHas('success');

        $this->get(route('sinav.admin.acc', ['ders' => $own['course']->id]));
        $section = AccSection::query()->where('course_id', $own['course']->id)->first();
        $this->assertNotNull($section);
        $this->post(route('sinav.admin.acc.section.store', $other['course']), ['title' => 'Ödev'])->assertForbidden();
    }

    public function test_komisyona_ait_sayfalar_hocaya_kapali(): void
    {
        $this->actingAs($this->instructor);
        foreach (['instructors', 'audit', 'settings'] as $page) {
            $this->get(route('sinav.admin.'.$page))->assertForbidden()->assertSee('yalnızca komisyona açık');
        }
        $this->get(route('sinav.admin.students.add'))->assertForbidden();
        $this->get(route('dashboard'))->assertRedirect(route('sinav.admin.dashboard'));
    }

    public function test_hoca_menusu_ana_modul_duzeninde(): void
    {
        $html = $this->actingAs($this->instructor)->get(route('sinav.admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('PROGRAMLARIM', $html);
        $this->assertStringContainsString('Derslerim', $html);
        $this->assertStringContainsString('Sınavlarım', $html);
        $this->assertStringContainsString('Öğretim Elemanı', $html);
        $this->assertStringNotContainsString('Değerlendirme Dosyaları', $html);
        $this->assertStringContainsString('1 grubun PIN\'i belirlenmedi', $html);
    }

    public function test_komisyon_hoca_hesabi_acar_derse_atar_ilk_giriste_parola_degisir(): void
    {
        $this->actingAs($this->commission)->post(route('sinav.admin.instructors.store'), ['name' => 'Dr. Yeni', 'email' => 'yeni@atauni.edu.tr'])
            ->assertSessionHas('account_password');
        $password = session('account_password')['password'];
        $new = User::query()->where('email', 'yeni@atauni.edu.tr')->firstOrFail();
        $this->assertTrue($new->isInstructor());
        $this->assertTrue((bool) $new->must_change_password);

        $course = $this->courses['FIZ101']['course'];
        $this->post(route('sinav.admin.courses.update', $course), ['name' => $course->name, 'instructor_user_id' => $new->id, 'active' => 1])->assertSessionHas('success');
        $this->post(route('sinav.admin.courses.update', $course), ['name' => $course->name, 'instructor_user_id' => $this->commission->id, 'active' => 1])->assertSessionHas('error');
        $this->assertSame($new->id, (int) $course->fresh()->instructor_user_id);

        $this->post(route('logout'));
        $this->post(route('login.attempt'), ['email' => 'yeni@atauni.edu.tr', 'password' => $password])->assertRedirect(route('sinav.admin.dashboard'));
        $this->get(route('sinav.admin.exams'))->assertRedirect(route('sinav.admin.password'));
        $this->put(route('sinav.admin.password.update'), ['current_password' => $password, 'password' => 'YeniParola-2026', 'password_confirmation' => 'YeniParola-2026'])
            ->assertRedirect(route('sinav.admin.dashboard'));
        $this->get(route('sinav.admin.exams'))->assertOk()->assertSee('FIZ101')->assertDontSee('MUH104');
    }
}
