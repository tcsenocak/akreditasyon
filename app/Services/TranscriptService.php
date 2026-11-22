<?php

namespace App\Services;

use App\Models\Student;
use App\Models\TranscriptCourse;

/**
 * Not dökümü satırlarını AÜ harf notuna çevirerek kaydeder (Muafiyet Esasları Md. 6/2).
 */
class TranscriptService
{
    public function __construct(private GradeService $grades) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function addRows(Student $student, array $rows, string $source, ?string $institution = null): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (empty($row['name'])) {
                continue;
            }
            $this->save(new TranscriptCourse(['student_id' => $student->id]), $row + ['source' => $source, 'institution' => $institution ?? $student->previous_institution]);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(TranscriptCourse $course, array $data): TranscriptCourse
    {
        $converted = $this->grades->convert($data['grade_raw'] ?? null);
        $course->fill([
            'institution' => $data['institution'] ?? $course->institution,
            'academic_year' => $data['academic_year'] ?? $course->academic_year,
            'term' => $data['term'] ?? $course->term,
            'code' => isset($data['code']) && $data['code'] !== '' ? mb_strtoupper(trim((string) $data['code']), 'UTF-8') : null,
            'name' => trim((string) $data['name']),
            'normalized_name' => TextNormalizer::normalize((string) $data['name']),
            'credit' => isset($data['credit']) && $data['credit'] !== '' ? (float) $data['credit'] : null,
            'ects' => isset($data['ects']) && $data['ects'] !== '' ? (float) $data['ects'] : null,
            'grade_raw' => $data['grade_raw'] ?? null,
            'letter' => $data['letter_override'] ?? $converted['letter'],
            'passed' => isset($data['letter_override']) ? $this->grades->isPassingLetter($data['letter_override']) : $converted['passed'],
            'semester' => isset($data['semester']) && $data['semester'] !== '' ? (int) $data['semester'] : null,
            'source' => $data['source'] ?? $course->source ?? 'manuel',
            'content' => $data['content'] ?? $course->content,
            'catalog_course_id' => array_key_exists('catalog_course_id', $data) ? $data['catalog_course_id'] : $course->catalog_course_id,
            'source_course_id' => array_key_exists('source_course_id', $data) ? $data['source_course_id'] : $course->source_course_id,
            'department' => $data['department'] ?? $course->department,
            'requested_course_id' => array_key_exists('requested_course_id', $data)
                ? ($data['requested_course_id'] ?: null)
                : $course->requested_course_id,
        ]);
        $course->save();

        return $course;
    }

    public function reconvert(Student $student): void
    {
        foreach ($student->transcript as $course) {
            $converted = $this->grades->convert($course->grade_raw);
            $course->update(['letter' => $converted['letter'], 'passed' => $converted['passed']]);
        }
    }
}
