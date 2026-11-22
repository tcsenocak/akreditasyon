<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

/** Onaylanan her sürümün değiştirilmeyen kaydı. */
class KeyVersion extends Model
{
    protected $table = 'sinav_key_versions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = ['files' => 'array', 'created_at' => 'datetime'];
}
