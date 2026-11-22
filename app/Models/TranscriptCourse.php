<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptCourse extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['passed' => 'boolean', 'credit' => 'float', 'ects' => 'float'];
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
