<?php

namespace App\Services;

use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use Collator;
use Illuminate\Support\Collection;

/**
 * Öğretim planını OBS Ders Bilgi Paketi'ndeki gibi yarıyıl tablolarına çevirir.
 * Yarıyılda seçenekleri listelenen seçmeli yuvaları (ör. "Bölüm Seçmeli (MMT1-351)") tabloda
 * ayrıca gösterilmez; yalnızca yarıyıl planı toplamına katılır.
 */
class CurriculumTable
{
    /**
     * @return array{semesters: array<int, array{rows: Collection<int, CurriculumCourse>, required: float, elective: float, planned: float}>, pools: Collection<int, CurriculumCourse>}
     */
    public static function build(Curriculum $curriculum): array
    {
        $courses = $curriculum->courses()->with('info')->get();
        $collator = new Collator('tr_TR');
        $semesters = [];

        $listed = $courses->filter(fn (CurriculumCourse $c): bool => self::semesterOf($c) !== null);
        foreach ($listed->groupBy(fn (CurriculumCourse $c): int => self::semesterOf($c))->sortKeys() as $semester => $items) {
            $options = $items->where('is_placeholder', false);
            $listedGroups = $options->pluck('elective_group')->filter()->unique()->all();
            $hasUniversity = $options->contains('category', 'university_elective');
            $rows = $items->reject(fn (CurriculumCourse $c): bool => $c->is_placeholder
                && (($c->elective_group && in_array($c->elective_group, $listedGroups, true)) || ($c->type === 'ÜS' && $hasUniversity)))
                ->sort(function (CurriculumCourse $a, CurriculumCourse $b) use ($collator): int {
                    return [self::isElective($a), $collator->getSortKey($a->name)] <=> [self::isElective($b), $collator->getSortKey($b->name)];
                })->values();
            $required = (float) $items->reject(fn (CurriculumCourse $c): bool => self::isElective($c))->sum('ects');
            $slots = (float) $items->filter(fn (CurriculumCourse $c): bool => $c->is_placeholder && self::isElective($c))->sum('ects');
            $semesters[(int) $semester] = [
                'rows' => $rows,
                'required' => $required,
                'elective' => (float) $rows->filter(fn (CurriculumCourse $c): bool => self::isElective($c))->sum('ects'),
                'planned' => $required + $slots,
            ];
        }

        $pools = $courses->filter(fn (CurriculumCourse $c): bool => self::semesterOf($c) === null && ! $c->is_placeholder)
            ->sortBy(fn (CurriculumCourse $c): string => $collator->getSortKey($c->name))->values();

        return ['semesters' => $semesters, 'pools' => $pools];
    }

    /** OBS tablosunda göründüğü yarıyıl (havuz dersleri için listelendiği yarıyıl). */
    public static function semesterOf(CurriculumCourse $course): ?int
    {
        $semester = $course->semester ?? $course->listed_semester;

        return $semester === null ? null : (int) $semester;
    }

    /** Seçmeli mi (S, ÜS veya seçmeli yuvası). */
    public static function isElective(CurriculumCourse $course): bool
    {
        return in_array($course->type, ['S', 'ÜS'], true) || $course->category !== 'plan';
    }
}
