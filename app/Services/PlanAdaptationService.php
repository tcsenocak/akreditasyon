<?php

namespace App\Services;

use App\Models\CourseMapping;
use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use App\Models\Student;
use App\Models\TranscriptCourse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Önlisans ve Lisans Öğretim Planları İntibak Usul ve Esasları (19.08.2022).
 *
 *  - Md. 6/1-a  Kök kodu veya adı aynı dersler sistemce eşleştirilir, değiştirilemez.
 *  - Md. 6/1-b  Diğerleri komisyon çalışmasıyla eşleştirilir.
 *  - Md. 6/1-ç  Eski planda eşleşmeyen ders kalmamalı.
 *  - Md. 7/1-c  AKTS/AGNO eşitleme (Topluma/Araştırmaya/Eğitime Katkı, 1-6 AKTS, 1-3. yarıyıl, yaz okulu)
 *  - Md. 7/1-c-iii Eski planı tamamlayanlara yeni plandaki alt yarıyıl dersleri CC ile muaf
 *  - Md. 7/1-c-iv M/G → CC
 *  - Md. 7/2..8  Kaldırma, birleştirme, bölünme, yarıyıl/tür/ad değişikliği
 */
class PlanAdaptationService
{
    public function __construct(private GradeService $grades) {}

    /**
     * Sistem eşleştirmesi: kök kod veya ad aynı olan dersler (kilitli) + benzer ad önerileri (kilitsiz).
     *
     * @return array{locked: int, suggested: int, unmatched: int}
     */
    public function autoMap(Curriculum $old, Curriculum $new): array
    {
        return DB::transaction(function () use ($old, $new): array {
            CourseMapping::query()->where('old_curriculum_id', $old->id)->where('new_curriculum_id', $new->id)
                ->where(fn ($q) => $q->where('locked', true)->orWhere('change_type', 'esdeger')->whereNull('decision_ref'))
                ->delete();

            $mappedOld = $this->mappedIds($old, $new, 'old');
            $mappedNew = $this->mappedIds($old, $new, 'new');
            $oldCourses = $old->courses()->with('info')->where('is_placeholder', false)->get()->reject(fn ($c) => in_array($c->id, $mappedOld, true));
            $newCourses = $new->courses()->with('info')->where('is_placeholder', false)->get()->reject(fn ($c) => in_array($c->id, $mappedNew, true));

            $locked = 0;
            $suggested = 0;
            foreach ($oldCourses as $oldCourse) {
                $match = $newCourses->first(fn (CurriculumCourse $c) => $oldCourse->root_code && $c->root_code === $oldCourse->root_code)
                    ?? $newCourses->first(fn (CurriculumCourse $c) => $c->normalized_name === $oldCourse->normalized_name);
                if ($match) {
                    $this->createMapping($old, $new, [$oldCourse], [$match], $this->detectChange($oldCourse, $match), true);
                    $newCourses = $newCourses->reject(fn ($c) => $c->id === $match->id);
                    $locked++;
                }
            }

            $mappedOld = $this->mappedIds($old, $new, 'old');
            foreach ($oldCourses->reject(fn ($c) => in_array($c->id, $mappedOld, true)) as $oldCourse) {
                $oldContent = $oldCourse->contentText();
                // Md. 6/1-c: Üniversite seçmeli dersleri komisyon eşleştirmesinin hedefi olamaz.
                $best = $newCourses->reject(fn (CurriculumCourse $c) => $c->category === 'university_elective' || $c->type === 'ÜS')->map(function (CurriculumCourse $c) use ($oldCourse, $oldContent): array {
                    $name = TextNormalizer::similarity($oldCourse->name, $c->name);
                    $content = TextNormalizer::contentSimilarity($oldContent, $c->contentText());
                    $score = $content === null ? $name : max($name, 0.4 * $name + 0.6 * min(1.0, $content / 0.45));

                    return ['course' => $c, 'score' => round($score, 3), 'name' => $name, 'content' => $content];
                })->sortByDesc('score')->first();
                if ($best && $best['score'] >= config('intibak.match_strong')) {
                    $note = 'Ad benzerliği '.number_format($best['name'], 2).($best['content'] !== null ? ', içerik benzerliği '.number_format($best['content'], 2) : '');
                    $this->createMapping($old, $new, [$oldCourse], [$best['course']], 'esdeger', false, $note);
                    $newCourses = $newCourses->reject(fn ($c) => $c->id === $best['course']->id);
                    $suggested++;
                }
            }

            $unmatched = $old->courses()->where('is_placeholder', false)->whereNotIn('id', $this->mappedIds($old, $new, 'old'))->count();

            return ['locked' => $locked, 'suggested' => $suggested, 'unmatched' => $unmatched];
        });
    }

