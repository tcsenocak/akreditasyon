<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Yedek komutu işlem (transaction) dışında çalışmalıdır; bu yüzden RefreshDatabase kullanılmaz.
 */
class YedekTest extends TestCase
{
    public function test_yedek_komutu_en_yeni_yedekleri_saklar(): void
    {
        $dir = storage_path('framework/testing/yedek-'.uniqid());
        config(['intibak.backup_path' => $dir]);

        $this->artisan('yedek:al', ['--tut' => 2])->assertSuccessful();
        $this->travel(1)->seconds();
        $this->artisan('yedek:al', ['--tut' => 2])->assertSuccessful();
        $this->travel(1)->seconds();
        $this->artisan('yedek:al', ['--tut' => 2])->assertSuccessful();

        $this->assertCount(2, File::glob($dir.'/intibak-yedek-*.zip'));
        $this->assertCount(0, File::glob($dir.'/*.sqlite'), 'Geçici veritabanı kopyası silinir');
        File::deleteDirectory($dir);
    }
}
