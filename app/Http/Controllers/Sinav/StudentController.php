<?php

namespace App\Http\Controllers\Sinav;

use App\Models\Sinav\Course;
use App\Models\Sinav\Enrollment;
use App\Models\Sinav\Group;
use App\Models\Sinav\Release;
use App\Models\Sinav\ReleaseFile;
use App\Models\Sinav\Setting;
use App\Models\Sinav\Student;
use App\Models\Sinav\Term;
use App\Services\Sinav\Exams;
use App\Services\Sinav\Files;
use App\Services\Sinav\Terms;
use App\Services\Sinav\Util;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Öğrenci tarafı. Giriş: öğrenci numarası + dersin (grubun) hocanın belirlediği PIN'i.
 * Öğrenci yalnızca PIN'ini girdiği gruplarının derslerini görür; başka dersi için o dersin
 * PIN'ini ayrıca ekler. Her istekte sunucuda denetlenir: dönem açık, kayıt var, oturum en fazla
 * 12 saatlik, grubun PIN'i o oturumdan beri değişmemiş, yayın onaylı ve yayın aralığında.
 */
class StudentController extends BaseController
{
    /** Numara yok / PIN yanlış / kayıt yok aynı mesajı verir: hangisinin yanlış olduğu anlaşılmasın. */
    const LOGIN_FAILED = 'Giriş yapılamadı. Öğrenci numaranızı ve dersinizin PIN\'ini kontrol edin. PIN\'i dersin öğretim elemanından alabilirsiniz.';

    const TOO_MANY = 'Çok fazla hatalı deneme yapıldı. Lütfen 15 dakika sonra tekrar deneyin.';

    /** @return array<string, string> */
    private function config(): array
    {
        return Setting::all_();
    }

    /**
     * Oturumdaki öğrenci ve PIN'iyle açtığı gruplar; geçersizse oturumu temizler ve nedenini yazar.
     *
     * @return array{0: Student, 1: Term, 2: array<int, int>}|null
     */
    private function current(?string &$reason = null): ?array
    {
        $session = session('sinav_student');
        if (! $session) {
            return null;
        }
        $term = Terms::activeTerm();
        $fresh = now()->getTimestamp() - (int) ($session['at'] ?? 0) < config('sinav.session_hours', 12) * 3600;
        $student = $term ? Student::query()->where('term_id', $term->id)->find($session['id'] ?? 0) : null;
        if (! $fresh) {
            $reason = 'expired';
        } elseif (! $term || ! $term->openForStudents() || (int) $term->id !== (int) ($session['term'] ?? 0) || ! $student) {
            $reason = 'ended';
        }

        $groupIds = [];
        if (! $reason) {
            // PIN'i değişmiş veya öğrencinin artık kayıtlı olmadığı gruplar düşer.
            $versions = (array) ($session['groups'] ?? []);
            $enrolled = Enrollment::query()->where('student_id', $student->id)->pluck('group_id')->map(fn ($id): int => (int) $id)->all();
            foreach (Group::query()->whereIn('id', array_keys($versions))->get() as $group) {
                if ($group->hasPin() && in_array((int) $group->id, $enrolled, true) && (int) $group->pin_version === (int) $versions[$group->id]) {
                    $groupIds[] = (int) $group->id;
                }
            }
            if ($groupIds === []) {
                $reason = 'pinChanged';
                // Oturumu düşüren istek dosya isteği de olabilir; uyarı giriş ekranında gösterilsin.
                session(['sinav_notice' => 'Dersinizin PIN\'i değiştirildi. Yeni PIN\'le yeniden giriş yapın.']);
            }
        }
        if ($reason) {
            session()->forget('sinav_student');

            return null;
        }

        return [$student, $term, $groupIds];
    }

    public function qr(Request $request, string $code): RedirectResponse
    {
        session(['sinav_qr' => strtoupper($code)]);

        return redirect()->route('sinav.home');
    }

