<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sınav Cevap Anahtarı Sistemi tabloları. Hepsi "sinav_" önekiyle başlar;
 * mevcut sisteminizin tablolarına dokunulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sinav_settings', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->text('value')->nullable();
        });

        Schema::create('sinav_terms', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name', 120);
            $t->string('academic_year', 40)->default('');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->dateTime('access_ends_at');
            $t->string('status', 16)->default('draft'); // draft | active | closing | closed
            $t->unsignedInteger('closure_students_deleted')->default(0);
            $t->dateTime('closure_started_at')->nullable();
            $t->dateTime('closure_closed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('sinav_courses', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('term_id');
            $t->string('code', 40);
            $t->string('code_slug', 60);
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->string('instructor', 200)->default('');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['term_id', 'code_slug']);
            $t->foreign('term_id')->references('id')->on('sinav_terms');
        });

        Schema::create('sinav_course_docs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('course_id');
            $t->string('title', 150);
            $t->string('url', 2000);
            $t->integer('sort')->default(0);
            $t->timestamps();
            $t->foreign('course_id')->references('id')->on('sinav_courses')->onDelete('cascade');
        });

        Schema::create('sinav_groups', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('term_id');
            $t->unsignedBigInteger('course_id');
            $t->string('name', 60);
            $t->string('name_slug', 80);
            $t->timestamps();
            $t->unique(['course_id', 'name_slug']);
            $t->foreign('course_id')->references('id')->on('sinav_courses')->onDelete('cascade');
        });

        // Öğrenciye ait kişisel veri yalnızca bu iki tabloda durur ve dönem kapanınca silinir.
        Schema::create('sinav_students', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('term_id');
            $t->string('student_no', 12);
            $t->string('name', 120)->default('');
            $t->timestamps();
            $t->unique(['term_id', 'student_no']);
            $t->foreign('term_id')->references('id')->on('sinav_terms');
        });

        Schema::create('sinav_enrollments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('course_id');
            $t->unsignedBigInteger('group_id');
            // Bir öğrenci aynı dersin yalnızca bir grubunda olabilir.
            $t->unique(['student_id', 'course_id']);
            $t->foreign('student_id')->references('id')->on('sinav_students')->onDelete('cascade');
            $t->foreign('group_id')->references('id')->on('sinav_groups');
        });

        Schema::create('sinav_exams', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('term_id');
            $t->unsignedBigInteger('course_id');
            $t->string('type', 20);
            $t->string('title', 120);
            $t->string('description', 500)->default('');
            // Dosya eklenip silindikçe artar; onaylanan değerden büyükse "onaylanmamış değişiklik var".
            $t->unsignedInteger('draft_rev')->default(0);
            $t->timestamps();
            $t->foreign('course_id')->references('id')->on('sinav_courses');
        });

        // Sınav dosyalarının ASIL kopyası (yalnızca yönetici).
        Schema::create('sinav_exam_files', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('exam_id');
            $t->string('name', 200);
            $t->string('mime', 40);
            $t->unsignedBigInteger('size');
            $t->string('kind', 16); // key | questions
            $t->text('group_ids')->nullable(); // JSON; boş = sınavın tüm grupları
            $t->string('path', 255);
            $t->timestamps();
            $t->foreign('exam_id')->references('id')->on('sinav_exams')->onDelete('cascade');
        });

        // Sınavın bir gruba açılması: tarihler ve onay durumu.
        Schema::create('sinav_releases', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('exam_id');
            $t->unsignedBigInteger('term_id');
            $t->unsignedBigInteger('course_id');
            $t->unsignedBigInteger('group_id');
            $t->dateTime('exam_starts_at');
            $t->dateTime('exam_ends_at');
            $t->dateTime('publish_at');
            $t->dateTime('publish_until')->nullable();
            $t->boolean('approved')->default(false);
            $t->boolean('halted')->default(false);
            $t->unsignedInteger('version')->default(0);
            $t->unsignedInteger('approved_rev')->default(0);
            $t->dateTime('content_updated_at')->nullable();
            $t->timestamps();
            $t->unique(['exam_id', 'group_id']);
            $t->index(['term_id', 'group_id']);
            $t->foreign('exam_id')->references('id')->on('sinav_exams')->onDelete('cascade');
            $t->foreign('group_id')->references('id')->on('sinav_groups');
        });

        // Onay anında gruba kopyalanan YAYIN kopyası: öğrenci yalnızca bunu görür.
        Schema::create('sinav_release_files', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('release_id');
            $t->unsignedBigInteger('exam_file_id');
            $t->string('name', 200);
            $t->string('mime', 40);
            $t->unsignedBigInteger('size');
            $t->string('kind', 16);
            $t->string('path', 255);
            $t->timestamps();
            $t->foreign('release_id')->references('id')->on('sinav_releases')->onDelete('cascade');
        });

        Schema::create('sinav_key_versions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('exam_id');
            $t->unsignedBigInteger('release_id');
            $t->unsignedBigInteger('group_id');
            $t->unsignedInteger('version');
            $t->text('files')->nullable(); // JSON [{id,name,kind}]
            $t->string('note', 500)->default('');
            $t->string('admin', 190)->default('');
            $t->dateTime('created_at')->nullable();
            $t->foreign('exam_id')->references('id')->on('sinav_exams')->onDelete('cascade');
        });

        // Ders dosyası (akreditasyon)
        Schema::create('sinav_acc_sections', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('course_id');
            $t->string('kind', 20);
            $t->string('title', 120);
            $t->integer('sort');
            $t->foreign('course_id')->references('id')->on('sinav_courses')->onDelete('cascade');
        });

        Schema::create('sinav_acc_files', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('course_id');
            $t->unsignedBigInteger('section_id');
            $t->string('name', 200);
            $t->string('mime', 40);
            $t->unsignedBigInteger('size');
            $t->string('path', 255);
            $t->integer('sort');
            $t->string('source', 10)->default('upload'); // upload | xlsx | exam
            $t->timestamps();
            $t->foreign('section_id')->references('id')->on('sinav_acc_sections')->onDelete('cascade');
        });

        Schema::create('sinav_acc_outputs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('course_id');
            $t->string('name', 200);
            $t->unsignedBigInteger('size');
            $t->unsignedInteger('page_count');
            $t->string('path', 255);
            $t->timestamps();
            $t->foreign('course_id')->references('id')->on('sinav_courses')->onDelete('cascade');
        });

        // İşlem geçmişi: yalnızca eklenir; öğrenci numarası veya adı YAZILMAZ.
        Schema::create('sinav_audit_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('action', 60);
            $t->string('object_type', 30);
            $t->string('object_id', 60);
            $t->string('result', 10)->default('ok');
            $t->string('note', 300)->default('');
            $t->string('admin', 190)->default('');
            $t->dateTime('created_at')->nullable();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        foreach ([
            'sinav_audit_logs', 'sinav_acc_outputs', 'sinav_acc_files', 'sinav_acc_sections',
            'sinav_key_versions', 'sinav_release_files', 'sinav_releases', 'sinav_exam_files',
            'sinav_exams', 'sinav_enrollments', 'sinav_students', 'sinav_groups',
            'sinav_course_docs', 'sinav_courses', 'sinav_terms', 'sinav_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
