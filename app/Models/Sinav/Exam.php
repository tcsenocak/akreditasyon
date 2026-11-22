<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    protected $table = 'sinav_exams';

    protected $guarded = ['id'];

    const TYPES = [
        'vize' => 'Vize',
        'final' => 'Final',
        'butunleme' => 'Bütünleme',
        'mazeret' => 'Mazeret',
        'kisa' => 'Kısa sınav',
        'deneme' => 'Deneme sınavı',
        'diger' => 'Diğer',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function files()
    {
        return $this->hasMany(ExamFile::class, 'exam_id')->orderBy('name');
    }

    public function releases()
    {
        return $this->hasMany(Release::class, 'exam_id');
    }

    public function versions()
    {
        return $this->hasMany(KeyVersion::class, 'exam_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function typeLabel()
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
