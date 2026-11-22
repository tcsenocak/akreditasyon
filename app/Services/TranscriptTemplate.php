<?php

namespace App\Services;

use App\Models\CurriculumCourse;
use App\Models\Student;
use App\Models\TranscriptCourse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use RuntimeException;

/**
 * Not dökümü / ders listesi Excel şablonu: indirme ve içe aktarma.
 */
class TranscriptTemplate
{
    /** @var array<string, string> sütun anahtarı => başlık */
    public const COLUMNS = [
        'institution' => 'Kurum (Üniversite)',
        'faculty' => 'Fakülte / MYO',
        'department' => 'Bölüm / Program',
        'semester' => 'Yarıyıl',
        'code' => 'Ders Kodu',
        'name' => 'Ders Adı',
        'credit' => 'Kredi',
        'ects' => 'AKTS',
        'grade' => 'Harf Notu / Puan',
        'year' => 'Eğitim-Öğretim Yılı',
    ];

    public function __construct(private CatalogService $catalog, private TranscriptService $transcripts) {}

    public function build(?Student $student = null, bool $withGrades = true): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Dersler');
        $columns = $withGrades ? self::COLUMNS : array_diff_key(self::COLUMNS, ['grade' => 1, 'year' => 1]);
        $sheet->fromArray([array_values($columns)]);
        $example = [
            'institution' => $student?->previous_institution ?: 'Karadeniz Teknik Üniversitesi',
            'faculty' => $student?->previous_faculty ?: 'Mühendislik Fakültesi',
            'department' => $student?->previous_program ?: 'Metalurji ve Malzeme Mühendisliği',
            'semester' => 1, 'code' => 'MAT1001', 'name' => 'Matematik I', 'credit' => 4, 'ects' => 6, 'grade' => 'BB', 'year' => '2024-2025',
        ];
        $sheet->fromArray([array_values(array_intersect_key($example, $columns))], null, 'A2');
        $last = chr(ord('A') + count($columns) - 1);
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F0FA');
        foreach (range('A', $last) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $help = $spreadsheet->createSheet();
        $help->setTitle('Açıklama');
        $help->fromArray([
            ['Nasıl doldurulur?'],
            ['1. Her satıra transkriptinizdeki bir dersi yazın; 2. satırdaki örneği silin.'],
            ['2. Kurum, Bölüm, Ders Adı'.($withGrades ? ' ve Harf Notu' : '').' zorunludur. Ders adlarını transkriptteki gibi yazın.'],
            ['3. Kredi ve AKTS değerlerini transkriptten aynen aktarın (ondalık için virgül veya nokta).'],
            $withGrades ? ['4. Not: AA, BA, BB ... FF; 100\'lük puan; 4\'lük katsayı (3,50) veya G/M yazılabilir.'] : ['4. Bu şablon ders kataloğu içindir; not sütunu yoktur.'],
            ['5. Bilgiler, yükleyeceğiniz resmi not döküm belgesiyle komisyonca karşılaştırılır.'],
        ]);
        $help->getColumnDimension('A')->setWidth(110);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $path): array
    {
        $sheet = IOFactory::load($path)->getSheet(0);
        $rows = $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().min($sheet->getHighestDataRow(), 2000), null, true, false, false);
        $header = array_shift($rows) ?? [];
        $map = [];
        foreach ($header as $i => $label) {
            $key = $this->columnKey((string) $label);
            if ($key && ! isset($map[$key])) {
                $map[$key] = $i;
            }
        }
        foreach (['institution', 'department', 'name'] as $required) {
            if (! isset($map[$required])) {
                throw new RuntimeException('Şablon sütunları bulunamadı: "'.self::COLUMNS[$required].'". Lütfen sistemden indirdiğiniz şablonu kullanın.');
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $get = fn (string $k) => isset($map[$k]) ? trim((string) ($row[$map[$k]] ?? '')) : '';
            $num = fn (string $v) => $v !== '' && is_numeric(str_replace(',', '.', $v)) ? (float) str_replace(',', '.', $v) : null;
            if ($get('name') === '') {
                continue;
            }
            $out[] = [
                'institution' => $get('institution'),
                'faculty' => $get('faculty') ?: null,
                'department' => $get('department'),
                'semester' => $num($get('semester')) !== null ? (int) $num($get('semester')) : null,
                'code' => $get('code') ?: null,
                'name' => $get('name'),
                'credit' => $num($get('credit')),
                'ects' => $num($get('ects')),
                'grade' => $get('grade') ?: null,
                'year' => $get('year') ?: null,
            ];
        }

        return $out;
    }

