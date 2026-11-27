<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptCourse extends Model
{
    protected $guarded = [];

    /**
     * Yeni kayıt, öğrencinin güncel başvurusuna bağlanır.
     */
    protected static function booted(): void
    {
        static::creating(function (TranscriptCourse $model): void {
            $model->application_id ??= Student::query()->whereKey($model->student_id)->value('current_application_id');
        });
    }

    protected function casts(): array
    {
        return ['passed' => 'boolean', 'credit' => 'float', 'ects' => 'float'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ExemptionApplication::class, 'application_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function requestedCourse(): BelongsTo
    {
        return $this->belongsTo(CurriculumCourse::class, 'requested_course_id');
    }

    public function getLabelAttribute(): string
    {
        return trim(($this->code ? $this->code.' ' : '').$this->name);
    }
}
