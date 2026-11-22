<?php

namespace Tests\Feature;

use App\Models\Sinav\Course;
use App\Models\Sinav\Exam;
use App\Models\Sinav\Group;
use App\Models\Sinav\Release;
use App\Models\Sinav\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sınav Cevap Anahtarı modülünün Muafiyet ve İntibak programıyla birleşik çalışması:
 * aynı düzen ve menü, aynı giriş, komisyon (admin) rolüyle yetki, ana sayfada özet.
 */
class SinavEntegrasyonTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['name' => 'Komisyon Üyesi']);
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'student'])->save();

        return $user;
    }

    private function activeTerm(): Term
    {
        $term = Term::query()->create([
            'name' => '2026-2027 Güz', 'academic_year' => '2026-2027', 'status' => 'active',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonths(2), 'access_ends_at' => now()->addMonths(3),
        ]);
        $course = Course::query()->create(['term_id' => $term->id, 'code' => 'MUH104', 'code_slug' => 'muh104', 'name' => 'Malzeme Bilimi', 'active' => true]);
        $group = Group::query()->create(['term_id' => $term->id, 'course_id' => $course->id, 'name' => 'A', 'name_slug' => 'a']);
        $exam = Exam::query()->create(['term_id' => $term->id, 'course_id' => $course->id, 'type' => 'vize', 'title' => 'Vize']);
        Release::query()->create([
            'exam_id' => $exam->id, 'term_id' => $term->id, 'course_id' => $course->id, 'group_id' => $group->id,
            'exam_starts_at' => now()->subDay(), 'exam_ends_at' => now()->subDay()->addHour(),
            'publish_at' => now()->subHours(2), 'publish_until' => now()->addDays(2), 'approved' => true, 'version' => 1,
        ]);

        return $term;
    }

    public function test_sinav_sayfalari_ortak_duzen_ve_menude_acilir(): void
    {
        $this->activeTerm();
        $admin = $this->admin();
        $routes = ['dashboard', 'courses', 'groups', 'students', 'exams', 'keys', 'publish', 'qr', 'acc', 'audit', 'settings'];

        foreach ($routes as $name) {
            $html = $this->actingAs($admin)->get(route('sinav.admin.'.$name))->assertOk()->getContent();
            $this->assertStringContainsString('Muafiyet, İntibak ve Sınav', $html);
            $this->assertStringContainsString('class="sidebar"', $html);
            $this->assertStringContainsString('SINAV CEVAP ANAHTARI', $html);
            $this->assertStringContainsString('Değerlendirme Dosyaları', $html);
            $this->assertStringContainsString('id="sinav-root"', $html);
            $this->assertStringNotContainsString('id="sn-nav"', $html);
        }
    }

    public function test_menude_bulunulan_sinav_sayfasi_isaretlenir(): void
    {
        $html = $this->actingAs($this->admin())->get(route('sinav.admin.publish'))->getContent();

        $this->assertMatchesRegularExpression('#<a href="[^"]*/sinav/yonetim/yayin-takvimi" class="nav-item active">#', $html);
    }

    public function test_yetki_komisyon_rolune_baglidir(): void
    {
        $this->get(route('sinav.admin.dashboard'))->assertRedirect(route('login'));
        $this->actingAs($this->student())->get(route('sinav.admin.dashboard'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('sinav.admin.dashboard'))->assertOk();
    }

    public function test_muafiyet_ana_sayfasi_sinav_modulune_baglanmaz(): void
    {
        $this->activeTerm();

        $this->actingAs($this->admin())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Sınav Cevap Anahtarları')
            ->assertDontSee('1 yayında');
    }

    public function test_sinav_sayfalarinda_donem_gosterilmez(): void
    {
        $this->activeTerm();
        $html = $this->actingAs($this->admin())->get(route('sinav.admin.exams'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Akademik Dönemler', $html);
        $this->assertStringNotContainsString('pill-brand', $html);
        $this->assertStringNotContainsString('donem-sec', $html);
    }
}
