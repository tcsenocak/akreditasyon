<?php

namespace App\Services;

use RuntimeException;

/**
 * Word 97-2003 (.doc) dosyasından düz metin çıkarır (OLE bileşik dosya + parça tablosu).
 * Tablo hücre sonları "\x07" karakteri olarak korunur.
 */
class WordDocReader
{
    private const END = 0xFFFFFFFA;

    private string $data;

    private int $sectorSize;

    private int $miniSectorSize;

    private int $miniCutoff;

    /** @var list<int> */
    private array $fat = [];

    /** @var list<int> */
    private array $miniFat = [];

    private string $miniStream = '';

    /** @var list<array{name: string, type: int, start: int, size: int}> */
    private array $entries = [];

    public function text(string $path): string
    {
        $this->data = (string) file_get_contents($path);
        if (substr($this->data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('Dosya Word 97-2003 (.doc) biçiminde değil.');
        }
        $this->readContainer();

        $word = $this->stream('WordDocument') ?? throw new RuntimeException('WordDocument akışı yok.');
        $flags = $this->u16($word, 0x0A);
        $table = $this->stream(($flags & 0x0200) ? '1Table' : '0Table') ?? throw new RuntimeException('Tablo akışı yok.');
        $fcClx = $this->u32($word, 0x01A2);
        $lcbClx = $this->u32($word, 0x01A6);
        $clx = substr($table, $fcClx, $lcbClx);

        $i = 0;
        while ($i < strlen($clx) && ord($clx[$i]) === 0x01) {
            $i += 3 + $this->u16($clx, $i + 1);
        }
        if ($i >= strlen($clx) || ord($clx[$i]) !== 0x02) {
            throw new RuntimeException('Parça tablosu bulunamadı.');
        }
        $lcb = $this->u32($clx, $i + 1);
        $plc = substr($clx, $i + 5, $lcb);
        $count = intdiv($lcb - 4, 12);

        $text = '';
        for ($k = 0; $k < $count; $k++) {
            $cpStart = $this->u32($plc, $k * 4);
            $cpEnd = $this->u32($plc, ($k + 1) * 4);
            $fc = $this->u32($plc, ($count + 1) * 4 + $k * 8 + 2);
            $chars = $cpEnd - $cpStart;
            if ($fc & 0x40000000) {
                $offset = intdiv($fc & ~0x40000000, 2);
                $text .= mb_convert_encoding(substr($word, $offset, $chars), 'UTF-8', 'Windows-1252');
            } else {
                $text .= mb_convert_encoding(substr($word, $fc, $chars * 2), 'UTF-8', 'UTF-16LE');
            }
        }

        return $text;
    }

    private function readContainer(): void
    {
        $this->sectorSize = 1 << $this->u16($this->data, 0x1E);
        $this->miniSectorSize = 1 << $this->u16($this->data, 0x20);
        $firstDir = $this->u32($this->data, 0x30);
        $this->miniCutoff = $this->u32($this->data, 0x38);
        $firstMiniFat = $this->u32($this->data, 0x3C);
        $difat = $this->u32($this->data, 0x44);
        $difatCount = $this->u32($this->data, 0x48);

        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $id = $this->u32($this->data, 0x4C + $i * 4);
            if ($id < self::END) {
                $fatSectors[] = $id;
            }
        }
        $perSector = intdiv($this->sectorSize, 4);
        for ($n = 0; $n < $difatCount && $difat < self::END; $n++) {
            $sector = $this->sector($difat);
            for ($i = 0; $i < $perSector - 1; $i++) {
                $id = $this->u32($sector, $i * 4);
                if ($id < self::END) {
                    $fatSectors[] = $id;
                }
            }
            $difat = $this->u32($sector, ($perSector - 1) * 4);
        }
        $this->fat = [];
        foreach ($fatSectors as $id) {
            $this->fat = array_merge($this->fat, array_values(unpack('V*', $this->sector($id))));
        }

        $dir = $this->readChain($firstDir);
        $this->entries = [];
        for ($i = 0; $i + 128 <= strlen($dir); $i += 128) {
            $entry = substr($dir, $i, 128);
            $nameLength = $this->u16($entry, 64);
            $this->entries[] = [
                'name' => mb_convert_encoding(substr($entry, 0, max(0, $nameLength - 2)), 'UTF-8', 'UTF-16LE'),
                'type' => ord($entry[66]),
                'start' => $this->u32($entry, 116),
                'size' => $this->u32($entry, 120),
            ];
        }
        $this->miniStream = $this->readChain($this->entries[0]['start']);
        $this->miniFat = $firstMiniFat < self::END ? array_values(unpack('V*', $this->readChain($firstMiniFat))) : [];
    }

    private function stream(string $name): ?string
    {
        foreach ($this->entries as $entry) {
            if ($entry['name'] !== $name || $entry['type'] !== 2) {
                continue;
            }
            if ($entry['size'] < $this->miniCutoff) {
                $out = '';
                $seen = [];
                for ($s = $entry['start']; $s < self::END && ! isset($seen[$s]); $s = $this->miniFat[$s] ?? self::END) {
                    $seen[$s] = true;
                    $out .= substr($this->miniStream, $s * $this->miniSectorSize, $this->miniSectorSize);
                }

                return substr($out, 0, $entry['size']);
            }

            return substr($this->readChain($entry['start']), 0, $entry['size']);
        }

        return null;
    }

    private function readChain(int $start): string
    {
        $out = '';
        $seen = [];
        for ($s = $start; $s < self::END && ! isset($seen[$s]); $s = $this->fat[$s] ?? self::END) {
            $seen[$s] = true;
            $out .= $this->sector($s);
        }

        return $out;
    }

    private function sector(int $id): string
    {
        return substr($this->data, 512 + $id * $this->sectorSize, $this->sectorSize);
    }

    private function u16(string $bytes, int $offset): int
    {
        return unpack('v', $bytes, $offset)[1];
    }

    private function u32(string $bytes, int $offset): int
    {
        return unpack('V', $bytes, $offset)[1];
    }
}
