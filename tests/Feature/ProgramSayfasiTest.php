<?php

namespace Tests\Feature;

use App\Models\CourseInfo;
use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use App\Models\Program;
use App\Models\User;
use App\Services\CurriculumTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Program sayfasının OBS Ders Bilgi Paketi düzeni: giriş yılı seçimi, yarıyıl tabloları, ders detayı.
 * Veriler uydurmadır.
 */
class ProgramSayfasiTest extends TestCase
{
    use RefreshDatabase;

    private Program $program;

    private Curriculum $newer;

    private Curriculum $older;

    protected function setUp(): void
    {
        parent::setUp();
        $this->program = Program::query()->create(['department' => 'Metalurji ve Malzeme Mühendisliği', 'name' => 'Metalurji ve Malzeme Mühendisliği', 'language' => 'TR', 'code_prefix' => 'MMT', 'slug' => 'mmt', 'obs_program_id' => 1768]);
        $this->older = $this->curriculum('2025-2026', false);
        $this->newer = $this->curriculum('2026-2027', true);
    }

    private function curriculum(string $year, bool $active): Curriculum
    {
        $c = Curriculum::query()->create(['program_id' => $this->program->id, 'title' => $year.' girişli müfredat (OBS)', 'start_year' => $year, 'source' => 'OBS Ders Bilgi Paketi', 'is_active' => $active, 'total_ects' => 240]);
        $row = fn (array $a) => CurriculumCourse::query()->create($a + ['curriculum_id' => $c->id, 'normalized_name' => mb_strtolower($a['name']), 'semester' => 5, 'credit' => 2, 'theory' => 2, 'practice' => 0, 'lab' => 0]);
        $row(['code' => 'MMT301', 'name' => 'Fiziksel Metalurji', 'type' => 'Z', 'category' => 'plan', 'ects' => 5, 'obs_course_id' => 65615]);
        $row(['code' => 'MMT1-351', 'name' => 'Mineraloji', 'type' => 'S', 'category' => 'elective_pool', 'elective_group' => 'MMT1-351', 'ects' => 3]);
        $row(['code' => 'MMT1-351', 'name' => 'Hidrometalurji', 'type' => 'S', 'category' => 'elective_pool', 'elective_group' => 'MMT1-351', 'ects' => 3]);
        $row(['code' => null, 'name' => 'Bölüm Seçmeli (MMT1-351)', 'type' => 'S', 'category' => 'plan', 'elective_group' => 'MMT1-351', 'is_placeholder' => true, 'ects' => 3, 'credit' => 0]);
        $row(['code' => null, 'name' => 'Üniversite Seçmeli', 'type' => 'ÜS', 'category' => 'plan', 'elective_group' => 'USD', 'is_placeholder' => true, 'ects' => 3, 'credit' => 0]);

        return $c;
    }

    public function test_yariyil_tablosu_obs_duzeninde(): void
    {
        $table = CurriculumTable::build($this->newer);
        $rows = $table['semesters'][5]['rows'];

        // Seçenekleri listelenen yuva gösterilmez; seçeneksiz yuva (Üniversite Seçmeli) görünür. Önce zorunlular.
        $this->assertSame(['Fiziksel Metalurji', 'Hidrometalurji', 'Mineraloji', 'Üniversite Seçmeli'], $rows->pluck('name')->all());
        $this->assertSame(5.0, $table['semesters'][5]['required']);
        $this->assertSame(9.0, $table['semesters'][5]['elective']);
        $this->assertSame(11.0, $table['semesters'][5]['planned']);
    }

    public function test_program_sayfasi_giris_yili_secilir_ve_ders_detayi_acilir(): void
    {
        CourseInfo::query()->create(['obs_course_id' => 65615, 'objective' => 'Metallerin iç yapısını öğretmek.', 'weekly_topics' => ['Kristal yapılar', 'Faz diyagramları'], 'offering_label' => '2025-2026 Güz']);
        $this->actingAs(User::factory()->create());

        $this->get(route('programs.index'))->assertOk()->assertSee(route('programs.show', $this->program), false);
        $this->get(route('programs.show', $this->program))->assertOk()
            ->assertSee('Müfredat Dersleri ve AKTS Kredileri')->assertSee('2026-2027 Akademik Yılı')
            ->assertSee('5. Yarıyıl')->assertSee('Toplam Zorunlu')->assertDontSee('Bölüm Seçmeli (MMT1-351)');
        $this->get(route('programs.show', ['program' => $this->program, 'plan' => $this->older->id]))->assertOk()
            ->assertSee('value="'.$this->older->id.'" selected', false);
        $this->get(route('programs.show', ['program' => $this->program, 'sekme' => 'birim']))->assertOk()->assertSee('Öğretim planları')->assertSee('1768');

        $course = $this->newer->courses()->where('code', 'MMT301')->firstOrFail();
        $this->get(route('curricula.course', [$this->newer, $course]))->assertOk()
            ->assertSee('MMT301 — Fiziksel Metalurji')->assertSee('Metallerin iç yapısını öğretmek.')->assertSee('Faz diyagramları')
            ->assertSee('dersDetay/65615/1768', false)->assertSee('2025-2026');
        $this->get(route('curricula.course', [$this->older, $course]))->assertNotFound();

        // Ders İçerikleri sekmesi ve önceki müfredatlara geçiş
        $this->get(route('programs.show', ['program' => $this->program, 'sekme' => 'icerik']))->assertOk()
            ->assertSee('Ders İçerikleri')->assertSee('Kristal yapılar')->assertSee('içerik yok')
            ->assertSee(route('programs.show', ['program' => $this->program, 'plan' => $this->older->id, 'sekme' => 'icerik']));
    }

    public function test_plan_intibaki_yeni_plani_aktif_plandan_alir(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('mappings.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Güncel plan: <b>2026-2027 girişli müfredat (OBS)</b>', $html);
        $this->assertStringNotContainsString('name="new"', $html);
        $this->assertStringContainsString('confirm(', $html);
        $this->assertStringContainsString('/'.$this->newer->id."'", $html);
    }

    public function test_esdegerlik_havuzu_tum_universiteler_varsayilan_ataturk(): void
    {
        $this->actingAs(User::factory()->create());
        $page = $this->get(route('equivalences.index'))->assertOk();
        $page->assertSee('<option value="Atatürk Üniversitesi" selected>', false)->assertSee('Zonguldak Bülent Ecevit Üniversitesi');
        $this->assertGreaterThan(200, count(config('universiteler.list')));

        $target = $this->newer->courses()->where('code', 'MMT301')->firstOrFail();
        $this->post(route('equivalences.store'), ['institution' => 'Ege Üniversitesi', 'ext_name' => 'Fiziksel Metalurji', 'ext_ects' => 5, 'target_course_id' => $target->id])
            ->assertRedirect(route('equivalences.index', ['kurum' => 'Ege Üniversitesi']));
        $this->post(route('equivalences.store'), ['institution' => 'Uydurma Üniversitesi', 'ext_name' => 'X', 'target_course_id' => $target->id])->assertSessionHasErrors('institution');
        $this->get(route('equivalences.index', ['kurum' => 'Ege Üniversitesi']))->assertSee('<b>MMT301 Fiziksel Metalurji</b>', false);
        $this->get(route('equivalences.index'))->assertDontSee('<b>MMT301 Fiziksel Metalurji</b>', false);
    }
}
