<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Öğrencinin geldiği bölüm (katalog anahtarı: "ext:ID" diğer kurum, "au:ID" AÜ programı)
            $table->string('source_program_key', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('students', fn (Blueprint $t) => $t->dropColumn('source_program_key'));
    }
};
