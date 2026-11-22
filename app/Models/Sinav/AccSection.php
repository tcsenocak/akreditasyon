<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

class AccSection extends Model
{
    protected $table = 'sinav_acc_sections';

    protected $guarded = ['id'];

    public $timestamps = false;

    /** Sabit bölümler (sıra numarası akreditasyon dosyasındaki sıradır). */
    const DEFAULTS = [
        ['kind' => 'izlence', 'title' => 'Ders İzlencesi', 'hint' => 'Ders tanımı, öğrenme çıktıları, haftalık konular, değerlendirme.', 'exam_type' => null],
        ['kind' => 'vize', 'title' => 'Vize Sınavı ve Cevapları', 'hint' => 'Soru kitapçığı ve cevap anahtarı.', 'exam_type' => 'vize'],
        ['kind' => 'final', 'title' => 'Final Sınavı ve Cevapları', 'hint' => 'Soru kitapçığı ve cevap anahtarı.', 'exam_type' => 'final'],
        ['kind' => 'butunleme', 'title' => 'Bütünleme Sınavı ve Cevapları', 'hint' => 'Soru kitapçığı ve cevap anahtarı.', 'exam_type' => 'butunleme'],
        ['kind' => 'matris', 'title' => 'Öğrenme Çıktısı – Program Çıktısı Matrisi', 'hint' => 'ÖÇ–PÇ ilişki matrisi.', 'exam_type' => null],
        ['kind' => 'icmal', 'title' => 'Öğrenci Ders İcmalleri', 'hint' => 'Not listesi / icmal (öğrenci adı ve notu içerir).', 'exam_type' => null],
        ['kind' => 'pcRapor', 'title' => 'Öğrenci Bazlı Program Çıktısı Raporu', 'hint' => 'Excel (.xlsx) yüklerseniz tabloya çevrilir.', 'exam_type' => null],
    ];

    public function definition()
    {
        foreach (self::DEFAULTS as $d) {
            if ($d['kind'] === $this->kind) {
                return $d;
            }
        }

        return null;
    }

    public function examType()
    {
        $d = $this->definition();

        return $d ? $d['exam_type'] : null;
    }
}
