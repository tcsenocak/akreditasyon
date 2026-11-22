<?php

namespace App\Services;

use App\Models\CatalogCourse;
use App\Models\CatalogProgram;
use App\Models\CurriculumCourse;
use App\Models\Program;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Öğrencinin daha önce aldığı dersleri seçtiği katalog.
 * Kurum Atatürk Üniversitesi ise sistemdeki AÜ programları ("au:" anahtarı),
 * diğer kurumlarda catalog_programs / catalog_courses ("ext:" anahtarı) kullanılır.
 */
class CatalogService
{
    public const ATATURK = 'Atatürk Üniversitesi';

    public static function isAtaturk(?string $institution): bool
    {
        return $institution !== null && str_contains(TextNormalizer::normalize($institution), 'ataturk universitesi');
    }

    /**
     * @return list<string>
     */
    public function institutions(string $query = ''): array
    {
        $like = '%'.$query.'%';
        $names = CatalogProgram::query()->when($query !== '', fn ($q) => $q->where('institution', 'like', $like))
            ->distinct()->orderBy('institution')->limit(30)->pluck('institution')->all();
        if ($query === '' || str_contains(TextNormalizer::normalize(self::ATATURK), TextNormalizer::normalize($query))) {
            array_unshift($names, self::ATATURK);
        }

        return array_values(array_unique($names));
    }

    /**
     * Bir kurumun bölüm/program listesi (öğrencinin kendi bölümünü seçmesi için).
     *
     * @return list<array{key: string, name: string, faculty: string|null, verified: bool}>
     */
    public function programsOf(string $institution): array
    {
        if (self::isAtaturk($institution)) {
            return Program::sortedTurkish(Program::query()->get())
                ->map(fn (Program $p) => ['key' => 'au:'.$p->id, 'name' => $p->full_name, 'faculty' => $p->faculty, 'verified' => true])->values()->all();
        }

        return CatalogProgram::query()->where('normalized_institution', TextNormalizer::normalize($institution))->orderBy('name')->get()
            ->map(fn (CatalogProgram $p) => ['key' => 'ext:'.$p->id, 'name' => $p->name, 'faculty' => $p->faculty, 'verified' => $p->verified])->values()->all();
    }

    /**
     * Öğrencinin beyan ettiği bölüm adından katalog anahtarını bulur.
     */
    public function guessProgramKey(?string $institution, ?string $programName): ?string
    {
        if (! $institution || ! $programName) {
            return null;
        }
        $wanted = TextNormalizer::normalize($programName);
        foreach ($this->programsOf($institution) as $program) {
            if (TextNormalizer::similarity(TextNormalizer::normalize($program['name']), $wanted) >= 0.9) {
                return $program['key'];
            }
        }

        return null;
    }

    /**
     * @return array{institution: string, name: string, faculty: string|null}|null
     */
    public function programInfo(string $programKey): ?array
    {
        try {
            [$type, $id] = $this->splitKey($programKey);
        } catch (RuntimeException) {
            return null;
        }
        if ($type === 'au') {
            $program = Program::query()->find($id);

            return $program ? ['institution' => self::ATATURK, 'name' => $program->full_name, 'faculty' => $program->faculty] : null;
        }
        $program = CatalogProgram::query()->find($id);

        return $program ? ['institution' => $program->institution, 'name' => $program->name, 'faculty' => $program->faculty] : null;
    }

    /**
     * Ders, öğrencinin seçtiği bölüme mi ait?
     */
    public function courseBelongsTo(string $courseKey, string $programKey): bool
    {
        try {
            [$courseType, $courseId] = $this->splitKey($courseKey);
            [$programType, $programId] = $this->splitKey($programKey);
        } catch (RuntimeException) {
            return false;
        }
        if ($courseType !== $programType) {
            return false;
        }

        return $courseType === 'au'
            ? CurriculumCourse::query()->whereKey($courseId)->whereHas('curriculum', fn ($q) => $q->where('program_id', $programId))->exists()
            : CatalogCourse::query()->whereKey($courseId)->where('catalog_program_id', $programId)->exists();
    }

