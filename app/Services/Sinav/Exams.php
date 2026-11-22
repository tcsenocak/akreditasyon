<?php

namespace App\Services\Sinav;

use App\Models\Sinav\Course;
use App\Models\Sinav\Exam;
use App\Models\Sinav\ExamFile;
use App\Models\Sinav\Group;
use App\Models\Sinav\KeyVersion;
use App\Models\Sinav\Release;
use App\Models\Sinav\ReleaseFile;
use App\Models\Sinav\Term;
use Illuminate\Support\Facades\DB;

/**
 * Sınavlar, cevap anahtarı dosyaları ve yayın (onay/durdurma) yönetimi.
 *
 *   sinav_exam_files     dosyaların ASIL kopyası (yalnızca yönetici)
 *   sinav_releases       sınavın bir gruba açılması: tarihler ve durum
 *   sinav_release_files  onay anında gruba kopyalanan YAYIN kopyası (öğrenci yalnızca bunu görür)
 *
 * Dosyalarda yapılan değişiklik yeniden onaylanana kadar öğrenciye yansımaz.
 */
class Exams
{
    /** Yayın bitişi: yayın zamanından N gün sonra. */
    public static function defaultPublishUntil($publishAt)
    {
        return $publishAt->copy()->addDays((int) config('sinav.answer_visible_days', 3));
    }

    /**
     * Formdan gelen saatleri çizelgeye çevirir. Sınav tek gündedir; başlangıç, bitiş ve
     * yayın saati aynı gündedir. Boş yayın saati = bitiş saati.
     */
    public static function scheduleFromForm(array $in, $groups)
    {
        $common = $in['common'] ?? [];
        $rows = $in['groups'] ?? [];
        $schedule = [];
        foreach ($groups as $g) {
            $row = $rows[$g->id] ?? [];
            if (empty($row['on'])) {
                continue;
            }
            $t = ! empty($row['custom']) ? $row : $common;
            $date = $t['date'] ?? '';
            $start = self::timeOf($t, 'start');
            $end = self::timeOf($t, 'end');
            $publish = self::timeOf($t, 'publish');
            if ($publish === '') {
                $publish = $end; // yayın saati boşsa sınav bitişi
            }
            if (! $date || ! $start || ! $end) {
                throw new UserError('"'.$g->name.'" grubu: sınav gününü, başlangıç ve bitiş saatini girin.');
            }
            $publishAt = Util::fromInput($date, $publish);
            $schedule[] = [
                'group_id' => $g->id,
                'group_name' => $g->name,
                'starts' => Util::fromInput($date, $start),
                'ends' => Util::fromInput($date, $end),
                'publish_at' => $publishAt,
                // Öğrenci cevap anahtarını yayından sonra yalnızca N gün görür.
                'publish_until' => $publishAt ? self::defaultPublishUntil($publishAt) : null,
            ];
        }

        return $schedule;
    }

    /** Saat iki açılır listeden gelir: "start_h" + "start_m" -> "10:30" (ya da doğrudan "start"). */
    private static function timeOf(array $t, $key)
    {
        if (isset($t[$key]) && preg_match('/^\d{2}:\d{2}$/', (string) $t[$key])) {
            return $t[$key];
        }
        $h = $t[$key.'_h'] ?? '';
        $m = $t[$key.'_m'] ?? '';
        if (! preg_match('/^\d{2}$/', (string) $h) || (int) $h > 23) {
            return '';
        }
        $m = preg_match('/^\d{2}$/', (string) $m) && (int) $m < 60 ? $m : '00';

        return $h.':'.$m;
    }

    public static function validateSchedule(array $s)
    {
        $p = '"'.$s['group_name'].'" grubu: ';
        if (! $s['starts'] || ! $s['ends'] || ! $s['publish_at']) {
            throw new UserError($p.'tüm tarihleri girin.');
        }
        if ($s['ends']->lte($s['starts'])) {
            throw new UserError($p.'sınav bitişi, başlangıcından sonra olmalı.');
        }
        if ($s['publish_at']->lt($s['ends'])) {
            throw new UserError($p.'cevap anahtarı sınav bitmeden yayınlanamaz. Yayın zamanı, sınav bitişinden önce olamaz.');
        }
        if ($s['publish_until'] && $s['publish_until']->lte($s['publish_at'])) {
            throw new UserError($p.'yayın bitişi, yayın başlangıcından sonra olmalı.');
        }
    }

