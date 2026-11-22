<?php

namespace App\Services\Sinav;

/**
 * Ek kütüphane gerektirmeyen küçük Excel (.xlsx) okuyucu/yazıcı.
 * Yalnızca PHP'nin "zip" eklentisini kullanır.
 */
class Xlsx
{
    /** İlk sayfanın satırlarını dizi olarak döner (her satır hücre değerleri dizisi). */
    public static function readFirstSheet($path)
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new UserError('Sunucuda PHP "zip" eklentisi yok; Excel okunamıyor. Listeyi kopyalayıp yapıştırarak ekleyebilirsiniz.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new UserError('Excel dosyası okunamadı. Dosyanın bozuk olmadığından ve .xlsx biçiminde olduğundan emin olun.');
        }
        try {
            $sheetPath = self::firstSheetPath($zip);
            $strings = self::sharedStrings($zip);
            $dateStyles = self::dateStyles($zip);
            self::guardSize($zip, $sheetPath);
            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                throw new UserError('Excel dosyasında sayfa bulunamadı.');
            }
            $sheet = self::load($xml);
            $rows = [];
            $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($sheet->xpath('//m:sheetData/m:row') as $row) {
                $r = (int) self::attr($row, 'r') ?: count($rows) + 1;
                $cells = [];
                foreach ($row->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main') as $c) {
                    if ($c->getName() !== 'c') {
                        continue;
                    }
                    $col = self::colIndex(self::attr($c, 'r'));
                    if ($col < 0) {
                        $col = count($cells);
                    }
                    $cells[$col] = self::cellValue($c, $strings, $dateStyles);
                }
                if ($cells) {
                    $max = max(array_keys($cells));
                    $line = [];
                    for ($i = 0; $i <= $max; $i++) {
                        $line[] = isset($cells[$i]) ? $cells[$i] : '';
                    }
                    $rows[$r] = $line;
                }
            }
            ksort($rows);

