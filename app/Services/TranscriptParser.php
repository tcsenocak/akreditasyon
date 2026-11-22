<?php

namespace App\Services;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Not döküm belgesini (PDF metni, Excel/CSV veya yapıştırılan tablo) ders satırlarına ayırır.
 */
class TranscriptParser
{
    private const GRADE_PATTERN = '/^(AA|BA|BB|CB|CC|DC|DD|FD|FF|A\+|A-|A|B\+|B-|B|C\+|C-|C|D\+|D-|D|F|G|M|Z|DZ|YZ|P|S|U|EX|MUAF|GEÇER|GEÇTİ|BAŞARILI)$/u';

    private const CODE_PATTERN = '/^([A-ZÇĞİÖŞÜ]{2,6}[\s\-]?\d{2,4}[A-Z]?)\b/u';

    /**
     * @return array{rows: list<array<string, mixed>>, meta: array<string, string|null>}
     */
    public function parseFile(string $path, string $originalName): array
    {
        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => $this->parseText($this->pdfText($path)),
            'xlsx', 'xls', 'csv', 'ods' => $this->parseTable($this->spreadsheetRows($path)),
            default => $this->parseText((string) file_get_contents($path)),
        };
    }

    public function pdfText(string $path): string
    {
        $parser = new PdfParser;

        return $parser->parseFile($path)->getText();
    }

    /**
     * Sekme/çoklu boşluk ayrımlı yapıştırılmış metin veya PDF metni.
     *
     * @return array{rows: list<array<string, mixed>>, meta: array<string, string|null>}
     */
    public function parseText(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $tabular = collect($lines)->filter(fn ($l) => substr_count($l, "\t") >= 2)->count();
        if ($tabular >= 2) {
            return $this->parseTable(array_map(fn ($l) => explode("\t", $l), $lines), $this->detectMeta($lines));
        }

        $rows = [];
        $semester = null;
        $year = null;
        $term = null;
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            if ($line === '') {
                continue;
            }
            $ascii = TextNormalizer::ascii($line);
            if (preg_match('/^(\d)\s*\.?\s*(yariyil|yari yil|donem|semester)/', $ascii, $m)) {
                $semester = (int) $m[1];
            }
            if (preg_match('/(20\d{2})\s*[-\/]\s*(20\d{2})/', $line, $m)) {
                $year = $m[1].'-'.$m[2];
            }
            if (preg_match('/\b(guz|bahar|yaz|fall|spring|summer)\b/', $ascii, $m)) {
                $term = ['guz' => 'Güz', 'fall' => 'Güz', 'bahar' => 'Bahar', 'spring' => 'Bahar', 'yaz' => 'Yaz', 'summer' => 'Yaz'][$m[1]];
            }
            $row = $this->parseLine($line);
            if ($row) {
                $rows[] = $row + ['semester' => $semester, 'academic_year' => $year, 'term' => $term];
            }
        }

        return ['rows' => $rows, 'meta' => $this->detectMeta($lines)];
    }

    /**
     * Tek satırdan ders bilgisi: [kod] ad [sayılar...] not [puan]
     *
     * @return array<string, mixed>|null
     */
    public function parseLine(string $line): ?array
    {
        $tokens = preg_split('/\s+/u', trim($line)) ?: [];
        if (count($tokens) < 3) {
            return null;
        }
        $code = null;
        if (preg_match(self::CODE_PATTERN, $line, $m)) {
            $code = str_replace(' ', '', $m[1]);
            $consumed = count(preg_split('/\s+/u', trim($m[1])) ?: []);
            $tokens = array_slice($tokens, $consumed);
        }

        $gradeIndex = null;
        for ($i = count($tokens) - 1; $i >= 1; $i--) {
            if (preg_match(self::GRADE_PATTERN, mb_strtoupper($tokens[$i], 'UTF-8'))) {
                $gradeIndex = $i;
                break;
            }
        }
        if ($gradeIndex === null) {
            return null;
        }

        $nameTokens = [];
        $numbers = [];
        foreach (array_slice($tokens, 0, $gradeIndex) as $token) {
            $numeric = str_replace(',', '.', $token);
            if (is_numeric($numeric) && $nameTokens !== []) {
                $numbers[] = (float) $numeric;
            } elseif ($numbers === []) {
                $nameTokens[] = $token;
            }
        }
        $name = trim(implode(' ', $nameTokens), ' -|');
        if ($name === '' || mb_strlen($name) < 3 || $numbers === [] && $code === null) {
            return null;
        }

        [$credit, $ects] = match (true) {
            count($numbers) >= 4 => [$numbers[count($numbers) - 2], $numbers[count($numbers) - 1]],
            count($numbers) >= 2 => [$numbers[count($numbers) - 2], $numbers[count($numbers) - 1]],
            count($numbers) === 1 => [null, $numbers[0]],
            default => [null, null],
        };

        return [
            'code' => $code,
            'name' => $name,
            'credit' => $credit,
            'ects' => $ects,
            'grade_raw' => mb_strtoupper($tokens[$gradeIndex], 'UTF-8'),
        ];
    }

    /**
     * Başlık satırını bularak sütunları eşler (Kod, Ders Adı, Kredi, AKTS, Not, Yarıyıl, Yıl, Dönem).
     *
     * @param  list<array<int|string, mixed>>  $table
     * @param  array<string, string|null>  $meta
     * @return array{rows: list<array<string, mixed>>, meta: array<string, string|null>}
     */
    public function parseTable(array $table, array $meta = []): array
    {
        $map = null;
        $rows = [];
        foreach ($table as $raw) {
            $cells = array_values(array_map(fn ($v) => trim((string) $v), $raw));
            if (implode('', $cells) === '') {
                continue;
            }
            if ($map === null) {
                $candidate = $this->headerMap($cells);
                if ($candidate !== null) {
                    $map = $candidate;

                    continue;
                }
                $line = $this->parseLine(implode(' ', $cells));
                if ($line) {
                    $rows[] = $line + ['semester' => null, 'academic_year' => null, 'term' => null];
                }

                continue;
            }
            $get = fn (string $key) => isset($map[$key]) ? ($cells[$map[$key]] ?? null) : null;
            $name = $get('name');
            $grade = $get('grade');
            if (! $name || $grade === null || $grade === '') {
                continue;
            }
            $num = fn (?string $v) => ($v !== null && is_numeric(str_replace(',', '.', $v))) ? (float) str_replace(',', '.', $v) : null;
            $rows[] = [
                'code' => $get('code') ?: null,
                'name' => $name,
                'credit' => $num($get('credit')),
                'ects' => $num($get('ects')),
                'grade_raw' => mb_strtoupper($grade, 'UTF-8'),
                'semester' => $num($get('semester')) ? (int) $num($get('semester')) : null,
                'academic_year' => $get('year') ?: null,
                'term' => $get('term') ?: null,
            ];
        }

        return ['rows' => $rows, 'meta' => $meta + ['institution' => null, 'faculty' => null, 'program' => null]];
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, int>|null
     */
    private function headerMap(array $cells): ?array
    {
        $map = [];
        foreach ($cells as $i => $cell) {
            $c = TextNormalizer::ascii($cell);
            match (true) {
                ! isset($map['code']) && preg_match('/^(ders )?kod|^code/', $c) === 1 => $map['code'] = $i,
                ! isset($map['name']) && preg_match('/ders ad|^ad$|^ders$|course (title|name)|^name/', $c) === 1 => $map['name'] = $i,
                ! isset($map['ects']) && preg_match('/akts|ects/', $c) === 1 => $map['ects'] = $i,
                ! isset($map['credit']) && preg_match('/kredi|credit|^k$/', $c) === 1 => $map['credit'] = $i,
                ! isset($map['grade']) && preg_match('/harf|not$|^not|grade|basari/', $c) === 1 => $map['grade'] = $i,
                ! isset($map['semester']) && preg_match('/yariyil|semester|donem no/', $c) === 1 => $map['semester'] = $i,
                ! isset($map['year']) && preg_match('/egitim yili|ogretim yili|akademik yil|^yil|year/', $c) === 1 => $map['year'] = $i,
                ! isset($map['term']) && preg_match('/^donem$|^term$/', $c) === 1 => $map['term'] = $i,
                default => null,
            };
        }

        return isset($map['name'], $map['grade']) ? $map : null;
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, string|null>
     */
    private function detectMeta(array $lines): array
    {
        $meta = ['institution' => null, 'faculty' => null, 'program' => null];
        foreach (array_slice($lines, 0, 60) as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            if ($line === '' || mb_strlen($line) > 140) {
                continue;
            }
            if (! $meta['institution'] && preg_match('/([\p{L}\s\.\-]+ (Ü|Ü)NİVERSİTESİ|[\p{L}\s\.\-]+ Üniversitesi|[\p{L}\s\.\-]+ University)/u', $line, $m)) {
                $meta['institution'] = trim($m[1]);
            }
            if (! $meta['faculty'] && preg_match('/([\p{L}\s\-]+ (FAKÜLTESİ|Fakültesi|YÜKSEKOKULU|Yüksekokulu|MESLEK YÜKSEKOKULU|Meslek Yüksekokulu))/u', $line, $m)) {
                $meta['faculty'] = trim($m[1]);
            }
            if (! $meta['program'] && preg_match('/(?:Bölüm|Program|BÖLÜM|PROGRAM)[\p{L}\s]*[:\-]\s*(.+)$/u', $line, $m)) {
                $meta['program'] = trim($m[1]);
            } elseif (! $meta['program'] && preg_match('/([\p{L}\s\-]+ (BÖLÜMÜ|Bölümü|PROGRAMI|Programı))/u', $line, $m)) {
                $meta['program'] = trim($m[1]);
            }
        }

        return $meta;
    }

    /**
     * @return list<list<mixed>>
     */
    private function spreadsheetRows(string $path): array
    {
        $sheet = IOFactory::load($path)->getSheet(0);
        $lastColumn = $sheet->getHighestDataColumn();
        $lastRow = min($sheet->getHighestDataRow(), 1000);

        return $sheet->rangeToArray('A1:'.$lastColumn.$lastRow, null, true, false, false);
    }
}