    public function home(Request $request): View
    {
        $config = $this->config();
        $term = Terms::activeTerm();
        $reason = null;
        $current = $this->current($reason);
        if (! $term || ! $term->openForStudents()) {
            return view('sinav.student.closed', compact('config'));
        }
        if ($reason === 'ended') {
            return view('sinav.student.ended', compact('config'));
        }
        if (! $current) {
            $qr = session('sinav_qr');
            // Eski (yenisi üretilmiş) QR kodla gelindiyse bilgi verilir; giriş yine de mümkündür.
            $staleQr = $qr && $config['qr_code'] !== '' && $qr !== $config['qr_code'];
            $notice = match ($reason) {
                'expired' => 'Oturum süreniz doldu. Lütfen yeniden giriş yapın.',
                'pinChanged' => 'Dersinizin PIN\'i değiştirildi. Yeni PIN\'le yeniden giriş yapın.',
                default => session('sinav_notice'),
            };

            return view('sinav.student.login', compact('config', 'term', 'staleQr', 'notice'));
        }
        [$student, $term, $groupIds] = $current;

        $courses = [];
        $enrollments = Enrollment::query()->with('group')->where('student_id', $student->id)->get();
        foreach ($enrollments->whereIn('group_id', $groupIds) as $enrollment) {
            $course = Course::query()->with('docs')->find($enrollment->course_id);
            if (! $course || ! $enrollment->group) {
                continue;
            }
            $courses[] = [
                'course' => $course,
                'group' => $enrollment->group,
                'releases' => Release::query()->with('exam')->where('term_id', $term->id)->where('group_id', $enrollment->group_id)->orderBy('exam_starts_at')->get(),
                'docs' => $course->docs->filter(fn ($doc): bool => stripos($doc->url, 'https://') === 0),
            ];
        }
        usort($courses, fn (array $a, array $b): int => strcmp($a['course']->code, $b['course']->code));
        $lockedCount = $enrollments->whereNotIn('group_id', $groupIds)->count();

        return view('sinav.student.home', compact('config', 'term', 'student', 'courses', 'lockedCount'));
    }

    public function login(Request $request): RedirectResponse
    {
        $ipKey = 'sinav-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            return back()->with('error', self::TOO_MANY);
        }
        RateLimiter::hit($ipKey, 120);

        $no = preg_replace('/\s+/', '', (string) $request->input('student_no'));
        $pin = preg_replace('/\s+/', '', (string) $request->input('pin'));
        if (! preg_match('/^\d{6,12}$/', $no)) {
            return back()->with('error', 'Öğrenci numarası yalnızca rakamlardan oluşmalı (6-12 hane).');
        }
        if (! preg_match('/^\d{4,8}$/', $pin)) {
            return back()->withInput($request->only('student_no'))->with('error', 'PIN 4-8 rakamdan oluşur. Dersinizin öğretim elemanından alın.');
        }
        // Aynı numaraya PIN tahmini için ayrı sınır (IP değiştirilse bile).
        $numberKey = 'sinav-login-no:'.$no;
        if (RateLimiter::tooManyAttempts($numberKey, 8)) {
            return back()->with('error', self::TOO_MANY);
        }

        $term = Terms::activeTerm();
        $student = $term && $term->openForStudents() ? Student::query()->where('term_id', $term->id)->where('student_no', $no)->first() : null;
        $matched = $student ? $this->groupsMatchingPin($student, $pin) : collect();
        if ($matched->isEmpty()) {
            RateLimiter::hit($numberKey, 900);

            return back()->withInput($request->only('student_no'))->with('error', self::LOGIN_FAILED);
        }
        RateLimiter::clear($numberKey);

