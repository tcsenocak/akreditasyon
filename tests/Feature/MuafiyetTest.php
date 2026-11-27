<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Equivalence;
use App\Models\ExemptionFile;
use App\Models\ExemptionItem;
use App\Models\Student;
use App\Models\User;
use App\Services\CurriculumImporter;
use App\Services\ExemptionEngine;
use App\Services\TranscriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MuafiyetTest extends TestCase
{
    use RefreshDatabase;

    private Curriculum $curriculum;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $path = storage_path('app/planlar/METALURJI-VE-MALZEME-MUH.xlsx');
        if (! is_file($path)) {
            $this->markTestSkipped('Öğretim planı dosyası yok: '.$path);
        }
        $this->curriculum = app(CurriculumImporter::class)->import($path)['curriculum'];
        $this->user = User::factory()->create();
    }

    public function test_plan_dogru_aktarilir(): void
    {
        $plan = $this->curriculum->courses()->where('category', 'plan');

        $this->assertSame('Metalurji ve Malzeme Mühendisliği', $this->curriculum->program->name);
        $this->assertSame(240.0, (float) $plan->sum('ects'));
        foreach (range(1, 8) as $semester) {
            $this->assertSame(30.0, (float) $this->curriculum->courses()->where('category', 'plan')->where('semester', $semester)->sum('ects'), "Yarıyıl {$semester}");
        }
        $this->assertSame('AIIT', $this->curriculum->courses()->where('code', 'AIT101')->value('common_group'));
        $this->assertSame('alternative', $this->curriculum->courses()->where('code', 'MMT444')->value('category'));
    }

    /**
     * Otomatik öneri motoru (öğrenciye “Otomatik eşleştirme öner” olarak sunulur).
     */
    public function test_otomatik_oneri_motoru_kurallari(): void
    {
        $student = $this->student(['yds_score' => 62, 'yds_exam' => 'YDS']);
        $this->addRows($student, [
            ['code' => 'ATA101', 'name' => 'Atatürk İlkeleri ve İnkılap Tarihi I', 'ects' => 1, 'grade_raw' => 'BB'],
            ['code' => 'ATA102', 'name' => 'Atatürk İlkeleri ve İnkılap Tarihi II', 'ects' => 1, 'grade_raw' => 'G'],
            ['code' => 'TUR101', 'name' => 'Türk Dili I', 'ects' => 2, 'grade_raw' => '75'],
            ['code' => 'MAT1001', 'name' => 'Matematik I', 'ects' => 6, 'grade_raw' => 'BA'],
            ['code' => 'MAT1002', 'name' => 'Matematik II', 'ects' => 6, 'grade_raw' => 'FF'],
            ['code' => 'KIM1001', 'name' => 'Genel Kimya', 'ects' => 6, 'grade_raw' => 'AA'],
            ['code' => 'MMM1003', 'name' => 'Teknik Resim', 'ects' => 2, 'grade_raw' => 'BB'],
            ['code' => 'MMT2001', 'name' => 'Malzeme Bilimine Giriş', 'ects' => 5, 'grade_raw' => 'CB'],
        ]);
        $items = $this->engineItems($student);

        $this->assertSame('ortak_zorunlu', $items['AIT101']->rule);
        $this->assertSame('CC', $items['AIT102']->letter); // G → CC
        $this->assertSame('BB', $items['TDZ101']->letter); // 75 → BB
        $this->assertSame('yds', $items['YDZ101']->rule);
        $this->assertSame('BA', $items['FMT151']->letter);
        $this->assertSame('FKM123', $items->first(fn ($i) => $i->sources->contains('name', 'Genel Kimya'))->course->code);
        $this->assertTrue($items['MUH104']->sources->contains('name', 'Malzeme Bilimine Giriş'));
        $this->assertFalse($items['MUH101']->ects_ok); // Teknik Resim 2 < 3 AKTS
        $this->assertArrayNotHasKey('FMT152', $items->all()); // başarısız ders kullanılmaz
    }

    public function test_akts_tutmazsa_kredi_ve_ayni_adli_derslerin_birlestirilmesi(): void
    {
        $student = $this->student();
        $this->addRows($student, [
            // AÜ FMT151 Matematik I: 4 kredi, 6 AKTS. Her biri tek başına yetersiz, toplamı yeterli.
            ['code' => 'MAT101', 'name' => 'Matematik I', 'credit' => 3, 'ects' => 4, 'grade_raw' => 'AA'],
            ['code' => 'MAT102', 'name' => 'Matematik II', 'credit' => 3, 'ects' => 4, 'grade_raw' => 'CC'],
            // AÜ FFZ121 Fizik I: 4 kredi, 6 AKTS. AKTS düşük, kredi yeterli.
            ['code' => 'FIZ101', 'name' => 'Fizik I', 'credit' => 4, 'ects' => 5, 'grade_raw' => 'BB'],
            // AÜ FFZ122 Fizik II: 4 kredi, 6 AKTS. İkisi de yetersiz.
            ['code' => 'FIZ102', 'name' => 'Fizik II', 'credit' => 3, 'ects' => 5, 'grade_raw' => 'BB'],
        ]);
        $items = $this->engineItems($student);

        $this->assertEqualsCanonicalizing(['Matematik I', 'Matematik II'], $items['FMT151']->sources->pluck('name')->all());
        $this->assertSame('BB', $items['FMT151']->letter); // (4*4 + 2*4) / 8 = 3,0
        $this->assertArrayNotHasKey('FMT152', $items->all());
        $this->assertTrue($items['FFZ121']->ects_ok);
        $this->assertStringContainsString('kredi yeterli', $items['FFZ121']->reason);
        $this->assertFalse($items['FFZ122']->ects_ok);
    }

    /**
     * Öğrenci eşleştirir → komisyon kontrollerle değerlendirir → karar havuza yazılır → sonraki öğrenciye öneri olur.
     */
    public function test_ogrenci_eslestirmesi_komisyon_degerlendirmesi_ve_havuz(): void
    {
        $first = $this->student();
        $this->addRows($first, [
            ['code' => 'MET210', 'name' => 'Metallerin Korunması', 'credit' => 3, 'ects' => 4, 'grade_raw' => 'BB', 'content' => 'Korozyon mekanizmaları, galvanik korozyon, pasivasyon, katodik koruma, kaplamalar, inhibitörler, korozyon deneyleri'],
            ['code' => 'MAT1001', 'name' => 'Matematik I', 'credit' => 4, 'ects' => 6, 'grade_raw' => 'AA'],
        ]);
        $target = $this->curriculum->courses()->where('code', 'MMT210')->firstOrFail();
        $math = $this->curriculum->courses()->where('code', 'FMT151')->firstOrFail();
        $first->transcript()->where('code', 'MET210')->update(['requested_course_id' => $target->id]);
        $first->transcript()->where('code', 'MAT1001')->update(['requested_course_id' => $math->id]);

        $this->actingAs($this->user)->post(route('files.store', $first))->assertRedirect();
        $file = ExemptionFile::query()->firstOrFail();
        $items = $file->items()->with('course')->get()->keyBy(fn ($i) => $i->course->code);

        // Öğrenci talepleri karar beklemeyle gelir; komisyon kendiliğinden kabul edilmiş satır görmez
        $this->assertSame('ogrenci', $items['MMT210']->rule);
        $this->assertSame('oneri', $items['MMT210']->status);
        $this->assertTrue($items['MMT210']->ects_ok);
        $this->assertSame('oneri', $items['FMT151']->status);
        $this->assertSame('inceleniyor', $first->fresh()->application_status);
        $this->actingAs($this->user)->get(route('files.show', $file))->assertOk()->assertSee('İçerik karşılaştır')->assertSee('Metallerin Korunması');

        // Komisyon içerik notuyla kabul eder; toplu kabul kontrolü geçen Matematik'i alır
        $this->actingAs($this->user)->put(route('files.items.update', $items['MMT210']), ['status' => 'kabul', 'letter' => 'BB', 'commission_note' => 'İçerik uyumlu'])->assertRedirect();
        $this->actingAs($this->user)->post(route('files.bulk', $file), ['action' => 'accept_strong']);
        $this->assertSame('kabul', $items['FMT151']->fresh()->status);
        $this->assertSame('İçerik uyumlu', $items['MMT210']->fresh()->commission_note);
        $this->assertSame(10.0, $file->fresh()->exempt_ects);

        $this->actingAs($this->user)->put(route('files.update', $file), ['status' => 'karar', 'decision_no' => '2026/15-3'])->assertRedirect();
        $this->assertSame('sonuclandi', $first->fresh()->application_status);
        $this->assertSame('MMT210', Equivalence::query()->where('ext_name', 'Metallerin Korunması')->value('target_code'));
        $this->actingAs($this->user)->get(route('files.report', $file))->assertOk()->assertSee('KOMİSYONU RAPORU');
        $this->actingAs($this->user)->get(route('files.report', [$file, 'tur' => 'ogrenci']))->assertOk()->assertSee('beş iş günü');
        $this->actingAs($this->user)->get(route('files.export', $file))->assertOk();

        // Sonraki öğrenciye havuzdan otomatik öneri
        $second = $this->student(['first_name' => 'İkinci']);
        $this->addRows($second, [['code' => 'MET210', 'name' => 'Metallerin Korunması', 'credit' => 3, 'ects' => 4, 'grade_raw' => 'CB']]);
        $suggestions = app(ExemptionEngine::class)->suggestRequests($second, $this->curriculum);
        $this->assertSame($target->id, $suggestions[0]['target_id']);
        $this->assertSame('havuz', $suggestions[0]['rule']);
        $this->assertSame(0, ExemptionFile::query()->where('student_id', $second->id)->count(), 'Öneri kalıcı dosya oluşturmaz');
    }

    public function test_plan_yeniden_aktarilinca_muafiyet_kalemleri_korunur(): void
    {
        $student = $this->student();
        $this->addRows($student, [['code' => 'MAT101', 'name' => 'Matematik I', 'credit' => 4, 'ects' => 6, 'grade_raw' => 'BB']]);
        $student->transcript()->update(['requested_course_id' => $this->curriculum->courses()->value('id')]);
        $this->actingAs($this->user)->post(route('files.store', $student));
        $before = ExemptionFile::query()->firstOrFail()->items()->count();
        $this->assertGreaterThan(0, $before);
        $courseIds = $this->curriculum->courses()->pluck('id')->sort()->values()->all();

        app(CurriculumImporter::class)->import(storage_path('app/planlar/METALURJI-VE-MALZEME-MUH.xlsx'));

        $this->assertSame($before, ExemptionFile::query()->firstOrFail()->items()->count());
        $this->assertSame($courseIds, $this->curriculum->courses()->pluck('id')->sort()->values()->all());
    }

    public function test_aktif_plan_degistirilir(): void
    {
        $old = Curriculum::query()->create(['program_id' => $this->curriculum->program_id, 'title' => 'Eski', 'is_active' => false]);

        $this->actingAs($this->user)->post(route('curricula.activate', $old));
        $this->assertTrue($old->fresh()->is_active);
        $this->assertFalse($this->curriculum->fresh()->is_active);

        $this->actingAs($this->user)->post(route('curricula.activate', $this->curriculum));
        $this->assertTrue($this->curriculum->fresh()->is_active);
        $this->assertFalse($old->fresh()->is_active);
    }

    public function test_hazirlik_beyani_ve_muafiyet_sinavi(): void
    {
        $student = $this->student(['prep_completed' => true]);
        $this->actingAs($this->user)->post(route('files.store', $student));
        $file = ExemptionFile::query()->firstOrFail();

        // Öğrenci ne talep ettiyse o: yabancı dil beyanı satır açmaz, dosyada not olarak görünür
        $this->assertSame(0, $file->items()->where('rule', 'hazirlik')->count());
        $this->actingAs($this->user)->get(route('files.show', $file))->assertOk()->assertSee('hazırlık sınıfını başarıyla tamamladığını');

        $course = $this->curriculum->courses()->where('code', 'MUH102')->firstOrFail();
        $course->update(['common_group' => 'BIL']);
        $this->actingAs($this->user)->put(route('files.update', $file), ['status' => 'taslak', 'exam_scores' => [$course->id => 82]]);
        $this->actingAs($this->user)->post(route('files.generate', $file));
        $item = $file->items()->where('curriculum_course_id', $course->id)->firstOrFail();

        $this->assertSame('muafiyet_sinavi', $item->rule);
        $this->assertSame('BA', $item->letter);
        $this->assertSame('kabul', $item->status);
    }

    public function test_komisyon_ogrenci_dersi_giremez(): void
    {
        $student = $this->student();
        $this->actingAs($this->user);

        $this->assertFalse(app('router')->has('transcript.store'));
        $this->assertFalse(app('router')->has('transcript.import'));
        $this->assertFalse(app('router')->has('files.items.store'));
        $this->get(route('students.show', $student))->assertOk()->assertDontSee('Yeni ders adı');
        $this->get(route('applications.show', $student->currentApplication))->assertOk()->assertDontSee('Yeni ders adı')->assertSee('salt okunur');
    }

    public function test_sayfalar_acilir(): void
    {
        $student = $this->student();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->actingAs($this->user);
        foreach ([route('dashboard'), route('rules'), route('programs.index'), route('programs.show', $this->curriculum->program_id), route('curricula.course', [$this->curriculum, $this->curriculum->courses()->first()]),
            route('students.index'), route('students.create'), route('students.show', $student), route('students.edit', $student),
            route('files.index'), route('equivalences.index'), route('mappings.index'), route('settings.index')] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('curricula.show', $this->curriculum))->assertRedirect(route('programs.show', ['program' => $this->curriculum->program_id, 'plan' => $this->curriculum->id]));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function addRows(Student $student, array $rows): void
    {
        app(TranscriptService::class)->addRows($student, $rows, 'test');
    }

    /**
     * @return Collection<string, ExemptionItem>
     */
    private function engineItems(Student $student): Collection
    {
        $file = ExemptionFile::query()->create(['student_id' => $student->id, 'curriculum_id' => $this->curriculum->id, 'options' => ['exam_scores' => []]]);
        app(ExemptionEngine::class)->generate($file);

        return $file->items()->with(['course', 'sources'])->get()->keyBy(fn ($i) => $i->course->code ?? $i->course->name);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function student(array $overrides = []): Student
    {
        return Student::query()->create($overrides + [
            'first_name' => 'Deneme',
            'last_name' => 'Öğrenci',
            'admission_type' => 'yatay',
            'program_id' => $this->curriculum->program_id,
            'curriculum_id' => $this->curriculum->id,
            'previous_institution' => 'Karadeniz Teknik Üniversitesi',
        ]);
    }
}
