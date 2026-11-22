<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CourseMapping extends Model
{
    protected $guarded = [];

    public const CHANGE_TYPES = [
        'ayni' => 'Aynı ders (kök kod/ad, sistem)',
        'isim' => 'Ders adı değişti (Md. 7/8)',
        'yariyil' => 'Yarıyılı değişti (Md. 7/5)',
        'zorunlu_secmeli' => 'Zorunlu → seçmeli (Md. 7/6)',
        'secmeli_zorunlu' => 'Seçmeli → zorunlu (Md. 7/7)',
        'birlesme' => 'Dersler birleştirildi (Md. 7/3)',
        'bolunme' => 'Ders bölündü (Md. 7/4)',
        'kaldirildi' => 'Ders kaldırıldı (Md. 7/2)',
        'esdeger' => 'Komisyon eşleştirmesi (Md. 6/1-b)',
    ];

    protected function casts(): array
    {
        return ['locked' => 'boolean'];
    }

    public function oldCurriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class, 'old_curriculum_id');
    }

    public function newCurriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class, 'new_curriculum_id');
    }

    public function oldCourses(): BelongsToMany
    {
        return $this->belongsToMany(CurriculumCourse::class, 'course_mapping_items')->wherePivot('side', 'old')->withPivot('side');
    }

    public function newCourses(): BelongsToMany
    {
        return $this->belongsToMany(CurriculumCourse::class, 'course_mapping_items')->wherePivot('side', 'new')->withPivot('side');
    }
}
