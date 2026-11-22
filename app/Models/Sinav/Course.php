<?php

namespace App\Models\Sinav;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $table = 'sinav_courses';

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean'];

    /** Dersin sorumlu öğretim elemanı (users.role = instructor). */
    public function instructorUser()
    {
        return $this->belongsTo(User::class, 'instructor_user_id');
    }

    public function term()
    {
        return $this->belongsTo(Term::class, 'term_id');
    }

    public function groups()
    {
        return $this->hasMany(Group::class, 'course_id')->orderBy('name');
    }

    public function docs()
    {
        return $this->hasMany(CourseDoc::class, 'course_id')->orderBy('sort')->orderBy('title');
    }

    public function label()
    {
        return $this->code.' — '.$this->name;
    }
}
