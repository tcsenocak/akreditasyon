<?php

namespace App\Http\Controllers\Sinav;

use App\Models\Sinav\Course;
use App\Models\Sinav\Exam;
use App\Models\Sinav\ExamFile;
use App\Models\Sinav\Group;
use App\Models\Sinav\Release;
use App\Models\Sinav\Term;
use App\Services\Sinav\Access;
use App\Services\Sinav\Exams;
use App\Services\Sinav\Files;
use App\Services\Sinav\Students;
use App\Services\Sinav\Terms;
use App\Services\Sinav\UserError;
use App\Services\Sinav\Util;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Sınavlar, cevap anahtarı dosyaları ve yayın takvimi. */
class ExamController extends BaseController
{
    private function data(Term $term)
    {
        $courses = $this->ownCourses(Course::query()->where('term_id', $term->id), 'id')->orderBy('code')->get();
        $groups = $this->ownCourses(Group::query()->where('term_id', $term->id))->orderBy('name')->get();
        $exams = $this->ownCourses(Exam::query()->where('term_id', $term->id))->orderBy('title')->get();
        $releases = $this->ownCourses(Release::query()->where('term_id', $term->id))->orderBy('publish_at')->get();

        return compact('courses', 'groups', 'exams', 'releases');
    }

    public function index(Request $request)
    {
        $term = $this->selectedTerm($request);
        $d = $this->data($term);
        $coursesWithGroups = $d['courses']->filter(function ($c) use ($d) {
            return $d['groups']->contains('course_id', $c->id);
        });

        return view('sinav.admin.exams', $d + compact('term', 'coursesWithGroups'));
    }

    // ---------- Sınav formu ----------

    /**
     * @param  array{code: string, name: string, group: string, students: array<string, string>, skipped: int}|null  $list
     */
    private function formView(Request $request, Term $term, ?Exam $exam, int $courseId = 0, ?array $list = null): View
    {
        $d = $this->data($term);
        $coursesWithGroups = $d['courses']->filter(function ($c) use ($d) {
            return $d['groups']->contains('course_id', $c->id);
        })->values();
        $courseId = $exam ? $exam->course_id : $courseId;
        $common = ['date' => '', 'start' => '', 'end' => '', 'publish' => ''];
        $rows = [];
        if ($exam) {
            $mine = $d['releases']->where('exam_id', $exam->id)->values();
            $toTimes = function ($r) {
                return [
                    'date' => Util::input($r->exam_starts_at, 'date'),
                    'start' => Util::input($r->exam_starts_at, 'time'),
                    'end' => Util::input($r->exam_ends_at, 'time'),
                    'publish' => Util::input($r->publish_at, 'time'),
                ];
            };
            if ($mine->first()) {
                $common = $toTimes($mine->first());
            }
            foreach ($mine as $r) {
                $t = $toTimes($r);
                $rows[$r->group_id] = ['on' => true, 'custom' => $t != $common, 'times' => $t];
            }
        } else {
            // Yeni sınavda dersin bütün grupları işaretli gelir.
            foreach ($d['groups']->where('course_id', $courseId) as $g) {
                $rows[$g->id] = ['on' => true, 'custom' => false, 'times' => $common];
            }
        }
        $formGroups = $d['groups']->where('course_id', $courseId)->values();
        $listCourse = $list ? Course::query()->where('term_id', $term->id)->where('code_slug', Util::slug($list['code']))->first() : null;
        $listGroup = $listCourse ? Group::query()->where('course_id', $listCourse->id)->where('name_slug', Util::slug($list['group']))->first() : null;

        return view('sinav.admin.exam-form', compact('term', 'exam', 'coursesWithGroups', 'courseId', 'common', 'rows', 'formGroups', 'list', 'listCourse', 'listGroup'));
    }

    /**
     * Yeni sınav. Öğrenci listesi okunduysa listeden gelen ders/grupla form; ?ders= ile kayıtlı
     * dersin formu; ikisi de yoksa başlangıç ekranı (liste yükle veya kayıtlı dersini seç).
     */
    public function create(Request $request)
    {
        $term = $this->selectedTerm($request);
        $list = $request->boolean('liste') ? session('sinav_list_draft') : null;
        if ($list) {
            return $this->formView($request, $term, null, 0, $list);
        }
        $courseId = (int) $request->query('ders');
        if ($courseId) {
            Access::authorizeCourse($courseId);

            return $this->formView($request, $term, null, $courseId);
        }
        $d = $this->data($term);
        $coursesWithGroups = $d['courses']->filter(fn ($c) => $d['groups']->contains('course_id', $c->id))->values();

        return view('sinav.admin.exam-start', compact('coursesWithGroups'));
    }

