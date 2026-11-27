<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İşlem geçmişi, dosya ataması, geri açma gerekçesi, itiraz, yaz okulu notları ve KVKK onayı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->string('description', 500);
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('exemption_files', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('exemption_applications', function (Blueprint $table) {
            $table->text('return_note')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('objection_text')->nullable();
            $table->timestamp('objected_at')->nullable();
            $table->text('objection_response')->nullable();
            $table->timestamp('objection_responded_at')->nullable();
            $table->timestamp('summer_grades_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('kvkk_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('kvkk_accepted_at'));
        Schema::table('exemption_applications', fn (Blueprint $t) => $t->dropColumn([
            'return_note', 'returned_at', 'objection_text', 'objected_at', 'objection_response', 'objection_responded_at', 'summer_grades_at',
        ]));
        Schema::table('exemption_files', fn (Blueprint $t) => $t->dropConstrainedForeignId('assigned_to'));
        Schema::dropIfExists('audit_logs');
    }
};
