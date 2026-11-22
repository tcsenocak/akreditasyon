<?php

namespace App\Http\Controllers\Sinav;

use App\Models\Sinav\AuditLog;
use App\Models\Sinav\Enrollment;
use App\Models\Sinav\Exam;
use App\Models\Sinav\Group;
use App\Models\Sinav\Release;
use App\Models\Sinav\Setting;
use App\Models\Sinav\Student;
use App\Services\Sinav\Access;
use App\Services\Sinav\Files;
use App\Services\Sinav\Terms;
use App\Services\Sinav\Util;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AdminController extends BaseController
{
    public function dashboard()
    {
        $term = Terms::current();
        $allowed = Access::courseIds();
        $releases = $this->scoped(Release::query()->where('term_id', $term->id), $allowed)->get();
        $count = ['draft' => 0, 'scheduled' => 0, 'live' => 0, 'halted' => 0, 'expired' => 0];
        foreach ($releases as $r) {
            $count[$r->status()]++;
        }
        $stats = [
            'students' => $allowed === null
                ? Student::query()->where('term_id', $term->id)->count()
                : Enrollment::query()->whereIn('course_id', $allowed)->distinct()->count('student_id'),
            'courses' => $this->scoped($term->courses()->getQuery(), $allowed, 'id')->count(),
            'exams' => $this->scoped(Exam::query()->where('term_id', $term->id), $allowed)->count(),
            'releases' => $count,
        ];
        $groupsWithoutPin = $this->scoped(Group::query()->with('course')->where('term_id', $term->id), $allowed)
            ->whereNull('pin_encrypted')->orderBy('name')->get();
        $upcoming = $this->scoped(Release::query()->with(['exam', 'group', 'course'])->where('term_id', $term->id), $allowed)
            ->where('publish_at', '>', now())->orderBy('publish_at')->limit(8)->get();

        return view('sinav.admin.dashboard', compact('term', 'stats', 'upcoming', 'groupsWithoutPin'));
    }

    /**
     * Sorguyu kullanıcının yönetebildiği derslerle sınırlar (komisyon için dokunmaz).
     *
     * @param  array<int, int>|null  $allowed
     */
    private function scoped(Builder $query, ?array $allowed, string $column = 'course_id'): Builder
    {
        return $allowed === null ? $query : $query->whereIn($column, $allowed);
    }

    public function password(): View
    {
        return view('sinav.admin.password', ['forced' => (bool) (Access::user()->must_change_password ?? false)]);
    }

    public function passwordUpdate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8), 'different:current_password'],
        ]);
        $request->user()->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false])->save();
        $request->session()->regenerate();

        return redirect()->route('sinav.admin.dashboard')->with('success', 'Parolanız değiştirildi.');
    }

    /** Tarayıcıdan gelen dosya parçası. */
    public function chunk(Request $request)
    {
        return $this->attemptJson(function () use ($request) {
            Files::storeChunk($request);

            return 'ok';
        });
    }

    public function qr(Request $request)
    {
        $code = Setting::get('qr_code');
        if ($code === '') {
            $code = self::makeCode();
            Setting::put('qr_code', $code);
        }
        $url = route('sinav.qr', ['code' => $code]);

        return view('sinav.admin.qr', compact('code', 'url'));
    }

    /** Yeni kod üretir; eski basılı QR "artık geçerli değil" uyarısı gösterir (giriş yine mümkündür). */
    public function qrRegenerate()
    {
        Setting::put('qr_code', self::makeCode());
        Util::log('qr.regenerate', 'settings', 'qr_code');

        return redirect()->route('sinav.admin.qr')->with('success', 'Yeni QR kod üretildi. Eski basılı QR kodları artık "geçerli değil" uyarısı gösterecek.');
    }

    public static function makeCode($length = 8)
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    public function audit()
    {
        $logs = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        return view('sinav.admin.audit', compact('logs'));
    }

    public function settings()
    {
        return view('sinav.admin.settings', ['settings' => Setting::all_(), 'uploadMax' => ini_get('upload_max_filesize'), 'postMax' => ini_get('post_max_size')]);
    }

    public function settingsSave(Request $request)
    {
        Setting::put('institution_name', mb_substr(trim((string) $request->input('institution_name')), 0, 200));
        $title = trim((string) $request->input('site_title'));
        Setting::put('site_title', mb_substr($title !== '' ? $title : Setting::DEFAULTS['site_title'], 0, 200));
        Util::log('settings.update', 'settings', 'site');

        return back()->with('success', 'Ayarlar kaydedildi.');
    }
}
