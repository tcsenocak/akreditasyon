<?php

namespace Tests\Feature;

use App\Models\CourseMapping;
use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Services\PlanAdaptationService;
use App\Services\TextNormalizer;
use App\Services\TranscriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanIntibakTest extends TestCase
{
    use RefreshDatabase;

    private Curriculum $old;

    private Curriculum $new;

    protected function setUp(): void
    {
        parent::setUp();
        $program = Program::query()->create(['department' => 'Test Mühendisliği', 'name' => 'Test Mühendisliği', 'slug' => 'test']);
        $this->old = Curriculum::query()->create(['program_id' => $program->id, 'title' => '2017 Planı', 'is_active' => false]);
        $this->new = Curriculum::query()->create(['program_id' => $program->id, 'title' => '2022 Planı', 'is_active' => true]);

        // Eski plan
        $this->course($this->old, 'TST101', 'Statik', 1, 5);
        $this->course($this->old, 'TST102', 'Dinamik', 2, 5);
        $this->course($this->old, 'TST103', 'Malzeme I', 1, 3);
        $this->course($this->old, 'TST104', 'Malzeme II', 2, 3);
        $this->course($this->old, 'TST105', 'Eski Seçmeli', 3, 4, 'S');
        $this->course($this->old, 'TST106', 'Mühendislik Etiği', 3, 3);
        // Yeni plan
        $this->course($this->new, 'TST101', 'Statik', 1, 5);           // aynı
        $this->course($this->new, 'TST202', 'Dinamik', 3, 5);          // adı aynı, yarıyıl değişti
        $this->course($this->new, 'TST110', 'Malzeme Bilimi', 2, 6);   // birleşme hedefi
        $this->course($this->new, 'TST120', 'Proje Yönetimi', 3, 4);   // kaldırılan dersin karşılığı
        $this->course($this->new, 'TST130', 'Bilgisayar Programlama', 1, 3); // yeni ders
    }

    public function test_sistem_eslestirmesi_kilitli_ve_silinemez(): void
    {
        $result = app(PlanAdaptationService::class)->autoMap($this->old, $this->new);

        $this->assertSame(2, $result['locked']);
        $types = CourseMapping::query()->pluck('change_type')->all();
        $this->assertContains('ayni', $types);
        $this->assertContains('yariyil', $types);

        $locked = CourseMapping::query()->where('locked', true)->firstOrFail();
        $this->actingAs(User::factory()->create())->delete(route('mappings.destroy', $locked))->assertSessionHasErrors('mapping');
        $this->assertDatabaseHas('course_mappings', ['id' => $locked->id]);
    }

    public function test_birlesme_kaldirma_ve_akts_esitleme(): void
    {
        $service = app(PlanAdaptationService::class);
        $service->autoMap($this->old, $this->new);
        $service->createMapping($this->old, $this->new, [$this->find($this->old, 'TST103'), $this->find($this->old, 'TST104')], [$this->find($this->new, 'TST110')], 'birlesme');
        $service->createMapping($this->old, $this->new, [$this->find($this->old, 'TST105')], [$this->find($this->new, 'TST120')], 'kaldirildi');
        $service->createMapping($this->old, $this->new, [$this->find($this->old, 'TST106')], [$this->find($this->new, 'TST120')], 'kaldirildi');

        $student = Student::query()->create(['first_name' => 'Eski', 'last_name' => 'Plan', 'admission_type' => 'diger']);
        app(TranscriptService::class)->addRows($student, [
            ['code' => 'TST101', 'name' => 'Statik', 'grade_raw' => 'BB'],
            ['code' => 'TST102', 'name' => 'Dinamik', 'grade_raw' => 'FF'],
            ['code' => 'TST103', 'name' => 'Malzeme I', 'grade_raw' => 'CC'],
            ['code' => 'TST104', 'name' => 'Malzeme II', 'grade_raw' => 'AA'],
            ['code' => 'TST105', 'name' => 'Eski Seçmeli', 'grade_raw' => 'M'],
        ], 'manuel');

        $result = $service->applyToStudent($student, $this->old, $this->new, 4, true);
        $rows = collect($result['rows'])->keyBy(fn ($r) => $r['course']->code);

        $this->assertSame('BB', $rows['TST101']['letter']);
        $this->assertSame('FF', $rows['TST202']['letter']);          // Md. 7/5-b
        $this->assertFalse($rows['TST202']['passed']);
        $this->assertSame('AA', $rows['TST110']['letter']);          // Md. 7/3-a yüksek not
        $this->assertSame('CC', $rows['TST120']['letter']);          // M → CC (Md. 7/1-c-iv)
        $this->assertSame('CC', $rows['TST130']['letter']);          // Md. 7/1-c-iii

        // Eski: 5+3+3+4 = 15 AKTS başarılı; yeni: 5+6+4+3 = 18
        $this->assertSame(15.0, $result['old']['ects']);
        $this->assertSame(18.0, $result['new']['ects']);
        $this->assertSame([], $result['balancing']);

        $this->actingAs(User::factory()->create())
            ->get(route('mappings.student', [$this->old, $this->new, 'student_id' => $student->id, 'semester' => 4, 'completed' => 1]))
            ->assertOk()->assertSee('Malzeme Bilimi');
        $this->get(route('mappings.show', [$this->old, $this->new]))->assertOk()->assertSee('uygulanıyor');
        $this->get(route('mappings.index'))->assertOk()->assertSee('Güncel durum: uygulanan plan intibakları')->assertSee($this->old->title)->assertDontSee('Henüz uygulanan plan intibakı yok');
    }

    public function test_akts_dusunce_katki_dersi_eklenir(): void
    {
        $service = app(PlanAdaptationService::class);
        $service->autoMap($this->old, $this->new);
        // Etik (3 AKTS) kaldırıldı ve hiçbir derse aktarılmadı → AKTS düşer
        $student = Student::query()->create(['first_name' => 'A', 'last_name' => 'B', 'admission_type' => 'diger']);
        app(TranscriptService::class)->addRows($student, [
            ['code' => 'TST101', 'name' => 'Statik', 'grade_raw' => 'BB'],
            ['code' => 'TST106', 'name' => 'Mühendislik Etiği', 'grade_raw' => 'AA'],
        ], 'manuel');

        $result = $service->applyToStudent($student, $this->old, $this->new);

        $this->assertSame(8.0, $result['old']['ects']);
        $this->assertSame(5.0, $result['new']['ects']);
        $this->assertSame(3.0, (float) collect($result['balancing'])->sum('ects'));
        $this->assertSame('Topluma Katkı', $result['balancing'][0]['name']);
        $this->assertNotEmpty($result['warnings']); // eşleşmeyen eski ders uyarısı (Md. 6/1-ç)
    }

    private function course(Curriculum $curriculum, string $code, string $name, int $semester, float $ects, string $type = 'Z'): CurriculumCourse
    {
        return $curriculum->courses()->create([
            'code' => $code, 'root_code' => $code, 'name' => $name, 'normalized_name' => TextNormalizer::normalize($name),
            'type' => $type, 'credit' => 3, 'ects' => $ects, 'semester' => $semester, 'category' => 'plan',
        ]);
    }

    private function find(Curriculum $curriculum, string $code): CurriculumCourse
    {
        return $curriculum->courses()->where('code', $code)->firstOrFail();
    }
}
