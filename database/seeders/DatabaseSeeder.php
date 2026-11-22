<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\CourseCatalogImporter;
use App\Services\CurriculumImporter;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Yönetici hesabını oluşturur ve storage/app/planlar içindeki öğretim planlarını aktarır.
     */
    public function run(CurriculumImporter $importer, CourseCatalogImporter $catalogImporter): void
    {
        User::query()->updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@intibak.local')],
            ['name' => 'Komisyon', 'password' => Hash::make((string) env('ADMIN_PASSWORD', 'degistir-beni'))],
        );

        foreach (glob(storage_path('app/planlar/*.xlsx')) ?: [] as $file) {
            $result = $importer->import($file);
            $this->command?->info("{$result['program']->full_name}: {$result['count']} ders");
        }

        foreach (glob(storage_path('app/ders-bilgi-paketleri/*.doc')) ?: [] as $file) {
            $result = $catalogImporter->import($file);
            $this->command?->info("{$result['program']->full_name}: {$result['count']} ders (eski plan)");
        }

        $this->call(GradeConversionSeeder::class);
    }
}
