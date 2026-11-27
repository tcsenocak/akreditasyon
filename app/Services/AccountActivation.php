<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\BasvuruBildirimi;
use Illuminate\Support\Str;
use Throwable;

/**
 * Öğrenci hesabının etkinleştirme bağlantısı: öğrenci bağlantıdan kendi parolasını belirler,
 * komisyon parola iletmez. E-posta sunucusu tanımlıysa bağlantı öğrenciye doğrudan gönderilir.
 */
class AccountActivation
{
    public const VALID_DAYS = 7;

    /**
     * Yeni bağlantı üretir (eskisi geçersiz olur) ve mümkünse e-postayla gönderir.
     *
     * @return array{email: string, url: string, sent: bool}
     */
    public static function issue(User $user): array
    {
        $token = Str::random(48);
        $user->forceFill([
            'activation_token' => hash('sha256', $token),
            'activation_expires_at' => now()->addDays(self::VALID_DAYS),
        ])->save();
        $url = route('activation.show', $token);

        return ['email' => $user->email, 'url' => $url, 'sent' => self::mail($user, $url)];
    }

    public static function find(string $token): ?User
    {
        return User::query()->where('activation_token', hash('sha256', $token))
            ->where('activation_expires_at', '>', now())->first();
    }

    private static function mail(User $user, string $url): bool
    {
        if (! Notifier::enabled()) {
            return false;
        }
        try {
            $user->notify(new BasvuruBildirimi('Hesabınızı etkinleştirin', [
                'Muafiyet ve intibak başvurunuz için adınıza bir hesap açıldı.',
                'Aşağıdaki düğmeyle hesabınızı etkinleştirip kendi parolanızı belirleyin. Bağlantı '.self::VALID_DAYS.' gün geçerlidir.',
                'Giriş e-postanız: '.$user->email,
            ], $url, 'Hesabımı etkinleştir'));

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
