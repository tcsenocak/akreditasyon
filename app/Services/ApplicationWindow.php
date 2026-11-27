<?php

namespace App\Services;

use App\Models\ApplicationPeriod;
use App\Models\AppSetting;

/**
 * Başvuru alımının açık olup olmadığı: sürekli açık, kapalı ya da yalnız takvimdeki tarih aralıklarında.
 */
class ApplicationWindow
{
    public const MODES = [
        'surekli' => 'Sürekli açık (yıl boyunca)',
        'takvim' => 'Yalnız başvuru takvimindeki tarihlerde',
        'kapali' => 'Kapalı',
    ];

    public static function mode(): string
    {
        $mode = AppSetting::get('application_mode', 'surekli');

        return array_key_exists($mode, self::MODES) ? $mode : 'surekli';
    }

    public static function isOpen(string $type): bool
    {
        return match (self::mode()) {
            'surekli' => true,
            'kapali' => false,
            default => self::currentPeriod($type) !== null,
        };
    }

    public static function currentPeriod(string $type): ?ApplicationPeriod
    {
        $today = now()->toDateString();

        return ApplicationPeriod::query()->where('type', $type)
            ->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)
            ->orderBy('ends_on')->first();
    }

    public static function nextPeriod(string $type): ?ApplicationPeriod
    {
        return ApplicationPeriod::query()->where('type', $type)
            ->whereDate('starts_on', '>', now()->toDateString())->orderBy('starts_on')->first();
    }

    /**
     * Öğrenciye gösterilecek açıklama (ör. "Başvurular 01.02.2027 – 14.02.2027 arasında alınır").
     */
    public static function message(string $type): string
    {
        if (self::mode() === 'surekli') {
            return 'Başvuru alımı açık.';
        }
        if (self::mode() === 'kapali') {
            return 'Başvuru alımı şu anda kapalı.';
        }
        if ($period = self::currentPeriod($type)) {
            return 'Başvuru alımı açık: '.$period->name.', son gün '.$period->ends_on->format('d.m.Y').'.';
        }
        $next = self::nextPeriod($type);

        return $next
            ? 'Başvuru alımı kapalı. Sonraki dönem: '.$next->name.' ('.$next->starts_on->format('d.m.Y').' – '.$next->ends_on->format('d.m.Y').').'
            : 'Başvuru alımı kapalı. Komisyon yeni başvuru takvimini henüz ilan etmedi.';
    }
}