        // Aynı tarayıcıda yönetici oturumu açıksa ona dokunulmaz; yalnızca kimlik yenilenir.
        $request->session()->regenerate();
        session(['sinav_student' => [
            'id' => $student->id,
            'term' => $term->id,
            'at' => now()->getTimestamp(),
            'groups' => $matched->mapWithKeys(fn (Group $group): array => [$group->id => (int) $group->pin_version])->all(),
        ]]);
        session()->forget('sinav_notice');

        return redirect()->route('sinav.home');
    }

    /** Oturum açıkken başka bir dersin PIN'iyle o dersi de görünür yapar. */
    public function addPin(Request $request): RedirectResponse
    {
        $current = $this->current();
        if (! $current) {
            return redirect()->route('sinav.home');
        }
        [$student] = $current;
        $numberKey = 'sinav-login-no:'.$student->student_no;
        if (RateLimiter::tooManyAttempts($numberKey, 8)) {
            return back()->with('error', self::TOO_MANY);
        }
        $pin = preg_replace('/\s+/', '', (string) $request->input('pin'));
        $matched = preg_match('/^\d{4,8}$/', $pin) ? $this->groupsMatchingPin($student, $pin) : collect();
        if ($matched->isEmpty()) {
            RateLimiter::hit($numberKey, 900);

            return back()->with('error', 'Bu PIN, kayıtlı olduğunuz derslerden hiçbirine ait değil.');
        }
        $session = session('sinav_student');
        foreach ($matched as $group) {
            $session['groups'][$group->id] = (int) $group->pin_version;
        }
        session(['sinav_student' => $session]);

        return redirect()->route('sinav.home')->with('success', 'Ders eklendi.');
    }

    /**
     * Öğrencinin kayıtlı olduğu ve PIN'i girilenle aynı olan gruplar.
     *
     * @return Collection<int, Group>
     */
    private function groupsMatchingPin(Student $student, string $pin): Collection
    {
        $groupIds = Enrollment::query()->where('student_id', $student->id)->pluck('group_id');

        return Group::query()->whereIn('id', $groupIds)->whereNotNull('pin_encrypted')->get()
            ->filter(fn (Group $group): bool => $group->pinMatches($pin))->values();
    }

    public function logout(Request $request): RedirectResponse
    {
        session()->forget('sinav_student');
        // Ortak cihazda bir sonraki kişi eski oturumun form anahtarını kullanamasın.
        $request->session()->regenerateToken();
        $request->session()->migrate(true);

        return redirect()->route('sinav.home');
    }

    public function exam(Request $request, int $releaseId): View|RedirectResponse
    {
        $config = $this->config();
        $current = $this->current();
        if (! $current) {
            return redirect()->route('sinav.home');
        }
        [$student, $term, $groupIds] = $current;
        $release = Release::query()->with(['exam', 'course'])->where('term_id', $term->id)->find($releaseId);
        if (! $release || ! in_array((int) $release->group_id, $groupIds, true)) {
            return redirect()->route('sinav.home');
        }
        $readable = Exams::studentCanRead($release, $student, $term, $groupIds);
        $files = $readable ? $release->files()->get()->sortBy(fn ($file): int => $file->kind === 'key' ? 0 : 1)->values() : collect();
        // Filigran: ekran görüntüsü paylaşılırsa kime ait olduğu görünür.
        $watermark = $student->student_no.' · '.Util::fmt(now());

        return view('sinav.student.exam', compact('config', 'term', 'student', 'release', 'readable', 'files', 'watermark'));
    }

    /** Yayın kopyasını sayfaya gömülü verir; her istekte tüm koşullar yeniden denetlenir. */
    public function file(Request $request, int $fileId): Response
    {
        $current = $this->current();
        abort_unless($current !== null, 403);
        [$student, $term, $groupIds] = $current;
        $file = ReleaseFile::query()->find($fileId);
        $release = $file ? Release::query()->find($file->release_id) : null;
        abort_unless($file && $release && Exams::studentCanRead($release, $student, $term, $groupIds), 403);

        return Files::respond($file->path, $file->mime, $file->name, false);
    }
}
