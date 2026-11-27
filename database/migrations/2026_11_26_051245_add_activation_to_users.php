<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hesap etkinleştirme / parola belirleme bağlantısı (belirtecin özeti saklanır).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('activation_token', 64)->nullable()->index();
            $table->timestamp('activation_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['activation_token']);
            $table->dropColumn(['activation_token', 'activation_expires_at']);
        });
    }
};
