<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogCourse extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['credit' => 'float', 'ects' => 'float'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(CatalogProgram::class, 'catalog_program_id');
    }
}
