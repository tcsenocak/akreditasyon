<?php

namespace App\Services\Sinav;

use App\Models\Sinav\Course;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Sınav yönetim paneline kimin, hangi derslerle girebileceği.
 *
 * - Komisyon (config sinav.admin_gate veya SINAV_ADMIN_EMAILS): tüm dersler ve dönem yönetimi.
 * - Öğretim elemanı (users.role = instructor): yalnızca kendisine atanmış dersler.
 */
class Access
{
    public static function user(): ?Authenticatable
    {
        return auth(config('sinav.guard'))->user();
    }

    public static function isCommission(?Authenticatable $user = null): bool
    {
        $user ??= self::user();
        if (! $user) {
            return false;
        }
        $gate = (string) config('sinav.admin_gate', '');
        if ($gate !== '') {
            return Gate::forUser($user)->allows($gate);
        }
        $email = mb_strtolower(trim((string) ($user->email ?? '')));

        return $email !== '' && in_array($email, config('sinav.admin_emails', []), true);
    }

    public static function isInstructor(?Authenticatable $user = null): bool
    {
        $user ??= self::user();

        return $user !== null && ($user->role ?? null) === 'instructor';
    }

    /**
     * Yönetilebilen ders kimlikleri; null = hepsi (komisyon).
     *
     * @return array<int, int>|null
     */
    public static function courseIds(): ?array
    {
        $attributes = request()->attributes;
        if ($attributes->has('sinav.course_ids')) {
            return $attributes->get('sinav.course_ids');
        }
        $user = self::user();
        $ids = match (true) {
            self::isCommission($user) => null,
            $user === null || ! self::isInstructor($user) => [],
            default => Course::query()->where('instructor_user_id', $user->getAuthIdentifier())->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        };
        $attributes->set('sinav.course_ids', $ids);

        return $ids;
    }

    /** Yeni ders açıldığında önbellekteki ders listesini temizler. */
    public static function forget(): void
    {
        request()->attributes->remove('sinav.course_ids');
    }

    public static function canCourse(int|string|null $courseId): bool
    {
        $ids = self::courseIds();

        return $ids === null || in_array((int) $courseId, $ids, true);
    }

    /** Ders bu kullanıcının değilse 403. */
    public static function authorizeCourse(int|string|null $courseId): void
    {
        abort_unless(self::canCourse($courseId), 403, 'Bu ders size atanmamış.');
    }

    public static function authorizeCommission(): void
    {
        abort_unless(self::isCommission(), 403, 'Bu işlem yalnızca komisyon üyelerine açıktır.');
    }
}