    /**
     * Seçili bölümün dersleri içinde kod veya ada göre süzme.
     *
     * @return list<array<string, mixed>>
     */
    public function searchInProgram(string $programKey, string $query): array
    {
        $courses = collect($this->programCourses($programKey));
        $query = trim($query);
        if ($query === '') {
            return $courses->values()->all();
        }
        $normalized = TextNormalizer::normalize($query);
        $code = TextNormalizer::rootCode($query) ?? '';

        return $courses->filter(fn (array $c) => str_contains(TextNormalizer::normalize($c['name']), $normalized)
            || ($code !== '' && str_contains((string) TextNormalizer::rootCode($c['code']), $code)))->values()->all();
    }

    /**
     * Bölüm adı, ders kodu veya ders adıyla arama.
     *
     * @return array{programs: list<array<string, mixed>>, courses: list<array<string, mixed>>}
     */
    public function search(string $institution, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return ['programs' => [], 'courses' => []];
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';
        $normalized = '%'.TextNormalizer::normalize($query).'%';
        $compactCode = '%'.TextNormalizer::rootCode($query).'%';

        if (self::isAtaturk($institution)) {
            $programs = Program::query()->where('name', 'like', $like)->withCount('curricula')->get()
                ->map(fn (Program $p) => ['key' => 'au:'.$p->id, 'name' => $p->full_name, 'faculty' => $p->faculty, 'verified' => true])->values()->all();
            $courses = CurriculumCourse::query()->with('curriculum.program')->where('is_placeholder', false)
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('normalized_name', 'like', $normalized)->orWhere('root_code', 'like', $compactCode))
                ->limit(300)->get();

            return ['programs' => $programs, 'courses' => $this->uniqueAu($courses)->take(40)->values()->all()];
        }

