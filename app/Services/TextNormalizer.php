<?php

namespace App\Services;

/**
 * Türkçe ders adlarını karşılaştırma için normalleştirir ve benzerlik hesaplar.
 */
class TextNormalizer
{
    /** @var array<string, string> */
    private const TRANSLIT = [
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
        'â' => 'a', 'î' => 'i', 'û' => 'u', 'é' => 'e', 'ä' => 'a',
    ];

    /** @var list<string> */
    private const STOPWORDS = [
        've', 'ile', 'icin', 'bir', 'giris', 'temel', 'genel', 'esaslari', 'prensipleri', 'kavramlari',
        'and', 'of', 'the', 'to', 'in', 'for', 'introduction', 'intro', 'principles', 'fundamentals', 'basic', 'a', 'an',
        'ders', 'dersi', 'course',
    ];

    /**
     * Ad/İngilizce eşanlamlıları (kök 5 harf üzerinden çalışır).
     *
     * @var array<string, string>
     */
    private const SYNONYMS = [
        'calculus' => 'matematik', 'mathematics' => 'matematik', 'math' => 'matematik',
        'physics' => 'fizik', 'chemistry' => 'kimya', 'chemical' => 'kimya',
        'laboratuar' => 'laboratuvar', 'lab' => 'laboratuvar', 'laboratory' => 'laboratuvar',
        'dogrusal' => 'lineer', 'linear' => 'lineer', 'algebra' => 'cebir',
        'differential' => 'diferansiyel', 'equations' => 'denklem', 'denklemler' => 'denklem', 'denklemleri' => 'denklem',
        'numerical' => 'sayisal', 'methods' => 'yontem', 'yontemler' => 'yontem', 'yontemleri' => 'yontem', 'analiz' => 'analiz',
        'probability' => 'olasilik', 'statistics' => 'istatistik',
        'programming' => 'programlama', 'computer' => 'bilgisayar',
        'technical' => 'teknik', 'drawing' => 'resim',
        'material' => 'malzeme', 'materials' => 'malzeme', 'science' => 'bilim', 'bilimi' => 'bilim',
        'thermodynamics' => 'termodinamik', 'termodinamigi' => 'termodinamik',
        'mechanics' => 'mekanik', 'mekanigi' => 'mekanik', 'fluid' => 'akiskan', 'akiskanlar' => 'akiskan',
        'statics' => 'statik', 'dynamics' => 'dinamik', 'strength' => 'mukavemet',
        'circuit' => 'devre', 'circuits' => 'devre', 'devreler' => 'devre', 'electric' => 'elektrik', 'electrical' => 'elektrik',
        'electronics' => 'elektronik', 'engineering' => 'muhendislik', 'muhendisligi' => 'muhendislik', 'muhendisligine' => 'muhendislik',
        'economics' => 'ekonomi', 'ekonomisi' => 'ekonomi', 'ekonomisine' => 'ekonomi',
        'english' => 'ingilizce', 'turkish' => 'turk', 'language' => 'dil', 'dili' => 'dil',
        'occupational' => 'is', 'health' => 'saglik', 'sagligi' => 'saglik', 'safety' => 'guvenlik', 'guvenligi' => 'guvenlik',
        'design' => 'tasarim', 'project' => 'proje', 'projesi' => 'proje', 'graduation' => 'bitirme',
        'internship' => 'staj', 'training' => 'staj',
    ];

    public static function lower(string $text): string
    {
        $text = str_replace(['İ', 'I'], ['i', 'ı'], $text);

        return mb_strtolower($text, 'UTF-8');
    }

    public static function ascii(string $text): string
    {
        return strtr(self::lower($text), self::TRANSLIT);
    }

    /**
     * Ders adının sonundaki sıra numarasını (I, II, 1, 2 ...) ayırır.
     *
     * @return array{base: string, sequence: int|null}
     */
    public static function splitSequence(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $name = rtrim($name, ' .*-');
        $romans = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8];

        if (preg_match('/^(.*?)[\s\-–]*\(?\b(VIII|VII|VI|IV|V|III|II|I)\)?$/u', $name, $m) && trim($m[1]) !== '') {
            return ['base' => trim($m[1]), 'sequence' => $romans[$m[2]]];
        }
        if (preg_match('/^(.*?)[\s\-–]+\(?([1-8])\)?$/u', $name, $m) && trim($m[1]) !== '') {
            return ['base' => trim($m[1]), 'sequence' => (int) $m[2]];
        }

