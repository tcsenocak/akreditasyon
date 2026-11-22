<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

/**
 * Öğrenci kaydı (öğrenci no + isteğe bağlı ad soyad). Yalnızca yönetici görür;
 * dönem kapanınca silinir. Ad soyad asla işlem geçmişine yazılmaz.
 */
class Student extends Model
{
    protected $table = 'sinav_students';

    protected $guarded = ['id'];

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }
}
