<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Diğer üniversitelerin bölüm/program ders listeleri (AÜ programları programs/curricula tablolarındadır)
        Schema::create('catalog_programs', function (Blueprint $table) {
            $table->id();
            $table->string('institution');
            $table->string('normalized_institution')->index();
            $table->string('faculty')->nullable();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->string('source', 16)->default('komisyon'); // komisyon | ogrenci
            $table->boolean('verified')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['normalized_institution', 'normalized_name']);
        });

        Schema::create('catalog_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_program_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32)->nullable();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->decimal('credit', 5, 1)->nullable();
            $table->decimal('ects', 5, 1)->nullable();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->text('content')->nullable();
            $table->timestamps();
        });

        Schema::table('transcript_courses', function (Blueprint $table) {
            $table->foreignId('catalog_course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_course_id')->nullable()->constrained('curriculum_courses')->nullOnDelete(); // AÜ içinden gelenler
            $table->string('department')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transcript_courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_course_id');
            $table->dropConstrainedForeignId('source_course_id');
            $table->dropColumn('department');
        });
        Schema::dropIfExists('catalog_courses');
        Schema::dropIfExists('catalog_programs');
    }
};
