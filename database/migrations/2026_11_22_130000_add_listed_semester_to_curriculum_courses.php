<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OBS müfredat tablosunda dersin listelendiği yarıyıl. Üniversite seçmelileri gibi her yarıyılda
 * alınabilen havuz derslerinin "semester" alanı boştur; sayfada OBS'deki gibi gösterilmeleri için
 * listelendikleri yarıyıl ayrıca saklanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_courses', function (Blueprint $table) {
            $table->unsignedTinyInteger('listed_semester')->nullable()->after('semester');
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_courses', function (Blueprint $table) {
            $table->dropColumn('listed_semester');
        });
    }
};