    /**
     * Öğrencinin şablonla yüklediği dersler: dersler kataloğa "öğrenci beyanı" olarak eklenir, döküm satırları kataloğa bağlanır.
     *
     * @return array{count: int, warnings: list<string>}
     */
    public function importForStudent(Student $student, string $path, ?int $userId): array
    {
        $warnings = [];
        $count = 0;
        foreach ($this->parse($path) as $index => $row) {
            $line = $index + 2;
            $institution = $row['institution'] ?: (string) $student->previous_institution;
            if ($institution === '' || $row['department'] === '') {
                $warnings[] = "Satır {$line}: kurum veya bölüm boş, atlandı.";

                continue;
            }
            $catalogCourse = null;
            if (! CatalogService::isAtaturk($institution)) {
                $program = $this->catalog->upsertProgram($institution, $row['faculty'], $row['department'], 'ogrenci', $userId, false);
                $catalogCourse = $this->catalog->upsertCourse($program, $row);
                // Bölümü henüz seçilmemiş öğrencinin bölümü olarak kaydet
                if (! $student->source_program_key && ! $this->catalog->guessProgramKey($student->previous_institution, $student->previous_program)) {
                    $student->update([
                        'source_program_key' => 'ext:'.$program->id,
                        'previous_institution' => $program->institution,
                        'previous_program' => $program->name,
                        'previous_faculty' => $student->previous_faculty ?: $program->faculty,
                    ]);
                }
            }
            if (! $row['grade']) {
                $warnings[] = "Satır {$line}: not boş; ders bölüm listesine eklendi ama dökümünüze eklenmedi.";

                continue;
            }

            if ($catalogCourse) {
                $data = $this->catalog->resolve('ext:'.$catalogCourse->id);
            } else {
                $source = CurriculumCourse::query()->where('is_placeholder', false)
                    ->where(fn ($q) => $row['code'] ? $q->where('root_code', TextNormalizer::rootCode($row['code'])) : $q->where('normalized_name', TextNormalizer::normalize($row['name'])))
                    ->latest('id')->first();
                if (! $source) {
                    $warnings[] = "Satır {$line}: {$row['name']} AÜ kataloğunda bulunamadı; listeden seçerek ekleyin.";

                    continue;
                }
                $data = $this->catalog->resolve('au:'.$source->id);
            }

            $this->transcripts->save(new TranscriptCourse(['student_id' => $student->id]), $data + [
                'grade_raw' => $row['grade'],
                'semester' => $row['semester'],
                'academic_year' => $row['year'],
                'source' => 'sablon',
            ]);
            $count++;
        }

        return ['count' => $count, 'warnings' => $warnings];
    }

    /**
     * Komisyonun bir kurumun bölüm ders listesini kataloğa yüklemesi (doğrulanmış).
     *
     * @return array{programs: int, courses: int}
     */
    public function importCatalog(string $path, ?int $userId): array
    {
        $programs = [];
        $courses = 0;
        foreach ($this->parse($path) as $row) {
            if ($row['institution'] === '' || $row['department'] === '') {
                continue;
            }
            $program = $this->catalog->upsertProgram($row['institution'], $row['faculty'], $row['department'], 'komisyon', $userId, true);
            $this->catalog->upsertCourse($program, $row);
            $programs[$program->id] = true;
            $courses++;
        }

        return ['programs' => count($programs), 'courses' => $courses];
    }

    private function columnKey(string $label): ?string
    {
        $l = TextNormalizer::ascii($label);

        return match (true) {
            str_contains($l, 'kurum') || str_contains($l, 'universite') => 'institution',
            str_contains($l, 'fakulte') || str_contains($l, 'myo') => 'faculty',
            str_contains($l, 'bolum') || str_contains($l, 'program') => 'department',
            str_contains($l, 'yariyil') || $l === 'donem' => 'semester',
            str_contains($l, 'kod') => 'code',
            str_contains($l, 'ders adi') || $l === 'ders' => 'name',
            str_contains($l, 'akts') || str_contains($l, 'ects') => 'ects',
            str_contains($l, 'kredi') => 'credit',
            str_contains($l, 'not') || str_contains($l, 'puan') => 'grade',
            str_contains($l, 'yil') => 'year',
            default => null,
        };
    }
}
