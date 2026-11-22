<?php

namespace App\Http\Controllers;

use App\Models\CurriculumCourse;
use App\Models\Equivalence;
use App\Models\Program;
use App\Services\TextNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Eşdeğerlik havuzu: kesinleşen ders eşleştirmeleri. Kurum listesi Türkiye'deki tüm
 * üniversitelerdir (config/universiteler.php); varsayılan Atatürk Üniversitesi.
 */
class EquivalenceController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $programId = $request->query('program');
        $institution = (string) $request->query('kurum', config('universiteler.default'));
        $equivalences = Equivalence::query()->with('program')
            ->when($institution !== '', fn ($q) => $q->where('normalized_institution', TextNormalizer::normalize($institution)))
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('ext_name', 'like', "%{$search}%")
                ->orWhere('target_name', 'like', "%{$search}%")->orWhere('institution', 'like', "%{$search}%")))
            ->orderBy('institution')->orderBy('ext_name')->paginate(50)->withQueryString();
        $programs = Program::sortedTurkish(Program::query()->with(['activeCurriculum.courses' => fn ($q) => $q->where('is_placeholder', false)->orderBy('semester')->orderBy('name')])->get());

        return view('equivalences.index', [
            'equivalences' => $equivalences,
            'programs' => $programs,
            'universities' => array_keys(config('universiteler.list')),
            'institution' => $institution,
            'search' => $search,
            'programId' => $programId,
        ]);
    }

    /** Havuza elle kayıt ekler (ör. önceki yönetim kurulu kararları). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'institution' => ['required', Rule::in(array_keys(config('universiteler.list')))],
            'ext_code' => ['nullable', 'string', 'max:32'],
            'ext_name' => ['required', 'string', 'max:255'],
            'ext_ects' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'target_course_id' => ['required', Rule::exists('curriculum_courses', 'id')],
            'decision_ref' => ['nullable', 'string', 'max:255'],
        ]);
        $target = CurriculumCourse::query()->with('curriculum')->findOrFail($data['target_course_id']);
        Equivalence::query()->create([
            'institution' => $data['institution'],
            'normalized_institution' => TextNormalizer::normalize($data['institution']),
            'ext_code' => ($data['ext_code'] ?? null) ?: null,
            'ext_name' => trim($data['ext_name']),
            'ext_normalized_name' => TextNormalizer::normalize($data['ext_name']),
            'ext_ects' => $data['ext_ects'] ?? null,
            'program_id' => $target->curriculum->program_id,
            'target_code' => $target->code,
            'target_name' => $target->name,
            'target_normalized_name' => $target->normalized_name,
            'times_used' => 0,
            'decision_ref' => ($data['decision_ref'] ?? null) ?: null,
        ]);

        return redirect()->route('equivalences.index', ['kurum' => $data['institution']])->with('status', 'Havuza eklendi: '.$data['ext_name'].' → '.$target->label);
    }

    public function destroy(Equivalence $equivalence): RedirectResponse
    {
        $equivalence->delete();

        return back()->with('status', 'Havuz kaydı silindi.');
    }
}
