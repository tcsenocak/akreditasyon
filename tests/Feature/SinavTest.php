<?php

namespace Tests\Feature;

use App\Http\Controllers\Sinav\StudentController;
use App\Models\Sinav\AccFile;
use App\Models\Sinav\AccOutput;
use App\Models\Sinav\AccSection;
use App\Models\Sinav\Course;
use App\Models\Sinav\Exam;
use App\Models\Sinav\ExamFile;
use App\Models\Sinav\Group;
use App\Models\Sinav\Release;
use App\Models\Sinav\ReleaseFile;
use App\Models\Sinav\Setting;
use App\Models\Sinav\Student;
use App\Models\Sinav\Term;
use App\Models\User;
use App\Services\Sinav\Students;
use App\Services\Sinav\Terms;
use App\Services\Sinav\Util;
use App\Services\Sinav\Xlsx;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Sınav sisteminin uçtan uca testi. Veriler uydurmadır.
 * Çalıştırma: php artisan test --filter=SinavTest
 */
class SinavTest extends TestCase
{
    use RefreshDatabase;

    private $admin;

    private $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Paketin kendi davranışı sınanır: kendi düzeni + e-posta listesiyle yetki.
        config(['sinav.admin_emails' => ['admin@test.local'], 'sinav.host_layout' => '', 'sinav.admin_gate' => '']);
        $this->admin = User::query()->create(['name' => 'Y', 'email' => 'admin@test.local', 'password' => bcrypt('x'.mt_rand())]);
        // Sabit "şimdi": 15.11.2026 12:00 Türkiye saati
        Carbon::setTestNow(Carbon::create(2026, 11, 15, 9, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin);
    }

    /** Ders + 2 grup (modül dönemsizdir; arka plandaki kayıt kendiliğinden açılır) */
    private function setupTerm()
    {
        $term = Terms::current();
        $course = Terms::createCourse($term, ['code' => 'muh104', 'name' => 'Malzeme Bilimi', 'instructor' => 'Dr. X', 'active' => 1]);
        $a = Terms::createGroup($course, 'A');
        $b = Terms::createGroup($course, 'B');
        $this->asAdmin();
        $this->post(route('sinav.admin.groups.pin', $a), ['pin' => self::PIN_A])->assertSessionHas('success');
        $this->post(route('sinav.admin.groups.pin', $b), ['pin' => self::PIN_B])->assertSessionHas('success');

        return [$term->fresh(), $course, $a->fresh(), $b->fresh()];
    }

    private function import(Term $term, Course $course, Group $group, $text)
    {
        $this->asAdmin()->post(route('sinav.admin.students.preview'), ['term_id' => $term->id, 'course_id' => $course->id, 'group_id' => $group->id, 'text' => $text])->assertOk();

        return $this->post(route('sinav.admin.students.apply'), ['term_id' => $term->id]);
    }

    private function upload($url, $bytes, array $fields)
    {
        $id = 'abcdef0123456789'.mt_rand(1000, 9999);
        $chunks = str_split($bytes, config('sinav.chunk_bytes'));
        foreach ($chunks as $i => $c) {
            $this->asAdmin()->post(route('sinav.admin.chunk'), ['upload_id' => $id, 'index' => $i, 'total' => count($chunks), 'chunk' => UploadedFile::fake()->createWithContent('parca', $c)])->assertJson(['ok' => true]);
        }

        return $this->post($url, ['upload_id' => $id] + $fields);
    }

    /** Sınav: bugün 13:00-14:00, yayın 14:00 (Türkiye) */
    private function createExam(Term $term, Course $course, array $groups, $publish = ['14', '00'])
    {
        $rows = [];
        foreach ($groups as $g) {
            $rows[$g->id] = ['on' => 1];
        }

        return $this->asAdmin()->post(route('sinav.admin.exams.store'), [
            'term_id' => $term->id, 'course_id' => $course->id, 'type' => 'vize', 'title' => 'Vize Sınavı',
            'common' => ['date' => '2026-11-15', 'start_h' => '13', 'start_m' => '00', 'end_h' => '14', 'end_m' => '00', 'publish_h' => $publish[0], 'publish_m' => $publish[1]],
            'groups' => $rows,
        ]);
    }

