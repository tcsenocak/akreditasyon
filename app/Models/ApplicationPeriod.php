<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Başvuru takvimi: belirli türde başvurunun alındığı tarih aralığı (ör. dönem başlamadan iki hafta önce).
 */
class ApplicationPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function isCurrent(): bool
    {
        $today = now()->startOfDay();

        return $this->starts_on->lte($today) && $this->ends_on->gte($today);
    }
}
