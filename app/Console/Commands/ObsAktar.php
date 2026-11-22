<?php

namespace App\Console\Commands;

use App\Services\Obs\ObsImporter;
use App\Services\TextNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('obs:aktar {--fakulte= : OBS fakülte kimliği (varsayılan Mühendislik 767)} {--program= : Yalnız adı bu metni içeren program} {--icerik : Ders içeriklerini de çek} {--yalniz-icerik : Müfredatı atla, yalnız eksik içerikleri çek} {--yenile : Önbellekteki içerikleri de yeniden çek} {--limit= : En fazla bu kadar ders içeriği çek}')]
#[Description('OBS Ders Bilgi Paketi\'nden giriş yılına göre müfredatları ve ders içeriklerini aktarır.')]
class ObsAktar extends Command
{
    public function handle(ObsImporter $importer): int
    {
        $importer->onLog(fn (string $m) => $this->line($m));
        $facultyId = (int) ($this->option('fakulte') ?: config('intibak.obs.faculty_id'));
        $failures = 0;

        if (! $this->option('yalniz-icerik')) {
            $programs = $importer->discoverPrograms($facultyId);
            if ($filter = $this->option('program')) {
                $programs = array_values(array_filter($programs, fn ($p) => str_contains(TextNormalizer::ascii($p['name']), TextNormalizer::ascii($filter))));
            }
            $this->info(count($programs).' program bulundu.');
            foreach ($programs as $obsProgram) {
                $this->info("▶ {$obsProgram['name']} (OBS {$obsProgram['obs_id']})");
                try {
                    foreach ($importer->importProgram($obsProgram) as $result) {
                        $this->line(sprintf('  ✔ %-38s %3d ders, %s AKTS', $result['curriculum']->title, $result['count'], (float) $result['curriculum']->total_ects));
                        foreach ($result['warnings'] as $warning) {
                            $this->warn('    ⚠ '.$warning);
                        }
                    }
                } catch (Throwable $e) {
                    $failures++;
                    $this->error('  ✘ '.$e->getMessage());
                }
            }
        }

        if ($this->option('icerik') || $this->option('yalniz-icerik')) {
            $this->info('Ders içerikleri çekiliyor…');
            $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
            $stats = $importer->fetchCourseInfos((bool) $this->option('yenile'), $limit);
            $this->info("İçerik: {$stats['fetched']} yeni, {$stats['skipped']} önbellekte, {$stats['failed']} hata.");
            $failures += $stats['failed'];
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
