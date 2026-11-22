<?php

namespace Tests\Feature;

use App\Services\ExemptionEngine;
use App\Services\GradeService;
use App\Services\TextNormalizer;
use App\Services\TranscriptParser;
use Database\Seeders\GradeConversionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KuralTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string|null, 2: bool}>
     */
    public static function gradeCases(): array
    {
        return [
            'harf' => ['BB', 'BB', true],
            'kucuk harf' => ['ba', 'BA', true],
            'yuzluk 88' => ['88', 'AA', true],
            'yuzluk 87,99' => ['87,99', 'BA', true],
            'yuzluk 60' => ['60', 'CC', true],
            'yuzluk 52' => ['52', 'DD', true],
            'yuzluk 49' => ['49', 'FF', false],
            'dortluk' => ['3,50', 'BA', true],
            'gecer' => ['G', 'CC', true],
            'muaf' => ['Muaf', 'CC', true],
            'devamsiz' => ['Z', 'FF', false],
            'ff' => ['FF', 'FF', false],
            'arti eksi' => ['B+', 'BA', true],
            'a1 sistemi' => ['C2', 'CC', true],
        ];
    }

    #[DataProvider('gradeCases')]
    public function test_not_donusumu(string $raw, ?string $letter, bool $passed): void
    {
        $this->seed(GradeConversionSeeder::class);
        $result = app(GradeService::class)->convert($raw);

        $this->assertSame($letter, $result['letter'], $raw);
        $this->assertSame($passed, $result['passed'], $raw);
    }

    public function test_dc_dd_kosullu_isaretlenir(): void
    {
        $result = app(GradeService::class)->convert('DD');

        $this->assertTrue($result['passed']);
        $this->assertTrue($result['conditional']);
    }

    public function test_muafiyet_sinavi_tablosu(): void
    {
        $grades = app(GradeService::class);

        $this->assertSame('AA', $grades->letterFromScore(100));
        $this->assertSame('CB', $grades->letterFromScore(67));
        $this->assertSame('DD', $grades->letterFromScore(50));
        $this->assertSame('FF', $grades->letterFromScore(49.99));
    }

    public function test_coklu_kaynak_notu_akts_agirlikli(): void
    {
        $letter = app(GradeService::class)->combine([['letter' => 'AA', 'ects' => 6], ['letter' => 'CC', 'ects' => 2]]);

        $this->assertSame('BA', $letter); // (4*6+2*2)/8 = 3.5
    }

    public function test_yariyil_intibaki_30_akts(): void
    {
        $this->assertSame(1, ExemptionEngine::semesterFor(0));
        $this->assertSame(1, ExemptionEngine::semesterFor(29.5));
        $this->assertSame(2, ExemptionEngine::semesterFor(30));
        $this->assertSame(3, ExemptionEngine::semesterFor(65));
        $this->assertSame(8, ExemptionEngine::semesterFor(240));
    }

    public function test_ad_benzerligi_ve_sira_numarasi(): void
    {
        $this->assertSame(1.0, TextNormalizer::similarity('Matematik I', 'MATEMATİK-I'));
        $this->assertLessThan(0.5, TextNormalizer::similarity('Matematik I', 'Matematik II'));
        $this->assertGreaterThanOrEqual(0.78, TextNormalizer::similarity('Malzeme Bilimine Giriş', 'Malzeme Bilimi'));
        $this->assertGreaterThanOrEqual(0.78, TextNormalizer::similarity('Calculus I', 'Matematik I'));
        $this->assertLessThan(0.55, TextNormalizer::similarity('Termodinamik', 'Malzeme Bilimi'));
    }

    public function test_ortak_zorunlu_grup_tespiti(): void
    {
        $this->assertSame('AIIT', TextNormalizer::commonGroup('Atatürk İlkeleri ve İnkılap Tarihi I'));
        $this->assertSame('TD', TextNormalizer::commonGroup('Türk Dili II'));
        $this->assertSame('YD', TextNormalizer::commonGroup('İngilizce I'));
        $this->assertSame('MYD', TextNormalizer::commonGroup('Mesleki İngilizce I'));
        $this->assertSame('ISG', TextNormalizer::commonGroup('İş Sağlığı ve Güvenliği'));
        $this->assertNull(TextNormalizer::commonGroup('Fizik I'));
    }

    public function test_yapistirilan_tablo_ayristirilir(): void
    {
        $text = "Kod\tDers Adı\tKredi\tAKTS\tNot\tYarıyıl\nMAT101\tMatematik I\t4\t6\tBB\t1\nFIZ101\tFizik I\t3\t5\t45\t1";
        $parsed = app(TranscriptParser::class)->parseText($text);

        $this->assertCount(2, $parsed['rows']);
        $this->assertSame('MAT101', $parsed['rows'][0]['code']);
        $this->assertSame(6.0, $parsed['rows'][0]['ects']);
        $this->assertSame('45', $parsed['rows'][1]['grade_raw']);
    }

    public function test_pdf_benzeri_satirlar_ayristirilir(): void
    {
        $text = "KARADENİZ TEKNİK ÜNİVERSİTESİ\nMÜHENDİSLİK FAKÜLTESİ\nBölüm: Metalurji ve Malzeme Mühendisliği\n2023-2024 Güz\nMAT 1001 Matematik I 4 0 4 6 BA\nKİM1003 Genel Kimya 3 2 4 5 CC\n";
        $parsed = app(TranscriptParser::class)->parseText($text);

        $this->assertCount(2, $parsed['rows']);
        $this->assertSame('MAT1001', $parsed['rows'][0]['code']);
        $this->assertSame('Matematik I', $parsed['rows'][0]['name']);
        $this->assertSame(6.0, $parsed['rows'][0]['ects']);
        $this->assertSame('BA', $parsed['rows'][0]['grade_raw']);
        $this->assertSame('KARADENİZ TEKNİK ÜNİVERSİTESİ', $parsed['meta']['institution']);
        $this->assertSame('Metalurji ve Malzeme Mühendisliği', $parsed['meta']['program']);
    }
}
