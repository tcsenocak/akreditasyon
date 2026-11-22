<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExemptionFile extends Model
{
    protected $guarded = [];

    public const STATUSES = ['taslak' => 'Taslak', 'komisyon' => 'Komisyonda', 'karar' => 'Yönetim kurulu kararı'];

    protected function casts(): array
    {
        return ['options' => 'array', 'decision_date' => 'date', 'exempt_ects' => 'float'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExemptionItem::class);
    }

    public function acceptedItems(): HasMany
    {
        return $this->hasMany(ExemptionItem::class)->where('status', 'kabul');
    }

    public function getEffectiveSemesterAttribute(): ?int
    {
        return $this->placed_semester_override ?: $this->placed_semester;
    }
}
