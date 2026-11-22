<?php

namespace App\Services;

use App\Models\GradeConversion;
use Illuminate\Support\Facades\Schema;

/**
 * Diğer kurum notlarını Atatürk Üniversitesi harf notlarına çevirir (Muafiyet Esasları Md. 6/2, 6/3).
 */
class GradeService
{
    /** @var array<string, string>|null */
    private ?array $customMap = null;

    /**
     * @return array{letter: string|null, passed: bool, conditional: bool, note: string|null}
     */
    public function convert(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['letter' => null, 'passed' => false, 'conditional' => false, 'note' => 'Not girilmemiş'];
        }
        $upper = mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $raw), 'UTF-8');
        $upper = str_replace(' ', '', $upper);
        $letters = config('intibak.letters');

        $custom = $this->customMap();
        if (isset($custom[$upper])) {
            return $this->result($custom[$upper], 'Dönüşüm tablosu');
        }

        if (isset($letters[$upper])) {
            return $this->result($upper, null);
        }

        $ascii = strtr($upper, ['İ' => 'I', 'Ş' => 'S', 'Ğ' => 'G', 'Ü' => 'U', 'Ö' => 'O', 'Ç' => 'C']);
        if (in_array($upper, config('intibak.pass_marks_to_cc'), true) || in_array($ascii, config('intibak.pass_marks_to_cc'), true)) {
            return ['letter' => 'CC', 'passed' => true, 'conditional' => false, 'note' => 'G/M notu CC olarak işlenir (Md. 6/3)'];
        }
        if (in_array($upper, config('intibak.fail_marks'), true)) {
            return ['letter' => in_array($upper, ['FD', 'FF'], true) ? $upper : 'FF', 'passed' => false, 'conditional' => false, 'note' => 'Başarısız/devamsız'];
        }

        $numeric = str_replace(',', '.', $upper);
        if (is_numeric($numeric)) {
            $value = (float) $numeric;
            if ($value <= 4.0 && str_contains($numeric, '.')) {
                return $this->result($this->letterFromCoefficient($value), '4\'lük katsayıdan çevrildi');
            }
            if ($value >= 0 && $value <= 100) {
                return $this->result($this->letterFromScore($value), '100\'lük puandan Tablo I ile çevrildi');
            }
        }

        $plusMinus = [
            'A+' => 'AA', 'A' => 'AA', 'A-' => 'BA', 'B+' => 'BA', 'B' => 'BB', 'B-' => 'CB',
            'C+' => 'CB', 'C' => 'CC', 'C-' => 'DC', 'D+' => 'DC', 'D' => 'DD', 'D-' => 'DD',
        ];
        if (isset($plusMinus[$upper])) {
            return $this->result($plusMinus[$upper], 'Harf sistemi eşlemesi — komisyon kontrol etmeli');
        }

        return ['letter' => null, 'passed' => false, 'conditional' => false, 'note' => 'Tanınmayan not: '.$raw];
    }

    public function letterFromScore(float $score): string
    {
        foreach (config('intibak.score_table') as $row) {
            if ($score >= $row['min']) {
                return $row['letter'];
            }
        }

        return 'FF';
    }

    public function letterFromCoefficient(float $value): string
    {
        $best = 'FF';
        $bestDiff = PHP_FLOAT_MAX;
        foreach (config('intibak.letters') as $letter => $coefficient) {
            $diff = abs($coefficient - $value);
            if ($diff < $bestDiff - 1e-9) {
                $best = $letter;
                $bestDiff = $diff;
            }
        }

        return $best;
    }

    public function coefficient(?string $letter): ?float
    {
        return $letter !== null ? (config('intibak.letters')[$letter] ?? null) : null;
    }

    /**
     * Birden çok kaynak dersten tek harf notu: AKTS ağırlıklı ortalama katsayının en yakın harfi.
     *
     * @param  list<array{letter: string|null, ects: float|null}>  $sources
     */
    public function combine(array $sources): ?string
    {
        $sum = 0.0;
        $weight = 0.0;
        foreach ($sources as $source) {
            $coefficient = $this->coefficient($source['letter']);
            if ($coefficient === null) {
                continue;
            }
            $w = max(1.0, (float) ($source['ects'] ?? 1));
            $sum += $coefficient * $w;
            $weight += $w;
        }

        return $weight > 0 ? $this->letterFromCoefficient($sum / $weight) : null;
    }

    public function isPassingLetter(?string $letter): bool
    {
        if ($letter === null) {
            return false;
        }
        if (in_array($letter, config('intibak.definite_pass_letters'), true)) {
            return true;
        }

        return config('intibak.accept_conditional_pass') && in_array($letter, config('intibak.conditional_pass_letters'), true);
    }

    /**
     * @return array{letter: string|null, passed: bool, conditional: bool, note: string|null}
     */
    private function result(string $letter, ?string $note): array
    {
        $conditional = in_array($letter, config('intibak.conditional_pass_letters'), true);

        return [
            'letter' => $letter,
            'passed' => $this->isPassingLetter($letter),
            'conditional' => $conditional,
            'note' => $conditional ? trim(($note ? $note.'; ' : '').'DC/DD koşullu geçer notu (Yönetmelik Md. 27/4-b-2)') : $note,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function customMap(): array
    {
        if ($this->customMap === null) {
            $this->customMap = Schema::hasTable('grade_conversions')
                ? GradeConversion::query()->pluck('letter', 'source_grade')->mapWithKeys(fn ($letter, $source) => [mb_strtoupper((string) $source, 'UTF-8') => $letter])->all()
                : [];
        }

        return $this->customMap;
    }
}
