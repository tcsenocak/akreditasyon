<?php

namespace Tests\Feature;

use App\Models\CourseInfo;
use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use App\Models\Program;
use App\Services\CourseCatalogImporter;
use App\Services\Obs\ObsImporter;
use App\Services\PlanAdaptationService;
use App\Services\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KaynakAktarimTest extends TestCase
{
    use RefreshDatabase;

    public function test_ders_bilgi_paketi_doc_eski_plan_olarak_aktarilir(): void
    {
        Program::query()->create(['department' => 'Metalurji ve Malzeme Mühendisliği', 'name' => 'Metalurji ve Malzeme Mühendisliği', 'slug' => 'mmm']);

        $result = app(CourseCatalogImporter::class)->import(base_path('tests/Fixtures/ders-bilgi-paketi-metalurji.doc'));
        $plan = $result['curriculum']->courses()->where('category', 'plan');

        $this->assertFalse($result['curriculum']->is_active);
        $this->assertSame(240.0, (float) $plan->sum('ects'));
        foreach (range(1, 8) as $semester) {
            $this->assertSame(30.0, (float) $result['curriculum']->courses()->where('category', 'plan')->where('semester', $semester)->sum('ects'));
        }
        $math = $result['curriculum']->courses()->where('name', 'Matematik I')->firstOrFail();
        $this->assertSame('MAT 101', $math->code);
        $this->assertSame(6.0, $math->ects);
        $this->assertStringContainsString('Limit', $math->content);
        $this->assertTrue($result['curriculum']->courses()->where('category', 'elective_pool')->where('name', 'Hidrometalurji')->exists());
    }

    public function test_obs_mufredati_yuvalar_ve_havuzlarla_eslenir(): void
    {
        $rows = json_decode((string) file_get_contents(base_path('tests/Fixtures/obs-mufredat-1768-131.json')), true);
        $courses = collect(app(ObsImporter::class)->mapCourses($rows));
        $plan = $courses->where('category', 'plan');

        $this->assertSame(240.0, (float) $plan->sum('ects'));
        foreach (range(1, 8) as $semester) {
            $this->assertSame(30.0, (float) $plan->where('semester', $semester)->sum('ects'), "Yarıyıl {$semester}");
        }
        $physics = $courses->firstWhere('code', 'FFZ101');
        $this->assertSame('Fizik I', $physics['name']);
        $this->assertSame(3.0, $physics['credit']);
        $this->assertSame(4.0, $physics['ects']);
        $this->assertSame('alternative', $courses->firstWhere('code', 'MMT444')['category']);
        $this->assertSame('elective_pool', $courses->firstWhere('name', 'Hidrometalurji')['category']);
        $this->assertNull($courses->firstWhere('name', 'Hidrometalurji')['root_code']);
        $this->assertSame('faculty_elective', $courses->firstWhere('code', 'MF-TEK')['category']);
        $this->assertNull($courses->firstWhere('code', 'PF001'), 'Pedagojik formasyon dersleri alınmaz');
        $this->assertTrue($plan->contains(fn ($c) => $c['is_placeholder'] && $c['elective_group'] === 'MMT1-351' && $c['semester'] === 5));
    }

    public function test_obs_ders_icerigi_saklanir(): void
    {
        $info = json_decode((string) file_get_contents(base_path('tests/Fixtures/obs-ders-bilgi.json')), true);
        $stored = app(ObsImporter::class)->storeInfo(65616, $info, '2026 - 2027 Akademik Yılı Güz MMT303 Kimyasal Metalurji');

        $this->assertSame('MMT303', $stored->code);
        $this->assertNotEmpty($stored->weekly_topics);
        $this->assertNotEmpty($stored->outcomes);
        $this->assertStringContainsString('Metalurji', $stored->full_text);
    }

    public function test_icerik_benzerligi_plan_eslestirmesinde_kullanilir(): void
    {
        $program = Program::query()->create(['department' => 'Test', 'name' => 'Test', 'slug' => 'test']);
        $old = Curriculum::query()->create(['program_id' => $program->id, 'title' => 'Eski', 'is_active' => false]);
        $new = Curriculum::query()->create(['program_id' => $program->id, 'title' => 'Yeni', 'is_active' => true]);
        $content = 'Kristal yapılar, kafes kusurları, difüzyon mekanizmaları, faz diyagramları, demir karbon diyagramı, mekanik özellikler, çekme deneyi, sertlik ölçümü, korozyon, seramik malzemeler, polimer malzemeler, kompozit malzemeler';
        $this->course($old, 'ESK201', 'Malzeme Bilgisi', $content);
        $this->course($new, 'YNI301', 'Mühendislik Malzemeleri', $content.', nanomalzemeler');
        CourseInfo::query()->create(['obs_course_id' => 1, 'description' => 'x']);

        $this->assertGreaterThan(0.8, TextNormalizer::contentSimilarity($content, $content.', nanomalzemeler'));
        $result = app(PlanAdaptationService::class)->autoMap($old, $new);

        $this->assertSame(1, $result['suggested']);
    }

    private function course(Curriculum $curriculum, string $code, string $name, string $content): CurriculumCourse
    {
        return $curriculum->courses()->create([
            'code' => $code, 'root_code' => $code, 'name' => $name, 'normalized_name' => TextNormalizer::normalize($name),
            'type' => 'Z', 'credit' => 3, 'ects' => 5, 'semester' => 3, 'category' => 'plan', 'content' => $content,
        ]);
    }
}
