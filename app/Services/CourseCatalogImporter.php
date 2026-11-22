<?php

namespace App\Services;

use App\Models\Curriculum;
use App\Models\Program;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AÜ Mühendislik Fakültesi "Ders Bilgi Paketi" (.doc) dosyalarını eski öğretim planı olarak aktarır.
 * Her ders: kod, ad, T-U-L-K-AKTS ve ders içeriği. "Bölüm Seçmeli I (X)" satırları seçmeli havuzuna,
 * içeriği "Alınan dersin..." olan satırlar seçmeli yuvasına dönüşür.
 */
class CourseCatalogImporter
{
    public function __construct(private WordDocReader $reader) {}

    /**
     * @return array{program: Program, curriculum: Curriculum, count: int, warnings: list<string>}
     */
    public function import(string $path, ?string $sourceLabel = null, string $startYear = '2018-2019', bool $activate = false): array
    {
        $parsed = $this->parse($this->reader->text($path));
        if ($parsed['courses'] === []) {
            throw new RuntimeException('Ders bilgi paketinde ders bulunamadı.');
        }
        $warnings = [];
        $program = $this->findProgram($parsed['department']);
        if (! $program) {
            throw new RuntimeException('Program bulunamadı: '.$parsed['department'].'. Önce güncel öğretim planını aktarın.');
        }

        return DB::transaction(function () use ($parsed, $program, $sourceLabel, $path, $startYear, $activate, $warnings): array {
            $curriculum = Curriculum::query()->firstOrNew(['program_id' => $program->id, 'title' => $startYear.' Öğretim Planı (Ders Bilgi Paketi)']);
            $planEcts = collect($parsed['courses'])->where('category', 'plan')->sum('ects');
            $curriculum->fill([
                'start_year' => $startYear,
                'total_credit' => collect($parsed['courses'])->where('category', 'plan')->sum('credit'),
                'total_ects' => $planEcts,
                'source' => $sourceLabel ?? basename($path),
                'is_active' => $activate,
            ])->save();
            app(CurriculumSync::class)->sync($curriculum, $parsed['courses']);
            if ($activate) {
                Curriculum::query()->where('program_id', $program->id)->whereKeyNot($curriculum->id)->update(['is_active' => false]);
            }
            if (abs($planEcts - 240) > 0.01) {
                $warnings[] = "Plan derslerinin AKTS toplamı {$planEcts} (240 beklenirdi).";
            }

            return ['program' => $program, 'curriculum' => $curriculum, 'count' => count($parsed['courses']), 'warnings' => $warnings];
        });
    }

    /**
     * @return array{department: string|null, courses: list<array<string, mixed>>}
     */
    public function parse(string $text): array
    {
        $cells = array_map(fn ($c) => trim(preg_replace('/\s+/u', ' ', str_replace(["\r", "\x0B", "\x0C"], ' ', $c)) ?? ''), explode("\x07", $text));
        $department = null;
        $semester = 1;
        $markerSeen = false;
        $courses = [];
        $seen = [];

        $count = count($cells);
        for ($i = 0; $i < $count; $i++) {
            $cell = $cells[$i];
            if ($department === null && preg_match('/^(.+?) BÖLÜMÜ/u', $cell, $m)) {
                $department = trim($m[1]);
            }
            if (! preg_match('/DERSİN( KODU)?$/u', $cell)) {
                continue;
            }
            if (preg_match('/(YARIYIL|DÖNEM)/u', $cell)) {
                $semester = $this->semesterNumber($cell, $markerSeen ? $semester + 1 : 1);
                $markerSeen = true;
            }
            // başlık: KODU, ADI, T, U, L, K, AKTS, (satır sonu), kod, ad, t, u, l, k, akts
            $aktsHeader = array_search('AKTS', array_slice($cells, $i, 8, true), true);
            if ($aktsHeader === false) {
                continue;
            }
            $j = $aktsHeader + 1;
            while ($j < $count && $cells[$j] === '') {
                $j++;
            }
            [$code, $name, $t, $u, $l, $k, $ects] = array_pad(array_slice($cells, $j, 7), 7, '');
            if (! is_numeric(str_replace(',', '.', $ects)) || $name === '') {
                continue;
            }
            $content = '';
            for ($c = $j + 7; $c < min($count, $j + 14); $c++) {
                if (str_contains($cells[$c], 'DERSİN KODU')) {
                    break;
                }
                if ($cells[$c] !== '' && ! str_contains($cells[$c], 'Ders İçeriği ve Programı')) {
                    $content = $cells[$c];
                    break;
                }
            }
            $course = $this->course($code, $name, (float) $t, (float) $u, (float) $l, (float) str_replace(',', '.', $k), (float) str_replace(',', '.', $ects), $semester, $content);
            $key = $course['category'].'|'.$course['normalized_name'].'|'.$semester;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $courses[] = $course;
        }

        return ['department' => $department, 'courses' => $courses];
    }

