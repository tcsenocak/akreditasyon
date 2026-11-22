<?php

namespace App\Services;

use App\Models\Curriculum;
use App\Models\Program;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * AÜ Mühendislik Fakültesi öğretim planı Excel dosyalarını (iki sütun bloklu yarıyıl düzeni) içe aktarır.
 */
class CurriculumImporter
{
    /**
     * Sol blok A..J, sağ blok L..U (V = okutulacağı yarıyıl).
     *
     * @var array<string, list<string>>
     */
    private const BLOCKS = [
        'left' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'],
        'right' => ['L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V'],
    ];

    private const VALID_TYPES = ['OZ', 'Z', 'S', 'ÜS', 'US'];

    /** @var list<string> */
    private const COMMON_PREFIXES = ['AIT', 'TDZ', 'YDZ', 'ISG', 'FMT', 'FFZ', 'FKM', 'FST', 'MUH', 'PRG', 'UNI'];

    /**
     * @return array{program: Program, curriculum: Curriculum, count: int, warnings: list<string>}
     */
    public function import(string $path, ?string $sourceLabel = null, bool $activate = true): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Dosya bulunamadı: {$path}");
        }
        ini_set('memory_limit', '512M');
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet(0);
        $lastRow = min($sheet->getHighestDataRow('A'), 400) ?: 400;
        $lastRow = max($lastRow, min($sheet->getHighestDataRow('L'), 400));
        $rows = $sheet->rangeToArray('A1:V'.$lastRow, null, true, false, true);
        $parsed = $this->parseRows($rows);
        $warnings = $parsed['warnings'];

        $fileName = $sourceLabel ?? basename($path);
        $isEnglish = Str::contains(Str::upper($fileName), ['INGILIZCE', 'ENGLISH'])
            || Str::contains(Str::lower($sheet->getTitle()), 'engineering');

        $department = $parsed['department'] ?? Str::of(pathinfo($fileName, PATHINFO_FILENAME))->replace(['-', '_', '.'], ' ')->title()->toString();
        $programName = $this->titleCase($department);
        $slug = Str::slug($programName.($isEnglish ? ' en' : ''));

        return DB::transaction(function () use ($parsed, $programName, $slug, $isEnglish, $fileName, $activate, $warnings): array {
            $program = Program::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'department' => $programName,
                    'name' => $programName,
                    'language' => $isEnglish ? 'EN' : 'TR',
                    'code_prefix' => $this->detectPrefix($parsed['courses']),
                ],
            );

            $startYear = $parsed['start_year'];
            $title = ($startYear ? $startYear.' ' : '').'Öğretim Planı (fakülte sitesi Excel)';
            $curriculum = Curriculum::query()->firstOrNew(['program_id' => $program->id, 'title' => $title]);
            $curriculum->fill([
                'start_year' => $startYear,
                'total_credit' => $parsed['total_credit'],
                'total_ects' => $parsed['total_ects'],
                'source' => $fileName,
                'is_active' => $activate,
            ])->save();

            if ($activate) {
                Curriculum::query()->where('program_id', $program->id)->whereKeyNot($curriculum->id)->update(['is_active' => false]);
            }

            app(CurriculumSync::class)->sync($curriculum, $parsed['courses']);

            $planEcts = collect($parsed['courses'])->where('category', 'plan')->sum('ects');
            if ($parsed['total_ects'] && abs($planEcts - $parsed['total_ects']) > 0.01) {
                $warnings[] = "Plan derslerinin AKTS toplamı {$planEcts}, dosyada belirtilen {$parsed['total_ects']}.";
            }

            return ['program' => $program, 'curriculum' => $curriculum, 'count' => count($parsed['courses']), 'warnings' => $warnings];
        });
    }

    /**
     * Fakülte sayfasındaki tüm .xlsx öğretim planlarını indirir.
     *
     * @return list<string> indirilen dosya yolları
     */
    public function downloadFromFacultyPage(string $pageUrl, string $targetDirectory): array
    {
        $html = Http::timeout(60)->get($pageUrl)->throw()->body();
        preg_match_all('/href="([^"]+\.xlsx)"/i', $html, $matches);
        $urls = array_values(array_unique($matches[1]));
        if (! is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0775, true);
        }
        $paths = [];
        foreach ($urls as $url) {
            $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);
            $target = rtrim($targetDirectory, '/\\').DIRECTORY_SEPARATOR.$name;
            Http::timeout(120)->sink($target)->get($url)->throw();
            $paths[] = $target;
        }

        return $paths;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{department: string|null, start_year: string|null, total_credit: float|null, total_ects: float|null, courses: list<array<string, mixed>>, warnings: list<string>}
     */
    public function parseRows(array $rows): array
    {
        $department = null;
        $startYear = null;
        $totalCredit = null;
        $totalEcts = null;
        $warnings = [];
        $courses = [];
        $section = ['left' => 'plan', 'right' => 'plan'];
        $semester = ['left' => null, 'right' => null];

        foreach ($rows as $row) {
            $cells = array_map(fn ($v) => is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v) ?? $v) : $v, $row);
            $joined = trim(implode(' ', array_filter($cells, fn ($v) => $v !== null && $v !== '')));
            if ($joined === '') {
                continue;
            }
            $joinedAscii = TextNormalizer::ascii($joined);

            if ($department === null && preg_match('/^(.+?)\s+(B[ÖO]L[ÜU]M[ÜU]|L[İI]SANS PROGRAMI)/u', $joined, $m) && ! Str::contains($joinedAscii, 'universitesi')) {
                $department = $this->cleanDepartment(trim($m[1]));
            }
            if (Str::contains($joinedAscii, 'bu ogretim plani')) {
                if (preg_match('/toplam\s*([\d.,]+)\s*kredi/iu', $joined, $m)) {
                    $totalCredit = (float) str_replace(',', '.', $m[1]);
                }
                if (preg_match('/([\d.,]+)\s*akts/iu', $joined, $m)) {
                    $totalEcts = (float) str_replace(',', '.', $m[1]);
                }
                if (preg_match('/(\d{4}\s*-\s*\d{4})/u', $joined, $m)) {
                    $startYear = str_replace(' ', '', $m[1]);
                }

                continue;
            }

            $a = (string) ($cells['A'] ?? '');
            $l = (string) ($cells['L'] ?? '');
            $aAscii = TextNormalizer::ascii($a);
            $lAscii = TextNormalizer::ascii($l);

            if (Str::contains($aAscii, 'fakulte secmeli')) {
                $section['left'] = 'faculty_elective';
                $semester['left'] = null;
            }
            if (Str::contains($lAscii, 'universite secmeli') && ! is_numeric($cells['U'] ?? null)) {
                $section['right'] = 'university_elective';
                $semester['right'] = null;
            }
            if (preg_match('/^secmeli dersler$/', trim($aAscii))) {
                $section = ['left' => 'elective_pool', 'right' => 'elective_pool'];

                continue;
            }

            foreach (['left' => $aAscii, 'right' => $lAscii] as $side => $text) {
                if (preg_match('/^(\d)\.\s*yariyil/', trim($text), $m)) {
                    $semester[$side] = (int) $m[1];
                }
            }

            foreach (self::BLOCKS as $side => $cols) {
                $course = $this->parseCourse($cells, $cols, $section[$side], $semester[$side]);
                if ($course !== null) {
                    $courses[] = $course;
                }
            }
        }

        if ($department === null) {
            $warnings[] = 'Bölüm adı başlıktan okunamadı; dosya adı kullanıldı.';
        }

        return [
            'department' => $department,
            'start_year' => $startYear,
            'total_credit' => $totalCredit,
            'total_ects' => $totalEcts,
            'courses' => $courses,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $cells
     * @param  list<string>  $cols
     * @return array<string, mixed>|null
     */
    private function parseCourse(array $cells, array $cols, string $category, ?int $semester): ?array
    {
        [$cCode, $cName, $cEn, $cType, $cMode, $cT, $cU, $cL, $cK, $cEcts, $cTerm] = $cols;
        $type = Str::upper(trim((string) ($cells[$cType] ?? '')));
        $type = $type === 'US' ? 'ÜS' : $type;
        $name = trim((string) ($cells[$cName] ?? ''));
        $ects = $cells[$cEcts] ?? null;

        if (! in_array($type, self::VALID_TYPES, true) || $name === '' || ! is_numeric($ects) || $name === '0') {
            return null;
        }
        $nameAscii = TextNormalizer::ascii($name);
        if (Str::contains($nameAscii, ['toplam', 'ders adi'])) {
            return null;
        }

        $code = trim((string) ($cells[$cCode] ?? ''));
        $code = ($code === '0' || $code === '') ? null : $code;
        $isPlaceholder = $code === null && preg_match('/secmeli|elective/', $nameAscii) === 1;

        $finalCategory = $category;
        $nameEnLower = Str::lower((string) ($cells[$cEn] ?? ''));
        if ($category === 'plan' && (Str::contains($nameAscii, ['mesleki egitim']) || Str::contains($nameEnLower, 'vocational education'))) {
            $finalCategory = 'alternative';
        }
        if (in_array($category, ['faculty_elective', 'university_elective'], true)) {
            $semester = null;
        }

        $nameEn = trim((string) ($cells[$cEn] ?? '')) ?: null;
        $split = TextNormalizer::splitSequence($name);
        $term = trim((string) ($cells[$cTerm] ?? '')) ?: null;

        return [
            'code' => $code,
            'root_code' => TextNormalizer::rootCode($code),
            'name' => rtrim($name, '*'),
            'name_en' => $nameEn,
            'normalized_name' => TextNormalizer::normalize($name),
            'type' => $type,
            'mode' => trim((string) ($cells[$cMode] ?? '')) ?: null,
            'theory' => (float) ($cells[$cT] ?? 0),
            'practice' => (float) ($cells[$cU] ?? 0),
            'lab' => (float) ($cells[$cL] ?? 0),
            'credit' => (float) ($cells[$cK] ?? 0),
            'ects' => (float) $ects,
            'semester' => $semester,
            'category' => $finalCategory,
            'is_placeholder' => $isPlaceholder,
            'term' => in_array($term, ['Güz', 'Bahar', 'Güz/Bahar', 'Yaz Okulu'], true) ? $term : null,
            'common_group' => $isPlaceholder ? null : TextNormalizer::commonGroup($name, $code, $nameEn),
            'sequence' => $split['sequence'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $courses
     */
    private function detectPrefix(array $courses): ?string
    {
        $counts = [];
        foreach ($courses as $course) {
            if ($course['root_code'] && preg_match('/^([A-Z]+)/', $course['root_code'], $m) && ! in_array($m[1], self::COMMON_PREFIXES, true)) {
                $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_key_first($counts);
    }

    /**
     * Başlıktaki yazım hatalarını ve kısaltmaları düzeltir ("MAKİNE" → "MAKİNE MÜHENDİSLİĞİ").
     */
    private function cleanDepartment(string $department): string
    {
        $department = str_replace(['MÜHENDİLİĞİ', 'MUHENDISLIGI'], 'MÜHENDİSLİĞİ', $department);
        if (! Str::contains(TextNormalizer::ascii($department), 'muhendis')) {
            $department .= ' MÜHENDİSLİĞİ';
        }

        return $department;
    }

    private function titleCase(string $text): string
    {
        $small = ['ve', 'ile', 'veya'];
        $capitalize = function (string $word) use ($small): string {
            if (in_array($word, $small, true) || $word === '') {
                return $word;
            }
            $first = mb_substr($word, 0, 1);
            $first = $first === 'i' ? 'İ' : ($first === 'ı' ? 'I' : mb_strtoupper($first));

            return $first.mb_substr($word, 1);
        };

        return implode(' ', array_map(
            fn (string $word): string => implode('-', array_map($capitalize, explode('-', $word))),
            explode(' ', TextNormalizer::lower(trim($text))),
        ));
    }
}
