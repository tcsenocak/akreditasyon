<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kim, ne zaman, ne yaptı: karar, durum değişikliği, silme, itiraz vb.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public const ACTIONS = [
        'gonderim' => 'Başvuru gönderildi',
        'durum' => 'Başvuru durumu değişti',
        'geri_acma' => 'Düzeltme için geri açıldı',
        'dosya' => 'Değerlendirme dosyası',
        'karar' => 'Satır kararları',
        'kesin_karar' => 'Kesin karar',
        'hazirlik' => 'Hazırlık kararı',
        'atama' => 'Dosya ataması',
        'itiraz' => 'İtiraz',
        'yaz_notu' => 'Yaz okulu notları',
        'yeni_basvuru' => 'Yeni başvuru',
        'silme' => 'Silme',
        'kullanici' => 'Kullanıcı işlemi',
        'hesap' => 'Öğrenci hesabı',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * İşlemi kaydeder; işlemi yapan oturumdaki kullanıcıdır.
     */
    public static function record(string $action, string $description, ?Student $student = null): void
    {
        static::query()->create([
            'user_id' => auth()->id(),
            'student_id' => $student?->id,
            'action' => $action,
            'description' => mb_substr($description, 0, 500),
        ]);
    }
}
