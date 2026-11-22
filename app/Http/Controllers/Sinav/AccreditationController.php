<?php

namespace App\Http\Controllers\Sinav;

use App\Models\Sinav\AccFile;
use App\Models\Sinav\AccOutput;
use App\Models\Sinav\AccSection;
use App\Models\Sinav\Course;
use App\Models\Sinav\Exam;
use App\Models\Sinav\ExamFile;
use App\Services\Sinav\Access;
use App\Services\Sinav\Accreditation;
use App\Services\Sinav\Files;
use App\Services\Sinav\UserError;
use App\Services\Sinav\Xlsx;
use Illuminate\Http\Request;

/** Ders dosyası (akreditasyon). Yalnızca yönetici; öğrenciler göremez. */
class AccreditationController extends BaseController
{
    public function index(Request $request)
    {
        $term = $this->selectedTerm($request);
        $courses = $this->ownCourses(Course::query()->where('term_id', $term->id), 'id')->orderBy('code')->get();
        $course = $courses->firstWhere('id', (int) $request->query('ders')) ?: $courses->first();
        $sections = $course ? Accreditation::sections($course) : collect();
        $files = $course ? Accreditation::files($course) : collect();
        $outputs = $course ? AccOutput::query()->where('course_id', $course->id)->orderByDesc('created_at')->orderByDesc('id')->get() : collect();
        // "Sınavlardan al" için: bu dersin vize/final/bütünleme sınavlarına yüklenmiş dosyalar.
        $examFiles = [];
        if ($course) {
            foreach (Exam::query()->where('course_id', $course->id)->get() as $e) {
                foreach ($e->files as $f) {
                    $examFiles[$e->type][] = ['exam' => $e, 'file' => $f];
                }
            }
        }

        return view('sinav.admin.accreditation', compact('term', 'courses', 'course', 'sections', 'files', 'outputs', 'examFiles'));
    }

    public function sectionStore(Request $request, Course $course)
    {
        Access::authorizeCourse($course->id);

        return $this->attempt(function () use ($request, $course) {
            Accreditation::addSection($course, $request->input('title'));
        }, 'Bölüm eklendi.');
    }

    public function sectionRename(Request $request, AccSection $section)
    {
        Access::authorizeCourse($section->course_id);

        return $this->attempt(function () use ($request, $section) {
            Accreditation::renameSection($section, $request->input('title'));
        }, 'Bölüm adı değiştirildi.');
    }

    public function sectionMove(Request $request, AccSection $section)
    {
        Access::authorizeCourse($section->course_id);

        Accreditation::moveSection($section, (int) $request->input('dir'));

        return back();
    }

    public function sectionDestroy(AccSection $section)
    {
        Access::authorizeCourse($section->course_id);

        return $this->attempt(function () use ($section) {
            Accreditation::deleteSection($section);
        }, 'Silindi.');
    }

    /** Tarayıcının parça parça gönderdiği dosyayı bölüme ekler (Excel tarayıcıda PDF'e çevrilmiş gelir). */
    public function upload(Request $request, AccSection $section)
    {
        Access::authorizeCourse($section->course_id);

        return $this->attemptJson(function () use ($request, $section) {
            Accreditation::upload($section, $request->input('upload_id'), (string) $request->input('name'), (string) $request->input('source'));

            return 'ok';
        });
    }

    /** Excel dosyasının ilk sayfasını satırlar halinde döner (tarayıcı bunu PDF tabloya çevirir). */
    public function xlsxRows(Request $request)
    {
        return $this->attemptJson(function () use ($request) {
            $f = Files::take($request->input('upload_id'), config('sinav.max_xlsx'));
            try {
                $rows = Xlsx::readFirstSheet($f['tmp']);
            } finally {
                @unlink($f['tmp']);
            }
            if (count($rows) === 0) {
                throw new UserError('Excel dosyasının ilk sayfası boş.');
            }

            return ['rows' => $rows];
        });
    }

    public function copyFromExam(Request $request, AccSection $section)
    {
        Access::authorizeCourse($section->course_id);

        $file = ExamFile::query()->findOrFail((int) $request->input('exam_file_id'));
        $exam = Exam::query()->findOrFail($file->exam_id);
        if ((int) $exam->course_id !== (int) $section->course_id) {
            abort(404);
        }

        return $this->attempt(function () use ($section, $file) {
            Accreditation::copyFromExam($section, $file);

            return '"'.$file->name.'" '.$section->title.' bölümüne eklendi.';
        });
    }

    public function fileShow(Request $request, AccFile $file)
    {
        Access::authorizeCourse($file->course_id);

        return Files::respond($file->path, $file->mime, $file->name, false);
    }

    public function fileMove(Request $request, AccFile $file)
    {
        Access::authorizeCourse($file->course_id);

        Accreditation::moveFile($file, (int) $request->input('dir'));

        return back();
    }

    public function fileDestroy(AccFile $file)
    {
        Access::authorizeCourse($file->course_id);

        return $this->attempt(function () use ($file) {
            Accreditation::deleteFile($file);
        }, 'Silindi.');
    }

    public function outputStore(Request $request, Course $course)
    {
        Access::authorizeCourse($course->id);

        return $this->attemptJson(function () use ($request, $course) {
            Accreditation::saveOutput($course, $request->input('upload_id'), (string) $request->input('name'), (int) $request->input('page_count'));

            return 'Sistemde de saklandı.';
        });
    }

    public function outputShow(AccOutput $output)
    {
        Access::authorizeCourse($output->course_id);

        return Files::respond($output->path, 'application/pdf', $output->name, true);
    }

    public function outputDestroy(AccOutput $output)
    {
        Access::authorizeCourse($output->course_id);

        return $this->attempt(function () use ($output) {
            Accreditation::deleteOutput($output);
        }, 'Saklanan PDF silindi.');
    }
}
