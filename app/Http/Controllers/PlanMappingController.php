<?php

namespace App\Http\Controllers;

use App\Models\CourseMapping;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Student;
use App\Services\PlanAdaptationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanMappingController extends Controller
{
    public function __construct(private PlanAdaptationService $service) {}

    public function index(): View
    {
        return view('mappings.index', [
            'programs' => Program::sortedTurkish(Program::query()->with(['curricula' => fn ($q) => $q->withCount('courses')->orderBy('start_year')])->get()),
        ]);
    }

    public function show(Curriculum $old, Curriculum $new): View
    {
        abort_if($old->id === $new->id, 422, 'Eski ve yeni plan aynı olamaz.');
        $old->load(['program', 'courses']);
        $new->load('courses');
        $mappings = CourseMapping::query()->with(['oldCourses', 'newCourses'])
            ->where('old_curriculum_id', $old->id)->where('new_curriculum_id', $new->id)->get();
        $mappedOld = $mappings->flatMap(fn ($m) => $m->oldCourses->pluck('id'));
        $mappedNew = $mappings->flatMap(fn ($m) => $m->newCourses->pluck('id'));

        return view('mappings.show', [
            'old' => $old,
            'new' => $new,
            'mappings' => $mappings->sortBy(fn ($m) => $m->oldCourses->min('semester')),
            'unmatchedOld' => $old->courses->where('is_placeholder', false)->whereNotIn('id', $mappedOld)->sortBy('semester'),
            'unmatchedNew' => $new->courses->where('is_placeholder', false)->whereNotIn('id', $mappedNew)->sortBy('semester'),
            'students' => Student::query()->where('program_id', $old->program_id)->orderBy('last_name')->get(),
        ]);
    }

    public function auto(Curriculum $old, Curriculum $new): RedirectResponse
    {
        $result = $this->service->autoMap($old, $new);

        return back()->with('status', "Sistem eşleştirmesi: {$result['locked']} kilitli (kök kod/ad), {$result['suggested']} benzer ad önerisi. Eşleşmeyen eski ders: {$result['unmatched']}.");
    }

    public function store(Request $request, Curriculum $old, Curriculum $new): RedirectResponse
    {
        $data = $request->validate([
            'change_type' => ['required', Rule::in(array_keys(CourseMapping::CHANGE_TYPES))],
            'old_ids' => ['required', 'array', 'min:1'],
            'old_ids.*' => [Rule::exists('curriculum_courses', 'id')->where('curriculum_id', $old->id)],
            'new_ids' => ['required', 'array', 'min:1'],
            'new_ids.*' => [Rule::exists('curriculum_courses', 'id')->where('curriculum_id', $new->id)],
            'decision_ref' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if ($data['change_type'] === 'birlesme' && count($data['old_ids']) < 2) {
            return back()->withErrors(['old_ids' => 'Birleştirme için en az iki eski ders seçin.']);
        }
        if ($data['change_type'] === 'bolunme' && count($data['new_ids']) < 2) {
            return back()->withErrors(['new_ids' => 'Bölünme için en az iki yeni ders seçin.']);
        }
        $this->service->createMapping(
            $old, $new,
            $old->courses()->whereIn('id', $data['old_ids'])->get()->all(),
            $new->courses()->whereIn('id', $data['new_ids'])->get()->all(),
            $data['change_type'], false, $data['note'] ?? null, $data['decision_ref'] ?? null,
        );

        return back()->with('status', 'Eşleştirme kaydedildi.');
    }

    public function destroy(CourseMapping $mapping): RedirectResponse
    {
        if ($mapping->locked) {
            return back()->withErrors(['mapping' => 'Kök kod/ad ile sistemce yapılan eşleştirme değiştirilemez (Usul ve Esaslar Md. 6/1-a).']);
        }
        $mapping->delete();

        return back()->with('status', 'Eşleştirme silindi.');
    }

    public function student(Request $request, Curriculum $old, Curriculum $new): View
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'semester' => ['nullable', 'integer', 'between:1,8'],
            'completed' => ['nullable', 'boolean'],
        ]);
        $student = Student::query()->with('transcript')->findOrFail($data['student_id']);
        $result = $this->service->applyToStudent($student, $old, $new, (int) ($data['semester'] ?? 1), $request->boolean('completed'));

        return view('mappings.student', compact('student', 'old', 'new', 'result'));
    }
}
