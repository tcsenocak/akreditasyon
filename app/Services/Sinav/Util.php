<?php

namespace App\Services\Sinav;

use App\Models\Sinav\AuditLog;
use Carbon\Carbon;

class Util
{
    const TR_MAP = ['ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g', 'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c'];

    /** Karşılaştırma için güvenli kısa ad: "MAT 101" -> "mat-101", "FİZ101" -> "fiz101". */
    public static function slug($text)
    {
        $t = strtr(trim((string) $text), self::TR_MAP);
        $t = strtolower($t);
        $t = preg_replace('/[^a-z0-9]+/', '-', $t);

        return trim($t, '-');
    }

    /** Türkçe büyük harf ("i" -> "İ"). */
    public static function upperTr($text)
    {
        return mb_strtoupper(strtr((string) $text, ['i' => 'İ', 'ı' => 'I']), 'UTF-8');
    }

    public static function lowerTr($text)
    {
        return mb_strtolower(strtr((string) $text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
    }

    // ---------- Tarih ----------
    // Veritabanında uygulamanın saat dilimiyle (çoğunlukla UTC) saklanır; ekranda Türkiye saati gösterilir.

    public static function tz()
    {
        return config('sinav.timezone', 'Europe/Istanbul');
    }

    /** "2026-11-18" + "10:30" (Türkiye saati) -> uygulama saat dilimindeki Carbon. */
    public static function fromInput($date, $time = '00:00')
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || ! preg_match('/^\d{2}:\d{2}$/', (string) $time)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, self::tz())->setTimezone(config('app.timezone'));
    }

    /** Ekranda gösterim: "18.11.2026 10:30" (Türkiye saati). */
    public static function fmt($dt, $withTime = true)
    {
        if (! $dt) {
            return '—';
        }

        return $dt->copy()->setTimezone(self::tz())->format($withTime ? 'd.m.Y H:i' : 'd.m.Y');
    }

    /** Form alanına koymak için: tarih "Y-m-d" veya saat "H:i" (Türkiye saati). */
    public static function input($dt, $part = 'date')
    {
        if (! $dt) {
            return '';
        }

        return $dt->copy()->setTimezone(self::tz())->format($part === 'date' ? 'Y-m-d' : 'H:i');
    }

    public static function formatSize($bytes)
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / 1024 / 1024, 1, ',', '').' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }

    /** Dosya türünü uzantıya değil, ilk baytlara bakarak belirler. */
    public static function sniffMime($head)
    {
        if (strncmp($head, '%PDF', 4) === 0) {
            return 'application/pdf';
        }
        if (strncmp($head, "\x89PNG", 4) === 0) {
            return 'image/png';
        }
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }

        return null;
    }

    /** Dosya adı için güvenli parça: "MUH104 Malzeme Bilimi" -> "MUH104_Malzeme_Bilimi". */
    public static function safeFileName($text)
    {
        $t = preg_replace('/[\\\\\/:*?"<>|]+/u', '', (string) $text);
        $t = preg_replace('/\s+/u', '_', $t);

        return mb_substr($t, 0, 120);
    }

    // ---------- İşlem geçmişi ----------

    public static function adminName()
    {
        $u = auth(config('sinav.guard'))->user();

        return $u ? (string) ($u->email ?? $u->getAuthIdentifier()) : '';
    }

    /** İşlem geçmişine kayıt. Not alanına öğrenci numarası veya adı YAZILMAZ. */
    public static function log($action, $objectType, $objectId, $result = 'ok', $note = '')
    {
        AuditLog::query()->create([
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => (string) $objectId,
            'result' => $result,
            'note' => mb_substr((string) $note, 0, 300),
            'admin' => self::adminName(),
            'created_at' => now(),
        ]);
    }
}
