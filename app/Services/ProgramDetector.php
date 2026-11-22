<?php

namespace App\Services;

use App\Models\Curriculum;
use App\Models\Program;
use Illuminate\Support\Collection;

/**
 * Not dökümündeki derslerden ve bölüm adından en uygun AÜ lisans programını bulur.
 */
class ProgramDetector
{
    /**
     * @param  list<string>  $courseNames
     * @return Collection<int, array{program: Program, curriculum: Curriculum, score: float, matched: int, name_score: float}>
     */
    public function rank(array $courseNames, ?string $programHint = null): Collection
    {
        $courseNames = array_values(array_filter($courseNames));
        $programs = Program::query()->with(['activeCurriculum.courses'])->get();

        return $programs->filter(fn (Program $p) => $p->activeCurriculum)->map(function (Program $program) use ($courseNames, $programHint): array {
            $courses = $program->activeCurriculum->courses
                ->reject(fn ($c) => $c->is_placeholder || $c->common_group || in_array($c->root_code ? substr($c->root_code, 0, 3) : '', ['FMT', 'FFZ', 'FKM', 'MUH', 'FST'], true));
            $matched = 0;
            foreach ($courseNames as $name) {
                foreach ($courses as $course) {
                    if (max(TextNormalizer::similarity($name, $course->name), $course->name_en ? TextNormalizer::similarity($name, $course->name_en) : 0) >= config('intibak.match_strong')) {
                        $matched++;
                        break;
                    }
                }
            }
            $nameScore = $programHint ? TextNormalizer::similarity($programHint, $program->name) : 0.0;
            $coverage = $courseNames ? $matched / count($courseNames) : 0.0;

            return [
                'program' => $program,
                'curriculum' => $program->activeCurriculum,
                'score' => round(0.7 * $coverage + 0.3 * $nameScore, 3),
                'matched' => $matched,
                'name_score' => $nameScore,
            ];
        })->sortByDesc('score')->values();
    }
}
