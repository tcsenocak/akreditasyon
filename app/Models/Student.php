<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    protected $guarded = [];

    public const ADMISSION_TYPES = [
        'yatay' => 'Yatay geçiş',
        'dikey' => 'Dikey geçiş',
        'af' => 'Af kanunu',
        'yeniden' => 'Yeniden kayıt (önceki öğrenim/mezuniyet)',
        'es_zamanli' => 'Eş zamanlı başka kurumda alınan ders',
        'diger' => 'Diğer benzer durum',
    ];

    public const APPLICATION_STATUSES = [
        'taslak' => 'Hazırlanıyor',
        'gonderildi' => 'Gönderildi',
        'inceleniyor' => 'Komisyonda',
        'sonuclandi' => 'Sonuçlandı',
    ];

    protected function casts(): array
    {
        return ['prep_completed' => 'boolean', 'yds_score' => 'float', 'submitted_at' => 'datetime'];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class)->latest();
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * Öğrenci başvurusunu yalnız gönderilmeden önce düzenleyebilir.
     */
    public function isEditableByStudent(): bool
    {
        return $this->application_status === 'taslak';
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function transcript(): HasMany
    {
        return $this->hasMany(TranscriptCourse::class)->orderBy('semester')->orderBy('id');
    }

    public function exemptionFiles(): HasMany
    {
        return $this->hasMany(ExemptionFile::class)->latest();
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name.' '.$this->last_name;
    }
}