    /**
     * @return array<string, mixed>
     */
    private function course(string $code, string $name, float $t, float $u, float $l, float $credit, float $ects, int $semester, string $content): array
    {
        $ascii = TextNormalizer::ascii($name);
        $isGeneric = (bool) preg_match('/alinan ders/i', TextNormalizer::ascii($content));
        $category = 'plan';
        $placeholder = false;
        $type = 'Z';

        if (preg_match('/^(.*secmeli[^(]*)\((.+)\)\s*$/u', $ascii) && preg_match('/\(([^)]+)\)\s*$/u', $name, $m)) {
            // "Bölüm Seçmeli I (Hidrometalurji)" → havuz dersi
            $category = str_contains($ascii, 'universite') ? 'university_elective' : 'elective_pool';
            $type = str_contains($ascii, 'universite') ? 'ÜS' : 'S';
            $name = trim($m[1]);
        } elseif ($isGeneric || preg_match('/^(bolum|fakulte|universite)\b.*secmeli/', $ascii)) {
            $placeholder = true;
            $type = str_contains($ascii, 'universite') ? 'ÜS' : 'S';
        }
        if (in_array(TextNormalizer::commonGroup($name, $code), ['AIIT', 'TD', 'YD', 'ISG'], true)) {
            $type = 'OZ';
        }
        $split = TextNormalizer::splitSequence($name);
        $code = trim($code);

        return [
            'code' => $code !== '' ? $code : null,
            'root_code' => TextNormalizer::rootCode($code),
            'name' => $name,
            'name_en' => null,
            'normalized_name' => TextNormalizer::normalize($name),
            'type' => $type,
            'mode' => null,
            'theory' => $t,
            'practice' => $u,
            'lab' => $l,
            'credit' => $credit,
            'ects' => $ects,
            'semester' => $category === 'university_elective' ? null : $semester,
            'category' => $category,
            'is_placeholder' => $placeholder,
            'term' => null,
            'common_group' => $placeholder ? null : TextNormalizer::commonGroup($name, $code),
            'sequence' => $split['sequence'],
            'content' => $isGeneric || $content === '' ? null : mb_substr($content, 0, 5000),
        ];
    }

    /**
     * "I.YARIYIL", "II. DÖNEM", "4.DÖNEM" → sayı; numarasız başlıkta varsayılan (önceki + 1).
     */
    private function semesterNumber(string $cell, int $fallback): int
    {
        if (preg_match('/(\d)\s*\.?\s*(YARIYIL|DÖNEM)/u', $cell, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\b(VIII|VII|VI|IV|V|III|II|I)\s*\.?\s*(YARIYIL|DÖNEM)/u', $cell, $m)) {
            return ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8][$m[1]];
        }

        return min(8, $fallback);
    }

    private function findProgram(?string $department): ?Program
    {
        if ($department === null) {
            return null;
        }
        $wanted = TextNormalizer::normalize(str_replace('MÜHENDİLİĞİ', 'MÜHENDİSLİĞİ', $department));

        return Program::query()->where('language', 'TR')->get()
            ->sortByDesc(fn (Program $p) => TextNormalizer::similarity($p->name, $wanted))
            ->first(fn (Program $p) => TextNormalizer::similarity($p->name, $wanted) >= 0.85);
    }

    /**
     * @return list<string>
     */
    public static function linksOn(string $html): array
    {
        preg_match_all('/href="([^"]+BOLUMU[^"]*\.doc)"/i', $html, $m);

        return array_values(array_unique(array_filter($m[1], fn ($u) => ! Str::contains(Str::upper($u), 'TAMAMLAMA'))));
    }
}
