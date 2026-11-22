<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Başka bir üniversitenin bölüm/program ders listesi.
 */
class CatalogProgram extends Model
{
    protected $guarded = [];

    public const SOURCES = ['komisyon' => 'Komisyon', 'ogrenci' => 'Öğrenci beyanı'];

    protected function casts(): array
    {
        return ['verified' => 'boolean'];
    }

    public function courses(): HasMany
    {
        return $this->hasMany(CatalogCourse::class)->orderBy('semester')->orderBy('code');
    }

    public function getLabelAttribute(): string
    {
        return $this->name.($this->faculty ? ' ('.$this->faculty.')' : '');
    }
}
