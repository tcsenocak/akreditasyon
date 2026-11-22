<?php

namespace App\Services\Sinav;

use App\Models\Sinav\Course;
use App\Models\Sinav\CourseDoc;
use App\Models\Sinav\Exam;
use App\Models\Sinav\Group;
use App\Models\Sinav\Release;
use App\Models\Sinav\Term;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Dönem, ders, grup ve ders evrakı işlemleri. */
class Terms
{
    // ---------- Dönem (arka planda tek kayıt) ----------

    /**
     * Sınav modülü dönemsiz çalışır. Tablolar dönem kimliği beklediği için arka planda tek bir
     * "Genel" kayıt tutulur; ekranlarda dönem seçimi yoktur. Kayıt yoksa ilk istekte açılır.
     */
    public static function current(): Term
    {
        $term = Term::query()->where('status', 'active')->orderBy('id')->first();
        if ($term) {
            return $term;
        }

        return Term::query()->create([
            'name' => 'Genel',
            'academic_year' => '',
            'status' => 'active',
            'starts_at' => now()->startOfDay(),
            'ends_at' => Term::OPEN_UNTIL,
            'access_ends_at' => Term::OPEN_UNTIL,
        ]);
    }

    /** Öğrenci tarafı için: modülün tek kaydı. */
    public static function activeTerm(): Term
    {
        return self::current();
    }

    // ---------- Dersler ----------

    public static function createCourse(Term $term, array $in)
    {
        self::requireEditable($term);
        $code = Util::upperTr(trim((string) ($in['code'] ?? '')));
        $slug = Util::slug($code);
        if ($slug === '') {
            throw new UserError('Ders kodu en az bir harf veya rakam içermeli.');
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new UserError('Ders adı boş olamaz.');
        }
        if (Course::query()->where('term_id', $term->id)->where('code_slug', $slug)->exists()) {
            throw new UserError('"'.$code.'" kodlu ders zaten var.');
        }
        $course = Course::query()->create([
            'term_id' => $term->id,
            'code' => mb_substr($code, 0, 40),
            'code_slug' => $slug,
            'name' => mb_substr($name, 0, 200),
            'description' => mb_substr((string) ($in['description'] ?? ''), 0, 2000),
            'instructor' => mb_substr(trim((string) ($in['instructor'] ?? '')), 0, 200),
            'instructor_user_id' => self::instructorId($in),
            'active' => ! empty($in['active']),
        ]);
        Util::log('course.create', 'course', $course->id);

        return $course;
    }

    /** Formdan gelen sorumlu öğretim elemanı; yalnızca öğretim elemanı hesabı seçilebilir. */
    private static function instructorId(array $in): ?int
    {
        $id = (int) ($in['instructor_user_id'] ?? 0);
        if ($id === 0) {
            return null;
        }
        if (! User::query()->whereKey($id)->where('role', 'instructor')->exists()) {
            throw new UserError('Seçilen sorumlu öğretim elemanı bulunamadı.');
        }

        return $id;
    }

