<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder;

class Student extends Model
{
    protected $guarded = [];

    public const ADMISSION_TYPES = [
        'yatay' => 'Yatay geçiş',
        'dikey' => 'Dikey geçiş',
        'af' => 'Af kanunu',
        'yeniden' => 'Yeniden kayıt (önceki öğrenim/mezuniyet)',
        'es_zamanli' => 'Eş zamanlı başka kurumda alınan ders',
        'diger' => 'Diğer benzer durum',
    ];

    public const APPLICATION_STATUSES = [
        'taslak' => 'Hazırlanıyor',
        'gonderildi' => 'Gönderildi',
        'inceleniyor' => 'Komisyonda',
        'sonuclandi' => 'Sonuçlandı',
    ];

    protected function casts(): array
    {
        return ['prep_completed' => 'boolean', 'yds_score' => 'float', 'submitted_at' => 'datetime'];
    }

    /**
     * Her öğrencinin bir güncel başvurusu vardır; öğrencinin durum alanları o başvurunun aynasıdır.
     */
    protected static function booted(): void
    {
        static::created(function (Student $student): void {
            if (! $student->current_application_id) {
                $student->startApplication('normal');
            }
        });
        static::updated(function (Student $student): void {
            if ($student->current_application_id && $student->wasChanged(['application_status', 'submitted_at'])) {
                ExemptionApplication::query()->whereKey($student->current_application_id)->update([
                    'status' => $student->application_status,
                    'submitted_at' => $student->submitted_at,
                ]);
            }
        });
    }

    /**
     * Yeni başvuru açar ve güncel başvuru yapar; önceki başvurunun dersleri ve belgeleri o başvuruda kalır.
     */
    public function startApplication(string $type): ExemptionApplication
    {
        $application = $this->applications()->create(['type' => $type, 'status' => 'taslak']);
        $this->forceFill(['current_application_id' => $application->id, 'application_status' => 'taslak', 'submitted_at' => null])->saveQuietly();

        return $application;
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ExemptionApplication::class)->orderBy('id');
    }

    public function currentApplication(): BelongsTo
    {
        return $this->belongsTo(ExemptionApplication::class, 'current_application_id');
    }

    /**
     * Güncel başvurunun belgeleri (önceki başvurulardakiler applications üzerinden okunur).
     */
    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class)->whereExists(self::currentApplicationOf('student_documents'))->latest();
    }

    public function allDocuments(): HasMany
    {
        return $this->hasMany(StudentDocument::class)->latest();
    }

    /**
     * Satırın öğrencinin güncel başvurusuna ait olması koşulu (tembel ve önceden yüklemede çalışır).
     */
    private static function currentApplicationOf(string $table): Closure
    {
        return fn (Builder $q) => $q->selectRaw('1')->from('students as cur')
            ->whereColumn('cur.id', $table.'.student_id')
            ->whereColumn('cur.current_application_id', $table.'.application_id');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * Öğrenci başvurusunu yalnız gönderilmeden önce düzenleyebilir.
     */
    public function isEditableByStudent(): bool
    {
        return $this->application_status === 'taslak';
    }

    public const PORTAL_MODES = ['yeni' => 'Yeni Başvuru', 'duzenle' => 'Başvuru Düzenle', 'izle' => 'Başvurum'];

    /**
     * Öğrenci panelinin ilk ekranı: "yeni" (başlatılmış başvuru yok), "duzenle" (yarım kalan taslak) ya da "izle" (komisyonda).
     */
    public function portalMode(): string
    {
        if ($this->application_status === 'sonuclandi') {
            return 'yeni';
        }
        if ($this->application_status !== 'taslak') {
            return 'izle';
        }
        $started = $this->submitted_at !== null || filled($this->currentApplication?->language_exam)
            || $this->transcript()->exists() || $this->documents()->exists();

        return $started ? 'duzenle' : 'yeni';
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * Güncel başvuruda girilen dersler.
     */
    public function transcript(): HasMany
    {
        return $this->hasMany(TranscriptCourse::class)->whereExists(self::currentApplicationOf('transcript_courses'))->orderBy('semester')->orderBy('id');
    }

    public function allTranscript(): HasMany
    {
        return $this->hasMany(TranscriptCourse::class)->orderBy('semester')->orderBy('id');
    }

    public function exemptionFiles(): HasMany
    {
        return $this->hasMany(ExemptionFile::class)->latest();
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name.' '.$this->last_name;
    }
}
