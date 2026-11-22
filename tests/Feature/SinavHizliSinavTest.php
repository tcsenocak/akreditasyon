<?php

namespace Tests\Feature;

use App\Models\Sinav\Course;
use App\Models\Sinav\Enrollment;
use App\Models\Sinav\Exam;
use App\Models\Sinav\Group;
use App\Models\Sinav\Release;
use App\Models\Sinav\Student;
use App\Models\Sinav\Term;
use App\Models\User;
use App\Services\Sinav\Students;
use App\Services\Sinav\Terms;
use App\Services\Sinav\Xlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dönemsiz, öğrenci listesinden hızlı sınav oluşturma. Veriler uydurmadır.
 */
class SinavHizliSinavTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->instructor = User::factory()->create(['name' => 'Dr. Hoca']);
        $this->instructor->forceFill(['role' => 'instructor'])->save();
    }

    /** @return array<string, mixed> */
    private function examFields(array $extra = []): array
    {
        return $extra + [
            'from_list' => 1, 'type' => 'vize', 'title' => '',
            'common' => ['date' => now()->addDay()->format('Y-m-d'), 'start_h' => '10', 'start_m' => '00', 'end_h' => '11', 'end_m' => '00'],
        ];
    }

    public function test_sinif_listesi_okunur(): void
    {
        $text = "ATATÜRK ÜNİVERSİTESİ\nDers Kodu:\tMUH104\nDers Adı:\tMalzeme Bilimi\nŞube:\tB\nSıra\tÖğrenci No\tAdı\tSoyadı\tBölüm\n1\t220101001\tAyşe\tYılmaz\tMetalurji\n2\t220101002\tMehmet Ali\tDemir\tMakine\n3\t220101001\tAyşe\tYılmaz\tMetalurji\n";
        $list = Students::readClassList($text);

        $this->assertSame('MUH104', $list['code']);
        $this->assertSame('Malzeme Bilimi', $list['name']);
        $this->assertSame('B', $list['group']);
        $this->assertSame(['220101001' => 'Ayşe Yılmaz', '220101002' => 'Mehmet Ali Demir'], $list['students']);
        $this->assertSame(1, $list['skipped']);

        $inline = Students::readClassList("MUH 104 - Malzeme Bilimi A Grubu\n220101005\tAli Veli");
        $this->assertSame(['MUH104', 'Malzeme Bilimi', 'A'], [$inline['code'], $inline['name'], $inline['group']]);

        $fromName = Students::readClassList("220101005\tAli Veli", 'fiz101-sinif-listesi.xlsx');
        $this->assertSame(['FİZ101', '', 'A'], [$fromName['code'], $fromName['name'], $fromName['group']]);
    }

    public function test_hoca_listeyle_tek_adimda_sinav_olusturur(): void
    {
        $bytes = Xlsx::write([['name' => 'Liste', 'rows' => [['Ders Kodu', 'MUH104'], ['Ders Adı', 'Malzeme Bilimi'], ['Öğrenci No', 'Ad Soyad'], ['220101001', 'Ayşe Yılmaz'], ['220101002', 'Mehmet Demir']]]]);
        $this->actingAs($this->instructor)->post(route('sinav.admin.exams.list'), ['list' => UploadedFile::fake()->createWithContent('liste.xlsx', $bytes)])
            ->assertRedirect(route('sinav.admin.exams.create', ['liste' => 1]));
        $this->get(route('sinav.admin.exams.create', ['liste' => 1]))->assertOk()
            ->assertSee('2 öğrenci', false)->assertSee('value="MUH104"', false)->assertSee('value="Malzeme Bilimi"', false);

        $this->post(route('sinav.admin.exams.store'), $this->examFields(['code' => 'MUH104', 'course_name' => 'Malzeme Bilimi', 'group_name' => 'A']))
            ->assertSessionHas('error', 'Öğrencilerin sınavı görebilmesi için bir PIN belirleyin (4-8 rakam).');
        $this->assertSame(0, Course::query()->count());

        $response = $this->post(route('sinav.admin.exams.store'), $this->examFields(['code' => 'MUH104', 'course_name' => 'Malzeme Bilimi', 'group_name' => 'A', 'pin' => '482913']));
        $exam = Exam::query()->firstOrFail();
        $response->assertRedirect(route('sinav.admin.keys', ['sinav' => $exam->id]))->assertSessionHas('success');

        $course = Course::query()->firstOrFail();
        $this->assertSame($this->instructor->id, (int) $course->instructor_user_id);
        $this->assertSame('Vize Sınavı', $exam->title);
        $group = Group::query()->firstOrFail();
        $this->assertTrue($group->pinMatches('482913'));
        $this->assertSame(2, Enrollment::query()->where('group_id', $group->id)->count());
        $this->assertSame(1, Release::query()->where('group_id', $group->id)->count());
        $this->assertSame(1, Term::query()->count());
        $this->assertNull(session('sinav_list_draft'));

        // İkinci sınav: kayıtlı ders, liste yeniden yüklenmez; PIN zaten var.
        $this->get(route('sinav.admin.exams.create'))->assertOk()->assertSee('MUH104 — Malzeme Bilimi');
        $this->post(route('sinav.admin.exams.store'), [
            'course_id' => $course->id, 'type' => 'final',
            'common' => ['date' => now()->addDays(20)->format('Y-m-d'), 'start_h' => '10', 'start_m' => '00', 'end_h' => '11', 'end_m' => '00'],
            'groups' => [$group->id => ['on' => 1]],
        ])->assertSessionHas('success');
        $this->assertSame(['Final Sınavı', 'Vize Sınavı'], Exam::query()->orderBy('title')->pluck('title')->all());
    }

    public function test_yeni_listedeki_ogrenciler_ayni_derse_eklenir(): void
    {
        $this->actingAs($this->instructor)->post(route('sinav.admin.exams.list'), ['list_text' => "Ders Kodu: MUH104\nDers Adı: Malzeme Bilimi\n220101001\tAyşe"]);
        $this->post(route('sinav.admin.exams.store'), $this->examFields(['code' => 'MUH104', 'course_name' => 'Malzeme Bilimi', 'group_name' => 'A', 'pin' => '482913']))->assertSessionHas('success');

        $this->post(route('sinav.admin.exams.list'), ['list_text' => "Ders Kodu: MUH104\n220101001\tAyşe\n220101003\tZeynep"]);
        $this->get(route('sinav.admin.exams.create', ['liste' => 1]))->assertSee('Bu ders sistemde kayıtlı')->assertSee('Mevcut PIN geçerli');
        $this->post(route('sinav.admin.exams.store'), $this->examFields(['code' => 'MUH104', 'group_name' => 'A', 'type' => 'final']))->assertSessionHas('success');

        $this->assertSame(1, Course::query()->count());
        $this->assertSame(2, Student::query()->count());
        $this->assertTrue(Group::query()->firstOrFail()->pinMatches('482913'));
    }

    public function test_baska_hocanin_dersine_liste_yuklenemez(): void
    {
        $other = User::factory()->create();
        $other->forceFill(['role' => 'instructor'])->save();
        Terms::createCourse(Terms::current(), ['code' => 'MUH104', 'name' => 'Malzeme Bilimi', 'instructor_user_id' => $other->id]);

        $this->actingAs($this->instructor)->post(route('sinav.admin.exams.list'), ['list_text' => "Ders Kodu: MUH104\n220101001\tAyşe"]);
        $this->post(route('sinav.admin.exams.store'), $this->examFields(['code' => 'MUH104', 'group_name' => 'A', 'pin' => '482913']))
            ->assertSessionHas('error', '"MUH104" dersi başka bir öğretim elemanına kayıtlı. Ders ataması için komisyona başvurun.');
        $this->assertSame(0, Student::query()->count());
    }

    public function test_numarasiz_liste_reddedilir_ve_pin_olmadan_kayitli_ders_formu_kaydedilmez(): void
    {
        $this->actingAs($this->instructor)->post(route('sinav.admin.exams.list'), ['list_text' => "Ders Kodu: MUH104\nAyşe Yılmaz"])
            ->assertSessionHas('error');

        $course = Terms::createCourse(Terms::current(), ['code' => 'FIZ101', 'name' => 'Fizik', 'instructor_user_id' => $this->instructor->id]);
        $group = Terms::createGroup($course, 'A');
        $this->get(route('sinav.admin.exams.create', ['ders' => $course->id]))->assertOk()->assertSee('bu grubun PIN\'i yok', false);
        $fields = [
            'course_id' => $course->id, 'type' => 'vize',
            'common' => ['date' => now()->addDay()->format('Y-m-d'), 'start_h' => '10', 'start_m' => '00', 'end_h' => '11', 'end_m' => '00'],
        ];
        $this->post(route('sinav.admin.exams.store'), $fields + ['groups' => [$group->id => ['on' => 1]]])->assertSessionHas('error');
        $this->assertSame(0, Exam::query()->count());
        $this->post(route('sinav.admin.exams.store'), $fields + ['groups' => [$group->id => ['on' => 1, 'pin' => '573086']]])->assertSessionHas('success');
        $this->assertTrue($group->fresh()->pinMatches('573086'));
    }
}
