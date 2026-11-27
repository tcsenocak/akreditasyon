<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Öğrencinin tek bir muafiyet başvurusu. Öğrenci önceki başvurusu sonuçlandıktan sonra yenisini açabilir.
 */
class ExemptionApplication extends Model
{
    protected $guarded = [];

    public const TYPES = [
        'normal' => 'Dönem başı muafiyet başvurusu',
        'yaz_okulu' => 'Yaz okulu ön onayı',
        'hazirlik' => 'Hazırlık sınıfı muafiyeti (yeni kayıt)',
    ];

    public const SHORT_TYPES = ['normal' => 'Normal', 'yaz_okulu' => 'Yaz okulu', 'hazirlik' => 'Hazırlık'];

    public const DECISIONS = ['kabul' => 'Kabul (hazırlıktan muaf)', 'ret' => 'Ret'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'returned_at' => 'datetime',
            'objected_at' => 'datetime',
            'objection_responded_at' => 'datetime',
            'summer_grades_at' => 'datetime',
            'language_exam_date' => 'date',
            'language_score' => 'float',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function transcript(): HasMany
    {
        return $this->hasMany(TranscriptCourse::class, 'application_id')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class, 'application_id')->latest();
    }

    public function exemptionFile(): HasOne
    {
        return $this->hasOne(ExemptionFile::class, 'application_id')->latestOfMany();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Komisyonun işlem bekleyen başvuruları: gönderilmiş ama karara bağlanmamışlar ve cevaplanmamış itirazlar.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereIn('status', ['gonderildi', 'inceleniyor'])
            ->orWhere(fn (Builder $w) => $w->whereNotNull('objected_at')->whereNull('objection_responded_at')));
    }

    public function hasOpenObjection(): bool
    {
        return $this->objected_at !== null && $this->objection_responded_at === null;
    }

    public function isPrep(): bool
    {
        return $this->type === 'hazirlik';
    }

    public function isSummer(): bool
    {
        return $this->type === 'yaz_okulu';
    }

    /**
     * Kararın öğrenciye açıklandığı gün: yönetim kurulu karar tarihi, yoksa kararın sisteme işlendiği gün.
     */
    public function decisionDate(): ?Carbon
    {
        if ($this->status !== 'sonuclandi') {
            return null;
        }
        $file = $this->exemptionFile;

        return $file?->decision_date ?? $this->decided_at ?? $file?->updated_at;
    }

    /**
     * İtirazın son günü: karardan sonraki beşinci iş günü (hafta sonu sayılmaz; resmî tatiller komisyonca dikkate alınır).
     */
    public function objectionDeadline(): ?Carbon
    {
        $date = $this->decisionDate()?->copy()->startOfDay();
        if (! $date) {
            return null;
        }
        $left = (int) config('intibak.objection_business_days', 5);
        while ($left > 0) {
            $date->addDay();
            if (! $date->isWeekend()) {
                $left--;
            }
        }

        return $date->endOfDay();
    }

    public function canObject(): bool
    {
        $deadline = $this->objectionDeadline();

        return $deadline !== null && $this->objected_at === null && now()->lte($deadline);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
