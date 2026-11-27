<?php

// Sınav Cevap Anahtarı Sistemi rotaları.
// routes/web.php dosyasının EN ALTINA şu satırı ekleyin:
//     require base_path('routes/sinav.php');

use App\Http\Controllers\Sinav\AccreditationController;
use App\Http\Controllers\Sinav\AdminController;
use App\Http\Controllers\Sinav\ExamController;
use App\Http\Controllers\Sinav\InstructorController;
use App\Http\Controllers\Sinav\StudentAdminController;
use App\Http\Controllers\Sinav\StudentController;
use App\Http\Controllers\Sinav\TermController;
use App\Http\Middleware\SinavAdmin;
use App\Http\Middleware\SinavSecurityHeaders;
use Illuminate\Support\Facades\Route;

Route::prefix(config('sinav.prefix', 'sinav'))->middleware(SinavSecurityHeaders::class)->name('sinav.')->group(function () {

    // ---------- Öğrenci (giriş gerektirmez; öğrenci numarası + grup PIN'iyle oturum açılır) ----------
    Route::get('/', [StudentController::class, 'home'])->name('home');
    Route::get('/g/{code}', [StudentController::class, 'qr'])->where('code', '[A-Za-z0-9]{1,16}')->name('qr');
    Route::post('/giris', [StudentController::class, 'login'])->middleware('throttle:30,1')->name('login');
    Route::post('/pin', [StudentController::class, 'addPin'])->middleware('throttle:30,1')->name('pin');
    Route::post('/cikis', [StudentController::class, 'logout'])->name('logout');
    Route::get('/cevap/{release}', [StudentController::class, 'exam'])->where('release', '[0-9]+')->name('exam');
    Route::get('/dosya/{file}', [StudentController::class, 'file'])->where('file', '[0-9]+')->name('file');

    // ---------- Yönetici ----------
    Route::prefix('yonetim')->middleware(SinavAdmin::class)->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::post('/yukleme', [AdminController::class, 'chunk'])->name('chunk');

        Route::get('/parola', [AdminController::class, 'password'])->name('password');
        Route::put('/parola', [AdminController::class, 'passwordUpdate'])->name('password.update');
        Route::post('/gruplar/{group}/pin', [TermController::class, 'groupPin'])->where('group', '[0-9]+')->name('groups.pin');

        // ---------- Yalnızca komisyon ----------
        Route::middleware(SinavAdmin::class.':commission')->group(function () {
            Route::post('/dersler/{course}', [TermController::class, 'courseUpdate'])->where('course', '[0-9]+')->name('courses.update');
            Route::post('/dersler/{course}/sil', [TermController::class, 'courseDestroy'])->where('course', '[0-9]+')->name('courses.destroy');
            Route::post('/gruplar/{group}/sil', [TermController::class, 'groupDestroy'])->where('group', '[0-9]+')->name('groups.destroy');
            Route::get('/ogrenciler/ekle', [StudentAdminController::class, 'addForm'])->name('students.add');
            Route::post('/ogrenciler/onizle', [StudentAdminController::class, 'preview'])->name('students.preview');
            Route::post('/ogrenciler/aktar', [StudentAdminController::class, 'apply'])->name('students.apply');
            Route::post('/ogrenciler/sil', [StudentAdminController::class, 'destroy'])->name('students.destroy');
            Route::post('/ogrenciler/gruptan-cikar', [StudentAdminController::class, 'removeFromGroup'])->name('students.remove');
            Route::get('/ogrenciler/sablon', [StudentAdminController::class, 'template'])->name('students.template');
            Route::post('/qr', [AdminController::class, 'qrRegenerate'])->name('qr.regenerate');
            Route::get('/islem-gecmisi', [AdminController::class, 'audit'])->name('audit');
            Route::get('/ayarlar', [AdminController::class, 'settings'])->name('settings');
            Route::post('/ayarlar', [AdminController::class, 'settingsSave'])->name('settings.save');
            Route::get('/ogretim-elemanlari', [InstructorController::class, 'index'])->name('instructors');
            Route::post('/ogretim-elemanlari', [InstructorController::class, 'store'])->name('instructors.store');
            Route::post('/ogretim-elemanlari/{instructor}/parola', [InstructorController::class, 'resetPassword'])->where('instructor', '[0-9]+')->name('instructors.reset');
            Route::post('/ogretim-elemanlari/{instructor}/sil', [InstructorController::class, 'destroy'])->where('instructor', '[0-9]+')->name('instructors.destroy');
        });

        // Dersler ve ders evrakı
        Route::get('/dersler', [TermController::class, 'courses'])->name('courses');
        Route::post('/dersler/{course}/evrak', [TermController::class, 'docStore'])->where('course', '[0-9]+')->name('docs.store');
        Route::post('/evrak/{doc}/sil', [TermController::class, 'docDestroy'])->where('doc', '[0-9]+')->name('docs.destroy');

        // Gruplar
        Route::get('/gruplar', [TermController::class, 'groups'])->name('groups');

        // Öğrenciler
        Route::get('/ogrenciler', [StudentAdminController::class, 'index'])->name('students');

        // Sınavlar, cevap anahtarları, yayın takvimi
        Route::get('/sinavlar', [ExamController::class, 'index'])->name('exams');
        Route::get('/sinavlar/yeni', [ExamController::class, 'create'])->name('exams.create');
        Route::post('/sinavlar/liste', [ExamController::class, 'readList'])->name('exams.list');
        Route::post('/sinavlar', [ExamController::class, 'store'])->name('exams.store');
        Route::get('/sinavlar/{exam}/duzenle', [ExamController::class, 'edit'])->where('exam', '[0-9]+')->name('exams.edit');
        Route::post('/sinavlar/{exam}', [ExamController::class, 'update'])->where('exam', '[0-9]+')->name('exams.update');
        Route::post('/sinavlar/{exam}/sil', [ExamController::class, 'destroy'])->where('exam', '[0-9]+')->name('exams.destroy');

        Route::get('/cevap-anahtarlari', [ExamController::class, 'keys'])->name('keys');
        Route::post('/sinavlar/{exam}/dosya', [ExamController::class, 'upload'])->where('exam', '[0-9]+')->name('files.upload');
        Route::post('/dosyalar/{file}/gruplar', [ExamController::class, 'fileGroups'])->where('file', '[0-9]+')->name('files.groups');
        Route::post('/dosyalar/{file}/sil', [ExamController::class, 'fileDestroy'])->where('file', '[0-9]+')->name('files.destroy');
        Route::get('/dosyalar/{file}', [ExamController::class, 'fileShow'])->where('file', '[0-9]+')->name('files.show');

        Route::get('/yayin-takvimi', [ExamController::class, 'publish'])->name('publish');
        Route::get('/yayin/{release}/kontrol', [ExamController::class, 'review'])->where('release', '[0-9]+')->name('releases.review');
        Route::post('/yayin/{release}/onayla', [ExamController::class, 'approve'])->where('release', '[0-9]+')->name('releases.approve');
        Route::post('/yayin/{release}/durdur', [ExamController::class, 'halt'])->where('release', '[0-9]+')->name('releases.halt');
        Route::post('/yayin/{release}/onayi-geri-al', [ExamController::class, 'unapprove'])->where('release', '[0-9]+')->name('releases.unapprove');

        // QR kod
        Route::get('/qr', [AdminController::class, 'qr'])->name('qr');

        // Ders dosyası (akreditasyon)
        Route::get('/ders-dosyasi', [AccreditationController::class, 'index'])->name('acc');
        Route::post('/ders-dosyasi/{course}/bolum', [AccreditationController::class, 'sectionStore'])->where('course', '[0-9]+')->name('acc.section.store');
        Route::post('/ders-dosyasi/bolum/{section}/ad', [AccreditationController::class, 'sectionRename'])->where('section', '[0-9]+')->name('acc.section.rename');
        Route::post('/ders-dosyasi/bolum/{section}/tasi', [AccreditationController::class, 'sectionMove'])->where('section', '[0-9]+')->name('acc.section.move');
        Route::post('/ders-dosyasi/bolum/{section}/sil', [AccreditationController::class, 'sectionDestroy'])->where('section', '[0-9]+')->name('acc.section.destroy');
        Route::post('/ders-dosyasi/bolum/{section}/dosya', [AccreditationController::class, 'upload'])->where('section', '[0-9]+')->name('acc.upload');
        Route::post('/ders-dosyasi/bolum/{section}/sinavdan', [AccreditationController::class, 'copyFromExam'])->where('section', '[0-9]+')->name('acc.fromExam');
        Route::post('/ders-dosyasi/xlsx-satirlar', [AccreditationController::class, 'xlsxRows'])->name('acc.xlsxRows');
        Route::get('/ders-dosyasi/dosya/{file}', [AccreditationController::class, 'fileShow'])->where('file', '[0-9]+')->name('acc.file');
        Route::post('/ders-dosyasi/dosya/{file}/tasi', [AccreditationController::class, 'fileMove'])->where('file', '[0-9]+')->name('acc.file.move');
        Route::post('/ders-dosyasi/dosya/{file}/sil', [AccreditationController::class, 'fileDestroy'])->where('file', '[0-9]+')->name('acc.file.destroy');
        Route::post('/ders-dosyasi/{course}/cikti', [AccreditationController::class, 'outputStore'])->where('course', '[0-9]+')->name('acc.output.store');
        Route::get('/ders-dosyasi/cikti/{output}', [AccreditationController::class, 'outputShow'])->where('output', '[0-9]+')->name('acc.output');
        Route::post('/ders-dosyasi/cikti/{output}/sil', [AccreditationController::class, 'outputDestroy'])->where('output', '[0-9]+')->name('acc.output.destroy');

        // Sistem
    });
});
