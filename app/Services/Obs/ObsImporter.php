<?php

namespace App\Services\Obs;

use App\Models\CourseInfo;
use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use App\Models\Program;
use App\Services\CurriculumSync;
use App\Services\TextNormalizer;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * OBS'den fakültenin programlarını, her giriş yılı müfredatını ve ders içeriklerini aktarır.
 */
class ObsImporter
{
    private ?Closure $logger = null;

    public function __construct(private ObsClient $client) {}

    public function onLog(Closure $logger): static
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Fakültedeki lisans programları (İ.Ö. hariç).
     *
     * @return list<array{obs_id: int, name: string, department: string, english: bool}>
     */
    public function discoverPrograms(int $facultyId): array
    {
        $programs = [];
        foreach ($this->client->units((string) $facultyId) as $department) {
            $departmentName = $this->cleanLabel($department['label']);
            foreach ($this->client->units($department['id']) as $unit) {
                $label = $unit['label'];
                if (config('intibak.obs.skip_evening') && Str::contains($label, ['(İ.Ö.)', '(İÖ)', 'İkinci Öğretim'])) {
                    continue;
                }
                $programs[] = [
                    'obs_id' => (int) $unit['id'],
                    'name' => $this->cleanLabel($label),
                    'department' => $departmentName,
                    'english' => Str::contains(TextNormalizer::ascii($label), ['ingilizce', 'english']),
                ];
            }
        }

        return $programs;
    }

