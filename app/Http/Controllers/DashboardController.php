<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CurriculumCourse;
use App\Models\Equivalence;
use App\Models\ExemptionApplication;
use App\Models\ExemptionFile;
use App\Models\Program;
use App\Models\Student;
use Illuminate\Http\Request;
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
            'pending' => ExemptionApplication::query()->with('student.program')->withCount(['transcript', 'documents'])
                ->where('status', 'gonderildi')->orderBy('submitted_at')->take(15)->get(),
            'objections' => ExemptionApplication::query()->with('student')->whereNotNull('objected_at')->whereNull('objection_responded_at')->orderBy('objected_at')->get(),
            'recentFiles' => ExemptionFile::query()->with(['student', 'curriculum.program'])->latest('updated_at')->take(8)->get(),
        ]);
    }

    public function rules(): View
    {
        return view('rules');
    }

    /**
     * İşlem geçmişi: kim, ne zaman, ne yaptı (komisyon başkanı).
     */
    public function audit(Request $request): View
    {
        $action = $request->query('islem');
        $logs = AuditLog::query()->with(['user', 'student'])
            ->when(array_key_exists((string) $action, AuditLog::ACTIONS), fn ($q) => $q->where('action', $action))
            ->latest('id')->paginate(50)->withQueryString();

        return view('audit', ['logs' => $logs, 'action' => $action]);
    }
}
