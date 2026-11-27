<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use App\Notifications\BasvuruBildirimi;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Başvuru bildirimleri. E-posta sunucusu tanımlı değilken (MAIL_MAILER=log) gönderilmez;
 * böylece öğrenci adları ve e-postaları kayıt dosyasına düşmez.
 */
class Notifier
{
    public static function enabled(): bool
    {
        return config('mail.default') !== 'log';
    }

    /**
     * @param  list<string>  $lines
     */
    public static function student(Student $student, string $subject, array $lines): void
    {
        $user = $student->user;
        if ($user) {
            self::send([$user], new BasvuruBildirimi($subject, $lines, route('portal.home')));
        }
    }

    /**
     * Komisyona bildirim: dosya bir üyeye atanmışsa ona, değilse tüm komisyona.
     *
     * @param  list<string>  $lines
     */
    public static function commission(Student $student, string $subject, array $lines, ?User $assignee = null): void
    {
        $recipients = $assignee ? [$assignee] : User::query()->whereIn('role', array_keys(User::COMMISSION_ROLES))->get()->all();
        self::send($recipients, new BasvuruBildirimi($subject, $lines, route('applications.index'), 'Başvuruları aç'));
    }

    /**
     * @param  array<int, User>  $recipients
     */
    private static function send(array $recipients, BasvuruBildirimi $notification): void
    {
        if (! self::enabled() || $recipients === []) {
            return;
        }
        try {
            Notification::send($recipients, $notification);
        } catch (Throwable) {
            // E-posta sunucusuna ulaşılamazsa işlem yarıda kalmasın; bildirim atlanır.
        }
    }
}
