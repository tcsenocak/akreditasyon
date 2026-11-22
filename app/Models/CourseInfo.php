<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * OBS Ders Bilgi Paketi'nden alınan ders içeriği.
 */
class CourseInfo extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['weekly_topics' => 'array', 'outcomes' => 'array', 'fetched_at' => 'datetime'];
    }

    /**
     * Karşılaştırma için tek parça metin: tanım + amaç + haftalık konular.
     */
    public function getFullTextAttribute(): string
    {
        return trim(implode("\n", array_filter([
            $this->description,
            $this->objective,
            implode("\n", $this->weekly_topics ?? []),
        ])));
    }
}
