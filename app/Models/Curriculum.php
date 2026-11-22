<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Curriculum extends Model
{
    protected $table = 'curricula';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'total_credit' => 'float', 'total_ects' => 'float'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(CurriculumCourse::class);
    }

    /**
     * Yarıyıla bağlı öğretim planı dersleri (seçmeli havuzları hariç).
     */
    public function planCourses(): HasMany
    {
        return $this->hasMany(CurriculumCourse::class)->where('category', 'plan')->orderBy('semester')->orderBy('id');
    }

    public function getLabelAttribute(): string
    {
        return ($this->program?->full_name ?? '').' — '.$this->title;
    }
}
