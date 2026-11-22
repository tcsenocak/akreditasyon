<?php

namespace App\Services\Sinav;

use App\Models\Sinav\Enrollment;
use App\Models\Sinav\Group;
use App\Models\Sinav\Student;
use App\Models\Sinav\Term;
use Illuminate\Support\Facades\DB;

/**
 * Öğrenci yönetimi. Öğrenciye ait kişisel veri: öğrenci numarası ve (isteğe bağlı) ad soyad.
 * İkisi de yalnızca sinav_students tablosunda durur ve yalnızca yönetici/hoca görür. Öğrenci
 * numara + dersin PIN'iyle girer. Muafiyet modülündeki öğrenci kayıtlarıyla bağlantısı yoktur.
 */
class Students
{
    const NO_PATTERN = '/^\d{6,12}$/';

    const NAME_MAX = 120;

    /**
     * Excel'den yapıştırılmış ya da dosyadan okunmuş metni satır satır çözer.
     * Her satırda 6-12 haneli ilk sayı öğrenci numarasıdır; numaradan sonra gelen ilk
     * yazı hücresi ad soyad kabul edilir. Satırda bu döneme ait bir ders kodu ve o dersin
     * grup adı yazılmışsa onlar kullanılır; yoksa ekranda seçilen ders ve grup.
     *
     * @return array satır listesi: [line, student_no?, name?, course_id?, group_id?, problem?]
     */
    public static function parse($text, $courses, $groups, $defaultCourseId, $defaultGroupId)
    {
        $byCode = [];
        foreach ($courses as $c) {
            $byCode[Util::slug($c->code)] = $c;
        }
        $out = [];
        foreach (preg_split('/\r?\n/', (string) $text) as $i => $raw) {
            $tokens = array_values(array_filter(array_map('trim', preg_split('/[\t;,]/', $raw)), function ($t) {
                return $t !== '';
            }));
            if (count($tokens) === 0) {
                continue;
            }
            $noIdx = -1;
            foreach ($tokens as $k => $t) {
                if (preg_match(self::NO_PATTERN, $t)) {
                    $noIdx = $k;
                    break;
                }
            }
            if ($noIdx < 0) {
                // Başlık satırı gibi hiç rakam içermeyen satırlar sessizce atlanır.
                foreach ($tokens as $t) {
                    if (preg_match('/\d/', $t)) {
                        $out[] = ['line' => $i + 1, 'problem' => 'Geçerli öğrenci numarası bulunamadı (6-12 hane olmalı).'];
                        break;
                    }
                }

                continue;
            }
            $studentNo = $tokens[$noIdx];
            $courseId = $defaultCourseId;
            $groupId = $defaultGroupId;
            $codeIdx = -1;
            $groupIdx = -1;
            foreach ($tokens as $k => $t) {
                if ($k !== $noIdx && isset($byCode[Util::slug($t)])) {
                    $codeIdx = $k;
                    break;
                }
            }
            if ($codeIdx >= 0) {
                $course = $byCode[Util::slug($tokens[$codeIdx])];
                $courseId = $course->id;
                $own = [];
                foreach ($groups as $g) {
                    if ($g->course_id == $course->id) {
                        $own[Util::slug($g->name)] = $g;
                    }
                }
                $named = null;
                foreach ($tokens as $k => $t) {
                    if ($k > $codeIdx && isset($own[Util::slug($t)])) {
                        $groupIdx = $k;
                        $named = $own[Util::slug($t)];
                        break;
                    }
                }
                $groupId = $named ? $named->id : ($defaultCourseId == $course->id ? $defaultGroupId : null);
                if (! $groupId) {
                    $out[] = ['line' => $i + 1, 'student_no' => $studentNo, 'course_id' => $courseId, 'problem' => $course->code.' için satırda geçerli bir grup adı yok.'];

                    continue;
                }
            }
            $name = null;
            foreach ($tokens as $k => $t) {
                if ($k > $noIdx && $k !== $codeIdx && $k !== $groupIdx && preg_match('/\p{L}/u', $t)) {
                    $name = mb_substr(preg_replace('/\s+/u', ' ', $t), 0, self::NAME_MAX);
                    break;
                }
            }
            if (! $courseId || ! $groupId) {
                $out[] = ['line' => $i + 1, 'student_no' => $studentNo, 'problem' => 'Ders ve grup belirtilmemiş.'];

                continue;
            }
            $row = ['line' => $i + 1, 'student_no' => $studentNo, 'course_id' => (int) $courseId, 'group_id' => (int) $groupId];
            if ($name) {
                $row['name'] = $name;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Sınıf listesini (OBS çıktısı, Excel, CSV veya yapıştırılmış metin) okur: ders kodu, ders adı,
     * grup ve öğrenciler. Ders bilgisi "Ders Kodu: ..." gibi başlık satırlarından, "MUH104 - Ders Adı"
     * biçimindeki bir hücreden ya da dosya adından alınır; bulunamazsa boş kalır, hoca formda yazar.
     *
     * @return array{code: string, name: string, group: string, students: array<string, string>, skipped: int}
     */
    public static function readClassList(string $text, string $fileName = ''): array
    {
        $code = '';
        $name = '';
        $group = '';
        $students = [];
        $skipped = 0;
        $splitName = false;
        $codePattern = '/^([A-ZÇĞİÖŞÜ]{2,6})\s?-?(\d{3,4}[A-Z]?)$/u';

        foreach (preg_split('/\r?\n/', $text) as $raw) {
            $tokens = array_values(array_filter(array_map(fn ($t) => trim((string) $t), preg_split('/[\t;,]/', $raw)), fn ($t) => $t !== ''));
            if ($tokens === []) {
                continue;
            }
            $noIdx = null;
            foreach ($tokens as $k => $t) {
                if (preg_match(self::NO_PATTERN, $t)) {
                    $noIdx = $k;
                    break;
                }
            }
            if ($noIdx === null) {
                $line = implode("\t", $tokens);
                if ($code === '' && preg_match('/Ders\s*Kodu\s*:?\s*\t?\s*([^\t]+)/iu', $line, $m)) {
                    $code = trim($m[1]);
                }
                if ($name === '' && preg_match('/Ders\s*Ad[ıiİI]\s*:?\s*\t?\s*([^\t]+)/iu', $line, $m)) {
                    $name = trim($m[1]);
                }
                if ($group === '' && preg_match('/(?:Şube|Grup|Grubu)\s*(?:Adı|No)?\s*:\s*\t?\s*([^\t]+)/iu', $line, $m)) {
                    $group = trim($m[1]);
                }
                foreach ($tokens as $k => $t) {
                    if ($code === '' && preg_match('/^([A-ZÇĞİÖŞÜ]{2,6}\s?-?\d{3,4}[A-Z]?)\s*[-–—:]\s*(.+)$/u', $t, $m)) {
                        $code = $m[1];
                        $name = $name !== '' ? $name : trim($m[2]);
                    } elseif ($code === '' && preg_match($codePattern, $t) && isset($tokens[$k + 1]) && preg_match('/\p{L}{3,}/u', $tokens[$k + 1])) {
                        $code = $t;
                        $name = $name !== '' ? $name : $tokens[$k + 1];
                    }
                }
                // "Adı" ve "Soyadı" ayrı sütunsa ad soyad birleştirilir.
                $lower = array_map(fn ($t) => mb_strtolower($t), $tokens);
                if (array_intersect($lower, ['adı', 'ad', 'adi']) && preg_grep('/^soyad/u', $lower)) {
                    $splitName = true;
                }

                continue;
            }
            $no = $tokens[$noIdx];
            $words = [];
            foreach (array_slice($tokens, $noIdx + 1) as $t) {
                if (! preg_match('/\p{L}/u', $t) || preg_match('/\d/', $t)) {
                    break;
                }
                $words[] = $t;
                if (count($words) === ($splitName ? 2 : 1)) {
                    break;
                }
            }
            if (isset($students[$no])) {
                $skipped++;

                continue;
            }
            $students[$no] = mb_substr(preg_replace('/\s+/u', ' ', implode(' ', $words)), 0, self::NAME_MAX);
        }

        if ($code === '' && preg_match('/([A-ZÇĞİÖŞÜ]{2,6})[\s_-]?(\d{3,4})/u', Util::upperTr(pathinfo($fileName, PATHINFO_FILENAME)), $m)) {
            $code = $m[1].$m[2];
        }
        // "Malzeme Bilimi A Grubu" -> ders adı "Malzeme Bilimi", grup "A"
        if (preg_match('/^(.*\S)\s+([A-ZÇĞİÖŞÜ0-9]{1,2})\s+(?:Grubu|Şubesi)$/u', $name, $m)) {
            $name = $m[1];
            $group = $group !== '' ? $group : $m[2];
        }
        $group = preg_replace('/\s*(Grubu|Şubesi)$/iu', '', $group);

        return [
            'code' => mb_substr(Util::upperTr(preg_replace('/\s+|-/u', '', $code)), 0, 40),
            'name' => mb_substr($name, 0, 200),
            'group' => mb_substr($group !== '' ? $group : 'A', 0, 60),
            'students' => $students,
            'skipped' => $skipped,
        ];
    }

    /** Dönemin üyeleri: [student_no => ['id','name','groups'=>[gid],'courses'=>[cid]]] */
    public static function members(Term $term)
    {
        $out = [];
        foreach (Student::query()->where('term_id', $term->id)->orderBy('student_no')->get() as $s) {
            $out[$s->student_no] = ['id' => $s->id, 'name' => $s->name, 'groups' => [], 'courses' => []];
        }
        $rows = DB::table('sinav_enrollments as e')->join('sinav_students as s', 's.id', '=', 'e.student_id')
            ->where('s.term_id', $term->id)->get(['s.student_no', 'e.group_id', 'e.course_id']);
        foreach ($rows as $r) {
            $out[$r->student_no]['groups'][] = (int) $r->group_id;
            $out[$r->student_no]['courses'][] = (int) $r->course_id;
        }

        return $out;
    }

    /** Önizleme: neler eklenecek, neler atlanacak. */
    public static function plan(array $lines, array $members)
    {
        $state = [];
        foreach ($members as $no => $m) {
            $state[$no] = ['groups' => $m['groups'], 'courses' => $m['courses']];
        }
        $plan = ['new' => [], 'enrollments' => [], 'duplicates' => 0, 'problems' => [], 'names' => [], 'name_updates' => []];
        foreach ($lines as $l) {
            if (! empty($l['problem']) || empty($l['student_no']) || empty($l['course_id']) || empty($l['group_id'])) {
                $plan['problems'][] = $l + ['problem' => 'Eksik bilgi.'];

                continue;
            }
            $no = $l['student_no'];
            if (! empty($l['name']) && ! isset($plan['names'][$no])) {
                $plan['names'][$no] = $l['name'];
            }
            if (! isset($state[$no])) {
                $state[$no] = ['groups' => [], 'courses' => []];
            }
            if (in_array($l['group_id'], $state[$no]['groups'], true)) {
                $plan['duplicates']++;

                continue;
            }
            if (in_array($l['course_id'], $state[$no]['courses'], true)) {
                $plan['problems'][] = $l + ['problem' => 'Bu öğrenci aynı dersin başka bir grubuna kayıtlı.'];

                continue;
            }
            $state[$no]['groups'][] = $l['group_id'];
            $state[$no]['courses'][] = $l['course_id'];
            $plan['enrollments'][] = ['student_no' => $no, 'course_id' => $l['course_id'], 'group_id' => $l['group_id']];
            if (! isset($members[$no]) && ! in_array($no, $plan['new'], true)) {
                $plan['new'][] = $no;
            }
        }
        foreach ($members as $no => $m) {
            if (isset($plan['names'][$no]) && $plan['names'][$no] !== $m['name']) {
                $plan['name_updates'][] = (string) $no;
            }
        }

        return $plan;
    }

    /** Planı uygular (tek işlemde; yarıda kalırsa hiçbiri yazılmaz). */
    public static function apply(Term $term, array $plan)
    {
        Terms::requireEditable($term);
        $result = ['created' => 0, 'enrollments' => 0, 'names' => 0];
        DB::transaction(function () use ($term, $plan, &$result) {
            $ids = Student::query()->where('term_id', $term->id)->pluck('id', 'student_no')->all();
            foreach ($plan['new'] as $no) {
                if (isset($ids[$no])) {
                    continue;
                }
                $s = Student::query()->create(['term_id' => $term->id, 'student_no' => (string) $no, 'name' => $plan['names'][$no] ?? '']);
                $ids[$no] = $s->id;
                $result['created']++;
            }
            $validGroups = Group::query()->where('term_id', $term->id)->pluck('course_id', 'id')->all();
            foreach ($plan['enrollments'] as $e) {
                if (! isset($ids[$e['student_no']]) || ! isset($validGroups[$e['group_id']]) || (int) $validGroups[$e['group_id']] !== (int) $e['course_id']) {
                    continue;
                }
                $exists = Enrollment::query()->where('student_id', $ids[$e['student_no']])->where('course_id', $e['course_id'])->exists();
                if ($exists) {
                    continue;
                }
                Enrollment::query()->create(['student_id' => $ids[$e['student_no']], 'course_id' => $e['course_id'], 'group_id' => $e['group_id']]);
                $result['enrollments']++;
            }
            foreach ($plan['name_updates'] as $no) {
                if (isset($ids[$no])) {
                    Student::query()->where('id', $ids[$no])->update(['name' => $plan['names'][$no]]);
                    $result['names']++;
                }
            }
        });
        Util::log('student.import', 'term', $term->id, 'ok', $result['created'].' yeni öğrenci, '.$result['enrollments'].' üyelik, 0 hata');

        return $result;
    }

    public static function delete(Term $term, array $studentIds)
    {
        Terms::requireEditable($term);
        $n = Student::query()->where('term_id', $term->id)->whereIn('id', $studentIds)->delete(); // üyelikler birlikte silinir
        Util::log('student.delete', 'term', $term->id, 'ok', $n.' öğrenci');

        return $n;
    }

    /** Öğrencileri bir gruptan çıkarır; başka üyeliği kalmayan öğrenci tamamen silinir. */
    public static function removeFromGroup(Term $term, array $studentIds, Group $group)
    {
        Terms::requireEditable($term);
        $removed = 0;
        $deleted = 0;
        DB::transaction(function () use ($term, $studentIds, $group, &$removed, &$deleted) {
            $removed = Enrollment::query()->where('group_id', $group->id)->whereIn('student_id', $studentIds)->delete();
            $orphans = Student::query()->where('term_id', $term->id)->whereIn('id', $studentIds)->whereDoesntHave('enrollments')->pluck('id');
            $deleted = Student::query()->whereIn('id', $orphans)->delete();
        });
        Util::log('student.removeFromGroup', 'group', $group->id, 'ok', $removed.' öğrenci');

        return ['removed' => $removed, 'deleted' => $deleted];
    }

    /** Excel / CSV / metin dosyasını tek metne çevirir. */
    public static function fileToText($path, $originalName)
    {
        $name = strtolower($originalName);
        if (substr($name, -4) === '.xls') {
            throw new UserError('Eski Excel biçimi (.xls) desteklenmiyor. Dosyayı Excel\'de "Farklı Kaydet" ile .xlsx olarak kaydedin.');
        }
        if (substr($name, -5) === '.xlsx') {
            $rows = Xlsx::readFirstSheet($path);

            return implode("\n", array_map(function ($r) {
                return implode("\t", $r);
            }, $rows));
        }
        $text = (string) file_get_contents($path);
        // Excel'in Türkçe CSV'si çoğunlukla Windows-1254 kodlamasıdır.
        if (! mb_check_encoding($text, 'UTF-8')) {
            $converted = function_exists('iconv') ? @iconv('Windows-1254', 'UTF-8//IGNORE', $text) : false;
            $text = $converted !== false ? $converted : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-9');
        }

        return preg_replace('/^\xEF\xBB\xBF/', '', $text);
    }
}
