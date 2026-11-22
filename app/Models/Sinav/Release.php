<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

/**
 * Bir sınavın bir gruba açılması. Öğrenci içeriği yalnızca şu koşulların HEPSİ
 * sağlanınca görür (karar sunucuda, sunucu saatiyle verilir):
 *   onaylı, durdurulmamış, yayın saati gelmiş, yayın bitişi geçmemiş,
 *   dönem aktif ve erişim süresi dolmamış, öğrenci bu grupta kayıtlı, oturum ≤ 12 saat.
 */
class Release extends Model
{
    protected $table = 'sinav_releases';

    protected $guarded = ['id'];

    protected $casts = [
        'exam_starts_at' => 'datetime',
        'exam_ends_at' => 'datetime',
        'publish_at' => 'datetime',
        'publish_until' => 'datetime',
        'content_updated_at' => 'datetime',
        'approved' => 'boolean',
        'halted' => 'boolean',
    ];

    const STATUS_LABEL = [
        'draft' => 'Onay bekliyor',
        'scheduled' => 'Yayın için planlandı',
        'live' => 'Yayında',
        'halted' => 'Yayından kaldırıldı',
        'expired' => 'Yayın süresi doldu',
    ];

    const STATUS_TONE = [
        'draft' => 'slate',
        'scheduled' => 'amber',
        'live' => 'green',
        'halted' => 'red',
        'expired' => 'slate',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function files()
    {
        return $this->hasMany(ReleaseFile::class, 'release_id');
    }

    public function status($now = null)
    {
        $now = $now ?: now();
        if ($this->halted) {
            return 'halted';
        }
        if (! $this->approved) {
            return 'draft';
        }
        if ($now->lt($this->publish_at)) {
            return 'scheduled';
        }
        if ($this->publish_until && $now->gte($this->publish_until)) {
            return 'expired';
        }

        return 'live';
    }

    public function statusLabel()
    {
        return self::STATUS_LABEL[$this->status()];
    }

    public function statusTone()
    {
        return self::STATUS_TONE[$this->status()];
    }

    /** Taslakta (dosyalarda) onaylanmamış değişiklik var mı? */
    public function hasPendingChange(Exam $exam)
    {
        return $this->approved && (int) $exam->draft_rev !== (int) $this->approved_rev;
    }
}