    /** A grubunun PIN'i (setupTerm'de belirlenir). */
    private const PIN_A = '482913';

    private const PIN_B = '573086';

    private function studentLogin($no, $pin = self::PIN_A)
    {
        return $this->post(route('sinav.login'), ['student_no' => $no, 'pin' => $pin]);
    }

    // ------------------------------------------------------------------

    public function test_yonetici_yetkisi()
    {
        $this->get(route('sinav.admin.dashboard'))->assertRedirect(route('login'));
        $other = User::query()->create(['name' => 'B', 'email' => 'baska@test.local', 'password' => bcrypt('y'.mt_rand())]);
        $this->actingAs($other)->get(route('sinav.admin.dashboard'))->assertStatus(403)->assertSee('yetkiniz yok', false);
        $this->asAdmin()->get(route('sinav.admin.dashboard'))->assertOk()->assertSee('Genel Bakış');
    }

    public function test_baska_uygulamanin_duzenine_gomulur_ve_gate_ile_yetki()
    {
        // Ana uygulamanın (ör. Ders Programı) düzenini taklit eden geçici bir şablon
        $dir = sys_get_temp_dir().'/sinav-host-'.getmypid();
        @mkdir($dir);
        file_put_contents($dir.'/anaduzen.blade.php', '<html><body><nav>ANA UYGULAMA MENÜSÜ</nav><main>@yield(\'icerik\')</main>@stack(\'x\')</body></html>');
        View::addLocation($dir);
        config(['sinav.host_layout' => 'anaduzen', 'sinav.host_section' => 'icerik']);
        [$term] = $this->setupTerm();
        foreach (['dashboard', 'exams', 'keys', 'acc'] as $r) {
            $html = $this->asAdmin()->get(route('sinav.admin.'.$r))->assertOk()->getContent();
            $this->assertStringContainsString('ANA UYGULAMA MENÜSÜ', $html);
            $this->assertStringContainsString('id="sinav-root"', $html);
            $this->assertStringContainsString('sn-embed', $html);
            $this->assertStringNotContainsString('id="sn-nav"', $html); // kendi kenar menüsü çizilmez
        }
        $this->assertStringContainsString('Yeni sınav', $this->get(route('sinav.admin.dashboard'))->getContent());
        // Gate ile yetki: e-posta listesinde olmayan kullanıcı, Gate izin verirse girer
        config(['sinav.admin_gate' => 'sinav-yonetimi']);
        Gate::define('sinav-yonetimi', function ($u) {
            return $u->email === 'baska@test.local';
        });
        $other = User::query()->create(['name' => 'B', 'email' => 'baska@test.local', 'password' => bcrypt('z'.mt_rand())]);
        $this->actingAs($other)->get(route('sinav.admin.dashboard'))->assertOk();
        $this->actingAs($this->admin)->get(route('sinav.admin.dashboard'))->assertStatus(403);
        @unlink($dir.'/anaduzen.blade.php');
    }

    public function test_kendi_duzeni_menu_ve_ust_cubuk()
    {
        [$term] = $this->setupTerm();
        $html = $this->asAdmin()->get(route('sinav.admin.exams'))->assertOk()->getContent();
        $this->assertStringContainsString('id="sn-nav"', $html);
        $this->assertMatchesRegularExpression('/<a href="[^"]*\/sinav\/yonetim\/sinavlar" class="active">/', $html);
        $this->assertStringContainsString('Merhaba, Y', $this->get(route('sinav.admin.dashboard'))->getContent());
    }

