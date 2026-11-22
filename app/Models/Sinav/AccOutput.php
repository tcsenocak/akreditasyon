<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

/** Oluşturulup sistemde saklanan birleşik ders dosyası PDF'i. */
class AccOutput extends Model
{
    protected $table = 'sinav_acc_outputs';

    protected $guarded = ['id'];
}
