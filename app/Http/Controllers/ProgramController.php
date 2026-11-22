<?php

namespace App\Http\Controllers;

use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use App\Models\Program;
use App\Services\CourseCatalogImporter;
use App\Services\CurriculumTable;
use App\Services\CurriculumImporter;
use App\Services\Obs\ObsImporter;
use App\Services\TextNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ProgramController extends Controller
{
    public function index(): View
    {
        return view('programs.index', [
            'programs' => Program::sortedTurkish(Program::query()->with(['curricula' => fn ($q) => $q->withCount('courses')->orderByDesc('start_year')->orderBy('title')])->get()),
        ]);
    }

    /**
     * Program sayfası (OBS Ders Bilgi Paketi düzeni): Birim Bilgileri ve giriş yılına göre
     * "Müfredat Dersleri ve AKTS Kredileri" sekmeleri.
     */
    public function show(Request $request, Program $program): View
    {
        $curricula = $program->curricula()->withCount('courses')->orderByDesc('start_year')->orderBy('title')->get();
        $curriculum = $curricula->firstWhere('id', (int) $request->query('plan'))
            ?? $curricula->firstWhere('is_active', true)
            ?? $curricula->first();
        $tab = in_array($request->query('sekme'), ['birim', 'icerik'], true) ? $request->query('sekme') : 'mufredat';
        $table = $curriculum ? CurriculumTable::build($curriculum) : ['semesters' => [], 'pools' => collect()];
        $contents = $curriculum && $tab === 'icerik'
            ? $curriculum->courses()->with('info')->where('is_placeholder', false)->get()
                ->sortBy(fn (CurriculumCourse $c): string => sprintf('%02d', CurriculumTable::semesterOf($c) ?? 99).TextNormalizer::ascii($c->name))->values()
            : collect();

        return view('programs.show', compact('program', 'curricula', 'curriculum', 'tab', 'table', 'contents'));
    }

    /** Eski bağlantılar: plan sayfası programın müfredat sekmesine yönlenir. */
    public function curriculum(Curriculum $curriculum): RedirectResponse
    {
        return redirect()->route('programs.show', ['program' => $curriculum->program_id, 'plan' => $curriculum->id]);
    }

    /** Ders detayı (OBS'deki "Ders Detayı" sayfasının karşılığı). */
    public function course(Curriculum $curriculum, CurriculumCourse $course): View
    {
        abort_unless((int) $course->curriculum_id === (int) $curriculum->id, 404);
        $curriculum->load('program');
        $course->load('info');
        $otherYears = $course->obs_course_id
            ? Curriculum::query()->where('program_id', $curriculum->program_id)->whereKeyNot($curriculum->id)
                ->whereHas('courses', fn ($q) => $q->where('obs_course_id', $course->obs_course_id))
                ->orderByDesc('start_year')->get(['id', 'title', 'start_year'])
            : collect();

        return view('programs.course', compact('curriculum', 'course', 'otherYears'));
    }

    public function import(Request $request, CurriculumImporter $importer, CourseCatalogImporter $catalogImporter): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['file', 'extensions:xlsx,xls,doc', 'max:10240'],
        ]);
        $messages = [];
        foreach ($request->file('files') as $upload) {
            try {
                $result = strtolower($upload->getClientOriginalExtension()) === 'doc'
                    ? $catalogImporter->import($upload->getRealPath(), $upload->getClientOriginalName())
                    : $importer->import($upload->getRealPath(), $upload->getClientOriginalName(), ! $request->boolean('passive'));
                $messages[] = "{$result['program']->full_name}: {$result['count']} ders aktarıldı".($result['warnings'] ? ' ('.implode(' ', $result['warnings']).')' : '');
            } catch (Throwable $e) {
                $messages[] = $upload->getClientOriginalName().': HATA — '.$e->getMessage();
            }
        }

        return back()->with('status', implode("\n", $messages));
    }

    public function download(CurriculumImporter $importer): RedirectResponse
    {
        try {
            $files = $importer->downloadFromFacultyPage(config('intibak.planlar_url'), storage_path('app/planlar'));
            $messages = [];
            foreach ($files as $file) {
                $result = $importer->import($file);
                $messages[] = "{$result['program']->full_name}: {$result['count']} ders";
            }

            return back()->with('status', count($files)." plan indirildi ve güncellendi.\n".implode("\n", $messages));
        } catch (Throwable $e) {
            return back()->withErrors(['download' => 'İndirme başarısız: '.$e->getMessage()]);
        }
    }

    /**
     * OBS Ders Bilgi Paketi'nden tüm programların giriş yılı müfredatlarını günceller (içerikler komutla çekilir).
     */
    public function obs(ObsImporter $importer): RedirectResponse
    {
        set_time_limit(0);
        try {
            $lines = [];
            foreach ($importer->discoverPrograms((int) config('intibak.obs.faculty_id')) as $obsProgram) {
                $results = $importer->importProgram($obsProgram);
                $lines[] = $obsProgram['name'].': '.count($results).' giriş yılı müfredatı';
            }

            return back()->with('status', "OBS'den güncellendi.\n".implode("\n", $lines)."\nDers içerikleri için: php artisan obs:aktar --yalniz-icerik");
        } catch (Throwable $e) {
            return back()->withErrors(['obs' => 'OBS güncellemesi başarısız: '.$e->getMessage()]);
        }
    }

    public function activate(Curriculum $curriculum): RedirectResponse
    {
        Curriculum::query()->where('program_id', $curriculum->program_id)->whereKeyNot($curriculum->id)->update(['is_active' => false]);
        Curriculum::query()->whereKey($curriculum->id)->update(['is_active' => true]);

        return back()->with('status', 'Aktif öğretim planı değiştirildi.');
    }

    public function destroyCurriculum(Curriculum $curriculum): RedirectResponse
    {
        $curriculum->delete();

        return redirect()->route('programs.index')->with('status', 'Öğretim planı silindi.');
    }

    public function updateCourse(Request $request, CurriculumCourse $course): RedirectResponse
    {
        $data = $request->validate([
            'common_group' => ['nullable', 'in:'.implode(',', array_keys(CurriculumCourse::COMMON_GROUPS))],
            'content' => ['nullable', 'string', 'max:5000'],
        ]);
        $course->update($data + ['normalized_name' => TextNormalizer::normalize($course->name)]);

        return back()->with('status', $course->label.' güncellendi.');
    }
}
