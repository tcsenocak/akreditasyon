<?php

namespace Database\Seeders;

use App\Models\GradeConversion;
use Illuminate\Database\Seeder;

class GradeConversionSeeder extends Seeder
{
    /**
     * Sık görülen farklı not gösterimleri için başlangıç dönüşümleri (komisyon düzenleyebilir).
     */
    public function run(): void
    {
        $rows = [
            ['source_grade' => 'A1', 'letter' => 'AA', 'note' => 'A1-F3 sistemi'],
            ['source_grade' => 'A2', 'letter' => 'BA', 'note' => 'A1-F3 sistemi'],
            ['source_grade' => 'B1', 'letter' => 'BA', 'note' => 'A1-F3 sistemi'],
            ['source_grade' => 'B2', 'letter' => 'BB', 'note' => 'A1-F3 sistemi'],
            ['source_grade' => 'B3', 'letter' => 'CB', 'note' => 'A1-F3 sistemi'],
            ['source_grade' => 'C1', 'letter' => 'CB', 'note' => 'A1-F3 sistemi'],
            ['source_grade' => 'C2', 'letter' => 'CC', 'note' => 'A1-F3 sistemi'],
            ['source_grade' => 'C3', 'letter' => 'DC', 'note' => 'A1-F3 sistemi'],
            ['source_grade' => 'D', 'letter' => 'DD', 'note' => 'A1-F3 sistemi'],
            ['source_grade' => 'F1', 'letter' => 'FF', 'note' => 'A1-F3 sistemi'],
            ['source_grade' => 'F2', 'letter' => 'FF', 'note' => 'A1-F3 sistemi'],
            ['source_grade' => 'F3', 'letter' => 'FF', 'note' => 'A1-F3 sistemi'],
        ];
        foreach ($rows as $row) {
            GradeConversion::query()->updateOrCreate(['source_grade' => $row['source_grade']], $row);
        }
    }
}
