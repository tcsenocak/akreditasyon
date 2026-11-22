<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EquivalenceController;
use App\Http\Controllers\ExemptionFileController;
use App\Http\Controllers\PlanMappingController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentDocumentController;
use App\Http\Controllers\StudentPortalController;
use App\Http\Controllers\TranscriptController;
use Illuminate\Support\Facades\Route;

Route::get('/giris', [AuthController::class, 'showLogin'])->name('login');
Route::post('/giris', [AuthController::class, 'login'])->name('login.attempt')->middleware('throttle:10,1');
Route::post('/cikis', [AuthController::class, 'logout'])->name('logout');

Route::middleware('guest')->group(function (): void {
    Route::get('/kayit', [StudentPortalController::class, 'showRegister'])->name('register');
    Route::post('/kayit', [StudentPortalController::class, 'register'])->name('register.store')->middleware('throttle:5,1');
});

// Öğrenci paneli
Route::middleware(['auth', 'role:student'])->prefix('basvuru')->name('portal.')->group(function (): void {
    Route::get('/', [StudentPortalController::class, 'home'])->name('home');
    Route::get('/parola', [StudentPortalController::class, 'showPassword'])->name('password');
    Route::put('/parola', [StudentPortalController::class, 'updatePassword'])->name('password.update');
    Route::put('/bilgiler', [StudentPortalController::class, 'updateProfile'])->name('profile');
    Route::get('/dersler', [StudentPortalController::class, 'courses'])->name('courses');
    Route::get('/dersler/ara', [StudentPortalController::class, 'search'])->name('courses.search')->middleware('throttle:120,1');
    Route::post('/dersler/bolum', [StudentPortalController::class, 'selectDepartment'])->name('courses.department');
    Route::get('/dersler/sablon', [StudentPortalController::class, 'template'])->name('courses.template');
    Route::post('/dersler', [StudentPortalController::class, 'storeCourse'])->name('courses.store');
    Route::post('/dersler/aktar', [StudentPortalController::class, 'importCourses'])->name('courses.import');
    Route::post('/dersler/muafiyet', [StudentPortalController::class, 'saveRows'])->name('courses.rows');
    Route::put('/dersler/{transcriptCourse}', [StudentPortalController::class, 'updateCourse'])->name('courses.update');
    Route::delete('/dersler/{transcriptCourse}', [StudentPortalController::class, 'destroyCourse'])->name('courses.destroy');
    Route::get('/eslestirmeler', [StudentPortalController::class, 'matches'])->name('matches');
    Route::post('/eslestirmeler/oner', [StudentPortalController::class, 'autoMatch'])->name('matches.auto');
    Route::get('/belgeler', [StudentPortalController::class, 'documents'])->name('documents');
    Route::post('/belgeler', [StudentDocumentController::class, 'store'])->name('documents.store');
    Route::post('/gonder', [StudentPortalController::class, 'submit'])->name('submit');
});

// Belgeler (öğrenci kendi belgesine, komisyon tümüne erişir)
Route::middleware('auth')->group(function (): void {
    Route::get('/belgeler/{document}', [StudentDocumentController::class, 'download'])->name('documents.download');
    Route::delete('/belgeler/{document}', [StudentDocumentController::class, 'destroy'])->name('documents.destroy');
});

