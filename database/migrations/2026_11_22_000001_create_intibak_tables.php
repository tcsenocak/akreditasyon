<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lisans programları (bölüm/program)
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('faculty')->default('Mühendislik Fakültesi');
            $table->string('department');            // Bölüm adı
            $table->string('name');                  // Program adı
            $table->string('language', 8)->default('TR');
            $table->string('code_prefix', 16)->nullable(); // MMT, MMM ...
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Öğretim planları (müfredat sürümleri)
        Schema::create('curricula', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('start_year')->nullable();     // 2022-2023
            $table->decimal('total_credit', 6, 1)->nullable();
            $table->decimal('total_ects', 6, 1)->nullable();
            $table->unsignedTinyInteger('semester_count')->default(8);
            $table->string('source')->nullable();        // dosya adı / URL
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Öğretim planındaki dersler (havuz)
        Schema::create('curriculum_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('curricula')->cascadeOnDelete();
            $table->string('code', 32)->nullable();
            $table->string('root_code', 32)->nullable()->index();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('normalized_name')->index();
            $table->string('type', 4);                   // OZ, Z, S, ÜS
            $table->string('mode', 4)->nullable();       // YE, UE
            $table->decimal('theory', 4, 1)->default(0);
            $table->decimal('practice', 4, 1)->default(0);
            $table->decimal('lab', 4, 1)->default(0);
            $table->decimal('credit', 5, 1)->default(0);
            $table->decimal('ects', 5, 1)->default(0);
            $table->unsignedTinyInteger('semester')->nullable();
            $table->string('category', 24)->default('plan'); // plan, elective_pool, faculty_elective, university_elective
            $table->boolean('is_placeholder')->default(false); // "Seçmeli Ders I" gibi yuva
            $table->string('term', 16)->nullable();      // Güz/Bahar
            $table->string('common_group', 16)->nullable(); // AIIT, TD, YD, ISG, MYD (mesleki yabancı dil), BIL
            $table->unsignedTinyInteger('sequence')->nullable(); // I/II sıra
            $table->text('content')->nullable();
            $table->timestamps();
        });

        // Öğrenciler
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_no')->nullable()->index();
            $table->string('first_name');
            $table->string('last_name');
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('curriculum_id')->nullable()->constrained('curricula')->nullOnDelete();
            $table->string('admission_type', 32)->default('yatay'); // yatay, dikey, af, yeniden, osym, es_zamanli, plan_degisikligi
            $table->string('entry_term')->nullable();       // 2025-2026 Güz
            $table->string('previous_institution')->nullable();
            $table->string('previous_faculty')->nullable();
            $table->string('previous_program')->nullable();
            $table->boolean('prep_completed')->default(false); // hazırlık başarı
            $table->decimal('yds_score', 5, 2)->nullable();
            $table->string('yds_exam')->nullable();            // YDS, YÖKDİL, TOEFL ...
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Öğrencinin önceki kurum/öğretim planı not dökümü
        Schema::create('transcript_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('institution')->nullable();
            $table->string('academic_year')->nullable();
            $table->string('term')->nullable();
            $table->string('code', 32)->nullable();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->decimal('credit', 5, 1)->nullable();
            $table->decimal('ects', 5, 1)->nullable();
            $table->string('grade_raw', 16)->nullable();
            $table->string('letter', 4)->nullable();        // AÜ harf notuna çevrilmiş
            $table->boolean('passed')->default(false);
            $table->unsignedTinyInteger('semester')->nullable();
            $table->string('source', 16)->default('manuel'); // manuel, excel, pdf, yapistir
            $table->text('content')->nullable();
            $table->timestamps();
        });

        // Muafiyet/intibak dosyası
        Schema::create('exemption_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_id')->constrained('curricula')->cascadeOnDelete();
            $table->string('status', 16)->default('taslak'); // taslak, komisyon, karar
            $table->string('decision_no')->nullable();
            $table->date('decision_date')->nullable();
            $table->decimal('exempt_ects', 6, 1)->default(0);
            $table->unsignedTinyInteger('placed_semester')->nullable();
            $table->unsignedTinyInteger('placed_semester_override')->nullable();
            $table->json('options')->nullable();   // yds, hazırlık, sınav puanları vb.
            $table->text('commission_note')->nullable();
            $table->timestamps();
        });

        Schema::create('exemption_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exemption_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_course_id')->constrained()->cascadeOnDelete();
            $table->string('letter', 4)->nullable();
            $table->string('rule', 32);       // havuz, ortak_zorunlu, esdeger, yds, hazirlik, muafiyet_sinavi, manuel
            $table->decimal('score', 5, 3)->default(0);
            $table->string('status', 12)->default('oneri'); // oneri, kabul, ret
            $table->boolean('ects_ok')->default(true);
            $table->string('reason')->nullable();
            $table->decimal('exam_score', 5, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('exemption_item_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exemption_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transcript_course_id')->constrained()->cascadeOnDelete();
        });

        // Eşdeğerlik havuzu: daha önce komisyonca kabul edilen eşleştirmeler
        Schema::create('equivalences', function (Blueprint $table) {
            $table->id();
            $table->string('institution')->nullable();
            $table->string('normalized_institution')->nullable()->index();
            $table->string('ext_code', 32)->nullable();
            $table->string('ext_name');
            $table->string('ext_normalized_name')->index();
            $table->decimal('ext_ects', 5, 1)->nullable();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_code', 32)->nullable();
            $table->string('target_name');
            $table->string('target_normalized_name')->index();
            $table->unsignedInteger('times_used')->default(1);
            $table->string('decision_ref')->nullable();
            $table->timestamps();
        });

        // Öğretim planı değişikliği ders eşleştirme (Usul ve Esaslar md. 6-7)
        Schema::create('course_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('old_curriculum_id')->constrained('curricula')->cascadeOnDelete();
            $table->foreignId('new_curriculum_id')->constrained('curricula')->cascadeOnDelete();
            $table->string('change_type', 24); // ayni, isim, yariyil, zorunlu_secmeli, secmeli_zorunlu, birlesme, bolunme, kaldirildi
            $table->boolean('locked')->default(false); // sistem eşleştirmesi (kök kod/ad) değiştirilemez
            $table->string('decision_ref')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('course_mapping_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_mapping_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_course_id')->constrained()->cascadeOnDelete();
            $table->string('side', 3); // old / new
        });

        // Harf notu dönüşüm tablosu (diğer kurum notları)
        Schema::create('grade_conversions', function (Blueprint $table) {
            $table->id();
            $table->string('source_grade', 16)->unique();
            $table->string('letter', 4);
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['grade_conversions', 'course_mapping_items', 'course_mappings', 'equivalences', 'exemption_item_sources',
            'exemption_items', 'exemption_files', 'transcript_courses', 'students', 'curriculum_courses', 'curricula', 'programs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
