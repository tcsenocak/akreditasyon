<?php

namespace App\Http\Controllers\Sinav;

use App\Models\Sinav\Course;
use App\Models\Sinav\CourseDoc;
use App\Models\Sinav\Group;
use App\Models\User;
use App\Services\Sinav\Access;
use App\Services\Sinav\Terms;
use Illuminate\Http\Request;

/** Dersler (+ ders evrakı) ve gruplar (PIN). Dersler, öğrenci listesiyle sınav oluşturulurken tanımlanır. */
class TermController extends BaseController
{
    // ---------- Dersler ----------

    public function courses(Request $request)
    {
        $term = $this->selectedTerm($request);
        $courses = $this->ownCourses(Course::query()->withCount('groups')->with(['docs', 'instructorUser'])->where('term_id', $term->id), 'id')->orderBy('code')->get();
        $instructors = Access::isCommission() ? User::query()->where('role', 'instructor')->orderBy('name')->get() : collect();
        $editCourse = $request->query('duzenle') ? $courses->firstWhere('id', (int) $request->query('duzenle')) : null;
        $docsCourse = $request->query('evrak') ? $courses->firstWhere('id', (int) $request->query('evrak')) : null;

        return view('sinav.admin.courses', compact('term', 'courses', 'editCourse', 'docsCourse', 'instructors'));
    }

    public function courseUpdate(Request $request, Course $course)
    {
        return $this->attempt(function () use ($request, $course) {
            Terms::updateCourse($course, $request->all());
        }, 'Ders güncellendi.', route('sinav.admin.courses'));
    }

    public function courseDestroy(Course $course)
    {
        return $this->attempt(function () use ($course) {
            Terms::deleteCourse($course);
        }, 'Ders silindi.');
    }

    public function docStore(Request $request, Course $course)
    {
        Access::authorizeCourse($course->id);

        return $this->attempt(function () use ($request, $course) {
            Terms::addDoc($course, $request->input('title'), $request->input('url'));
        }, 'Evrak bağlantısı eklendi.');
    }

    public function docDestroy(CourseDoc $doc)
    {
        Access::authorizeCourse($doc->course_id);

        return $this->attempt(function () use ($doc) {
            Terms::deleteDoc($doc);
        }, 'Evrak bağlantısı kaldırıldı (Drive\'daki dosyaya dokunulmadı).');
    }

    // ---------- Gruplar ----------

    public function groups(Request $request)
    {
        $term = $this->selectedTerm($request);
        $courses = $this->ownCourses(Course::query()->with(['groups' => function ($q) {
            $q->withCount('enrollments');
        }])->where('term_id', $term->id), 'id')->orderBy('code')->get();

        return view('sinav.admin.groups', compact('term', 'courses'));
    }

    /** Hoca (veya komisyon) gruba öğrenci giriş PIN'i verir; boş bırakılırsa rastgele üretilir. */
    public function groupPin(Request $request, Group $group)
    {
        Access::authorizeCourse($group->course_id);

        return $this->attempt(function () use ($request, $group) {
            $pin = Terms::setGroupPin($group, $request->input('pin'));

            return '"'.$group->name.'" grubunun PIN\'i: '.$pin.'. Öğrencilere duyurun; eski PIN\'le girmiş olanlar bu dersi yeniden PIN girene kadar göremez.';
        });
    }

    public function groupDestroy(Group $group)
    {
        return $this->attempt(function () use ($group) {
            Terms::deleteGroup($group);
        }, 'Grup silindi.');
    }
}