// Komisyon
Route::middleware(['auth', 'role:admin'])->group(function (): void {
    Route::post('/katalog/{program}/onayla', [CatalogController::class, 'verify'])->name('catalog.verify');
    Route::put('/ogrenciler/{student}/basvuru-durumu', [StudentController::class, 'applicationStatus'])->name('students.application');
    Route::post('/ogrenciler/{student}/hesap', [StudentController::class, 'createAccount'])->name('students.account.store');
    Route::post('/ogrenciler/{student}/hesap/parola', [StudentController::class, 'resetPassword'])->name('students.account.reset');
    Route::delete('/ogrenciler/{student}/hesap', [StudentController::class, 'destroyAccount'])->name('students.account.destroy');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/mevzuat', [DashboardController::class, 'rules'])->name('rules');

    Route::get('/programlar', [ProgramController::class, 'index'])->name('programs.index');
    Route::post('/programlar/aktar', [ProgramController::class, 'import'])->name('programs.import');
    Route::post('/programlar/indir', [ProgramController::class, 'download'])->name('programs.download');
    Route::post('/programlar/obs', [ProgramController::class, 'obs'])->name('programs.obs');
    Route::get('/programlar/{program}', [ProgramController::class, 'show'])->whereNumber('program')->name('programs.show');
    Route::get('/planlar/{curriculum}', [ProgramController::class, 'curriculum'])->name('curricula.show');
    Route::get('/planlar/{curriculum}/ders/{course}', [ProgramController::class, 'course'])->name('curricula.course');
    Route::post('/planlar/{curriculum}/aktif', [ProgramController::class, 'activate'])->name('curricula.activate');
    Route::delete('/planlar/{curriculum}', [ProgramController::class, 'destroyCurriculum'])->name('curricula.destroy');
    Route::post('/plan-dersleri/{course}', [ProgramController::class, 'updateCourse'])->name('curriculum-courses.update');

    Route::resource('ogrenciler', StudentController::class)->parameters(['ogrenciler' => 'student'])->names('students');

    // Komisyon öğrenci dersi girmez; yalnız not dönüşüm kuralları değişince yeniden çevirir.
    Route::post('/ogrenciler/{student}/dokum/cevir', [TranscriptController::class, 'reconvert'])->name('transcript.reconvert');

    Route::post('/ogrenciler/{student}/muafiyet', [ExemptionFileController::class, 'store'])->name('files.store');
    Route::get('/muafiyet', [ExemptionFileController::class, 'index'])->name('files.index');
    Route::get('/muafiyet/{file}', [ExemptionFileController::class, 'show'])->name('files.show');
    Route::put('/muafiyet/{file}', [ExemptionFileController::class, 'update'])->name('files.update');
    Route::delete('/muafiyet/{file}', [ExemptionFileController::class, 'destroy'])->name('files.destroy');
    Route::post('/muafiyet/{file}/olustur', [ExemptionFileController::class, 'generate'])->name('files.generate');
    Route::put('/muafiyet-kalem/{item}', [ExemptionFileController::class, 'updateItem'])->name('files.items.update');
    Route::delete('/muafiyet-kalem/{item}', [ExemptionFileController::class, 'destroyItem'])->name('files.items.destroy');
    Route::post('/muafiyet/{file}/toplu', [ExemptionFileController::class, 'bulk'])->name('files.bulk');
    Route::get('/muafiyet/{file}/rapor', [ExemptionFileController::class, 'report'])->name('files.report');
    Route::get('/muafiyet/{file}/excel', [ExemptionFileController::class, 'export'])->name('files.export');

    Route::get('/havuz', [EquivalenceController::class, 'index'])->name('equivalences.index');
    Route::post('/havuz', [EquivalenceController::class, 'store'])->name('equivalences.store');
    Route::delete('/havuz/{equivalence}', [EquivalenceController::class, 'destroy'])->name('equivalences.destroy');

    Route::get('/plan-intibak', [PlanMappingController::class, 'index'])->name('mappings.index');
    Route::get('/plan-intibak/{old}/{new}', [PlanMappingController::class, 'show'])->name('mappings.show');
    Route::post('/plan-intibak/{old}/{new}/otomatik', [PlanMappingController::class, 'auto'])->name('mappings.auto');
    Route::post('/plan-intibak/{old}/{new}', [PlanMappingController::class, 'store'])->name('mappings.store');
    Route::delete('/eslestirme/{mapping}', [PlanMappingController::class, 'destroy'])->name('mappings.destroy');
    Route::get('/plan-intibak/{old}/{new}/ogrenci', [PlanMappingController::class, 'student'])->name('mappings.student');

    Route::get('/ayarlar', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/ayarlar/not', [SettingsController::class, 'storeGrade'])->name('settings.grades.store');
    Route::delete('/ayarlar/not/{gradeConversion}', [SettingsController::class, 'destroyGrade'])->name('settings.grades.destroy');
});

// Sınav Cevap Anahtarı Sistemi (öğrenci: /sinav, komisyon: /sinav/yonetim)
require base_path('routes/sinav.php');
