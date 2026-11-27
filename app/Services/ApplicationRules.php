<?php

namespace App\Services;

use App\Models\CurriculumCourse;
use App\Models\ExemptionItem;
use App\Models\Student;
use App\Models\TranscriptCourse;
use Illuminate\Support\Collection;

/**
 * Çoklu başvuru kuralları: önceki başvuruda talep edilen ders yeniden istenemez,
 * yaz okulu ön onayında toplam AKTS sınırı vardır.
 */
class ApplicationRules
{
    private const STATUS_TEXT = ['oneri' => 'karar bekliyor', 'kabul' => 'kabul edildi', 'ret' => 'reddedildi'];

    /**
     * Öğrencinin önceki başvurularında talep ettiği AÜ dersleri; anahtar ders kodu (yoksa adı).
     *
     * @return Collection<string, array{label: string, status: string}>
     */
    public static function previousRequests(Student $student): Collection
    {
        $current = $student->current_application_id;
        $found = collect();

        $items = ExemptionItem::query()->with('course')
            ->whereHas('file', fn ($q) => $q->where('student_id', $student->id)->where(fn ($w) => $w->whereNull('application_id')->orWhere('application_id', '!=', $current)))
            ->get();
        foreach ($items as $item) {
            if ($item->course) {
                $found[self::key($item->course)] = ['label' => $item->course->label, 'status' => self::STATUS_TEXT[$item->status] ?? $item->status];
            }
        }

        $rows = TranscriptCourse::query()->with('requestedCourse')->where('student_id', $student->id)
            ->where('application_id', '!=', $current)->whereNotNull('requested_course_id')->get();
        foreach ($rows as $row) {
            if ($row->requestedCourse) {
                $found[self::key($row->requestedCourse)] ??= ['label' => $row->requestedCourse->label, 'status' => 'talep edildi'];
            }
        }

        return $found;
    }

    /**
     * Önceki başvuruda istendiği için bu başvuruda seçilemeyecek hedef dersler.
     *
     * @param  iterable<CurriculumCourse>  $targets
     * @return array<int, array{label: string, status: string}>
     */
    public static function blockedTargets(Student $student, iterable $targets): array
    {
        $previous = self::previousRequests($student);
        if ($previous->isEmpty()) {
            return [];
        }
        $blocked = [];
        foreach ($targets as $target) {
            if ($hit = $previous->get(self::key($target))) {
                $blocked[(int) $target->id] = $hit;
            }
        }

        return $blocked;
    }

    public static function summerMaxEcts(): float
    {
        return (float) config('intibak.summer_school_max_ects', 20);
    }

    /**
     * Seçilen AÜ derslerinin AKTS toplamı (aynı ders bir kez sayılır).
     *
     * @param  Collection<int, CurriculumCourse>  $targets
     * @param  list<int>  $ids
     */
    public static function totalEcts(Collection $targets, array $ids): float
    {
        return (float) $targets->whereIn('id', array_values(array_unique($ids)))->sum(fn (CurriculumCourse $c) => (float) $c->ects);
    }

    public static function key(CurriculumCourse $course): string
    {
        $code = preg_replace('/\s+/u', '', mb_strtoupper((string) $course->code, 'UTF-8'));

        return $code !== '' ? 'k:'.$code : 'a:'.$course->normalized_name;
    }
}
