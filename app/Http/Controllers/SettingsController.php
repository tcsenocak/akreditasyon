<?php

namespace App\Http\Controllers;

use App\Models\ApplicationPeriod;
use App\Models\AppSetting;
use App\Models\ExemptionApplication;
use App\Models\GradeConversion;
use App\Services\ApplicationWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        return view('settings', [
            'conversions' => GradeConversion::query()->orderBy('source_grade')->get(),
            'mode' => ApplicationWindow::mode(),
            'periods' => ApplicationPeriod::query()->orderByDesc('starts_on')->get(),
        ]);
    }

    public function storeGrade(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_grade' => ['required', 'string', 'max:16'],
            'letter' => ['required', Rule::in(array_keys(config('intibak.letters')))],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $data['source_grade'] = mb_strtoupper(trim($data['source_grade']), 'UTF-8');
        GradeConversion::query()->updateOrCreate(['source_grade' => $data['source_grade']], $data);

        return back()->with('status', 'Dönüşüm kaydedildi. Mevcut dökümler için öğrenci sayfasında "Notları yeniden çevir" kullanın.');
    }

    public function destroyGrade(GradeConversion $gradeConversion): RedirectResponse
    {
        $gradeConversion->delete();

        return back()->with('status', 'Dönüşüm silindi.');
    }

    /**
     * Başvuru alımı: sürekli açık, kapalı ya da yalnız takvimdeki tarihlerde.
     */
    public function updateWindow(Request $request): RedirectResponse
    {
        $data = $request->validate(['application_mode' => ['required', Rule::in(array_keys(ApplicationWindow::MODES))]]);
        AppSetting::put('application_mode', $data['application_mode']);

        return back()->with('status', 'Başvuru alımı: '.ApplicationWindow::MODES[$data['application_mode']].'.');
    }

    public function storePeriod(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(ExemptionApplication::TYPES))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);
        ApplicationPeriod::query()->create($data);

        return back()->with('status', 'Başvuru dönemi eklendi.');
    }

    public function destroyPeriod(ApplicationPeriod $period): RedirectResponse
    {
        $period->delete();

        return back()->with('status', 'Başvuru dönemi silindi.');
    }
}