    /** Sınıf listesini okur ve formu listedeki ders, grup ve öğrencilerle açar. */
    public function readList(Request $request): RedirectResponse
    {
        try {
            $file = $request->file('list');
            if ($file && $file->isValid()) {
                if ($file->getSize() > 2 * 1024 * 1024) {
                    throw new UserError('Liste dosyası en fazla 2 MB olabilir.');
                }
                $text = Students::fileToText($file->getRealPath(), $file->getClientOriginalName());
                $fileName = $file->getClientOriginalName();
            } else {
                $text = (string) $request->input('list_text', '');
                $fileName = (string) $request->input('list_name', '');
            }
            $list = Students::readClassList($text, $fileName);
            if (count($list['students']) === 0) {
                throw new UserError('Listede öğrenci numarası bulunamadı. Listede 6-12 haneli öğrenci numaraları olmalı.');
            }
            session(['sinav_list_draft' => $list]);

            return redirect()->route('sinav.admin.exams.create', ['liste' => 1]);
        } catch (UserError $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function edit(Request $request, Exam $exam)
    {
        Access::authorizeCourse($exam->course_id);

        return $this->formView($request, Term::query()->findOrFail($exam->term_id), $exam);
    }

    private function save(Request $request, Term $term, ?Exam $exam): RedirectResponse
    {
        try {
            $courseId = $exam ? $exam->course_id : (int) $request->input('course_id');
            Access::authorizeCourse($courseId);
            $groups = Group::query()->where('course_id', $courseId)->orderBy('name')->get();
            $schedule = Exams::scheduleFromForm($request->all(), $groups);
            $saved = DB::transaction(function () use ($term, $exam, $request, $schedule, $groups) {
                // Seçili gruplardan PIN'i olmayanlara hocanın yazdığı PIN verilir.
                foreach ($schedule as $s) {
                    $group = $groups->firstWhere('id', $s['group_id']);
                    $pin = trim((string) $request->input('groups.'.$group->id.'.pin', ''));
                    if ($pin !== '') {
                        Terms::setGroupPin($group, $pin);
                    } elseif (! $group->hasPin()) {
                        throw new UserError('"'.$group->name.'" grubu için PIN belirleyin; PIN olmadan öğrenciler sınavı göremez.');
                    }
                }

                return Exams::save($term, $exam, $request->all(), $schedule);
            });

            return redirect()->route('sinav.admin.keys', ['sinav' => $saved->id])->with('success', $exam
                ? 'Sınav güncellendi.'
                : 'Sınav oluşturuldu. Şimdi cevap anahtarı dosyasını yükleyin.');
        } catch (UserError $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $term = $this->selectedTerm($request);
        if (! $request->boolean('from_list')) {
            return $this->save($request, $term, null);
        }
        $list = session('sinav_list_draft');
        if (! $list) {
            return redirect()->route('sinav.admin.exams.create')->with('error', 'Öğrenci listesi bulunamadı; listeyi yeniden yükleyin.');
        }
        try {
            $r = Exams::createWithList($term, $list, $request->all());
            session()->forget('sinav_list_draft');
            $msg = 'Sınav oluşturuldu: '.$r['course']->code.' — '.$r['course']->name.', '.$r['group']->name.' grubu, '.$r['added'].' öğrenci eklendi.';
            if ($r['pin']) {
                $msg .= " Öğrenci PIN'i: ".$r['pin'].'.';
            }

            return redirect()->route('sinav.admin.keys', ['sinav' => $r['exam']->id])->with('success', $msg.' Şimdi cevap anahtarı dosyasını yükleyin.');
        } catch (UserError $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function update(Request $request, Exam $exam): RedirectResponse
    {
        Access::authorizeCourse($exam->course_id);

        return $this->save($request, Term::query()->findOrFail($exam->term_id), $exam);
    }

    public function destroy(Request $request, Exam $exam)
    {
        Access::authorizeCourse($exam->course_id);

        return $this->attempt(function () use ($request, $exam) {
            if (Util::upperTr(trim((string) $request->input('confirm'))) !== 'SİL') {
                throw new UserError('Onay için kutuya SİL yazın.');
            }
            $title = $exam->title;
            Exams::delete($exam);

            return '"'.$title.'" silindi.';
        });
    }

    // ---------- Cevap anahtarı dosyaları ----------

    public function keys(Request $request)
    {
        $term = $this->selectedTerm($request);
        $d = $this->data($term);
        $exam = $request->query('sinav') ? $d['exams']->firstWhere('id', (int) $request->query('sinav')) : null;
        $files = $exam ? $exam->files()->get() : collect();
        $versions = $exam ? $exam->versions()->get() : collect();
        $examGroupIds = $exam ? $d['releases']->where('exam_id', $exam->id)->pluck('group_id')->map(function ($x) {
            return (int) $x;
        })->values()->all() : [];

        return view('sinav.admin.keys', $d + compact('term', 'exam', 'files', 'versions', 'examGroupIds'));
    }

    public function upload(Request $request, Exam $exam)
    {
        Access::authorizeCourse($exam->course_id);

        return $this->attemptJson(function () use ($request, $exam) {
            Exams::uploadFile($exam, $request->input('upload_id'), (string) $request->input('name'), (string) $request->input('kind'));

            return 'Dosya yüklendi. Öğrencilerin görmesi için "Yayın Takvimi" bölümünden kontrol edip onaylayın.';
        });
    }

    public function fileGroups(Request $request, ExamFile $file)
    {
        $exam = Exam::query()->findOrFail($file->exam_id);
        Access::authorizeCourse($exam->course_id);

        return $this->attempt(function () use ($request, $file, $exam) {
            Exams::setFileGroups($file, $exam, (array) $request->input('group_ids', []));
        }, 'Dosyayı görecek gruplar güncellendi.');
    }

    public function fileDestroy(ExamFile $file)
    {
        $exam = Exam::query()->findOrFail($file->exam_id);
        Access::authorizeCourse($exam->course_id);

        return $this->attempt(function () use ($file, $exam) {
            $name = $file->name;
            Exams::deleteFile($file, $exam);

            return '"'.$name.'" silindi.';
        });
    }

    /** Yönetici önizlemesi (asıl kopya). */
    public function fileShow(Request $request, ExamFile $file)
    {
        Access::authorizeCourse(Exam::query()->whereKey($file->exam_id)->value('course_id'));

        return Files::respond($file->path, $file->mime, $file->name, $request->boolean('indir'));
    }

    // ---------- Yayın takvimi ----------

    public function publish(Request $request)
    {
        $term = $this->selectedTerm($request);
        $d = $this->data($term);

        return view('sinav.admin.publish', $d + compact('term'));
    }

    public function review(Release $release)
    {
        Access::authorizeCourse($release->course_id);

        $exam = Exam::query()->findOrFail($release->exam_id);
        $term = Term::query()->findOrFail($release->term_id);
        $check = Exams::check($exam, $release);
        $course = Course::query()->find($release->course_id);
        $group = Group::query()->find($release->group_id);

        return view('sinav.admin.review', compact('release', 'exam', 'term', 'check', 'course', 'group'));
    }

    public function approve(Request $request, Release $release)
    {
        Access::authorizeCourse($release->course_id);

        $exam = Exam::query()->findOrFail($release->exam_id);

        return $this->attempt(function () use ($request, $release, $exam) {
            $v = Exams::approve($exam, $release, (string) $request->input('note', ''));

            return $release->publish_at->lte(now())
                ? 'Onaylandı (sürüm '.$v.'). Yayın zamanı geçtiği için cevap anahtarı şu an öğrencilere açık.'
                : 'Onaylandı (sürüm '.$v.'). Cevap anahtarı '.Util::fmt($release->publish_at).' tarihinde öğrencilere kendiliğinden açılacak.';
        }, null, route('sinav.admin.publish'));
    }

    public function halt(Release $release)
    {
        Access::authorizeCourse($release->course_id);

        return $this->attempt(function () use ($release) {
            Exams::halt($release);

            return 'Yayından kaldırıldı. Öğrenciler artık göremez.';
        });
    }

    public function unapprove(Release $release)
    {
        Access::authorizeCourse($release->course_id);

        return $this->attempt(function () use ($release) {
            Exams::unapprove($release);
        }, 'Onay geri alındı.');
    }
}
