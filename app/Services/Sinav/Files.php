<?php

namespace App\Services\Sinav;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dosya saklama. Dosyalar storage/app/sinav altında durur (dışarıdan doğrudan
 * erişilemez); yalnızca yetki denetimi yapan denetleyiciler üzerinden okunur.
 *
 * Tarayıcı büyük dosyaları küçük parçalar halinde gönderir (sunucunun
 * upload_max_filesize sınırı çoğunlukla 2 MB olduğu için). Parçalar burada
 * birleştirilir.
 */
class Files
{
    public static function disk()
    {
        return Storage::disk(config('sinav.disk', 'local'));
    }

    private static function tmpDir($uploadId)
    {
        $owner = substr(sha1(Util::adminName()), 0, 16);

        return 'sinav/tmp/'.$owner.'/'.$uploadId;
    }

    private static function checkId($uploadId)
    {
        if (! is_string($uploadId) || ! preg_match('/^[A-Za-z0-9]{16,40}$/', $uploadId)) {
            throw new UserError('Geçersiz yükleme kimliği.');
        }
    }

    /** Bir parçayı kaydeder. */
    public static function storeChunk(Request $request)
    {
        $uploadId = (string) $request->input('upload_id');
        self::checkId($uploadId);
        $index = (int) $request->input('index');
        $total = (int) $request->input('total');
        $file = $request->file('chunk');
        if (! $file || ! $file->isValid()) {
            throw new UserError('Dosya parçası sunucuya ulaşmadı. Sunucunun yükleme sınırı (upload_max_filesize = '.ini_get('upload_max_filesize').') çok düşük olabilir.');
        }
        if ($index < 0 || $total < 1 || $total > 400 || $index >= $total) {
            throw new UserError('Geçersiz parça numarası.');
        }
        if ($file->getSize() > config('sinav.chunk_bytes') + 1024) {
            throw new UserError('Parça çok büyük.');
        }
        self::disk()->putFileAs(self::tmpDir($uploadId), $file, sprintf('%05d.part', $index));
        if ($index === 0) {
            self::cleanupOld();
        }
    }

    /**
     * Parçaları birleştirip yerel geçici dosya olarak verir.
     * Dönüş: ['tmp' => yerel yol, 'size' => bayt, 'head' => ilk 8 bayt]
     */
    public static function take($uploadId, $maxBytes)
    {
        self::checkId($uploadId);
        $dir = self::tmpDir($uploadId);
        $parts = self::disk()->files($dir);
        sort($parts);
        if (count($parts) === 0) {
            throw new UserError('Yüklenen dosya bulunamadı. Lütfen tekrar deneyin.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'sinav');
        $out = fopen($tmp, 'wb');
        $size = 0;
        foreach ($parts as $i => $p) {
            if ($p !== $dir.'/'.sprintf('%05d.part', $i)) {
                fclose($out);
                @unlink($tmp);
                self::disk()->deleteDirectory($dir);
                throw new UserError('Dosya eksik yüklendi. Lütfen tekrar deneyin.');
            }
            $in = self::disk()->readStream($p);
            $size += stream_copy_to_stream($in, $out);
            fclose($in);
            if ($size > $maxBytes) {
                fclose($out);
                @unlink($tmp);
                self::disk()->deleteDirectory($dir);
                throw new UserError('Dosya çok büyük (en fazla '.round($maxBytes / 1024 / 1024).' MB).');
            }
        }
        fclose($out);
        self::disk()->deleteDirectory($dir);
        if ($size === 0) {
            @unlink($tmp);
            throw new UserError('Dosya boş.');
        }
        $h = fopen($tmp, 'rb');
        $head = fread($h, 8);
        fclose($h);

        return ['tmp' => $tmp, 'size' => $size, 'head' => $head];
    }

    /** Yerel geçici dosyayı kalıcı yere taşır; saklama yolunu döner. */
    public static function keep($tmp, $folder, $ext)
    {
        $path = 'sinav/'.trim($folder, '/').'/'.Str::random(32).'.'.$ext;
        $h = fopen($tmp, 'rb');
        self::disk()->writeStream($path, $h);
        if (is_resource($h)) {
            fclose($h);
        }
        @unlink($tmp);

        return $path;
    }

    public static function copy($from, $folder, $ext)
    {
        $path = 'sinav/'.trim($folder, '/').'/'.Str::random(32).'.'.$ext;
        self::disk()->copy($from, $path);

        return $path;
    }

    public static function delete($path)
    {
        if ($path) {
            self::disk()->delete($path);
        }
    }

    public static function extFor($mime)
    {
        return ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'][$mime] ?? 'bin';
    }

    /**
     * Dosyayı tarayıcıya verir. $download=false ise sayfaya gömülü gösterilir.
     * Önbelleğe alınmaz (öğrencinin erişimi sona erince tarayıcıda kopya kalmasın).
     */
    public static function respond($path, $mime, $name, $download = false)
    {
        $disk = self::disk();
        if (! $disk->exists($path)) {
            abort(404);
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($name));
        $disposition = ($download ? 'attachment' : 'inline').'; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($name);

        return response()->stream(function () use ($disk, $path) {
            $s = $disk->readStream($path);
            fpassthru($s);
            fclose($s);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $disk->size($path),
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Yarıda kalmış, 1 günden eski geçici yüklemeleri temizler. */
    private static function cleanupOld()
    {
        $disk = self::disk();
        foreach ($disk->directories('sinav/tmp') as $owner) {
            foreach ($disk->directories($owner) as $dir) {
                $files = $disk->files($dir);
                $last = 0;
                foreach ($files as $f) {
                    $last = max($last, $disk->lastModified($f));
                }
                if (count($files) === 0 || $last < time() - 86400) {
                    $disk->deleteDirectory($dir);
                }
            }
        }
    }
}
