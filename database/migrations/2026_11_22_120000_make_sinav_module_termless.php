<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sınav modülü dönemsiz çalışır: arka plandaki aktif kayıt "Genel" olur ve öğrenci erişimi
 * dönem bitişine bağlı kalmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sinav_terms')->where('status', 'active')->update([
            'ends_at' => '2099-12-31 23:59:59',
            'access_ends_at' => '2099-12-31 23:59:59',
        ]);
    }

    public function down(): void
    {
        //
    }
};
