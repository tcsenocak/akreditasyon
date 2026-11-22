<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

/** Sınav dosyasının ASIL kopyası (yalnızca yönetici görür). */
class ExamFile extends Model
{
    protected $table = 'sinav_exam_files';

    protected $guarded = ['id'];

    protected $casts = ['group_ids' => 'array'];

    const KIND_LABEL = ['key' => 'Cevap anahtarı', 'questions' => 'Soru kitapçığı'];

    public function kindLabel()
    {
        return self::KIND_LABEL[$this->kind] ?? $this->kind;
    }

    /** Bu dosyayı görecek gruplar; boş liste = sınavın tüm grupları. */
    public function groupIds()
    {
        return array_map('intval', $this->group_ids ?: []);
    }

    public function visibleTo($groupId)
    {
        $ids = $this->groupIds();

        return count($ids) === 0 || in_array((int) $groupId, $ids, true);
    }
}
