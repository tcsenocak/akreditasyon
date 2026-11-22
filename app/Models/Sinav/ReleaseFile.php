<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

/** Onay anında gruba kopyalanan yayın kopyası. Öğrenci yalnızca bunu görür. */
class ReleaseFile extends Model
{
    protected $table = 'sinav_release_files';

    protected $guarded = ['id'];

    public function release()
    {
        return $this->belongsTo(Release::class, 'release_id');
    }

    public function kindLabel()
    {
        return ExamFile::KIND_LABEL[$this->kind] ?? $this->kind;
    }
}
