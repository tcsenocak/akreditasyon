<?php

namespace Tests\Feature;

use App\Models\CatalogProgram;
use App\Models\Curriculum;
use App\Models\ExemptionFile;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\TranscriptCourse;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\TextNormalizer;
use App\Services\TranscriptTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class OgrenciPaneliTest extends TestCase
{
    use RefreshDatabase;

    private Program $program;

    private Curriculum $curriculum;

    private CatalogProgram $ktu;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['intibak.student_registration' => true]);
        $this->program = Program::query()->create(['department' => 'Metalurji', 'name' => 'Metalurji ve Malzeme Mühendisliği', 'slug' => 'mmm']);
        $this->curriculum = Curriculum::query()->create(['program_id' => $this->program->id, 'title' => '2026', 'start_year' => '2026-2027', 'is_active' => true]);
        foreach ([['FMT151', 'Matematik I', 4, 6, 1], ['FFZ121', 'Fizik I', 4, 6, 1], ['MMT201', 'Mühendislik Mekaniği', 3, 4, 3]] as [$code, $name, $credit, $ects, $semester]) {
            $this->curriculum->courses()->create([
                'code' => $code, 'root_code' => $code, 'name' => $name, 'normalized_name' => TextNormalizer::normalize($name),
                'type' => 'Z', 'credit' => $credit, 'ects' => $ects, 'semester' => $semester, 'category' => 'plan',
            ]);
        }
        $catalog = app(CatalogService::class);
        $this->ktu = $catalog->upsertProgram('Karadeniz Teknik Üniversitesi', 'Mühendislik Fakültesi', 'Metalurji ve Malzeme Mühendisliği', 'komisyon', null, true);
        $catalog->upsertCourse($this->ktu, ['code' => 'MAT1001', 'name' => 'Matematik I', 'credit' => 4, 'ects' => 6, 'semester' => 1]);
        $catalog->upsertCourse($this->ktu, ['code' => 'FIZ1001', 'name' => 'Fizik I', 'credit' => 3, 'ects' => 5, 'semester' => 1]);
    }

    public function test_ogrenci_kayit_olur_ve_panele_yonlenir(): void
    {
        $this->post(route('register.store'), $this->registration())->assertRedirect(route('portal.home'));
        $user = User::query()->where('email', 'ogrenci@example.com')->firstOrFail();

        $this->assertSame('student', $user->role);
        $this->assertSame($this->curriculum->id, $user->student->curriculum_id);
    }

    public function test_kayit_kapaliyken_ogrenci_kendi_hesabini_acamaz(): void
    {
        config(['intibak.student_registration' => false]);

        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.store'), $this->registration())->assertNotFound();
        $this->get(route('login'))->assertOk()->assertDontSee('Hesap oluşturun');
    }

    public function test_komisyon_hesap_acar_ogrenci_ilk_giriste_parola_degistirir(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->post(route('students.store'), [
            'first_name' => 'Ali', 'last_name' => 'Kaya', 'program_id' => $this->program->id, 'account_email' => 'ali@example.com',
        ])->assertRedirect()->assertSessionHas('account_password');
        $temporary = session('account_password')['password'];
        $student = Student::query()->where('first_name', 'Ali')->firstOrFail();
        $this->assertSame($this->curriculum->id, $student->curriculum_id);
        auth()->logout();

        $this->post(route('login.attempt'), ['email' => 'ali@example.com', 'password' => $temporary])->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertRedirect(route('portal.password'));
        $this->put(route('portal.password.update'), [
            'current_password' => $temporary, 'password' => 'yeni-parola-123', 'password_confirmation' => 'yeni-parola-123',
        ])->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertOk()->assertSee('Merhaba, Ali');

        $this->actingAs($admin)->post(route('students.account.reset', $student))->assertSessionHas('account_password');
        $this->assertTrue($student->user()->first()->must_change_password);
    }

    public function test_ogrenci_komisyon_sayfalarina_giremez(): void
    {
        $user = $this->studentUser();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('portal.home'));
        $this->actingAs($user)->get(route('equivalences.index'))->assertRedirect(route('portal.home'));
        $this->actingAs(User::factory()->create())->get(route('portal.home'))->assertForbidden();
    }

    public function test_yalniz_secili_bolumun_dersleri_listelenir_ve_eklenir(): void
    {
        $user = $this->studentUser();
        // Aynı kurumda başka bir bölüm
        $catalog = app(CatalogService::class);
        $other = $catalog->upsertProgram('Karadeniz Teknik Üniversitesi', 'Mühendislik Fakültesi', 'İnşaat Mühendisliği', 'komisyon', null, true);
        $otherCourse = $catalog->upsertCourse($other, ['code' => 'INS101', 'name' => 'Statik', 'credit' => 3, 'ects' => 5]);

        // Beyan edilen bölüm adından bölüm bulunur
        $this->actingAs($user)->get(route('portal.courses'))->assertOk()
            ->assertSee('Karadeniz Teknik Üniversitesi — Metalurji ve Malzeme Mühendisliği');

        // Arama yalnız seçili bölümün içinde çalışır
        $byCode = $this->actingAs($user)->getJson(route('portal.courses.search', ['q' => 'MAT1001']))->assertOk()->json();
        $this->assertCount(1, $byCode);
        $this->assertSame('Matematik I', $byCode[0]['name']);
        $this->assertSame([], $this->actingAs($user)->getJson(route('portal.courses.search', ['q' => 'Statik']))->json());

        // Başka bölümün dersi eklenemez
        $this->actingAs($user)->post(route('portal.courses.store'), ['course_key' => 'ext:'.$otherCourse->id, 'grade_raw' => 'AA'])
            ->assertSessionHasErrors('course_key');

        // Bölüm değiştirme: kurum üniversite listesinden; bölüm AÜ Mühendislik programlarından ya da "Diğer" ile yazılır
        $this->actingAs($user)->get(route('portal.courses'))->assertSee('Diğer (bölümümü yazacağım)')->assertSee('<option value="Zonguldak Bülent Ecevit Üniversitesi"', false);
        $this->actingAs($user)->post(route('portal.courses.department'), ['institution' => 'Karadeniz Teknik Üniversitesi', 'department' => '__diger', 'department_other' => 'İnşaat Mühendisliği'])->assertRedirect(route('portal.courses'));
        $this->assertSame('İnşaat Mühendisliği', $user->student->fresh()->previous_program);
        $this->assertSame('Statik', $this->actingAs($user)->getJson(route('portal.courses.search', ['q' => 'statik']))->json()[0]['name']);
        // Listede olmayan kurum ve adı yazılmamış "Diğer" kabul edilmez
        $this->actingAs($user)->post(route('portal.courses.department'), ['institution' => 'Uydurma Üniversitesi', 'department' => '__diger', 'department_other' => 'X'])->assertSessionHasErrors('institution');
        $this->actingAs($user)->post(route('portal.courses.department'), ['institution' => 'Ege Üniversitesi', 'department' => '__diger'])->assertSessionHasErrors('department_other');
        $this->actingAs($user)->post(route('portal.courses.department'), ['institution' => 'Karadeniz Teknik Üniversitesi', 'department' => $this->curriculum->program->full_name])->assertRedirect(route('portal.courses'));
        $this->assertSame('ext:'.$this->ktu->id, $user->student->fresh()->source_program_key);

        // Öğrenci ad/kredi gönderse bile katalog değerleri yazılır
        $this->actingAs($user)->post(route('portal.courses.store'), [
            'course_key' => 'ext:'.$this->ktu->courses()->where('code', 'MAT1001')->value('id'), 'grade_raw' => '85', 'name' => 'Değiştirilmiş Ad', 'credit' => 99,
        ])->assertRedirect();
        $course = $user->student->transcript()->firstOrFail();
        $this->assertSame('Matematik I', $course->name);
        $this->assertSame(4.0, $course->credit);
        $this->assertSame('BA', $course->letter);
        $this->assertNotNull($course->catalog_course_id);
        $this->assertSame('Metalurji ve Malzeme Mühendisliği', $course->department);

        // Güncellemede yalnız not değişir
        $this->actingAs($user)->put(route('portal.courses.update', $course), ['grade_raw' => 'CC', 'name' => 'Başka'])->assertRedirect();
        $this->assertSame('Matematik I', $course->fresh()->name);
        $this->assertSame('CC', $course->fresh()->letter);

        // Katalog anahtarı olmadan ders eklenemez
        $this->actingAs($user)->post(route('portal.courses.store'), ['name' => 'Serbest Ders', 'grade_raw' => 'AA'])->assertSessionHasErrors('course_key');
    }

    public function test_sablon_indirilir_ve_listede_olmayan_bolum_kataloga_eklenir(): void
    {
        $user = $this->studentUser();
        $this->actingAs($user)->get(route('portal.courses.template'))->assertOk()->assertDownload('not-dokumu-sablonu.xlsx');

        $spreadsheet = app(TranscriptTemplate::class)->build();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Ege Üniversitesi', 'Mühendislik Fakültesi', 'Makine Mühendisliği', 1, 'MAK101', 'Statik', 3, 5, 'BB', '2024-2025'],
            ['Ege Üniversitesi', 'Mühendislik Fakültesi', 'Makine Mühendisliği', 2, 'MAK102', 'Dinamik', 3, 5, '', '2024-2025'],
        ], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'sablon').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $this->actingAs($user)->post(route('portal.courses.import'), ['file' => new UploadedFile($path, 'dersler.xlsx', null, null, true)])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, $user->student->transcript()->count(), 'Notu boş satır atlanır');
        // Bölümü olan öğrencinin seçimi şablonla değişmez
        $this->assertSame('Metalurji ve Malzeme Mühendisliği', $user->student->fresh()->previous_program);
        $program = CatalogProgram::query()->where('institution', 'Ege Üniversitesi')->firstOrFail();
        $this->assertSame('ogrenci', $program->source);
        $this->assertFalse($program->verified);
        $this->assertSame(2, $program->courses()->count());
        // Öğrencinin girdiği bölüm listesini komisyon öğrencinin sayfasından onaylar
        $this->actingAs(User::factory()->create())->get(route('students.show', $user->student))->assertOk()
            ->assertSee('Öğrencinin girdiği bölüm ders listeleri')->assertSee('Makine Mühendisliği')->assertSee('onay bekliyor');
        $this->post(route('catalog.verify', $program))->assertSessionHas('status');
        $this->assertTrue($program->fresh()->verified);
    }

    public function test_ogrenci_once_derslerini_girer_sonra_eslestirip_kaydeder(): void
    {
        $user = $this->studentUser();
        $math = $this->curriculum->courses()->where('code', 'FMT151')->firstOrFail();
        $other = $this->curriculum->courses()->where('code', 'MMT201')->firstOrFail();

        $html = $this->actingAs($user)->get(route('portal.courses'))->assertOk()->getContent();
        $this->assertStringContainsString('1. Aldığım dersler', $html);
        $this->assertStringContainsString('data-add="'.$math->id.'"', $html);
        $this->assertStringContainsString('Kaydet ve gönder', $html);
        $this->actingAs($user)->get(route('portal.matches'))->assertRedirect(route('portal.courses'));

        $this->actingAs($user)->post(route('portal.courses.rows'), ['items' => [
            ['institution' => 'Karadeniz Teknik Üniversitesi', 'code' => 'mat1001', 'name' => 'Matematik I', 'credit' => '4', 'ects' => '6', 'grade_raw' => 'BB', 'target_id' => $math->id],
            ['institution' => 'Ege Üniversitesi', 'code' => '', 'name' => 'Genel Kimya', 'credit' => '', 'ects' => '5', 'grade_raw' => '85', 'target_id' => ''],
        ]])->assertRedirect(route('portal.courses'))->assertSessionHas('status');

        $rows = $user->student->transcript()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['MAT1001', 'Matematik I', 6.0, $math->id], [$rows[0]->code, $rows[0]->name, (float) $rows[0]->ects, $rows[0]->requested_course_id]);
        $this->assertSame('BB', $rows[0]->letter);
        $this->assertNull($rows[1]->requested_course_id);
        $this->assertNotNull($rows[1]->catalog_course_id, 'AÜ dışı ders komisyon onayı için bölüm listesine eklenir');

        // Eşleştirme değişir, silinen satır kalkar
        $this->actingAs($user)->post(route('portal.courses.rows'), [
            'items' => [['id' => $rows[0]->id, 'institution' => 'Karadeniz Teknik Üniversitesi', 'code' => 'MAT1001', 'name' => 'Matematik I', 'credit' => '4', 'ects' => '6', 'grade_raw' => 'BB', 'target_id' => $other->id]],
            'deleted' => [$rows[1]->id],
        ])->assertRedirect();
        $this->assertSame([$other->id], $user->student->transcript()->pluck('requested_course_id')->all());

        // Plan dışı ders ve listede olmayan kurum kabul edilmez
        $foreign = $this->curriculum->courses()->first()->replicate(['curriculum_id'])->fill(['curriculum_id' => Curriculum::query()->create(['program_id' => $this->program->id, 'title' => 'eski', 'is_active' => false])->id]);
        $foreign->save();
        $bad = ['institution' => 'Ege Üniversitesi', 'name' => 'X', 'ects' => '3', 'grade_raw' => 'AA'];
        $this->actingAs($user)->post(route('portal.courses.rows'), ['items' => [$bad + ['target_id' => $foreign->id]]])->assertSessionHasErrors('items.0.target_id');
        $this->actingAs($user)->post(route('portal.courses.rows'), ['items' => [['institution' => 'Uydurma Üniversitesi'] + $bad]])->assertSessionHasErrors('items.0.institution');
    }

    public function test_belgeler_tek_panelde_ve_basvuru_asamalari_gorunur(): void
    {
        $user = $this->studentUser();
        $this->actingAs($user)->get(route('portal.documents'))->assertRedirect(route('portal.courses').'#mx-docs');
        $this->actingAs($user)->get(route('portal.courses'))->assertOk()->assertSee('3. Belgeler ve dilekçeler')->assertSee('4. Kaydet ve gönder');

        // Belge sayfa yenilenmeden (JSON) yüklenir ve silinir
        $this->actingAs($user)->postJson(route('portal.documents.store'), ['type' => 'dilekce', 'files' => [UploadedFile::fake()->create('dilekce.pdf', 50, 'application/pdf')]])
            ->assertOk()->assertJson(['message' => '1 belge yüklendi.']);
        $doc = $user->student->documents()->firstOrFail();
        $this->actingAs($user)->deleteJson(route('documents.destroy', $doc))->assertOk();
        $this->assertSame(0, $user->student->documents()->count());

        $html = $this->actingAs($user)->get(route('portal.home'))->assertOk()->getContent();
        $this->assertStringContainsString('Başvurumun aşamaları', $html);
        $this->assertStringContainsString('st-current', $html);
        $this->assertStringContainsString('Komisyonda inceleniyor', $html);
    }

    public function test_kaydet_ve_gonder_eksik_belgede_durur(): void
    {
        $user = $this->studentUser();
        $math = $this->curriculum->courses()->where('code', 'FMT151')->firstOrFail();
        $item = ['institution' => 'Karadeniz Teknik Üniversitesi', 'name' => 'Matematik I', 'credit' => '4', 'ects' => '6', 'grade_raw' => 'BB', 'target_id' => $math->id];

        $this->actingAs($user)->from(route('portal.courses'))->post(route('portal.courses.rows'), ['items' => [$item], 'gonder' => 1])
            ->assertRedirect(route('portal.courses'))->assertSessionHasErrors('submit');
        $this->assertSame(1, $user->student->transcript()->count(), 'Eksik belge olsa da dersler kaydedilir');
        $this->assertSame('taslak', $user->student->fresh()->application_status);

        foreach (['transkript', 'dilekce'] as $type) {
            $this->actingAs($user)->post(route('portal.documents.store'), ['type' => $type, 'files' => [UploadedFile::fake()->create($type.'.pdf', 120, 'application/pdf')]]);
        }
        $id = $user->student->transcript()->value('id');
        $this->actingAs($user)->post(route('portal.courses.rows'), ['items' => [['id' => $id] + $item], 'gonder' => 1])->assertRedirect(route('portal.home'));
        $this->assertSame('gonderildi', $user->student->fresh()->application_status);
        $this->actingAs($user)->post(route('portal.courses.rows'), ['items' => [['id' => $id] + $item]])->assertForbidden();
    }

    public function test_otomatik_oneri_baglanmamis_dersleri_eslestirir(): void
    {
        $user = $this->studentUser();
        $key = 'ext:'.$this->ktu->courses()->where('code', 'MAT1001')->value('id');
        $this->actingAs($user)->post(route('portal.courses.store'), ['course_key' => $key, 'grade_raw' => 'BB']);

        $this->actingAs($user)->post(route('portal.matches.auto'))->assertRedirect();
        $math = $this->curriculum->courses()->where('code', 'FMT151')->firstOrFail();
        $this->assertSame($math->id, $user->student->transcript()->firstOrFail()->requested_course_id);
    }

    public function test_belge_yukleme_gonderme_ve_kilit(): void
    {
        $user = $this->studentUser();
        $this->actingAs($user)->post(route('portal.submit'))->assertSessionHasErrors('submit');

        $key = 'ext:'.$this->ktu->courses()->where('code', 'FIZ1001')->value('id');
        $this->actingAs($user)->post(route('portal.courses.store'), ['course_key' => $key, 'grade_raw' => 'BB']);
        $user->student->transcript()->update(['requested_course_id' => $this->curriculum->courses()->where('code', 'FFZ121')->value('id')]);
        foreach (['transkript', 'dilekce'] as $type) {
            $this->actingAs($user)->post(route('portal.documents.store'), [
                'type' => $type, 'files' => [UploadedFile::fake()->create($type.'.pdf', 120, 'application/pdf')],
            ])->assertRedirect();
        }
        $document = $user->student->documents()->firstOrFail();
        Storage::disk('local')->assertExists($document->path);

        $this->actingAs($user)->post(route('portal.submit'))->assertRedirect(route('portal.home'));
        $student = $user->student->fresh();
        $this->assertSame('gonderildi', $student->application_status);
        $this->actingAs($user->fresh())->post(route('portal.courses.store'), ['course_key' => $key, 'grade_raw' => 'AA'])->assertForbidden();

        $admin = User::factory()->create();
        $this->actingAs($admin)->get(route('documents.download', $document))->assertOk();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Değerlendirme bekleyen başvurular');
        $this->actingAs($admin)->post(route('files.store', $student))->assertRedirect();
        $item = ExemptionFile::query()->firstOrFail()->items()->firstOrFail();
        $this->assertSame('ogrenci', $item->rule);
        $this->assertSame('oneri', $item->status);
        $this->assertFalse($item->ects_ok); // 3 kr / 5 AKTS < 4 kr / 6 AKTS
        $this->assertSame('inceleniyor', $student->fresh()->application_status);

        // Komisyon geri açınca öğrenci yeniden düzenleyebilir
        $this->actingAs($admin)->put(route('students.application', $student), ['application_status' => 'taslak'])->assertRedirect();
        $this->actingAs($user->fresh())->post(route('portal.courses.store'), ['course_key' => $key, 'grade_raw' => 'AA'])->assertRedirect();
    }

    public function test_baska_ogrencinin_dersi_ve_belgesi_degistirilemez(): void
    {
        $user = $this->studentUser();
        $intruder = $this->studentUser('baska@example.com');
        $course = TranscriptCourse::query()->create(['student_id' => $user->student_id, 'name' => 'Fizik I', 'normalized_name' => 'fizik 1', 'grade_raw' => 'BB']);
        $document = StudentDocument::query()->create(['student_id' => $user->student_id, 'type' => 'transkript', 'original_name' => 'a.pdf', 'path' => 'belgeler/x/a.pdf']);

        $this->actingAs($intruder)->delete(route('portal.courses.destroy', $course))->assertForbidden();
        $this->actingAs($intruder)->get(route('documents.download', $document))->assertForbidden();
        $this->assertDatabaseHas('transcript_courses', ['id' => $course->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(string $email = 'ogrenci@example.com'): array
    {
        return [
            'first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'email' => $email,
            'password' => 'gizli-parola-1', 'password_confirmation' => 'gizli-parola-1',
            'program_id' => $this->program->id, 'admission_type' => 'yatay',
            'previous_institution' => 'Karadeniz Teknik Üniversitesi',
            'previous_program' => 'Metalurji ve Malzeme Mühendisliği',
        ];
    }

    private function studentUser(string $email = 'ogrenci@example.com'): User
    {
        $this->post(route('register.store'), $this->registration($email));
        auth()->logout();

        return User::query()->where('email', $email)->firstOrFail();
    }
}