    public function test_tum_yonetici_sayfalari_acilir()
    {
        [$term, $course] = $this->setupTerm();
        foreach (['dashboard', 'courses', 'groups', 'students', 'exams', 'keys', 'publish', 'qr', 'acc', 'audit', 'settings'] as $r) {
            $this->asAdmin()->get(route('sinav.admin.'.$r))->assertOk();
        }
        $this->get(route('sinav.admin.students.add'))->assertOk();
        $this->get(route('sinav.admin.exams.create'))->assertOk()->assertSee('Öğrenci listesiyle')->assertSee('MUH104');
        $this->get(route('sinav.admin.exams.create', ['ders' => $course->id]))->assertOk()->assertSee('Sınava giren gruplar');
        $this->get(route('sinav.admin.courses', ['evrak' => $course->id]))->assertOk()->assertSee('Ders evrakı');
    }

    public function test_ders_kodu_kurallari()
    {
        [$term] = $this->setupTerm();
        $this->assertSame('fiz101', Util::slug('FİZ101'));
        $this->assertSame(Util::slug('fiz101'), Util::slug('FIZ101'));
        // Aynı ders kodu (Türkçe i/İ farkıyla) ikinci kez eklenemez
        $this->expectExceptionMessage('"MUH104" kodlu ders zaten var.');
        Terms::createCourse($term, ['code' => 'MUH104', 'name' => 'Tekrar']);
    }

    public function test_ders_evraki()
    {
        [$term, $course] = $this->setupTerm();
        $this->asAdmin()->post(route('sinav.admin.docs.store', $course), ['title' => 'İzlence', 'url' => 'http://x.com/a'])->assertSessionHas('error', 'Bağlantı https:// ile başlamalı.');
        $this->post(route('sinav.admin.docs.store', $course), ['title' => 'İzlence', 'url' => 'https://drive.google.com/file/d/abc/view'])->assertSessionHas('success');
        $this->get(route('sinav.admin.courses', ['evrak' => $course->id]))->assertSee('İzlence');
    }

    public function test_ogrenci_listesi_cozumleme_ve_aktarma()
    {
        [$term, $course, $a, $b] = $this->setupTerm();
        $text = "Sıra\tÖğrenci No\tAd Soyad\n1\t220101001\tAyşe YILMAZ\n2\t220101002\tMehmet Demir\n3\t220101001\tAyşe YILMAZ\n4\t12ab\tHatalı\n";
        $this->import($term, $course, $a, $text)->assertSessionHas('success', '2 yeni öğrenci eklendi, 2 ders üyeliği oluşturuldu.');
        $this->assertSame('Ayşe YILMAZ', Student::query()->where('student_no', '220101001')->value('name'));
        // Aynı öğrenciyi aynı dersin başka grubuna ekleme engellenir; adı güncellenebilir
        $this->asAdmin()->post(route('sinav.admin.students.preview'), ['term_id' => $term->id, 'course_id' => $course->id, 'group_id' => $b->id, 'text' => "220101001\tAyşe Yılmaz Kaya"])
            ->assertOk()->assertSee('Bu öğrenci aynı dersin başka bir grubuna kayıtlı.');
        // Satırda ders kodu + grup adı yazılırsa o kullanılır (Türkçe büyük/küçük harf farkı önemsiz)
        $lines = Students::parse('220101005;MUH104;b;Ali Veli', Course::all(), Group::all(), $course->id, $a->id);
        $this->assertEquals($b->id, $lines[0]['group_id']);
        $this->assertSame('Ali Veli', $lines[0]['name']);
        // Liste sayfası ve arama
        // Liste yalnız seçili dersin öğrencilerini, yalnız numarayla gösterir; arama numarayla yapılır
        $this->get(route('sinav.admin.students', ['ders' => $course->id, 'ara' => '101002']))->assertOk()->assertSee('220101002')->assertDontSee('220101001')->assertDontSee('Mehmet Demir');
        $this->assertDatabaseMissing('sinav_audit_logs', ['note' => 'Ayşe YILMAZ']);
    }