    /**
     * Sınavı ve seçili her grup için yayın kaydını oluşturur/günceller.
     * Seçimden çıkarılan grupların yayın kaydı ve yayın kopyaları silinir.
     */
    public static function save(Term $term, $exam, array $in, array $schedule)
    {
        Terms::requireEditable($term);
        $type = (string) ($in['type'] ?? '');
        if (! isset(Exam::TYPES[$type])) {
            throw new UserError('Sınav türü seçin.');
        }
        // Ad boş bırakılırsa türünden üretilir: "Vize Sınavı".
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            $title = str_ends_with(Exam::TYPES[$type], 'sınavı') ? Exam::TYPES[$type] : Exam::TYPES[$type].' Sınavı';
        }
        $courseId = $exam ? $exam->course_id : (int) ($in['course_id'] ?? 0);
        $course = Course::query()->where('term_id', $term->id)->find($courseId);
        if (! $course) {
            throw new UserError('Ders seçin.');
        }
        if (count($schedule) === 0) {
            throw new UserError('En az bir grup seçin.');
        }
        foreach ($schedule as $s) {
            self::validateSchedule($s);
        }

        $removedPaths = [];
        $exam = DB::transaction(function () use ($term, $exam, $course, $title, $type, $in, $schedule, &$removedPaths) {
            $data = ['term_id' => $term->id, 'course_id' => $course->id, 'type' => $type, 'title' => mb_substr($title, 0, 120),
                'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 500)];
            if ($exam) {
                $exam->update($data);
            } else {
                $exam = Exam::query()->create($data);
            }
            $keep = [];
            foreach ($schedule as $s) {
                $keep[] = $s['group_id'];
                $r = Release::query()->firstOrNew(['exam_id' => $exam->id, 'group_id' => $s['group_id']]);
                // Onay ve durdurma durumu tarih düzenlemesiyle değişmez.
                $r->fill([
                    'term_id' => $term->id, 'course_id' => $course->id,
                    'exam_starts_at' => $s['starts'], 'exam_ends_at' => $s['ends'],
                    'publish_at' => $s['publish_at'], 'publish_until' => $s['publish_until'],
                ]);
                $r->save();
            }
            foreach (Release::query()->where('exam_id', $exam->id)->whereNotIn('group_id', $keep)->get() as $r) {
                foreach ($r->files as $f) {
                    $removedPaths[] = $f->path;
                }
                $r->delete();
            }

            return $exam;
        });
        foreach ($removedPaths as $p) {
            Files::delete($p);
        }
        Util::log($exam->wasRecentlyCreated ? 'exam.create' : 'exam.update', 'exam', $exam->id);

        return $exam;
    }

    /**
     * Hızlı sınav: okunan sınıf listesinden ders ve grubu tanımlar (yoksa açar), öğrencileri gruba
     * ekler, grubun PIN'ini ayarlar ve sınavı oluşturur. Hepsi tek işlemdir; hata olursa hiçbiri
     * kaydedilmez.
     *
     * @param  array{code: string, name: string, group: string, students: array<string, string>, skipped: int}  $list
     * @return array{exam: Exam, course: Course, group: Group, added: int, pin: ?string}
     */
    public static function createWithList(Term $term, array $list, array $in): array
    {
        $code = Util::upperTr(trim((string) ($in['code'] ?? '')));
        $slug = Util::slug($code);
        $courseName = trim((string) ($in['course_name'] ?? ''));
        $groupName = trim((string) ($in['group_name'] ?? '')) ?: 'A';
        if ($slug === '') {
            throw new UserError('Ders kodunu yazın (listede bulunamadı).');
        }
        if (count($list['students']) === 0) {
            throw new UserError('Listede öğrenci bulunamadı.');
        }

        return DB::transaction(function () use ($term, $list, $in, $code, $slug, $courseName, $groupName) {
            $course = Course::query()->where('term_id', $term->id)->where('code_slug', $slug)->first();
            if ($course && ! Access::canCourse($course->id)) {
                throw new UserError('"'.$course->code.'" dersi başka bir öğretim elemanına kayıtlı. Ders ataması için komisyona başvurun.');
            }
            if (! $course) {
                if ($courseName === '') {
                    throw new UserError('Ders adını yazın (listede bulunamadı).');
                }
                $course = Terms::createCourse($term, [
                    'code' => $code, 'name' => $courseName, 'active' => 1,
                    'instructor_user_id' => Access::isInstructor() ? Access::user()->id : 0,
                ]);
                Access::forget();
            }
            $group = Group::query()->where('course_id', $course->id)->where('name_slug', Util::slug($groupName))->first()
                ?: Terms::createGroup($course, $groupName);

            $lines = [];
            foreach ($list['students'] as $no => $name) {
                $lines[] = array_filter(['student_no' => (string) $no, 'name' => $name, 'course_id' => (int) $course->id, 'group_id' => (int) $group->id]);
            }
            $result = Students::apply($term, Students::plan($lines, Students::members($term)));

            $pin = null;
            if (trim((string) ($in['pin'] ?? '')) !== '') {
                $pin = Terms::setGroupPin($group, (string) $in['pin']);
            } elseif (! $group->hasPin()) {
                throw new UserError('Öğrencilerin sınavı görebilmesi için bir PIN belirleyin (4-8 rakam).');
            }

            $schedule = self::scheduleFromForm(['common' => $in['common'] ?? [], 'groups' => [$group->id => ['on' => 1]]], collect([$group]));
            $exam = self::save($term, null, ['course_id' => $course->id] + $in, $schedule);

            return ['exam' => $exam, 'course' => $course, 'group' => $group, 'added' => $result['enrollments'], 'pin' => $pin];
        });
    }