        $key = TextNormalizer::normalize($institution);
        $programs = CatalogProgram::query()->where('normalized_institution', $key)
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('normalized_name', 'like', $normalized)->orWhere('faculty', 'like', $like))
            ->withCount('courses')->orderBy('name')->limit(20)->get()
            ->map(fn (CatalogProgram $p) => ['key' => 'ext:'.$p->id, 'name' => $p->name, 'faculty' => $p->faculty, 'verified' => $p->verified, 'count' => $p->courses_count])->values()->all();
        $courses = CatalogCourse::query()->with('program')
            ->whereHas('program', fn ($q) => $q->where('normalized_institution', $key))
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('normalized_name', 'like', $normalized))
            ->limit(40)->get()->map(fn (CatalogCourse $c) => $this->extRow($c))->values()->all();

        return ['programs' => $programs, 'courses' => $courses];
    }

    /**
     * Seçilen bölümün tüm dersleri.
     *
     * @return list<array<string, mixed>>
     */
    public function programCourses(string $programKey): array
    {
        [$type, $id] = $this->splitKey($programKey);
        if ($type === 'au') {
            $courses = CurriculumCourse::query()->with('curriculum.program')->where('is_placeholder', false)
                ->whereHas('curriculum', fn ($q) => $q->where('program_id', $id))->get();

            return $this->uniqueAu($courses)->sortBy(fn ($r) => sprintf('%02d-%s', $r['semester'] ?? 99, $r['code']))->values()->all();
        }

        return CatalogCourse::query()->with('program')->where('catalog_program_id', $id)->orderBy('semester')->orderBy('code')->get()
            ->map(fn (CatalogCourse $c) => $this->extRow($c))->values()->all();
    }

    /**
     * Ders anahtarını döküm satırı verisine çevirir; ad/kod/kredi/AKTS katalogdan gelir (öğrenci değiştiremez).
     *
     * @return array<string, mixed>
     */
    public function resolve(string $courseKey): array
    {
        [$type, $id] = $this->splitKey($courseKey);
        if ($type === 'au') {
            $course = CurriculumCourse::query()->with(['curriculum.program', 'info'])->findOrFail($id);

            return [
                'institution' => self::ATATURK,
                'department' => $course->curriculum->program->full_name,
                'code' => $course->code,
                'name' => $course->name,
                'credit' => $course->credit,
                'ects' => $course->ects,
                'source_course_id' => $course->id,
                'catalog_course_id' => null,
                'content' => $course->contentText() ?: null,
            ];
        }
        $course = CatalogCourse::query()->with('program')->findOrFail($id);

        return [
            'institution' => $course->program->institution,
            'department' => $course->program->name,
            'code' => $course->code,
            'name' => $course->name,
            'credit' => $course->credit,
            'ects' => $course->ects,
            'source_course_id' => null,
            'catalog_course_id' => $course->id,
            'content' => $course->content,
        ];
    }

    public function upsertProgram(string $institution, ?string $faculty, string $name, string $source, ?int $userId, bool $verified): CatalogProgram
    {
        $program = CatalogProgram::query()->firstOrNew([
            'normalized_institution' => TextNormalizer::normalize($institution),
            'normalized_name' => TextNormalizer::normalize($name),
        ]);
        if (! $program->exists) {
            $program->fill(['institution' => trim($institution), 'name' => trim($name), 'faculty' => $faculty ?: null, 'source' => $source, 'created_by' => $userId]);
        }
        if ($verified) {
            $program->verified = true;
        }
        $program->faculty = $program->faculty ?: ($faculty ?: null);
        $program->save();

        return $program;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function upsertCourse(CatalogProgram $program, array $row): CatalogCourse
    {
        $code = isset($row['code']) && $row['code'] !== '' ? mb_strtoupper(trim((string) $row['code']), 'UTF-8') : null;
        $course = $program->courses()->getQuery()
            ->where(fn ($q) => $code ? $q->where('code', $code) : $q->whereNull('code'))
            ->where('normalized_name', TextNormalizer::normalize((string) $row['name']))->first()
            ?? new CatalogCourse(['catalog_program_id' => $program->id]);
        $course->fill([
            'code' => $code,
            'name' => trim((string) $row['name']),
            'normalized_name' => TextNormalizer::normalize((string) $row['name']),
            'credit' => $row['credit'] ?? $course->credit,
            'ects' => $row['ects'] ?? $course->ects,
            'semester' => $row['semester'] ?? $course->semester,
        ])->save();

        return $course;
    }

    /**
     * @param  Collection<int, CurriculumCourse>  $courses
     * @return Collection<int, array<string, mixed>>
     */
    private function uniqueAu(Collection $courses): Collection
    {
        return $courses->sortByDesc(fn (CurriculumCourse $c) => $c->curriculum->start_year)
            ->unique(fn (CurriculumCourse $c) => $c->curriculum->program_id.'|'.mb_strtoupper((string) $c->code).'|'.$c->normalized_name.'|'.$c->credit.'|'.$c->ects)
            ->map(fn (CurriculumCourse $c) => [
                'key' => 'au:'.$c->id,
                'code' => $c->code,
                'name' => $c->name,
                'credit' => $c->credit,
                'ects' => $c->ects,
                'semester' => $c->semester,
                'program' => $c->curriculum->program->full_name,
                'plan' => $c->curriculum->title,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extRow(CatalogCourse $c): array
    {
        return [
            'key' => 'ext:'.$c->id,
            'code' => $c->code,
            'name' => $c->name,
            'credit' => $c->credit,
            'ects' => $c->ects,
            'semester' => $c->semester,
            'program' => $c->program->name,
            'plan' => $c->program->verified ? 'doğrulanmış' : 'öğrenci beyanı',
        ];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function splitKey(string $key): array
    {
        if (! preg_match('/^(au|ext):(\d+)$/', $key, $m)) {
            throw new RuntimeException('Geçersiz katalog anahtarı.');
        }

        return [$m[1], (int) $m[2]];
    }
}