    public function test_excel_sablonu_ve_excel_ile_ekleme()
    {
        [$term, $course, $a] = $this->setupTerm();
        $res = $this->asAdmin()->get(route('sinav.admin.students.template'));
        $res->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $res->getContent());
        $this->assertSame([['Öğrenci No', 'Ad Soyad']], Xlsx::readFirstSheet($tmp));
        // Doldurulmuş Excel ile yükleme
        $bytes = Xlsx::write([['name' => 'Öğrenciler', 'rows' => [['Öğrenci No', 'Ad Soyad'], ['220101009', 'Zeynep Şahin'], ['0220101010', 'Çağrı Öz']]]]);
        $this->post(route('sinav.admin.students.preview'), ['term_id' => $term->id, 'course_id' => $course->id, 'group_id' => $a->id, 'file' => UploadedFile::fake()->createWithContent('liste.xlsx', $bytes)])->assertOk()->assertSee('2</strong> yeni öğrenci', false);
        $this->post(route('sinav.admin.students.apply'), ['term_id' => $term->id])->assertSessionHas('success');
        $this->assertTrue(Student::query()->where('student_no', '0220101010')->where('name', 'Çağrı Öz')->exists());
    }

    public function test_sinav_formu_kurallari()
    {
        [$term, $course, $a] = $this->setupTerm();
        $this->createExam($term, $course, [$a], ['13', '30'])->assertSessionHas('error', '"A" grubu: cevap anahtarı sınav bitmeden yayınlanamaz. Yayın zamanı, sınav bitişinden önce olamaz.');
        $this->asAdmin()->post(route('sinav.admin.exams.store'), ['term_id' => $term->id, 'course_id' => $course->id, 'type' => 'vize', 'title' => 'X', 'common' => [], 'groups' => []])->assertSessionHas('error', 'En az bir grup seçin.');
        // Yayın saati boş bırakılırsa sınav bitişi kullanılır; yayın bitişi +3 gün
        $this->createExam($term, $course, [$a], ['', ''])->assertSessionHas('success');
        $r = Release::query()->firstOrFail();
        $this->assertSame('15.11.2026 13:00', Util::fmt($r->exam_starts_at));
        $this->assertSame('15.11.2026 14:00', Util::fmt($r->publish_at));
        $this->assertSame('18.11.2026 14:00', Util::fmt($r->publish_until));
        $this->assertSame('2026-11-15 11:00:00', $r->getRawOriginal('publish_at')); // UTC saklanır
        $this->assertMatchesRegularExpression('/value="13"\s+selected/', $this->get(route('sinav.admin.exams.edit', $r->exam_id))->assertOk()->getContent());
    }

    public function test_yayin_akisi_ve_ogrenci_erisimi()
    {
        [$term, $course, $a, $b] = $this->setupTerm();
        $this->import($term, $course, $a, "220101001\tAyşe Yılmaz");
        $this->import($term, $course, $b, "220101002\tMehmet Demir");
        $this->createExam($term, $course, [$a, $b])->assertSessionHas('success');
        $exam = Exam::query()->firstOrFail();
        $ra = Release::query()->where('group_id', $a->id)->firstOrFail();
        $rb = Release::query()->where('group_id', $b->id)->firstOrFail();

        // Dosya yokken onay reddedilir
        $this->asAdmin()->post(route('sinav.admin.releases.approve', $ra))->assertSessionHas('error');
        // İçeriği PDF olmayan dosya reddedilir
        $this->upload(route('sinav.admin.files.upload', $exam), 'merhaba', ['name' => 'x.pdf', 'kind' => 'key'])->assertStatus(422);
        $this->upload(route('sinav.admin.files.upload', $exam), $this->pdf, ['name' => 'vize-cevap.pdf', 'kind' => 'key'])->assertJson(['ok' => true]);
        $this->assertSame(1, ExamFile::query()->count());
        $this->get(route('sinav.admin.releases.review', $ra))->assertOk()->assertSee('vize-cevap.pdf')->assertSee('Sınav henüz bitmedi');
        $this->post(route('sinav.admin.releases.approve', $ra), ['note' => ''])->assertSessionHas('success');
        $ra->refresh();
        $this->assertTrue($ra->approved);
        $this->assertEquals(1, $ra->version);
        $rf = ReleaseFile::query()->where('release_id', $ra->id)->firstOrFail();

        // Öğrenci girişi
        $this->post(route('sinav.logout'));
        $this->studentLogin('999999999')->assertSessionHas('error', StudentController::LOGIN_FAILED);
        $this->studentLogin('220101001')->assertRedirect(route('sinav.home'));
        $this->get(route('sinav.home'))->assertOk()->assertSee('Derslerim')->assertSee('15.11.2026 14:00 tarihinde yayınlanacak');
        // Yayın saatinden önce dosya verilmez
        $this->get(route('sinav.file', $rf->id))->assertStatus(403);
        // Yayın saati geldi
        Carbon::setTestNow(Carbon::create(2026, 11, 15, 11, 5, 0, 'UTC'));
        $this->get(route('sinav.home'))->assertSee('Cevap anahtarı yayınlandı');
        $this->get(route('sinav.exam', $ra->id))->assertOk()->assertSee('data-protected-file', false)->assertSee('data-watermark="220101001 · 15.11.2026 14:05"', false);
        $res = $this->get(route('sinav.file', $rf->id));
        $res->assertOk();
        $this->assertStringStartsWith('inline', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        // Başka grubun yayınını göremez (B grubu onaylanmadı ve öğrenci B'de değil)
        $this->get(route('sinav.exam', $rb->id))->assertRedirect(route('sinav.home'));
        // Acil durdurma
        $this->asAdmin()->post(route('sinav.admin.releases.halt', $ra))->assertSessionHas('success');
        $this->get(route('sinav.file', $rf->id))->assertStatus(403);
        // Yeniden onay: sürüm 2
        $this->asAdmin()->post(route('sinav.admin.releases.approve', $ra), ['note' => 'düzeltme'])->assertSessionHas('success');
        $this->assertEquals(2, $ra->fresh()->version);
        $this->get(route('sinav.file', ReleaseFile::query()->where('release_id', $ra->id)->value('id')))->assertOk();
        // Dosya taslakta silinirse öğrenci yeniden onaya kadar eski kopyayı görür; "onaylanmamış değişiklik" uyarısı çıkar
        $this->post(route('sinav.admin.files.destroy', ExamFile::query()->first()))->assertSessionHas('success');
        $this->get(route('sinav.admin.publish'))->assertSee('Taslakta onaylanmamış değişiklik var');
        $this->get(route('sinav.file', ReleaseFile::query()->where('release_id', $ra->id)->value('id')))->assertOk();
        // 3 gün sonra süre doldu
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 10, 59, 0, 'UTC'));
        $this->studentLogin('220101001'); // önceki oturum 12 saati aştı
        $this->get(route('sinav.file', ReleaseFile::query()->where('release_id', $ra->id)->value('id')))->assertOk();
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 11, 1, 0, 'UTC'));
        $this->get(route('sinav.file', ReleaseFile::query()->where('release_id', $ra->id)->value('id')))->assertStatus(403);
        $this->get(route('sinav.home'))->assertSee('görüntüleme süresi doldu');
    }

    public function test_guvenlik_basliklari()
    {
        [$term, $course, $a] = $this->setupTerm();
        $this->import($term, $course, $a, "220101001\tAyşe");
        $this->createExam($term, $course, [$a]);
        $exam = Exam::query()->firstOrFail();
        $this->upload(route('sinav.admin.files.upload', $exam), $this->pdf, ['name' => 'v.pdf', 'kind' => 'key']);
        $release = Release::query()->firstOrFail();
        $this->post(route('sinav.admin.releases.approve', $release));
        // Yönetim sayfalarında CSP yok (başka uygulamanın düzenine gömülebilir), çerçeve yasağı var
        $admin = $this->get(route('sinav.admin.dashboard'));
        $this->assertNull($admin->headers->get('Content-Security-Policy'));
        $this->assertSame('DENY', $admin->headers->get('X-Frame-Options'));
        // Yönetici dosya önizlemesi aynı sitedeki sayfada çerçeve içinde açılabilir
        $preview = $this->get(route('sinav.admin.files.show', ExamFile::query()->firstOrFail()))->assertOk();
        $this->assertSame('SAMEORIGIN', $preview->headers->get('X-Frame-Options'));
        $this->assertSame("frame-ancestors 'self'", $preview->headers->get('Content-Security-Policy'));
        // Öğrenci sayfası: sıkı CSP; satır içi betik yalnızca nonce ile
        $this->post(route('sinav.logout'));
        $home = $this->get(route('sinav.home'));
        $csp = (string) $home->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-eval\'', str_replace('wasm-unsafe-eval', '', $csp));
        $this->assertStringContainsString('no-store', (string) $home->headers->get('Cache-Control'));
        // Öğrenci sayfalarında satır içi olay işleyicisi ve nonce'suz satır içi betik yok
        Carbon::setTestNow(Carbon::create(2026, 11, 15, 11, 5, 0, 'UTC'));
        $this->studentLogin('220101001');
        foreach ([route('sinav.home'), route('sinav.exam', $release->id)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/\son(click|contextmenu|load|error)=/i', $html);
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
        }
        // Dosya yanıtı: hiçbir şey çalıştıramayan sandbox politikası
        $file = $this->get(route('sinav.file', ReleaseFile::query()->value('id')))->assertOk();
        $this->assertSame("default-src 'none'; sandbox", $file->headers->get('Content-Security-Policy'));
        // Çıkışta oturum anahtarı yenilenir
        $token = session()->token();
        $this->post(route('sinav.logout'));
        $this->assertNotSame($token, session()->token());
    }

    public function test_oturum_12_saat_ve_qr()
    {
        [$term, $course, $a] = $this->setupTerm();
        $this->import($term, $course, $a, "220101001\tAyşe");
        $this->asAdmin()->get(route('sinav.admin.qr'))->assertOk();
        $code = Setting::get('qr_code');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $code);
        $this->post(route('sinav.admin.qr.regenerate'))->assertRedirect();
        $this->assertNotSame($code, Setting::get('qr_code'));
        $this->post(route('sinav.logout'));
        $this->get(route('sinav.qr', $code))->assertRedirect(route('sinav.home'));
        $this->get(route('sinav.home'))->assertSee('artık geçerli değil');
        $this->studentLogin('220101001');
        $this->get(route('sinav.home'))->assertSee('Derslerim');
        Carbon::setTestNow(Carbon::now()->addHours(13));
        $this->get(route('sinav.home'))->assertSee('Oturum süreniz doldu');
    }

    public function test_gruptan_cikarma_ve_silme()
    {
        [$term, $course, $a] = $this->setupTerm();
        $this->import($term, $course, $a, "220101001\n220101002\n220101003");
        $ids = Student::query()->orderBy('student_no')->pluck('id')->all();
        $this->asAdmin()->post(route('sinav.admin.students.remove'), ['term_id' => $term->id, 'group_id' => $a->id, 'ids' => [$ids[0]]])->assertSessionHas('success', '1 öğrenci gruptan çıkarıldı; 1 tanesinin başka dersi kalmadığı için kaydı silindi.');
        $this->post(route('sinav.admin.students.destroy'), ['term_id' => $term->id, 'ids' => [$ids[1]], 'confirm' => 'sil'])->assertSessionHas('success', '1 öğrenci silindi.');
        $this->assertSame(1, Student::query()->count());
    }

    public function test_ders_dosyasi()
    {
        [$term, $course, $a] = $this->setupTerm();
        $this->asAdmin()->get(route('sinav.admin.acc', ['ders' => $course->id]))->assertOk()->assertSee('Ders İzlencesi');
        $this->assertSame(7, AccSection::query()->count());
        $izlence = AccSection::query()->where('kind', 'izlence')->first();
        $this->upload(route('sinav.admin.acc.upload', $izlence), $this->pdf, ['name' => 'izlence.pdf', 'source' => 'upload'])->assertJson(['ok' => true]);
        $this->upload(route('sinav.admin.acc.upload', $izlence), $this->pdf, ['name' => 'ek.pdf', 'source' => 'upload'])->assertJson(['ok' => true]);
        $second = AccFile::query()->where('name', 'ek.pdf')->first();
        $this->post(route('sinav.admin.acc.file.move', $second), ['dir' => -1]);
        $this->assertSame(['ek.pdf', 'izlence.pdf'], AccFile::query()->orderBy('sort')->pluck('name')->all());
        $this->post(route('sinav.admin.acc.section.store', $course), ['title' => 'Ödevler'])->assertSessionHas('success');
        $odev = AccSection::query()->where('kind', 'custom')->first();
        $this->post(route('sinav.admin.acc.section.move', $odev), ['dir' => -1]);
        $this->assertEquals(7, $odev->fresh()->sort);
        $this->post(route('sinav.admin.acc.section.destroy', $izlence))->assertSessionHas('error', 'Sabit bölümler silinemez.');
        $this->post(route('sinav.admin.acc.section.destroy', $odev))->assertSessionHas('success');
        // Excel satırları
        $x = Xlsx::write([['name' => 'S', 'rows' => [['No', 'PÇ1'], ['220101001', '85']]]]);
        $id = 'xlsx0123456789abcdef';
        $this->post(route('sinav.admin.chunk'), ['upload_id' => $id, 'index' => 0, 'total' => 1, 'chunk' => UploadedFile::fake()->createWithContent('p', $x)]);
        $this->post(route('sinav.admin.acc.xlsxRows'), ['upload_id' => $id])->assertJson(['ok' => true, 'data' => ['rows' => [['No', 'PÇ1'], ['220101001', '85']]]]);
        // Sınavdan alma
        $this->createExam($term, $course, [$a]);
        $exam = Exam::query()->first();
        $this->upload(route('sinav.admin.files.upload', $exam), $this->pdf, ['name' => 'vize.pdf', 'kind' => 'key']);
        $vize = AccSection::query()->where('kind', 'vize')->first();
        $this->post(route('sinav.admin.acc.fromExam', $vize), ['exam_file_id' => ExamFile::query()->value('id')])->assertSessionHas('success');
        // Birleşik PDF'in saklanması
        $this->upload(route('sinav.admin.acc.output.store', $course), $this->pdf, ['name' => 'MUH104_Ders_Dosyasi.pdf', 'page_count' => 3])->assertJson(['ok' => true]);
        $o = AccOutput::query()->firstOrFail();
        $this->get(route('sinav.admin.acc.output', $o))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_donem_bitince_ogrenci_erisimi_kesilmez()
    {
        [$term, $course, $a] = $this->setupTerm();
        $this->import($term, $course, $a, '220101001');
        $this->post(route('sinav.logout'));
        Carbon::setTestNow(Carbon::create(2028, 2, 11, 9, 0, 0, 'UTC'));
        $this->studentLogin('220101001')->assertRedirect(route('sinav.home'));
        $this->get(route('sinav.home'))->assertSee('Derslerim');
        $this->assertSame(1, Term::query()->count());
    }
}