    /**
     * @param  list<int>|null  $onlyYears  ay_id filtresi
     * @return list<array{program: Program, curriculum: Curriculum, count: int, warnings: list<string>}>
     */
    public function importProgram(array $obsProgram, ?array $onlyYears = null): array
    {
        $program = $this->localProgram($obsProgram);
        $years = $this->client->academicYears($obsProgram['obs_id']);
        $results = [];
        foreach ($years as $yearId => $label) {
            if ($onlyYears !== null && ! in_array($yearId, $onlyYears, true)) {
                continue;
            }
            $rows = $this->client->curriculum($obsProgram['obs_id'], $yearId);
            if (collect($rows)->whereNotNull('DersKodu')->isEmpty()) {
                $this->log("  – {$label}: müfredat boş");

                continue;
            }
            $results[] = $this->storeCurriculum($program, $yearId, $this->yearLabel($label), $rows);
        }

        // En yeni giriş yılı müfredatını aktif yap
        $latest = Curriculum::query()->where('program_id', $program->id)->whereNotNull('obs_year_id')->orderByDesc('start_year')->first();
        if ($latest) {
            Curriculum::query()->where('program_id', $program->id)->whereKeyNot($latest->id)->update(['is_active' => false]);
            Curriculum::query()->whereKey($latest->id)->update(['is_active' => true]);
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{program: Program, curriculum: Curriculum, count: int, warnings: list<string>}
     */
    public function storeCurriculum(Program $program, int $yearId, string $startYear, array $rows): array
    {
        $courses = $this->mapCourses($rows);
        $warnings = [];

        return DB::transaction(function () use ($program, $yearId, $startYear, $courses, $warnings): array {
            $curriculum = Curriculum::query()->firstOrNew(['program_id' => $program->id, 'obs_year_id' => $yearId]);
            $planCourses = collect($courses)->where('category', 'plan');
            $curriculum->fill([
                'title' => $startYear.' girişli müfredat (OBS)',
                'start_year' => $startYear,
                'total_credit' => $planCourses->where('is_placeholder', false)->sum('credit'),
                'total_ects' => $planCourses->sum('ects'),
                'source' => 'OBS Ders Bilgi Paketi',
                'is_active' => $curriculum->is_active ?? false,
            ])->save();
            app(CurriculumSync::class)->sync($curriculum, $courses);
            $total = (float) $planCourses->sum('ects');
            if (abs($total - 240) > 0.01) {
                $semesters = $planCourses->pluck('semester')->filter()->unique()->sort()->values();
                $warnings[] = "Plan AKTS toplamı {$total}; OBS'de tanımlı yarıyıllar: ".$semesters->implode(', ');
            }

            return ['program' => $program, 'curriculum' => $curriculum, 'count' => count($courses), 'warnings' => $warnings];
        });
    }

    /**
     * OBS müfredat satırlarını plan derslerine, seçmeli havuzlarına ve seçmeli yuvalarına dönüştürür.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function mapCourses(array $rows): array
    {
        $courses = [];
        $bySemester = [];
        foreach ($rows as $row) {
            if (empty($row['DersKodu']) || empty($row['DersAdi']) || (string) ($row['PFDersiMi'] ?? '0') === '1') {
                continue;
            }
            $semester = is_numeric($row['Donem'] ?? null) ? (int) $row['Donem'] : null;
            $bySemester[$semester ?? 0][] = $row;
        }

        foreach ($bySemester as $semester => $semesterRows) {
            $semester = $semester ?: null;
            $compulsoryEcts = 0.0;
            $groups = ['dept' => [], 'faculty' => false, 'university' => false];

            foreach ($semesterRows as $row) {
                $name = $this->cleanCourseName((string) $row['DersAdi']);
                $code = trim((string) $row['DersKodu']);
                $ects = (float) $row['AKTS'];
                $isElective = ($row['DersTipiKisa'] ?? '') === 'S';
                $kind = $this->electiveKind($code, $name);

                if (! $isElective) {
                    $alternative = $ects >= 20 && (Str::contains(TextNormalizer::ascii($name), 'mesleki egitim')
                        || Str::contains(Str::lower($name.' '.($row['DersAdiYabanci'] ?? '')), 'vocational education'));
                    if (! $alternative) {
                        $compulsoryEcts += $ects;
                    }
                    $category = match (true) {
                        $semester === null => 'preparatory', // hazırlık vb. yarıyılsız dersler 240 AKTS'ye girmez
                        $alternative => 'alternative',
                        default => 'plan',
                    };
                    $courses[] = $this->course($row, $name, $code, $semester, $category, 'Z');

                    continue;
                }
                if (preg_match('/^(üniversite|universite) seçmeli/ui', $name)) {
                    $groups['university'] = true;

                    continue; // OBS'deki genel yuva satırı
                }
                match ($kind) {
                    'university' => $groups['university'] = true,
                    'faculty' => $groups['faculty'] = true,
                    default => $groups['dept'][$code] = max($groups['dept'][$code] ?? 0, $ects),
                };
                $category = ['university' => 'university_elective', 'faculty' => 'faculty_elective'][$kind] ?? 'elective_pool';
                $courses[] = $this->course($row, $name, $code, $kind === 'university' ? null : $semester, $category, $kind === 'university' ? 'ÜS' : 'S', $code, $semester);
            }

            if ($semester === null) {
                continue;
            }
            // Seçmeli yuvaları: yarıyıl 30 AKTS'ye tamamlanır.
            $needed = max(0.0, 30.0 - $compulsoryEcts);
            $slot = 0;
            foreach ($groups['dept'] as $group => $ects) {
                if ($needed <= 0) {
                    break;
                }
                $slot++;
                $courses[] = $this->slot("Bölüm Seçmeli ({$group})", $ects, $semester, 'S', $group);
                $needed -= $ects;
            }
            if ($groups['faculty'] && $needed > 0) {
                $courses[] = $this->slot('Fakülte Teknik Seçmeli', 3, $semester, 'S', 'MF-TEK');
                $needed -= 3;
            }
            if ($groups['university'] && $needed > 0) {
                $courses[] = $this->slot('Üniversite Seçmeli', 3, $semester, 'ÜS', 'USD');
                $needed -= 3;
            }
            if ($needed > 0.01) {
                $courses[] = $this->slot('Seçmeli / Staj (OBS listesinde ayrıca gösterilmeyen)', $needed, $semester, 'S', null);
            }
        }

        return $courses;
    }

    /**
     * Tekil dersler için içerik (OBS Ders Bilgi Paketi) çeker; önbellekte olanları atlar.
     *
     * @return array{fetched: int, skipped: int, failed: int}
     */
    public function fetchCourseInfos(bool $refresh = false, ?int $limit = null): array
    {
        $pairs = CurriculumCourse::query()
            ->join('curricula', 'curricula.id', '=', 'curriculum_courses.curriculum_id')
            ->join('programs', 'programs.id', '=', 'curricula.program_id')
            ->whereNotNull('curriculum_courses.obs_course_id')
            ->whereNotNull('programs.obs_program_id')
            ->select('curriculum_courses.obs_course_id', DB::raw('MIN(programs.obs_program_id) as obs_program_id'))
            ->groupBy('curriculum_courses.obs_course_id')
            ->get();
        $existing = $refresh ? [] : CourseInfo::query()->pluck('obs_course_id')->flip()->all();
        $stats = ['fetched' => 0, 'skipped' => 0, 'failed' => 0];
        $total = $pairs->count();

        foreach ($pairs as $index => $pair) {
            if (isset($existing[$pair->obs_course_id])) {
                $stats['skipped']++;

                continue;
            }
            if ($limit !== null && $stats['fetched'] >= $limit) {
                break;
            }
            try {
                $offering = $this->client->latestOffering((int) $pair->obs_course_id, (int) $pair->obs_program_id);
                $info = $offering ? $this->client->courseInfo($offering['ap_id'], (int) $pair->obs_program_id) : [];
                $this->storeInfo((int) $pair->obs_course_id, $info, $offering['label'] ?? null);
                $stats['fetched']++;
                if ($stats['fetched'] % 25 === 0) {
                    $this->log("  içerik {$stats['fetched']} / ".($total - $stats['skipped']));
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->log("  ✘ ders {$pair->obs_course_id}: ".$e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    public function storeInfo(int $obsCourseId, array $info, ?string $offeringLabel): CourseInfo
    {
        $definition = $info['ders_tanim'] ?? [];
        $clean = fn ($text) => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? '');
        $list = fn ($items) => array_values(array_filter(array_map(fn ($i) => $clean($i['Icerik'] ?? ''), is_array($items) ? $items : [])));
        $code = null;
        if ($offeringLabel && preg_match('/\b([A-ZÇĞİÖŞÜ]{2,5}[0-9]{3}[A-Z]?)\b/u', $offeringLabel, $m)) {
            $code = $m[1];
        }

        return CourseInfo::query()->updateOrCreate(['obs_course_id' => $obsCourseId], [
            'code' => $code,
            'language' => $clean($definition['EgitimDil'] ?? null) ?: null,
            'prerequisites' => $clean($definition['OnkosulDersler'] ?? null) ?: null,
            'objective' => $clean($definition['DersinAmaci'] ?? null) ?: null,
            'description' => $clean($definition['DersinTanimi'] ?? null) ?: null,
            'weekly_topics' => $list($info['ders_icerik'] ?? []),
            'outcomes' => $list($info['ders_ogrenme_ciktilar'] ?? []),
            'offering_label' => $offeringLabel ? mb_substr($offeringLabel, 0, 250) : null,
            'fetched_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $obsProgram
     */
    private function localProgram(array $obsProgram): Program
    {
        $wanted = TextNormalizer::normalize($obsProgram['name']);
        $language = $obsProgram['english'] ? 'EN' : 'TR';
        $program = Program::query()->where('obs_program_id', $obsProgram['obs_id'])->first()
            ?? Program::query()->where('language', $language)->get()
                ->first(fn (Program $p) => TextNormalizer::similarity(TextNormalizer::normalize($p->name), preg_replace('/ ingilizce$/', '', $wanted)) >= 0.9);

        if (! $program) {
            $name = trim(preg_replace('/\s*\((İngilizce|English)\)\s*/u', '', $obsProgram['name']) ?? $obsProgram['name']);
            $program = Program::query()->create([
                'department' => $obsProgram['department'],
                'name' => $name,
                'language' => $language,
                'slug' => Str::slug($name.($language === 'EN' ? ' en' : '')),
            ]);
        }
        $program->update(['obs_program_id' => $obsProgram['obs_id']]);

        return $program;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function course(array $row, string $name, string $code, ?int $semester, string $category, string $type, ?string $group = null, ?int $listedSemester = null): array
    {
        $commonGroup = TextNormalizer::commonGroup($name, $code, $row['DersAdiYabanci'] ?? null);
        if ($type === 'Z' && in_array($commonGroup, ['AIIT', 'TD', 'YD', 'ISG'], true)) {
            $type = 'OZ';
        }

        return [
            'code' => $code,
            // Seçmeli grup kodları (MMT1-351, USD, MF-TEK) birden çok derste ortak; kök kod olarak kullanılmaz.
            'root_code' => $group === null ? TextNormalizer::rootCode($code) : null,
            'name' => $name,
            'name_en' => trim((string) ($row['DersAdiYabanci'] ?? '')) ?: null,
            'normalized_name' => TextNormalizer::normalize($name),
            'type' => $type,
            'mode' => null,
            'theory' => (float) ($row['TeoriSaati'] ?? 0),
            'practice' => (float) ($row['UygulamaSaati'] ?? 0),
            'lab' => (float) ($row['LabSaati'] ?? 0),
            'credit' => (float) ($row['Kredi'] ?? 0),
            'ects' => (float) ($row['AKTS'] ?? 0),
            'semester' => $semester,
            'listed_semester' => $listedSemester ?? $semester,
            'category' => $category,
            'is_placeholder' => false,
            'common_group' => $commonGroup,
            'sequence' => TextNormalizer::splitSequence($name)['sequence'],
            'obs_course_id' => is_numeric($row['DersID'] ?? null) ? (int) $row['DersID'] : null,
            'elective_group' => $group,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function slot(string $name, float $ects, int $semester, string $type, ?string $group): array
    {
        return [
            'code' => null, 'root_code' => null, 'name' => $name, 'name_en' => null,
            'normalized_name' => TextNormalizer::normalize($name), 'type' => $type, 'mode' => null,
            'theory' => 0, 'practice' => 0, 'lab' => 0, 'credit' => 0, 'ects' => $ects,
            'semester' => $semester, 'listed_semester' => $semester, 'category' => 'plan', 'is_placeholder' => true,
            'common_group' => null, 'sequence' => null, 'obs_course_id' => null, 'elective_group' => $group,
        ];
    }

    private function electiveKind(string $code, string $name): string
    {
        $code = TextNormalizer::ascii($code);
        if (preg_match('/^(usd|uss|us\b|ussd)/', $code) || preg_match('/^sosyal calisma/', TextNormalizer::ascii($name))) {
            return 'university';
        }
        if (str_starts_with($code, 'mf-') || str_starts_with($code, 'mf')) {
            return 'faculty';
        }

        return 'department';
    }

    private function cleanCourseName(string $name): string
    {
        return trim(preg_replace('/\s*\(\d+\)\s*(\[-?\d+\])?\s*$/u', '', $name) ?? $name);
    }

    private function cleanLabel(string $label): string
    {
        $label = preg_replace('/\s*\(\d+\)\s*$/u', '', $label) ?? $label;

        return trim(preg_replace('/\s+(Bölümü|Programı)(\s*\(|$)/u', '$2', $label) ?? $label);
    }

    private function yearLabel(string $label): string
    {
        return preg_match('/(\d{4})\s*-\s*(\d{4})/', $label, $m) ? $m[1].'-'.$m[2] : $label;
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)($message);
        }
    }
}