    /** Ders kodu sonradan değiştirilemez; diğer alanlar güncellenir. */
    public static function updateCourse(Course $course, array $in)
    {
        self::requireEditable($course->term);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new UserError('Ders adı boş olamaz.');
        }
        $course->update([
            'name' => mb_substr($name, 0, 200),
            'description' => mb_substr((string) ($in['description'] ?? ''), 0, 2000),
            'instructor' => mb_substr(trim((string) ($in['instructor'] ?? '')), 0, 200),
            'instructor_user_id' => self::instructorId($in),
            'active' => ! empty($in['active']),
        ]);
        Util::log('course.update', 'course', $course->id);
    }

    /** Dersi; gruplarını ve evrak bağlantılarını da silerek kaldırır. */
    public static function deleteCourse(Course $course)
    {
        self::requireEditable($course->term);
        if (Exam::query()->where('course_id', $course->id)->exists()) {
            throw new UserError('Bu derse bağlı sınavlar var. Önce "Sınavlar" bölümünden o sınavları silin.');
        }
        $groupIds = Group::query()->where('course_id', $course->id)->pluck('id');
        if (DB::table('sinav_enrollments')->whereIn('group_id', $groupIds)->exists()) {
            throw new UserError('Bu dersin gruplarında öğrenci kayıtlı. Önce "Öğrenciler" bölümünden öğrencileri gruptan çıkarın.');
        }
        $n = count($groupIds);
        $id = $course->id;
        DB::transaction(function () use ($course) {
            Accreditation::deleteAllForCourse($course);
            $course->delete(); // gruplar ve evrak bağlantıları birlikte silinir
        });
        Util::log('course.delete', 'course', $id, 'ok', $n.' grup ile birlikte');
    }

    // ---------- Gruplar ----------

    public static function createGroup(Course $course, $rawName)
    {
        self::requireEditable($course->term);
        $name = trim((string) $rawName);
        $slug = Util::slug($name);
        if ($slug === '') {
            throw new UserError('Grup adı en az bir harf veya rakam içermeli.');
        }
        if (Group::query()->where('course_id', $course->id)->where('name_slug', $slug)->exists()) {
            throw new UserError('"'.$name.'" grubu bu derste zaten var.');
        }
        $g = Group::query()->create(['term_id' => $course->term_id, 'course_id' => $course->id, 'name' => mb_substr($name, 0, 60), 'name_slug' => $slug]);
        Util::log('group.create', 'group', $g->id);

        return $g;
    }

    public static function deleteGroup(Group $group)
    {
        self::requireEditable($group->course->term);
        if (Release::query()->where('group_id', $group->id)->exists()) {
            throw new UserError('Bu grup bir sınava atanmış. Önce sınavdan bu grubu çıkarın.');
        }
        if (DB::table('sinav_enrollments')->where('group_id', $group->id)->exists()) {
            throw new UserError('Bu grupta öğrenci kayıtlı. Önce "Öğrenciler" bölümünden öğrencileri gruptan çıkarın.');
        }
        $id = $group->id;
        $group->delete();
        Util::log('group.delete', 'group', $id);
    }

    // ---------- Grup PIN'i ----------

    /** Hocanın gruba verdiği PIN: 4-8 rakam. Boş bırakılırsa 6 haneli rastgele üretilir. */
    public static function setGroupPin(Group $group, ?string $rawPin): string
    {
        self::requireEditable($group->course->term);
        $pin = preg_replace('/\s+/', '', (string) $rawPin);
        if ($pin === '') {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        }
        if (! preg_match('/^\d{4,8}$/', $pin)) {
            throw new UserError('PIN 4-8 rakamdan oluşmalı.');
        }
        if (preg_match('/^(\d)\1+$/', $pin) || str_contains('0123456789', $pin) || str_contains('9876543210', $pin)) {
            throw new UserError('PIN tahmin edilmesi kolay olmamalı (1111, 1234 gibi). Başka bir PIN seçin.');
        }
        $group->forceFill([
            'pin_encrypted' => Crypt::encryptString($pin),
            'pin_version' => $group->pin_version + 1,
            'pin_updated_at' => now(),
        ])->save();
        Util::log('group.pin', 'group', $group->id);

        return $pin;
    }

    // ---------- Ders evrakı (bağlantı) ----------

    /** Yalnızca https bağlantı kabul edilir. */
    public static function normalizeDocUrl($raw)
    {
        $url = trim((string) $raw);
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new UserError('Geçerli bir bağlantı yapıştırın (https:// ile başlamalı).');
        }
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new UserError('Bağlantı https:// ile başlamalı.');
        }
        if (strlen($url) > 2000) {
            throw new UserError('Bağlantı çok uzun.');
        }

        return $url;
    }

    public static function addDoc(Course $course, $title, $url)
    {
        $t = trim((string) $title);
        if ($t === '') {
            throw new UserError('Başlık boş olamaz.');
        }
        if (mb_strlen($t) > 150) {
            throw new UserError('Başlık en fazla 150 karakter olabilir.');
        }
        $sort = (int) CourseDoc::query()->where('course_id', $course->id)->max('sort') + 1;
        CourseDoc::query()->create(['course_id' => $course->id, 'title' => $t, 'url' => self::normalizeDocUrl($url), 'sort' => $sort]);
        Util::log('courseDoc.add', 'course', $course->id);
    }

    public static function deleteDoc(CourseDoc $doc)
    {
        $cid = $doc->course_id;
        $doc->delete();
        Util::log('courseDoc.delete', 'course', $cid);
    }

    public static function requireEditable($term)
    {
        if (! $term || ! $term->editable()) {
            throw new UserError('Bu dönem kapalı olduğu için değiştirilemez.');
        }
    }
}