    /**
     * @param  list<CurriculumCourse>  $oldCourses
     * @param  list<CurriculumCourse>  $newCourses
     */
    public function createMapping(Curriculum $old, Curriculum $new, array $oldCourses, array $newCourses, string $type, bool $locked = false, ?string $note = null, ?string $decisionRef = null): CourseMapping
    {
        $mapping = CourseMapping::query()->create([
            'old_curriculum_id' => $old->id,
            'new_curriculum_id' => $new->id,
            'change_type' => $type,
            'locked' => $locked,
            'note' => $note,
            'decision_ref' => $decisionRef,
        ]);
        foreach ($oldCourses as $course) {
            $mapping->oldCourses()->attach($course->id, ['side' => 'old']);
        }
        foreach ($newCourses as $course) {
            $mapping->newCourses()->attach($course->id, ['side' => 'new']);
        }

        return $mapping;
    }

    public function detectChange(CurriculumCourse $old, CurriculumCourse $new): string
    {
        $oldElective = $old->isElective();
        $newElective = $new->isElective();

        return match (true) {
            ! $oldElective && $newElective => 'zorunlu_secmeli',
            $oldElective && ! $newElective => 'secmeli_zorunlu',
            $old->semester && $new->semester && $old->semester !== $new->semester => 'yariyil',
            $old->normalized_name !== $new->normalized_name => 'isim',
            default => 'ayni',
        };
    }

    /**
     * Öğrencinin eski plan notlarını yeni plana aktarır.
     *
     * @return array{rows: list<array<string, mixed>>, old: array{ects: float, agno: float|null}, new: array{ects: float, agno: float|null}, balancing: list<array<string, mixed>>, unmatched_old: list<string>, warnings: list<string>}
     */
    public function applyToStudent(Student $student, Curriculum $old, Curriculum $new, int $currentSemester = 1, bool $completedOldLowerSemesters = false): array
    {
        $records = $this->recordsForOldPlan($student, $old);
        $mappings = CourseMapping::query()->with(['oldCourses', 'newCourses'])
            ->where('old_curriculum_id', $old->id)->where('new_curriculum_id', $new->id)->get();

        $rows = [];
        $warnings = [];
        $covered = [];
        foreach ($mappings as $mapping) {
            $oldGrades = $mapping->oldCourses->map(fn (CurriculumCourse $c) => ['course' => $c, 'record' => $records[$c->id] ?? null]);
            $taken = $oldGrades->filter(fn ($g) => $g['record'] !== null);
            if ($taken->isEmpty()) {
                // Md. 7/2-c: kaldırılan dersi hiç almayan sorumlu tutulmaz; diğer türlerde yeni ders normal alınır.
                continue;
            }
            $passed = $taken->filter(fn ($g) => $g['record']['passed']);
            foreach ($mapping->newCourses as $newCourse) {
                $covered[$newCourse->id] = true;
                if ($passed->isNotEmpty()) {
                    $letter = $passed->map(fn ($g) => $g['record']['letter'])->sortByDesc(fn ($l) => $this->grades->coefficient($l) ?? -1)->first();
                    $rule = match ($mapping->change_type) {
                        'birlesme' => 'Birleştirilen derslerden yüksek not (Md. 7/3-a/b)',
                        'bolunme' => 'Bölünen dersin notu (Md. 7/4-a)',
                        'kaldirildi' => 'Kaldırılan dersin notu, içerik aranmaz (Md. 7/2-a)',
                        default => CourseMapping::CHANGE_TYPES[$mapping->change_type] ?? $mapping->change_type,
                    };
                    if ($mapping->change_type === 'birlesme' && $passed->count() < $mapping->oldCourses->count()) {
                        $rule .= ' — başarısız/alınmamış dersler dikkate alınmadı';
                    }
                    $rows[] = $this->row($newCourse, $letter, true, $rule, $mapping, $passed->map(fn ($g) => $g['course']->label)->implode(' + '));
                } else {
                    $failLetter = $taken->first()['record']['letter'] ?? 'FF';
                    $rows[] = $this->row($newCourse, $failLetter, false, 'Başarısız ders aynı notla yeni derse (Md. 7/2-b, 7/3-c, 7/4-b)', $mapping, $taken->map(fn ($g) => $g['course']->label)->implode(' + '));
                }
            }
        }

        // Md. 7/1-c-iii
        if ($completedOldLowerSemesters) {
            $newOnly = $new->courses()->where('category', 'plan')->where('is_placeholder', false)
                ->where('semester', '<', $currentSemester)->get()
                ->reject(fn ($c) => isset($covered[$c->id]));
            foreach ($newOnly as $course) {
                $rows[] = $this->row($course, 'CC', true, 'Eski planda olmayan alt yarıyıl dersi, CC ile muaf (Md. 7/1-c-iii)', null, '—');
            }
        }

        $unmatchedOld = $old->courses()->where('is_placeholder', false)
            ->whereNotIn('id', $mappings->flatMap(fn ($m) => $m->oldCourses->pluck('id'))->all())
            ->get()->filter(fn ($c) => isset($records[$c->id]))->map(fn ($c) => $c->label)->values()->all();
        if ($unmatchedOld) {
            $warnings[] = 'Eşleşmeyen eski plan dersi var (Md. 6/1-ç): '.implode(', ', $unmatchedOld);
        }

        $oldStats = $this->stats(collect($records)->map(fn ($r) => ['letter' => $r['letter'], 'ects' => $r['ects'], 'passed' => $r['passed']]));
        $newStats = $this->stats(collect($rows)->map(fn ($r) => ['letter' => $r['letter'], 'ects' => $r['ects'], 'passed' => $r['passed']]));
        $balancing = $this->balancing($oldStats, $newStats);
        if ($newStats['ects'] > $oldStats['ects'] + 0.01) {
            $warnings[] = 'Yeni AKTS eskisinden yüksek; eşitleme birim yönetim kurulu kararına bağlıdır (Md. 6/1-d, 8/2).';
        }

        return ['rows' => $rows, 'old' => $oldStats, 'new' => $newStats, 'balancing' => $balancing, 'unmatched_old' => $unmatchedOld, 'warnings' => $warnings];
    }

