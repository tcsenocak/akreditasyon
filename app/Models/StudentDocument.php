<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDocument extends Model
{
    protected $guarded = [];

    /**
     * Yeni kayıt, öğrencinin güncel başvurusuna bağlanır.
     */
    protected static function booted(): void
    {
        static::creating(function (StudentDocument $model): void {
            $model->application_id ??= Student::query()->whereKey($model->student_id)->value('current_application_id');
        });
    }

    /** Muafiyet Esasları Md. 5/2: dilekçe, not döküm belgesi ve onaylı ders içerikleri */
    public const TYPES = [
        'dilekce' => 'Muafiyet dilekçesi',
        'transkript' => 'Not döküm belgesi (transkript)',
        'icerik' => 'Onaylı ders içerikleri',
        'dil' => 'Yabancı dil sınav sonuç belgesi (YDS vb.)',
        'hazirlik' => 'Hazırlık sınıfı başarı belgesi',
        'diger' => 'Diğer',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(ExemptionApplication::class, 'application_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function getSizeLabelAttribute(): string
    {
        return $this->size >= 1048576 ? number_format($this->size / 1048576, 1, ',', '').' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }
}