    public static function delete(Exam $exam)
    {
        Terms::requireEditable(Term::query()->find($exam->term_id));
        $paths = [];
        foreach ($exam->releases as $r) {
            foreach ($r->files as $f) {
                $paths[] = $f->path;
            }
        }
        foreach ($exam->files as $f) {
            $paths[] = $f->path;
        }
        $id = $exam->id;
        // Önce yayın kayıtları silinir: öğrenci erişimi hemen kesilir.
        DB::transaction(function () use ($exam) {
            Release::query()->where('exam_id', $exam->id)->delete();
            $exam->delete();
        });
        foreach ($paths as $p) {
            Files::delete($p);
        }
        Util::log('exam.delete', 'exam', $id);
    }

    // ---------- Dosyalar ----------

    public static function uploadFile(Exam $exam, $uploadId, $originalName, $kind)
    {
        Terms::requireEditable(Term::query()->find($exam->term_id));
        if (! isset(ExamFile::KIND_LABEL[$kind])) {
            throw new UserError('Dosya türü seçin.');
        }
        $f = Files::take($uploadId, config('sinav.max_exam_file'));
        $mime = Util::sniffMime($f['head']);
        if (! $mime) {
            @unlink($f['tmp']);
            throw new UserError('Yalnızca PDF, PNG veya JPG dosyası yüklenebilir. (Dosya içeriği bu türlerden birine ait değil.)');
        }
        $path = Files::keep($f['tmp'], 'exams/'.$exam->id, Files::extFor($mime));
        ExamFile::query()->create([
            'exam_id' => $exam->id, 'name' => mb_substr((string) $originalName, 0, 200), 'mime' => $mime,
            'size' => $f['size'], 'kind' => $kind, 'group_ids' => [], 'path' => $path,
        ]);
        $exam->increment('draft_rev');
        Util::log('file.upload', 'exam', $exam->id, 'ok', $mime.', '.round($f['size'] / 1024).' KB');
    }

    public static function setFileGroups(ExamFile $file, Exam $exam, array $groupIds)
    {
        Terms::requireEditable(Term::query()->find($exam->term_id));
        $all = $exam->releases()->pluck('group_id')->map(function ($x) {
            return (int) $x;
        })->all();
        $sel = array_values(array_intersect($all, array_map('intval', $groupIds)));
        if (count($sel) === 0) {
            throw new UserError('Dosya en az bir gruba açık olmalı. Kimse görmeyecekse dosyayı silin.');
        }
        $file->update(['group_ids' => count($sel) === count($all) ? [] : $sel]);
        $exam->increment('draft_rev');
    }

    public static function deleteFile(ExamFile $file, Exam $exam)
    {
        Terms::requireEditable(Term::query()->find($exam->term_id));
        Files::delete($file->path);
        $file->delete();
        $exam->increment('draft_rev');
        Util::log('file.delete', 'exam', $exam->id);
    }

    // ---------- Yayın öncesi kontrol, onay, durdurma ----------

    public static function filesForGroup(Exam $exam, $groupId)
    {
        return $exam->files()->get()->filter(function ($f) use ($groupId) {
            return $f->visibleTo($groupId);
        })->values();
    }