            return array_values($rows);
        } finally {
            $zip->close();
        }
    }

    /** Ad alanı olmayan özniteliği okur (öğe bir ad alanındayken $node['x'] boş döner). */
    private static function attr($node, $name)
    {
        $a = $node->attributes();

        return isset($a[$name]) ? (string) $a[$name] : '';
    }

    private static function load($xml)
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($x === false) {
            throw new UserError('Excel dosyası okunamadı (bozuk içerik).');
        }

        return $x;
    }

    private static function firstSheetPath(\ZipArchive $zip)
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }
        $wbx = self::load($wb);
        $sheets = $wbx->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->sheets;
        if (! $sheets || ! $sheets->sheet) {
            return 'xl/worksheets/sheet1.xml';
        }
        $first = $sheets->sheet[0];
        $rid = (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach (self::load($rels)->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $rel) {
            if (self::attr($rel, 'Id') === $rid) {
                $target = ltrim(self::attr($rel, 'Target'), '/');

                return strpos($target, 'xl/') === 0 ? $target : 'xl/'.$target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** Açılınca aşırı büyüyen (zip bombası) dosyaları belleğe almadan reddeder. */
    private static function guardSize(\ZipArchive $zip, $name)
    {
        $stat = $zip->statName($name);
        if ($stat !== false && $stat['size'] > 60 * 1024 * 1024) {
            throw new UserError('Excel dosyası açıldığında çok büyük (60 MB üstü); okunamadı.');
        }
    }

    private static function sharedStrings(\ZipArchive $zip)
    {
        self::guardSize($zip, 'xl/sharedStrings.xml');
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $out = [];
        foreach (self::load($xml)->children(self::NS)->si as $si) {
            $out[] = self::richText($si);
        }

        return $out;
    }

    const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private static function richText($node)
    {
        if ($node === null) {
            return '';
        }
        $kids = $node->children(self::NS);
        if (isset($kids->t) && ! isset($kids->r)) {
            return (string) $kids->t;
        }
        $text = '';
        foreach ($kids->r as $run) {
            $text .= (string) $run->children(self::NS)->t;
        }

        return $text;
    }

    /** Tarih biçimli hücre stillerinin numaraları. */
    private static function dateStyles(\ZipArchive $zip)
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [];
        }
        $x = self::load($xml)->children(self::NS);
        $custom = [];
        if (isset($x->numFmts)) {
            foreach ($x->numFmts->children(self::NS)->numFmt as $f) {
                $code = strtolower(preg_replace('/"[^"]*"|\[[^\]]*\]/', '', self::attr($f, 'formatCode')));
                if (preg_match('/[dy]|m{3,}/', $code) || (strpos($code, 'm') !== false && preg_match('/[dy]/', $code))) {
                    $custom[(int) self::attr($f, 'numFmtId')] = true;
                }
            }
        }
        $out = [];
        if (isset($x->cellXfs)) {
            $i = 0;
            foreach ($x->cellXfs->children(self::NS)->xf as $xf) {
                $id = (int) self::attr($xf, 'numFmtId');
                if (($id >= 14 && $id <= 22) || isset($custom[$id])) {
                    $out[$i] = true;
                }
                $i++;
            }
        }

        return $out;
    }

    private static function colIndex($ref)
    {
        if (! preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
            return -1;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private static function cellValue($c, $strings, $dateStyles)
    {
        $t = self::attr($c, 't');
        $kids = $c->children(self::NS);
        if ($t === 'inlineStr') {
            return trim(self::richText(isset($kids->is) ? $kids->is : null));
        }
        $v = isset($kids->v) ? (string) $kids->v : '';
        if ($t === 's') {
            return trim($strings[(int) $v] ?? '');
        }
        if ($t === 'b') {
            return $v === '1' ? 'DOĞRU' : 'YANLIŞ';
        }
        if ($t === 'str' || $t === 'e') {
            return trim($v);
        }
        if ($v === '') {
            return '';
        }
        if (is_numeric($v)) {
            $style = (int) self::attr($c, 's');
            if (isset($dateStyles[$style])) {
                $days = (float) $v;
                $ts = (int) round(($days - 25569) * 86400);

                return gmdate($days - floor($days) > 0 ? 'd.m.Y H:i' : 'd.m.Y', $ts);
            }
            // Büyük tam sayılar (öğrenci no) bilimsel gösterime düşmesin.
            if (preg_match('/^-?\d+$/', $v)) {
                return $v;
            }
            $f = (float) $v;
            if (abs($f) >= 1e6 && floor($f) == $f) {
                return sprintf('%.0f', $f);
            }

            return rtrim(rtrim(number_format($f, 10, ',', ''), '0'), ',');
        }

        return trim($v);
    }

    // ---------- Şablon yazma ----------

    /**
     * Basit .xlsx üretir. $sheets: [['name' => 'Ad', 'widths' => [18, 36], 'rows' => [[...], ...],
     * 'boldFirstRow' => true, 'freeze' => true]]
     */
    public static function write(array $sheets)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $n = count($sheets);

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $ct.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheets as $i => $s) {
            $k = $i + 1;
            $wb .= '<sheet name="'.self::esc($s['name']).'" sheetId="'.$k.'" r:id="rId'.$k.'"/>';
            $rels .= '<Relationship Id="rId'.$k.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$k.'.xml"/>';
        }
        $rels .= '<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('xl/workbook.xml', $wb.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        // Stil 0: normal, stil 1: kalın, stil 2: metin biçimi (@)
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            .'</styleSheet>');

        foreach ($sheets as $i => $s) {
            $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
            if (! empty($s['freeze'])) {
                $x .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
            }
            if (! empty($s['widths'])) {
                $x .= '<cols>';
                foreach ($s['widths'] as $c => $w) {
                    // İlk sütun (öğrenci no) metin biçiminde: baştaki sıfırlar silinmesin.
                    $style = ! empty($s['textFirstCol']) && $c === 0 ? ' style="2"' : '';
                    $x .= '<col min="'.($c + 1).'" max="'.($c + 1).'" width="'.$w.'" customWidth="1"'.$style.'/>';
                }
                $x .= '</cols>';
            }
            $x .= '<sheetData>';
            foreach ($s['rows'] as $r => $row) {
                $x .= '<row r="'.($r + 1).'">';
                foreach ($row as $c => $val) {
                    $ref = chr(65 + $c).($r + 1);
                    $style = ($r === 0 && ! empty($s['boldFirstRow'])) ? ' s="1"' : '';
                    $x .= '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.self::esc($val).'</t></is></c>';
                }
                $x .= '</row>';
            }
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $x.'</sheetData></worksheet>');
        }
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    private static function esc($s)
    {
        return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Toplu öğrenci ekleme şablonu (örnek satır BİLEREK yok: unutulup gerçek öğrenci gibi yüklenmesin). */
    public static function studentTemplate()
    {
        return self::write([
            ['name' => 'Öğrenciler', 'widths' => [18, 36], 'freeze' => true, 'boldFirstRow' => true, 'textFirstCol' => true, 'rows' => [['Öğrenci No', 'Ad Soyad']]],
            ['name' => 'Açıklama', 'widths' => [100], 'boldFirstRow' => true, 'rows' => [
                ['Nasıl doldurulur?'],
                ['1. "Öğrenciler" sayfasında 2. satırdan başlayarak her satıra bir öğrenci yazın.'],
                ['2. "Öğrenci No" zorunludur. "Ad Soyad" isteğe bağlıdır; yazarsanız öğrenci listesinde görünür.'],
                ['3. Ders ve grup bu dosyada yazılmaz: dosyayı yüklerken ekranda seçtiğiniz ders ve grup kullanılır.'],
                ['4. Farklı ders veya grup için ayrı bir dosya hazırlayıp o ders ve grubu seçerek yükleyin.'],
                ['5. Numaranın başında sıfır varsa hücreyi "Metin" biçiminde tutun, yoksa Excel sıfırı siler.'],
                ['6. Başka sütun (sıra no vb.) eklerseniz yok sayılır.'],
            ]],
        ]);
    }
}
