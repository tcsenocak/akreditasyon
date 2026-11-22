<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exemption_items', function (Blueprint $table) {
            // Komisyonun içerik/değerlendirme notu (satır bazında)
            $table->text('commission_note')->nullable();
            // İçerik benzerliği (öğrencinin girdiği içerik ↔ AÜ ders içeriği), 0-1
            $table->decimal('content_score', 5, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exemption_items', function (Blueprint $table) {
            $table->dropColumn(['commission_note', 'content_score']);
        });
    }
};
