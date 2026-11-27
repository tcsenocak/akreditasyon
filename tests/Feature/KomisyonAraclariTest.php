<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\ExemptionFile;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Notifications\BasvuruBildirimi;
use App\Services\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KomisyonAraclariTest extends TestCase
{
    use RefreshDatabase;

    private Curriculum $curriculum;

    private User $chair;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $program = Program::query()->create(['department' => 'Makine', 'name' => 'Makine Mühendisliği', 'slug' => 'mak']);
        $this->curriculum = Curriculum::query()->create(['program_id' => $program->id, 'title' => '2026', 'is_active' => true]);
        foreach ([['MAT101', 'Matematik I', 4, 6, 1], ['FIZ101', 'Fizik I', 4, 6, 1]] as [$code, $name, $credit, $ects, $semester]) {
            $this->curriculum->courses()->create([
                'code' => $code, 'root_code' => $code, 'name' => $name, 'normalized_name' => TextNormalizer::normalize($name),
                'type' => 'Z', 'credit' => $credit, 'ects' => $ects, 'semester' => $semester, 'category' => 'plan',
            ]);
        }
        $this->chair = User::factory()->create(['name' => 'Başkan']);
        $this->chair->forceFill(['role' => 'admin', 'title' => 'Prof. Dr.'])->save();
        $this->member = User::factory()->create(['name' => 'Üye']);
        $this->member->forceFill(['role' => 'commission', 'title' => 'Arş. Gör.'])->save();
    }

    public function test_bildirim_gerekce_atama_ve_islem_gecmisi(): void
    {
        [$user, $student] = $this->submitted();
        Notification::assertSentTo([$this->chair, $this->member], BasvuruBildirimi::class, fn ($n) => $n->subject === 'Yeni başvuru');

        $this->actingAs($this->chair)->put(route('students.application', $student), ['application_status' => 'taslak', 'note' => 'Transkript okunmuyor, yeniden yükleyin.']);
        Notification::assertSentTo($user, BasvuruBildirimi::class, fn ($n) => str_contains(implode(' ', $n->lines), 'Transkript okunmuyor'));
        $this->actingAs($user->fresh())->get(route('portal.courses'))->assertSee('Transkript okunmuyor, yeniden yükleyin.');

        $this->actingAs($user->fresh())->post(route('portal.submit'))->assertRedirect(route('portal.home'));
        $this->actingAs($this->member)->post(route('files.store', $student));
        $file = ExemptionFile::query()->firstOrFail();
        $this->actingAs($this->chair)->put(route('files.assign', $file), ['assigned_to' => $this->member->id])->assertRedirect();
        $this->assertSame($this->member->id, $file->fresh()->assigned_to);
        Notification::assertSentTo($this->member, BasvuruBildirimi::class, fn ($n) => $n->subject === 'Size dosya atandı');
        $this->actingAs($this->member)->get(route('files.index', ['suz' => 'bana']))->assertSee($student->full_name);

        $this->actingAs($this->chair)->get(route('audit.index'))->assertOk()->assertSee('Düzeltme için geri açıldı')->assertSee('Üye üzerine atandı');
        $this->actingAs($this->member)->get(route('audit.index'))->assertForbidden();
        $this->assertGreaterThanOrEqual(4, AuditLog::query()->where('student_id', $student->id)->count());
    }

    public function test_dilekce_ve_toplu_karar_listesi(): void
    {
        [$user, $student] = $this->submitted();
        $this->actingAs($user->fresh())->get(route('portal.petition'))->assertOk()
            ->assertSee('DEKANLIĞINA')->assertSee('MAT1001 Matematik')->assertSee('MAT101 Matematik I');

        $this->actingAs($this->member)->post(route('files.store', $student));
        $file = ExemptionFile::query()->firstOrFail();
        $item = $file->items()->firstOrFail();
        $this->actingAs($this->member)->put(route('files.decisions', $file), ['items' => [$item->id => ['status' => 'kabul', 'letter' => 'BA']]]);

        $this->actingAs($this->member)->get(route('files.board', ['ids' => [$file->id]]))->assertOk()
            ->assertSee('KARAR LİSTESİ')->assertSee($student->full_name)->assertSee('BA');
    }

    public function test_itiraz_bes_is_gunu_icinde_bir_kez_yapilir(): void
    {
        [$user, $student] = $this->submitted();
        $this->actingAs($this->member)->post(route('files.store', $student));
        $file = ExemptionFile::query()->firstOrFail();
        $this->travelTo(now()->setDate(2026, 11, 20)->setTime(10, 0)); // Cuma
        $this->actingAs($this->chair)->put(route('files.update', $file), ['status' => 'karar', 'decision_date' => '2026-11-20']);
        Notification::assertSentTo($user, BasvuruBildirimi::class, fn ($n) => $n->subject === 'Muafiyet kararınız açıklandı');

        $application = $student->fresh()->currentApplication;
        $this->assertSame('2026-11-27', $application->objectionDeadline()->toDateString(), 'Hafta sonu sayılmaz');

        $this->travelTo(now()->setDate(2026, 11, 30));
        $this->actingAs($user->fresh())->post(route('portal.applications.objection', $application), ['objection_text' => 'Fizik I dersinin içeriği aynıdır, yeniden değerlendirilmesini istiyorum.'])->assertStatus(422);

        $this->travelTo(now()->setDate(2026, 11, 26));
        $this->actingAs($user->fresh())->post(route('portal.applications.objection', $application), ['objection_text' => 'kısa'])->assertSessionHasErrors('objection_text');
        $this->actingAs($user->fresh())->post(route('portal.applications.objection', $application), ['objection_text' => 'Fizik I dersinin içeriği aynıdır, yeniden değerlendirilmesini istiyorum.'])->assertRedirect();
        $this->actingAs($user->fresh())->post(route('portal.applications.objection', $application), ['objection_text' => 'İkinci itiraz denemesi yapılamaz, bu metin reddedilmeli.'])->assertStatus(422);

        // İtiraz komisyonun Başvurular listesine ve menü rozetine düşer; cevaplanınca listeden çıkar
        $this->actingAs($this->member)->get(route('applications.index'))->assertOk()->assertSee('İtirazı cevapla')->assertSee($student->full_name)->assertSee('nav-badge', false);
        $this->actingAs($this->member)->get(route('dashboard'))->assertSee('1 itiraz cevap bekliyor');
        $this->actingAs($this->member)->get(route('files.show', $file))->assertSee('Öğrenci bu karara itiraz etti');
        $this->actingAs($this->member)->put(route('applications.objection', $application), ['objection_response' => 'İtirazınız incelendi, karar değişmedi.'])->assertRedirect();
        $this->actingAs($this->member)->get(route('applications.index'))->assertDontSee('İtirazı cevapla')->assertDontSee('nav-badge', false);
        $this->actingAs($user->fresh())->get(route('portal.applications.show', $application))->assertSee('İtirazınız incelendi, karar değişmedi.');
        $this->actingAs($user->fresh())->get(route('portal.applications.index'))->assertSee('cevaplandı');
    }

    public function test_yaz_okulu_sonrasi_notlar_girilir(): void
    {
        [$user, $student] = $this->submitted('yaz_okulu');
        $this->actingAs($this->member)->post(route('files.store', $student));
        $file = ExemptionFile::query()->firstOrFail();
        $item = $file->items()->firstOrFail();
        $this->actingAs($this->member)->put(route('files.decisions', $file), ['items' => [$item->id => ['status' => 'kabul', 'letter' => 'CC']]]);
        $this->actingAs($this->chair)->put(route('files.update', $file), ['status' => 'karar']);

        $row = $student->transcript()->firstOrFail();
        $application = $student->fresh()->currentApplication;
        $this->actingAs($user->fresh())->get(route('portal.applications.show', $application))->assertSee('Yaz okulu notlarım');
        $this->actingAs($user->fresh())->post(route('portal.applications.summer-grades', $application), ['grades' => [$row->id => 'BA']])->assertSessionHasErrors('transkript');
        $this->actingAs($user->fresh())->post(route('portal.applications.summer-grades', $application), [
            'grades' => [$row->id => 'BA'], 'transkript' => UploadedFile::fake()->create('yaz.pdf', 30, 'application/pdf'),
        ])->assertRedirect();
        $this->assertSame('BA', $row->fresh()->letter);
        $this->assertNotNull($student->fresh()->currentApplication->summer_grades_at);
        Notification::assertSentTo($this->member, BasvuruBildirimi::class, fn ($n) => $n->subject === 'Yaz okulu notları');
    }

    /**
     * @return array{0: User, 1: Student}
     */
    private function submitted(string $type = 'normal'): array
    {
        $student = Student::query()->create([
            'first_name' => 'Deneme', 'last_name' => 'Öğrenci', 'admission_type' => 'yatay',
            'program_id' => $this->curriculum->program_id, 'curriculum_id' => $this->curriculum->id,
            'previous_institution' => 'Karadeniz Teknik Üniversitesi', 'previous_program' => 'Makine Mühendisliği',
        ]);
        $student->currentApplication->update(['type' => $type]);
        $user = new User(['name' => $student->full_name, 'email' => 'ogr@example.com', 'password' => 'x']);
        $user->forceFill(['role' => 'student', 'student_id' => $student->id, 'kvkk_accepted_at' => now()])->save();

        $target = $this->curriculum->courses()->where('code', 'MAT101')->value('id');
        $this->actingAs($user->fresh())->post(route('portal.courses.rows'), ['items' => [[
            'institution' => 'Karadeniz Teknik Üniversitesi', 'code' => 'MAT1001', 'name' => 'Matematik', 'credit' => 4, 'ects' => 6,
            'grade_raw' => $type === 'yaz_okulu' ? null : 'BB', 'target_id' => $target,
        ]]])->assertSessionHasNoErrors();
        foreach ($type === 'yaz_okulu' ? ['dilekce', 'icerik'] : ['dilekce', 'transkript'] as $doc) {
            $this->actingAs($user->fresh())->post(route('portal.documents.store'), ['type' => $doc, 'files' => [UploadedFile::fake()->create($doc.'.pdf', 20, 'application/pdf')]]);
        }
        $this->actingAs($user->fresh())->post(route('portal.submit'))->assertRedirect(route('portal.home'));

        return [$user, $student->fresh()];
    }
}
