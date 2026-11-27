<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bir öğrencinin birden çok başvurusu (normal dönem, yaz okulu ön onayı, hazırlık muafiyeti),
 * başvuru takvimi, komisyon rolleri ve satır bazında ret gerekçesi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exemption_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('normal'); // normal, yaz_okulu, hazirlik
            $table->string('status', 16)->default('taslak'); // taslak, gonderildi, inceleniyor, sonuclandi
            $table->timestamp('submitted_at')->nullable();
            $table->string('language_exam', 60)->nullable();
            $table->decimal('language_score', 5, 2)->nullable();
            $table->date('language_exam_date')->nullable();
            $table->string('decision', 8)->nullable(); // hazırlık başvurusunda kabul/ret
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('current_application_id')->nullable()->constrained('exemption_applications')->nullOnDelete();
        });
        foreach (['transcript_courses', 'student_documents', 'exemption_files'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('application_id')->nullable()->constrained('exemption_applications')->nullOnDelete();
            });
        }
        Schema::table('student_documents', function (Blueprint $table) {
            $table->string('institution')->nullable();
        });
        Schema::table('exemption_items', function (Blueprint $table) {
            $table->string('reject_reason', 24)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('title', 40)->nullable();
        });

        Schema::create('application_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('type', 16); // normal, yaz_okulu, hazirlik
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Mevcut her öğrencinin bugüne kadarki başvurusu ilk başvuru sayılır.
        $now = now();
        foreach (DB::table('students')->get(['id', 'application_status', 'submitted_at', 'created_at']) as $student) {
            $applicationId = DB::table('exemption_applications')->insertGetId([
                'student_id' => $student->id,
                'type' => 'normal',
                'status' => $student->application_status ?: 'taslak',
                'submitted_at' => $student->submitted_at,
                'created_at' => $student->created_at ?? $now,
                'updated_at' => $now,
            ]);
            DB::table('students')->where('id', $student->id)->update(['current_application_id' => $applicationId]);
            foreach (['transcript_courses', 'student_documents', 'exemption_files'] as $name) {
                DB::table($name)->where('student_id', $student->id)->update(['application_id' => $applicationId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('application_periods');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('title'));
        Schema::table('exemption_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn('reject_reason');
        });
        Schema::table('student_documents', fn (Blueprint $t) => $t->dropColumn('institution'));
        foreach (['transcript_courses', 'student_documents', 'exemption_files'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropConstrainedForeignId('application_id'));
        }
        Schema::table('students', fn (Blueprint $t) => $t->dropConstrainedForeignId('current_application_id'));
        Schema::dropIfExists('exemption_applications');
    }
};
