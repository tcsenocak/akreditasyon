<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Program extends Model
{
    protected $guarded = [];

    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }

    public function activeCurriculum(): HasOne
    {
        return $this->hasOne(Curriculum::class)->where('is_active', true)->latestOfMany();
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * Türkçe alfabeye göre sıralı programlar (SQLite Ç/İ harflerini sona atar).
     *
     * @return Collection<int, static>
     */
    public static function sortedTurkish(Collection $programs): Collection
    {
        $collator = new \Collator('tr_TR');

        return $programs->sort(fn (self $a, self $b): int => $collator->compare($a->full_name, $b->full_name) ?: 0)->values();
    }

    public function getFullNameAttribute(): string
    {
        return $this->name.($this->language === 'EN' ? ' (İngilizce)' : '');
    }
}
