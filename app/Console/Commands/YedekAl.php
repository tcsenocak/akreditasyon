<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Veritabanı ve yüklenen öğrenci belgelerinin yedeği; en yeni N yedek tutulur.
 */
#[Signature('yedek:al {--tut=14 : Saklanacak yedek sayısı}')]
#[Description('Veritabanını ve öğrenci belgelerini tarihli bir zip dosyasına yedekler')]
class YedekAl extends Command
{
    public function handle(): int
    {
        $dir = (string) config('intibak.backup_path');
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Y-m-d_His');
        $dbCopy = $dir.DIRECTORY_SEPARATOR.'veritabani-'.$stamp.'.sqlite';

        // Çalışan veritabanının tutarlı kopyası
        DB::statement('VACUUM INTO ?', [$dbCopy]);

        $zipPath = $dir.DIRECTORY_SEPARATOR.'intibak-yedek-'.$stamp.'.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('Yedek dosyası oluşturulamadı: '.$zipPath);

            return self::FAILURE;
        }
        $zip->addFile($dbCopy, 'database.sqlite');
        foreach (['belgeler', 'sinav'] as $folder) {
            $root = storage_path('app/private/'.$folder);
            if (! is_dir($root)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $zip->addFile($file->getPathname(), $folder.'/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/'));
            }
        }
        $zip->close();
        File::delete($dbCopy);

        $keep = max(1, (int) $this->option('tut'));
        $old = collect(File::glob($dir.DIRECTORY_SEPARATOR.'intibak-yedek-*.zip'))->sort()->values();
        $old->slice(0, max(0, $old->count() - $keep))->each(fn (string $path) => File::delete($path));

        $this->info('Yedek alındı: '.$zipPath.' ('.round(filesize($zipPath) / 1048576, 1).' MB)');

        return self::SUCCESS;
    }
}
