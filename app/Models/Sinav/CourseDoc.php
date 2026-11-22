<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

/** Ders evrakı: yalnızca başlık ve bağlantı (dosya Google Drive vb. yerde durur). */
class CourseDoc extends Model
{
    protected $table = 'sinav_course_docs';

    protected $guarded = ['id'];

    public function isDrive()
    {
        $host = parse_url($this->url, PHP_URL_HOST) ?: '';

        return (bool) preg_match('/(^|\.)(drive|docs)\.google\.com$/', $host);
    }
}
