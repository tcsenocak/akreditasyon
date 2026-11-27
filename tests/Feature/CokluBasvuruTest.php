<?php

namespace Tests\Feature;

use App\Models\ApplicationPeriod;
use App\Models\AppSetting;
use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use App\Models\ExemptionFile;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CokluBasvuruTest extends TestCase
{
    use RefreshDatabase;

    private Program $program;

    private Curriculum $curriculum;

    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->program = Program::query()->create(['department' => 'Bilgisayar', 'name' => 'Bilgisayar Mühendisliği', 'slug' => 'bm-en', 'language' => 'EN']);
        $this->curriculum = Curriculum::query()->create(['program_id' => $this->program->id, 'title' => '2026', 'is_active' => true]);
        foreach ([['MAT101', 'Matematik I', 4, 6, 1], ['FIZ101', 'Fizik I', 4, 6, 1], ['BIL201', 'Veri Yapıları', 3, 7, 3], ['BIL202', 'Algoritmalar', 3, 7, 4], ['BIL301', 'İşletim Sistemleri', 3, 8, 5]] as [$code, $name, $credit, $ects, $semester]) {
            $this->curriculum->courses()->create([
                'code' => $code, 'root_code' => $code, 'name' => $name, 'normalized_name' => TextNormalizer::normalize($name),
                'type' => 'Z', 'credit' => $credit, 'ects' => $ects, 'semester' => $semester, 'category' => 'plan',
            ]);
        }
        $this->chair = User::factory()->create(['name' => 'Başkan']);
        $this->chair->forceFill(['role' => 'admin', 'title' => 'Prof. Dr.'])->save();
    }

    public function test_ders_icin_yeniden_basvuru_engellenir_ve_yeni_basvuru_sonuclanmadan_acilamaz(): void
    {
        [$user, $student] = $this->studentUser();
        $math = $this->course('MAT101');
        $this->actingAs($user->fresh())->post(route('portal.courses.rows'), ['items' => [$this->row('Matematik I', $math->id)]])->assertRedirect();

        $this->actingAs($user->fresh())->post(route('portal.applications.start'), ['type' => 'yaz_okulu'])->assertRedirect();
        $this->assertSame('yaz_okulu', $student->fresh()->currentApplication->type, 'Taslakta yalnız tür değişir');
        $this->assertSame(1, $student->applications()->count());

        $student->update(['application_status' => 'gonderildi']);
        $this->actingAs($user->fresh())->post(route('portal.applications.start'), ['type' => 'normal'])->assertSessionHasErrors('type');

        $student->update(['application_status' => 'sonuclandi']);
        $this->actingAs($user->fresh())->post(route('portal.applications.start'), ['type' => 'normal'])->assertRedirect(route('portal.courses'));
        $student->refresh();
        $this->assertSame(2, $student->applications()->count());
        $this->assertSame('taslak', $student->application_status);
        $this->assertCount(0, $student->transcript, 'Önceki başvurunun dersleri yeni başvuruya taşınmaz');
        $this->assertSame('sonuclandi', $student->applications()->first()->status);

        $this->actingAs($user->fresh())->post(route('portal.courses.rows'), ['items' => [$this->row('Matematik I', $math->id)]])->assertSessionHasErrors('items');
        $this->actingAs($user->fresh())->post(route('portal.courses.rows'), ['items' => [$this->row('Fizik I', $this->course('FIZ101')->id)]])->assertSessionHasNoErrors();
        $this->actingAs($user->fresh())->get(route('portal.courses'))->assertOk()->assertSee('önceki başvuru: talep edildi');
    }

    public function test_yaz_okulu_20_akts_siniri_ve_not_zorunlu_degil(): void
    {
        [$user, $student] = $this->studentUser();
        $student->currentApplication->update(['type' => 'yaz_okulu']);

        $tooMuch = [$this->row('Veri Yapıları', $this->course('BIL201')->id, null), $this->row('Algoritmalar', $this->course('BIL202')->id, null), $this->row('İşletim', $this->course('BIL301')->id, null)];
        $this->actingAs($user->fresh())->post(route('portal.courses.rows'), ['items' => $tooMuch])->assertSessionHasErrors('items');

        $this->actingAs($user->fresh())->post(route('portal.courses.rows'), ['items' => array_slice($tooMuch, 0, 2)])->assertSessionHasNoErrors();
        $this->assertSame(2, $student->transcript()->count());
        $this->assertNull($student->transcript()->value('grade_raw'));
    }

    public function test_basvuru_alimi_kapaliyken_gonderilemez_takvimde_acilir(): void
    {
        [$user, $student] = $this->studentUser();
        $this->readyToSend($user, $student);
        AppSetting::put('application_mode', 'takvim');

        $this->actingAs($user->fresh())->post(route('portal.submit'))->assertSessionHasErrors('submit');
        $this->assertSame('taslak', $student->fresh()->application_status);

        ApplicationPeriod::query()->create(['name' => '2026-2027 Bahar', 'type' => 'normal', 'starts_on' => now()->subDay(), 'ends_on' => now()->addDays(13)]);
        $this->actingAs($user->fresh())->post(route('portal.submit'))->assertRedirect(route('portal.home'));
        $this->assertSame('gonderildi', $student->fresh()->application_status);
        $this->assertSame('gonderildi', $student->fresh()->currentApplication->status);
    }

    public function test_birden_fazla_kurum_icin_ayri_transkript_istenir(): void
    {
        [$user, $student] = $this->studentUser();
        $this->actingAs($user->fresh())->post(route('portal.courses.rows'), ['items' => [
            $this->row('Matematik I', $this->course('MAT101')->id),
            ['institution' => 'Ankara Üniversitesi'] + $this->row('Fizik I', $this->course('FIZ101')->id),
        ]]);
        $this->upload($user, 'dilekce');
        $this->upload($user, 'transkript', 'Karadeniz Teknik Üniversitesi');

        $this->actingAs($user->fresh())->post(route('portal.submit'))->assertSessionHasErrors('submit');
        $this->actingAs($user->fresh())->get(route('portal.courses'))->assertSee('tek bir PDF dosyasında birleştirip', false);

        $this->upload($user, 'transkript', 'Ankara Üniversitesi');
        $this->actingAs($user->fresh())->post(route('portal.submit'))->assertRedirect(route('portal.home'));
    }

    public function test_komisyon_tum_kararlari_tek_dugmeyle_kaydeder_uye_kesin_karar_veremez(): void
    {
        [$user, $student] = $this->studentUser();
        $this->actingAs($user->fresh())->post(route('portal.courses.rows'), ['items' => [
            $this->row('Matematik I', $this->course('MAT101')->id),
            $this->row('Veri Yapıları', $this->course('BIL201')->id),
        ]]);
        $member = User::factory()->create(['name' => 'Üye']);
        $member->forceFill(['role' => 'commission', 'title' => 'Arş. Gör.'])->save();

        $this->actingAs($member)->post(route('files.store', $student))->assertRedirect();
        $file = ExemptionFile::query()->firstOrFail();
        $this->actingAs($member)->get(route('files.show', $file))->assertOk()->assertSee('Tüm kararları kaydet')->assertSee('Türkçe programdan İngilizce programa', false);
        [$a, $b] = $file->items()->orderBy('id')->get()->all();

        $this->actingAs($member)->put(route('files.decisions', $file), ['items' => [
            $a->id => ['status' => 'kabul', 'letter' => 'BB'],
            $b->id => ['status' => 'ret', 'letter' => 'CC', 'reject_reason' => 'tr_en', 'commission_note' => 'Alan dersi'],
        ]])->assertSessionHas('status', '2 satırdaki karar kaydedildi.');
        $this->assertSame('kabul', $a->fresh()->status);
        $this->assertSame('tr_en', $b->fresh()->reject_reason);
        $this->assertSame($member->id, $b->fresh()->decided_by);

        $this->actingAs($member)->put(route('files.update', $file), ['status' => 'karar'])->assertSessionHasErrors('status');
        $this->actingAs($member)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($member)->delete(route('files.destroy', $file))->assertForbidden();

        $this->actingAs($this->chair)->put(route('files.update', $file), ['status' => 'karar'])->assertSessionHasNoErrors();
        $this->assertSame('sonuclandi', $student->fresh()->application_status);
        // Karara bağlanan başvuru hemen "Muafiyet Başvurularım" listesine düşer; ilk ekran yeni başvuruya döner
        $application = $student->fresh()->currentApplication;
        $this->actingAs($user->fresh())->get(route('portal.home'))->assertSee('Yeni Başvuru')->assertSee('Başvuruya başla');
        $this->actingAs($user->fresh())->get(route('portal.applications.index'))->assertSee('1 / 2 ders kabul')->assertSee('İtiraz et');
        $this->actingAs($user->fresh())->get(route('portal.applications.show', $application))->assertSee('Türkçe programdan İngilizce programın alan derslerinden', false)->assertSee('İtirazımı gönder');
        $this->actingAs($user->fresh())->get(route('portal.courses'))->assertRedirect(route('portal.applications.index'));
    }

    public function test_baskan_ogretim_uyesi_olmali_uye_kendi_hesabiyla_girer(): void
    {
        $this->actingAs($this->chair)->post(route('users.store'), ['name' => 'Ayşe Demir', 'email' => 'ayse@example.com', 'role' => 'admin', 'title' => 'Arş. Gör.'])->assertSessionHasErrors('title');
        $this->actingAs($this->chair)->post(route('users.store'), ['name' => 'Ayşe Demir', 'email' => 'ayse@example.com', 'role' => 'commission', 'title' => 'Arş. Gör.'])
            ->assertSessionHas('account_password');
        $password = session('account_password')['password'];
        auth()->logout();

        $this->post(route('login.attempt'), ['email' => 'ayse@example.com', 'password' => $password])->assertRedirect();
        $this->get(route('students.index'))->assertRedirect(route('portal.password'));
        $this->put(route('portal.password.update'), ['current_password' => $password, 'password' => 'yeni-parola-9', 'password_confirmation' => 'yeni-parola-9'])->assertRedirect(route('dashboard'));
        $this->get(route('students.index'))->assertOk();
        $this->get(route('users.index'))->assertForbidden();
    }

    public function test_hazirlik_muafiyeti_basvurusu_ve_karari(): void
    {
        [$user, $student] = $this->studentUser();
        $this->actingAs($user->fresh())->post(route('portal.applications.start'), ['type' => 'hazirlik']);
        $this->actingAs($user->fresh())->get(route('portal.courses'))->assertOk()->assertSee('Dil sınavı');

        $this->actingAs($user->fresh())->put(route('portal.prep'), ['language_exam' => 'YDS', 'language_score' => 72, 'gonder' => 1])->assertSessionHasErrors('submit');
        $this->upload($user, 'dilekce');
        $this->upload($user, 'dil');
        $this->actingAs($user->fresh())->put(route('portal.prep'), ['language_exam' => 'YDS', 'language_score' => 72, 'gonder' => 1])->assertRedirect(route('portal.home'));

        $application = $student->fresh()->currentApplication;
        // "Değerlendirmeye al" hazırlık başvurusunda hata vermez; karar sayfasına yönlendirir
        $this->actingAs($this->chair)->post(route('files.store', $student))->assertRedirect(route('applications.show', $application));
        $this->actingAs($this->chair)->get(route('applications.index'))->assertOk()->assertSee('Karar ver');
        $this->actingAs($this->chair)->get(route('applications.show', $application))->assertOk()->assertSee('Kararı kaydet');
        $this->actingAs($this->chair)->get(route('dashboard'))->assertOk()->assertSee('Karar ver');
        $this->actingAs($this->chair)->put(route('applications.decide', $application), ['decision' => 'kabul', 'decision_note' => 'Hazırlıktan muaf'])->assertRedirect();
        $this->assertSame('sonuclandi', $student->fresh()->application_status);
        $this->actingAs($user->fresh())->get(route('portal.applications.show', $application))->assertSee('Hazırlıktan muaf');
    }

    /**
     * @return array{0: User, 1: Student}
     */
    private function studentUser(): array
    {
        $student = Student::query()->create([
            'first_name' => 'Deneme', 'last_name' => 'Öğrenci', 'admission_type' => 'yatay',
            'program_id' => $this->program->id, 'curriculum_id' => $this->curriculum->id,
            'previous_institution' => 'Karadeniz Teknik Üniversitesi', 'previous_program' => 'Bilgisayar Mühendisliği',
        ]);
        $user = new User(['name' => $student->full_name, 'email' => 'ogr@example.com', 'password' => 'x']);
        $user->forceFill(['role' => 'student', 'student_id' => $student->id, 'kvkk_accepted_at' => now()])->save();

        return [$user, $student];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $name, ?int $targetId, ?string $grade = 'BB'): array
    {
        return ['institution' => 'Karadeniz Teknik Üniversitesi', 'name' => $name, 'credit' => 4, 'ects' => 7, 'grade_raw' => $grade, 'target_id' => $targetId];
    }

    private function course(string $code): CurriculumCourse
    {
        return $this->curriculum->courses()->where('code', $code)->firstOrFail();
    }

    private function upload(User $user, string $type, ?string $institution = null): void
    {
        $this->actingAs($user->fresh())->post(route('portal.documents.store'), [
            'type' => $type, 'institution' => $institution, 'files' => [UploadedFile::fake()->create($type.'.pdf', 20, 'application/pdf')],
        ])->assertSessionHasNoErrors();
    }

    private function readyToSend(User $user, Student $student): void
    {
        $this->actingAs($user->fresh())->post(route('portal.courses.rows'), ['items' => [$this->row('Matematik I', $this->course('MAT101')->id)]]);
        $this->upload($user, 'dilekce');
        $this->upload($user, 'transkript');
        $this->assertSame(2, StudentDocument::query()->where('student_id', $student->id)->count());
    }
}
