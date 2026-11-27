<?php

namespace App\Http\Controllers\Sinav;

use App\Models\Sinav\Course;
use App\Models\Sinav\Group;
use App\Services\Sinav\Access;
use App\Services\Sinav\Students;
use App\Services\Sinav\UserError;
use App\Services\Sinav\Util;
use App\Services\Sinav\Xlsx;
use Illuminate\Http\Request;

class StudentAdminController extends BaseController
{
    public function index(Request $request)
    {
        $term = $this->selectedTerm($request);
        $courses = $this->ownCourses(Course::query()->where('term_id', $term->id), 'id')->orderBy('code')->get();
        $groups = $this->ownCourses(Group::query()->where('term_id', $term->id))->orderBy('name')->get();
        $members = Students::members($term);
        // Öğretim elemanı yalnızca kendi derslerindeki öğrencileri ve o derslerdeki gruplarını görür.
        if (Access::courseIds() !== null) {
            $ownGroupIds = $groups->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $members = array_filter(array_map(function (array $m) use ($ownGroupIds): array {
                $m['groups'] = array_values(array_intersect($m['groups'], $ownGroupIds));

                return $m;
            }, $members), fn (array $m): bool => $m['groups'] !== []);
        }

        // Tüm öğrenciler birlikte listelenmez: her zaman seçili bir dersin öğrencileri gösterilir.
        $search = preg_replace('/\D+/', '', (string) $request->query('ara', ''));
        $courseFilter = (int) $request->query('ders', 0);
        if (! $courses->contains('id', $courseFilter)) {
            $courseFilter = (int) ($courses->first()->id ?? 0);
        }
        $groupFilter = (int) $request->query('grup', 0);
        $filtered = array_filter($members, function ($m, $no) use ($search, $courseFilter, $groupFilter) {
            return ($search === '' || str_contains((string) $no, $search))
                && in_array($courseFilter, $m['courses'], true)
                && (! $groupFilter || in_array($groupFilter, $m['groups'], true));
        }, ARRAY_FILTER_USE_BOTH);
        ksort($filtered, SORT_STRING);
        $courseGroupIds = $groups->where('course_id', $courseFilter)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $label = [];
        foreach ($groups as $g) {
            $c = $courses->firstWhere('id', $g->course_id);
            $label[$g->id] = ($c ? $c->code : '?').' / '.$g->name;
        }
        $activeGroup = $groupFilter ? $groups->firstWhere('id', $groupFilter) : null;

        return view('sinav.admin.students', compact('term', 'courses', 'groups', 'members', 'filtered', 'search', 'courseFilter', 'groupFilter', 'label', 'activeGroup', 'courseGroupIds'));
    }

    public function addForm(Request $request)
    {
        $term = $this->selectedTerm($request);
        $courses = Course::query()->where('term_id', $term->id)->orderBy('code')->get();
        $groups = Group::query()->where('term_id', $term->id)->orderBy('name')->get();
        $withGroups = $courses->filter(function ($c) use ($groups) {
            return $groups->contains('course_id', $c->id);
        })->values();

        return view('sinav.admin.students-add', [
            'term' => $term, 'courses' => $withGroups, 'groups' => $groups,
            'courseId' => (int) $request->query('ders', 0), 'groupId' => (int) $request->query('grup', 0),
        ]);
    }

    /** Listeyi çözüp önizleme gösterir; plan oturumda saklanır ve "Aktar" ile uygulanır. */
    public function preview(Request $request)
    {
        $term = $this->selectedTerm($request);
        try {
            $text = (string) $request->input('text', '');
            $file = $request->file('file');
            if ($file) {
                if (! $file->isValid()) {
                    throw new UserError('Dosya sunucuya ulaşmadı (sunucunun yükleme sınırı: '.ini_get('upload_max_filesize').').');
                }
                if ($file->getSize() > config('sinav.max_student_list')) {
                    throw new UserError('Dosya çok büyük (en fazla 2 MB).');
                }
                $text = Students::fileToText($file->getRealPath(), $file->getClientOriginalName());
            }
            $courses = Course::query()->where('term_id', $term->id)->get();
            $groups = Group::query()->where('term_id', $term->id)->get();
            $courseId = (int) $request->input('course_id');
            $groupId = (int) $request->input('group_id');
            $g = $groups->firstWhere('id', $groupId);
            if (! $g || (int) $g->course_id !== $courseId) {
                throw new UserError('Ders ve grup seçin.');
            }
            $lines = Students::parse($text, $courses, $groups, $courseId, $groupId);
            if (count($lines) === 0) {
                throw new UserError('Listede öğrenci numarası bulunamadı.');
            }
            $plan = Students::plan($lines, Students::members($term));
            session(['sinav_import' => ['term' => $term->id, 'plan' => $plan], 'sinav_import_text' => mb_substr($text, 0, 200000)]);

            return view('sinav.admin.students-preview', compact('term', 'plan', 'text', 'courseId', 'groupId'));
        } catch (UserError $e) {
            return redirect()->route('sinav.admin.students.add', ['ders' => $request->input('course_id'), 'grup' => $request->input('group_id')])
                ->withInput()->with('error', $e->getMessage());
        }
    }

    public function apply(Request $request)
    {
        $data = session('sinav_import');
        $term = $this->selectedTerm($request);
        if (! $data || (int) $data['term'] !== (int) $term->id) {
            return redirect()->route('sinav.admin.students')->with('error', 'Önizleme bulunamadı. Listeyi yeniden önizleyin.');
        }
        session()->forget(['sinav_import', 'sinav_import_text']);

        return $this->attempt(function () use ($term, $data) {
            $r = Students::apply($term, $data['plan']);

            return $r['created'].' yeni öğrenci eklendi, '.$r['enrollments'].' ders üyeliği oluşturuldu.'
                .($r['names'] ? ' '.$r['names'].' kayıtlı öğrencinin adı güncellendi.' : '');
        }, null, route('sinav.admin.students'));
    }

    public function destroy(Request $request)
    {
        $term = $this->selectedTerm($request);

        return $this->attempt(function () use ($request, $term) {
            if (Util::upperTr(trim((string) $request->input('confirm'))) !== 'SİL') {
                throw new UserError('Onay için kutuya SİL yazın.');
            }
            $ids = array_map('intval', (array) $request->input('ids', []));
            if (! $ids) {
                throw new UserError('Öğrenci seçin.');
            }

            return Students::delete($term, $ids).' öğrenci silindi.';
        });
    }

    public function removeFromGroup(Request $request)
    {
        $term = $this->selectedTerm($request);
        $group = Group::query()->where('term_id', $term->id)->findOrFail((int) $request->input('group_id'));

        return $this->attempt(function () use ($request, $term, $group) {
            $ids = array_map('intval', (array) $request->input('ids', []));
            if (! $ids) {
                throw new UserError('Öğrenci seçin.');
            }
            $r = Students::removeFromGroup($term, $ids, $group);

            return $r['removed'].' öğrenci gruptan çıkarıldı'.($r['deleted'] ? '; '.$r['deleted'].' tanesinin başka dersi kalmadığı için kaydı silindi' : '').'.';
        });
    }

    public function template()
    {
        return response(Xlsx::studentTemplate(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="ogrenci-sablonu.xlsx"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
