<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->unsignedInteger('obs_program_id')->nullable()->index();
        });

        Schema::table('curricula', function (Blueprint $table) {
            $table->unsignedInteger('obs_year_id')->nullable();
        });

        Schema::table('curriculum_courses', function (Blueprint $table) {
            $table->unsignedInteger('obs_course_id')->nullable()->index();
            $table->string('elective_group', 32)->nullable();
        });

        // Ders içerikleri (OBS Ders Bilgi Paketi)
        Schema::create('course_infos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('obs_course_id')->unique();
            $table->string('code', 32)->nullable();
            $table->string('name')->nullable();
            $table->string('language', 32)->nullable();
            $table->text('prerequisites')->nullable();
            $table->text('objective')->nullable();
            $table->text('description')->nullable();
            $table->json('weekly_topics')->nullable();
            $table->json('outcomes')->nullable();
            $table->string('offering_label')->nullable(); // hangi dönemin içeriği
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_infos');
        Schema::table('curriculum_courses', fn (Blueprint $t) => $t->dropColumn(['obs_course_id', 'elective_group']));
        Schema::table('curricula', fn (Blueprint $t) => $t->dropColumn('obs_year_id'));
        Schema::table('programs', fn (Blueprint $t) => $t->dropColumn('obs_program_id'));
    }
};
