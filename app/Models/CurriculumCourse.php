<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumCourse extends Model
{
    protected $guarded = [];

    public const TYPES = ['OZ' => 'Ortak Zorunlu', 'Z' => 'Zorunlu', 'S' => 'Seçmeli', 'ÜS' => 'Üniversite Seçmeli'];

    public const CATEGORIES = [
        'plan' => 'Öğretim planı',
        'elective_pool' => 'Bölüm seçmeli havuzu',
        'faculty_elective' => 'Fakülte seçmeli havuzu',
        'university_elective' => 'Üniversite seçmeli havuzu',
        'alternative' => 'Alternatif (İşletmede Mesleki Eğitim)',
        'preparatory' => 'Hazırlık / yarıyıl dışı',
    ];

    public const COMMON_GROUPS = [
        'AIIT' => 'Atatürk İlkeleri ve İnkılap Tarihi',
        'TD' => 'Türk Dili',
        'YD' => 'Yabancı Dil (ortak zorunlu)',
        'ISG' => 'İş Sağlığı ve Güvenliği',
        'MYD' => 'Mesleki Yabancı Dil',
        'BIL' => 'Temel bilgisayar (muafiyet sınavı)',
    ];

    protected function casts(): array
    {
        return [
            'is_placeholder' => 'boolean',
            'credit' => 'float',
            'ects' => 'float',
            'theory' => 'float',
            'practice' => 'float',
            'lab' => 'float',
        ];
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function info(): BelongsTo
    {
        return $this->belongsTo(CourseInfo::class, 'obs_course_id', 'obs_course_id');
    }

    /**
     * Ders içeriği: OBS bilgisi varsa o, yoksa içe aktarmada gelen içerik.
     */
    public function contentText(): string
    {
        return $this->info?->full_text ?: (string) $this->content;
    }

    public function getLabelAttribute(): string
    {
        return trim(($this->code ? $this->code.' ' : '').$this->name);
    }

    public function isElective(): bool
    {
        return in_array($this->type, ['S', 'ÜS'], true);
    }
}
