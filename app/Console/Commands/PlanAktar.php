<?php

namespace App\Console\Commands;

use App\Services\CourseCatalogImporter;
use App\Services\CurriculumImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('plan:aktar {yol? : .xlsx/.doc dosyası veya klasör} {--indir : Fakülte sayfasından güncel planları indir} {--eski : 2018 sonrası ders bilgi paketlerini (.doc) indir ve eski plan olarak ekle} {--pasif : Planı aktif yapma (eski plan olarak ekle)}')]
#[Description('Öğretim planlarını (Excel) ve ders bilgi paketlerini (.doc) sisteme aktarır.')]
class PlanAktar extends Command
{
    public function handle(CurriculumImporter $importer, CourseCatalogImporter $catalogImporter): int
    {
        $files = [];
        if ($this->option('indir')) {
            $target = storage_path('app/planlar');
            $this->info('İndiriliyor: '.config('intibak.planlar_url'));
            $files = $importer->downloadFromFacultyPage(config('intibak.planlar_url'), $target);
            $this->info(count($files).' dosya indirildi → '.$target);
        }
        if ($this->option('eski')) {
            $files = array_merge($files, $this->downloadCatalogs());
        }

        $path = $this->argument('yol');
        if ($path) {
            $files = array_merge($files, is_dir($path)
                ? array_merge(glob(rtrim($path, '/\\').DIRECTORY_SEPARATOR.'*.xlsx') ?: [], glob(rtrim($path, '/\\').DIRECTORY_SEPARATOR.'*.doc') ?: [])
                : [$path]);
        }
        if ($files === []) {
            $this->error('Aktarılacak dosya yok. Bir yol verin veya --indir / --eski kullanın.');

            return self::FAILURE;
        }

        $failures = 0;
        foreach ($files as $file) {
            try {
                $result = str_ends_with(strtolower($file), '.doc')
                    ? $catalogImporter->import($file)
                    : $importer->import($file, null, ! $this->option('pasif'));
                $this->line(sprintf('✔ %-45s %3d ders  (%s, %s AKTS)', $result['program']->full_name, $result['count'], $result['curriculum']->title, (float) $result['curriculum']->total_ects));
                foreach ($result['warnings'] as $warning) {
                    $this->warn('   ⚠ '.$warning);
                }
            } catch (Throwable $e) {
                $failures++;
                $this->error('✘ '.basename($file).': '.$e->getMessage());
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function downloadCatalogs(): array
    {
        $url = config('intibak.ders_bilgi_paketi_url');
        $this->info('İndiriliyor: '.$url);
        $links = CourseCatalogImporter::linksOn(Http::timeout(60)->get($url)->throw()->body());
        $target = storage_path('app/ders-bilgi-paketleri');
        if (! is_dir($target)) {
            mkdir($target, 0775, true);
        }
        $paths = [];
        foreach ($links as $link) {
            $file = $target.DIRECTORY_SEPARATOR.basename(parse_url($link, PHP_URL_PATH) ?: $link);
            Http::timeout(120)->sink($file)->get($link)->throw();
            $paths[] = $file;
        }
        $this->info(count($paths).' ders bilgi paketi indirildi → '.$target);

        return $paths;
    }
}
