<?php

namespace App\Http\Controllers;

use App\Models\CurriculumCourse;
use App\Models\Equivalence;
use App\Models\ExemptionFile;
use App\Models\Program;
use App\Models\Student;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard', [
            'stats' => [
                'programs' => Program::query()->count(),
                'courses' => CurriculumCourse::query()->where('is_placeholder', false)->count(),
                'students' => Student::query()->count(),
                'files' => ExemptionFile::query()->count(),
                'pool' => Equivalence::query()->count(),
            ],
            'pending' => Student::query()->with('program')->withCount(['transcript', 'documents'])
                ->where('application_status', 'gonderildi')->orderBy('submitted_at')->take(15)->get(),
            'recentFiles' => ExemptionFile::query()->with(['student', 'curriculum.program'])->latest('updated_at')->take(8)->get(),
        ]);
    }

    public function rules(): View
    {
        return view('rules');
    }
}