    public static function check(Exam $exam, Release $release)
    {
        $files = self::filesForGroup($exam, $release->group_id);
        $c = ['files' => $files, 'errors' => [], 'warnings' => []];
        if ($files->isEmpty()) {
            $c['errors'][] = 'Bu grup için yayınlanacak bir şey yok. "Cevap Anahtarları" bölümünden PDF/görsel yükleyin.';
        } elseif (! $files->contains('kind', 'key')) {
            $c['warnings'][] = 'Yalnızca soru kitapçığı var; cevap anahtarı dosyası yüklenmemiş.';
        }
        if ($release->publish_at->lte(now())) {
            $c['warnings'][] = 'Yayın zamanı geçmiş: onayladığınız anda öğrenciler cevap anahtarını görebilir.';
        }
        if ($release->exam_ends_at->gt(now())) {
            $c['warnings'][] = 'Sınav henüz bitmedi. Cevap anahtarı yine de yayın zamanından önce görünmez.';
        }

        return $c;
    }

    /**
     * Onay: dosyaların yayın kopyasını eşitler, sürüm kaydı ekler, yayını "onaylı" yapar.
     * Yayın zamanı gelince öğrenciler kendiliğinden görür.
     */
    public static function approve(Exam $exam, Release $release, $note)
    {
        Terms::requireEditable(Term::query()->find($exam->term_id));
        $check = self::check($exam, $release);
        if (count($check['errors']) > 0) {
            throw new UserError(implode(' ', $check['errors']));
        }
        $version = $release->version + 1;
        $wanted = $check['files'];
        $removed = [];
        DB::transaction(function () use ($exam, $release, $wanted, $version, $note, &$removed) {
            $have = $release->files()->get()->keyBy('exam_file_id');
            foreach ($wanted as $f) {
                if ($have->has($f->id)) {
                    continue;
                }
                ReleaseFile::query()->create([
                    'release_id' => $release->id, 'exam_file_id' => $f->id, 'name' => $f->name, 'mime' => $f->mime,
                    'size' => $f->size, 'kind' => $f->kind, 'path' => Files::copy($f->path, 'releases/'.$release->id, Files::extFor($f->mime)),
                ]);
            }
            $ids = $wanted->pluck('id')->all();
            foreach ($have as $examFileId => $rf) {
                if (! in_array($examFileId, $ids)) {
                    $removed[] = $rf->path;
                    $rf->delete();
                }
            }
            KeyVersion::query()->create([
                'exam_id' => $exam->id, 'release_id' => $release->id, 'group_id' => $release->group_id, 'version' => $version,
                'files' => $wanted->map(function ($f) {
                    return ['id' => $f->id, 'name' => $f->name, 'kind' => $f->kind];
                })->all(),
                'note' => mb_substr(trim((string) $note), 0, 500), 'admin' => Util::adminName(), 'created_at' => now(),
            ]);
            $release->update(['approved' => true, 'halted' => false, 'version' => $version, 'approved_rev' => $exam->draft_rev, 'content_updated_at' => now()]);
        });
        foreach ($removed as $p) {
            Files::delete($p);
        }
        Util::log('release.approve', 'release', $release->id, 'ok', 'sürüm '.$version);

        return $version;
    }

    /** ACİL DURDURMA: bir sonraki istekten itibaren öğrenciler içeriği göremez. */
    public static function halt(Release $release)
    {
        $release->update(['halted' => true]);
        Util::log('release.halt', 'release', $release->id);
    }

    /** Onayı geri alır (henüz yayınlanmamış planı iptal etmek için). */
    public static function unapprove(Release $release)
    {
        $release->update(['approved' => false, 'halted' => false]);
        Util::log('release.unapprove', 'release', $release->id);
    }

    /** Öğrenci bu yayının içeriğini şu an görebilir mi? (Tüm koşullar sunucuda denetlenir.) */
    /**
     * Öğrenci bu yayını şu an görebilir mi: dönem açık, yayın canlı, öğrenci grupta kayıtlı ve
     * grubun PIN'i bu oturumda girilmiş ($pinGroupIds).
     *
     * @param  array<int, int>  $pinGroupIds
     */
    public static function studentCanRead(Release $release, $student, $term, array $pinGroupIds = []): bool
    {
        if (! $term || ! $term->openForStudents() || (int) $release->term_id !== (int) $term->id) {
            return false;
        }
        if ($release->status() !== 'live' || ! in_array((int) $release->group_id, $pinGroupIds, true)) {
            return false;
        }

        return DB::table('sinav_enrollments')->where('student_id', $student->id)->where('group_id', $release->group_id)->exists();
    }

    public static function groupName($id)
    {
        $g = Group::query()->find($id);

        return $g ? $g->name : '?';
    }
}
