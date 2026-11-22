<?php

namespace App\Services;

use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use Illuminate\Support\Facades\DB;

/**
 * Öğretim planı derslerini yerinde eşitler: var olan dersler güncellenir, yeniler eklenir,
 * kaynakta artık olmayan dersler muafiyet/eşleştirme kaydında kullanılmıyorsa silinir.
 * Böylece plan yeniden aktarıldığında muafiyet dosyaları ve plan eşleştirmeleri korunur.
 */
class CurriculumSync
{
    /**
     * @param  list<array<string, mixed>>  $courses
     * @return array{created: int, updated: int, deleted: int, kept: int}
     */
    public function sync(Curriculum $curriculum, array $courses): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'kept' => 0];
        $existing = $curriculum->courses()->get()->groupBy(fn (CurriculumCourse $c) => $this->key($c->toArray()));
        $seen = [];

        foreach ($courses as $data) {
            $key = $this->key($data);
            $match = $existing->get($key)?->first(fn (CurriculumCourse $c) => ! isset($seen[$c->id]));
            if ($match) {
                $match->fill($data)->save();
                $seen[$match->id] = true;
                $stats['updated']++;
            } else {
                $created = $curriculum->courses()->create($data);
                $seen[$created->id] = true;
                $stats['created']++;
            }
        }

        foreach ($existing->flatten() as $course) {
            if (isset($seen[$course->id])) {
                continue;
            }
            $inUse = DB::table('exemption_items')->where('curriculum_course_id', $course->id)->exists()
                || DB::table('course_mapping_items')->where('curriculum_course_id', $course->id)->exists();
            if ($inUse) {
                $stats['kept']++;
            } else {
                $course->delete();
                $stats['deleted']++;
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $course
     */
    private function key(array $course): string
    {
        return implode('|', [
            $course['category'] ?? '',
            $course['obs_course_id'] ?? '',
            mb_strtoupper((string) ($course['code'] ?? '')),
            $course['normalized_name'] ?? '',
            $course['semester'] ?? '',
        ]);
    }
}
