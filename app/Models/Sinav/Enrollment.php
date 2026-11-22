<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

class Enrollment extends Model
{
    protected $table = 'sinav_enrollments';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}