    /**
     * Öğrencinin not dökümünü eski plan derslerine bağlar (kod, sonra ad).
     *
     * @return array<int, array{letter: string|null, passed: bool, ects: float}>
     */
    public function recordsForOldPlan(Student $student, Curriculum $old): array
    {
        $courses = $old->courses()->where('is_placeholder', false)->get();
        $records = [];
        foreach ($student->transcript as $entry) {
            /** @var TranscriptCourse $entry */
            $root = TextNormalizer::rootCode($entry->code);
            $course = $courses->first(fn ($c) => $root && $c->root_code === $root)
                ?? $courses->first(fn ($c) => $c->normalized_name === $entry->normalized_name);
            if (! $course) {
                continue;
            }
            $letter = $entry->letter;
            $existing = $records[$course->id] ?? null;
            // Tekrar alınan derste son/başarılı not esas alınır.
            if ($existing && $existing['passed'] && ! $entry->passed) {
                continue;
            }
            $records[$course->id] = ['letter' => $letter, 'passed' => $entry->passed, 'ects' => (float) $course->ects];
        }

        return $records;
    }

    /**
     * @param  Collection<int, array{letter: string|null, ects: float, passed: bool}>  $records
     * @return array{ects: float, agno: float|null}
     */
    private function stats(Collection $records): array
    {
        $ects = (float) $records->where('passed', true)->sum('ects');
        $weighted = 0.0;
        $weight = 0.0;
        foreach ($records as $record) {
            $c = $this->grades->coefficient($record['letter']);
            if ($c === null) {
                continue;
            }
            $weighted += $c * $record['ects'];
            $weight += $record['ects'];
        }

        return ['ects' => $ects, 'agno' => $weight > 0 ? round($weighted / $weight, 2) : null];
    }

    /**
     * AKTS farkını 1-6 AKTS'lik katkı dersleriyle kapatır; harf notu AGNO'yu eski değere en yakın tutacak şekilde seçilir.
     *
     * @param  array{ects: float, agno: float|null}  $old
     * @param  array{ects: float, agno: float|null}  $new
     * @return list<array<string, mixed>>
     */
    private function balancing(array $old, array $new): array
    {
        $diff = round($old['ects'] - $new['ects'], 1);
        if ($diff < config('intibak.balancing_min_ects')) {
            return [];
        }
        $letter = 'CC';
        if ($old['agno'] !== null && $new['agno'] !== null) {
            $needed = ($old['agno'] * ($new['ects'] + $diff) - $new['agno'] * $new['ects']) / $diff;
            $letter = $this->grades->letterFromCoefficient(max(1.0, min(4.0, $needed)));
        }
        $names = config('intibak.balancing_courses');
        $max = (int) config('intibak.balancing_max_ects');
        $courses = [];
        $i = 0;
        while ($diff > 0.01) {
            $ects = min($max, $diff);
            $courses[] = [
                'name' => $names[$i % count($names)],
                'ects' => $ects,
                'semester' => ($i % 3) + 1,
                'letter' => $letter,
                'note' => 'Yaz okulunda alınmış sayılır; plan dersi yerine sayılmaz (Md. 7/1-c-ii)',
            ];
            $diff = round($diff - $ects, 1);
            $i++;
        }

        return $courses;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CurriculumCourse $course, ?string $letter, bool $passed, string $rule, ?CourseMapping $mapping, string $from): array
    {
        if (in_array($letter, ['M', 'G'], true)) {
            $letter = 'CC';
        }

        return [
            'course' => $course,
            'letter' => $letter,
            'passed' => $passed,
            'ects' => (float) $course->ects,
            'rule' => $rule,
            'mapping' => $mapping,
            'from' => $from,
        ];
    }

    /**
     * @return list<int>
     */
    private function mappedIds(Curriculum $old, Curriculum $new, string $side): array
    {
        return DB::table('course_mapping_items')
            ->join('course_mappings', 'course_mappings.id', '=', 'course_mapping_items.course_mapping_id')
            ->where('course_mappings.old_curriculum_id', $old->id)
            ->where('course_mappings.new_curriculum_id', $new->id)
            ->where('course_mapping_items.side', $side)
            ->pluck('course_mapping_items.curriculum_course_id')->map(fn ($id) => (int) $id)->all();
    }
}
