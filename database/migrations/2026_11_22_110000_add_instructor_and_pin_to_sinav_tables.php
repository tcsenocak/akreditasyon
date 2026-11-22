<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sınav modülü: dersin sorumlu öğretim elemanı (users.role = instructor) ve
 * ders grubunun öğrenci giriş PIN'i. PIN şifreli saklanır (hoca yeniden görebilsin diye
 * geri çözülebilir şifreleme; uygulama anahtarı olmadan okunamaz). Her değişiklikte
 * pin_version artar ve eski PIN'le açılmış öğrenci oturumları o grubu kaybeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sinav_courses', function (Blueprint $table): void {
            $table->foreignId('instructor_user_id')->nullable()->after('instructor')->constrained('users')->nullOnDelete();
        });

        Schema::table('sinav_groups', function (Blueprint $table): void {
            $table->text('pin_encrypted')->nullable()->after('name_slug');
            $table->unsignedInteger('pin_version')->default(0)->after('pin_encrypted');
            $table->dateTime('pin_updated_at')->nullable()->after('pin_version');
        });
    }

    public function down(): void
    {
        Schema::table('sinav_groups', function (Blueprint $table): void {
            $table->dropColumn(['pin_encrypted', 'pin_version', 'pin_updated_at']);
        });

        Schema::table('sinav_courses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('instructor_user_id');
        });
    }
};
