<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('admin'); // admin (komisyon) | student
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('application_status', 16)->default('taslak'); // taslak, gonderildi, inceleniyor, sonuclandi
            $table->timestamp('submitted_at')->nullable();
            $table->string('phone', 32)->nullable();
        });

        Schema::table('transcript_courses', function (Blueprint $table) {
            // Md. 5/2: öğrencinin dilekçede belirttiği, muaf olmak istediği AÜ dersi
            $table->foreignId('requested_course_id')->nullable()->constrained('curriculum_courses')->nullOnDelete();
        });

        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('original_name');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime', 120)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
        Schema::table('transcript_courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_course_id');
        });
        Schema::table('students', fn (Blueprint $t) => $t->dropColumn(['application_status', 'submitted_at', 'phone']));
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_id');
            $table->dropColumn('role');
        });
    }
};