        return ['base' => $name, 'sequence' => null];
    }

    /**
     * Karşılaştırma anahtarı: küçük harf, ASCII, noktalama yok, sıra numarası korunur.
     */
    public static function normalize(string $name): string
    {
        $split = self::splitSequence($name);
        $base = preg_replace('/[^a-z0-9 ]+/', ' ', self::ascii($split['base'])) ?? '';
        $base = trim(preg_replace('/\s+/', ' ', $base) ?? '');

        return $split['sequence'] ? $base.' '.$split['sequence'] : $base;
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $name): array
    {
        $base = self::splitSequence($name)['base'];
        $words = preg_split('/[^a-z0-9]+/', self::ascii($base), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        foreach ($words as $word) {
            $word = self::SYNONYMS[$word] ?? $word;
            if (in_array($word, self::STOPWORDS, true) || strlen($word) < 2) {
                continue;
            }
            $stem = substr($word, 0, 5);
            $tokens[$stem] = $stem;
        }

        return array_values($tokens);
    }

    /**
     * İki ders adı arasındaki benzerlik (0-1). Sıra numaraları farklıysa ceza uygulanır.
     */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if (self::normalize($a) === self::normalize($b)) {
            return 1.0;
        }
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        $dice = 0.0;
        if ($ta && $tb) {
            $common = count(array_intersect($ta, $tb));
            $dice = (2 * $common) / (count($ta) + count($tb));
        }
        similar_text(implode(' ', $ta), implode(' ', $tb), $percent);
        $score = 0.7 * $dice + 0.3 * ($percent / 100);

        $sa = self::splitSequence($a)['sequence'];
        $sb = self::splitSequence($b)['sequence'];
        if ($sa !== null && $sb !== null && $sa !== $sb) {
            $score *= 0.35;
        } elseif (($sa === null) !== ($sb === null)) {
            $score *= 0.92;
        }

        return round(min(1.0, $score), 3);
    }

    /**
     * İki ders içeriği (tanım, haftalık konular) arasındaki benzerlik (0-1); boşsa null.
     */
    public static function contentSimilarity(?string $a, ?string $b): ?float
    {
        $ta = self::contentTokens((string) $a);
        $tb = self::contentTokens((string) $b);
        if (count($ta) < 5 || count($tb) < 5) {
            return null;
        }
        $common = count(array_intersect_key($ta, $tb));

        return round((2 * $common) / (count($ta) + count($tb)), 3);
    }

    /**
     * @return array<string, true>
     */
    private static function contentTokens(string $text): array
    {
        static $stop = [
            'icin' => 1, 'olarak' => 1, 'ile' => 1, 'veya' => 1, 'gibi' => 1, 'olan' => 1, 'kadar' => 1, 'daha' => 1,
            'temel' => 1, 'genel' => 1, 'giris' => 1, 'konu' => 1, 'konular' => 1, 'dersin' => 1, 'ders' => 1, 'hafta' => 1,
            'tanim' => 1, 'tanimi' => 1, 'tanimlar' => 1, 'kavram' => 1, 'kavramlar' => 1, 'ornek' => 1, 'ornekler' => 1,
            'uygulama' => 1, 'uygulamalar' => 1, 'uygulamalari' => 1, 'problem' => 1, 'cozumu' => 1, 'sinav' => 1, 'arasinav' => 1,
            'final' => 1, 'midterm' => 1, 'exam' => 1, 'with' => 1, 'from' => 1, 'that' => 1, 'this' => 1, 'their' => 1, 'introduction' => 1,
            'ogrenci' => 1, 'ogrenciler' => 1, 'ogrencilere' => 1, 'bilgi' => 1, 'kazandirmak' => 1, 'ogretmek' => 1, 'amac' => 1,
        ];
        $tokens = [];
        foreach (preg_split('/[^a-z0-9]+/', self::ascii($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (strlen($word) < 4 || isset($stop[$word]) || is_numeric($word)) {
                continue;
            }
            $tokens[substr(self::SYNONYMS[$word] ?? $word, 0, 6)] = true;
        }

        return $tokens;
    }

    /**
     * Ders kodunun kök kısmı (boşluksuz, büyük harf): "MMT 101" → "MMT101".
     */
    public static function rootCode(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }
        $code = strtoupper(strtr(self::lower($code), self::TRANSLIT));

        return preg_replace('/[^A-Z0-9]/', '', $code) ?: null;
    }

    /**
     * Ortak zorunlu/özel ders grubu tespiti (Muafiyet Esasları Md. 5/8, 6/5, 6/6, 6/7).
     */
    public static function commonGroup(string $name, ?string $code = null, ?string $nameEn = null): ?string
    {
        $text = ' '.self::ascii($name.' '.($nameEn ?? '')).' ';
        $root = self::rootCode($code) ?? '';

        if (preg_match('/mesleki (yabanci dil|ingilizce)|teknik ingilizce|professional english|technical english|english for (engineer|specific)|meslek(i)? ingilizce/', $text)) {
            return 'MYD';
        }
        if (preg_match('/ataturk|inkilap|inkilab|turkish revolution|history of (the )?turkish/', $text) || str_starts_with($root, 'AIT') || str_starts_with($root, 'ATA')) {
            return 'AIIT';
        }
        if (preg_match('/ turk dili|turkish language| turkce |^ ?turkish (i|ii|1|2)? $/', $text) || str_starts_with($root, 'TDZ') || str_starts_with($root, 'TDL')) {
            return 'TD';
        }
        if (preg_match('/is sagligi|occupational health/', $text) || str_starts_with($root, 'ISG')) {
            return 'ISG';
        }
        if (preg_match('/yabanci dil|ingilizce|foreign language|^ ?english|almanca|fransizca|german|french/', $text) || str_starts_with($root, 'YDZ') || str_starts_with($root, 'YDI')) {
            return 'YD';
        }
        if (preg_match('/temel bilgisayar|bilgi ve iletisim teknoloji|bilgi iletisim teknoloji|temel bilgi teknoloji|information and communication tech|basic computer/', $text)) {
            return 'BIL';
        }

        return null;
    }
}
