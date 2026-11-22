<?php

namespace App\Http\Controllers;

use App\Models\GradeConversion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        return view('settings', ['conversions' => GradeConversion::query()->orderBy('source_grade')->get()]);
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
}
