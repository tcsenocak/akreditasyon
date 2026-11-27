<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ExemptionItem extends Model
{
    protected $guarded = [];

    public const RULES = [
        'ogrenci' => 'Öğrenci eşleştirmesi (Md. 5/2)',
        'havuz' => 'Eşdeğerlik havuzu (önceki komisyon kararı)',
        'ayni_ders' => 'Aynı kod veya ad',
        'ortak_zorunlu' => 'Ortak zorunlu ders, AKTS/içerik aranmaz (Md. 6/5)',
        'esdeger' => 'Ad/içerik ve AKTS uyumu (Md. 4/ç)',
        'yds' => 'YDS ≥ 50 veya eşdeğeri (Md. 5/8)',
        'hazirlik' => 'Yabancı dil hazırlık başarısı (Md. 6/7)',
        'muafiyet_sinavi' => 'Muafiyet sınavı ≥ 50 (Md. 6/6)',
        'manuel' => 'Komisyon tarafından elle',
    ];

    public const STATUSES = ['oneri' => 'Öneri', 'kabul' => 'Kabul', 'ret' => 'Ret'];

    /** Ret gerekçeleri; öğrenciye sonuçla birlikte gösterilir. */
    public const REJECT_REASONS = [
        'icerik' => 'Ders içerikleri yeterince örtüşmüyor',
        'akts' => 'AKTS ve kredi yetersiz',
        'basarisiz' => 'Ders başarısız ya da notu muafiyete esas alınamıyor',
        'tr_en' => 'Türkçe programdan İngilizce programın alan derslerinden muafiyet istenemez',
        'onceki' => 'Bu ders için daha önce başvuru yapılmış',
        'yaz_akts' => 'Yaz okulu AKTS sınırı aşılıyor',
        'belge' => 'Belge eksik veya onaysız',
        'diger' => 'Diğer (komisyon notuna bakınız)',
    ];

    protected function casts(): array
    {
        return ['ects_ok' => 'boolean', 'score' => 'float', 'exam_score' => 'float', 'content_score' => 'float'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ExemptionFile::class, 'exemption_file_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CurriculumCourse::class, 'curriculum_course_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(TranscriptCourse::class, 'exemption_item_sources');
    }
}
