<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

/** İşlem geçmişi. Kayıtlar yalnızca eklenir; arayüzde değiştirme veya silme yoktur. */
class AuditLog extends Model
{
    protected $table = 'sinav_audit_logs';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];

    const LABEL = [
        'term.create' => 'Dönem oluşturuldu',
        'term.update' => 'Dönem güncellendi',
        'term.activate' => 'Dönem aktif yapıldı',
        'term.revertToDraft' => 'Dönem taslağa alındı',
        'term.delete' => 'Dönem silindi',
        'term.close' => 'Dönem kapatıldı',
        'course.create' => 'Ders oluşturuldu',
        'course.update' => 'Ders güncellendi',
        'course.delete' => 'Ders silindi',
        'course.copyTemplate' => 'Ders şablonu kopyalandı',
        'group.create' => 'Grup oluşturuldu',
        'group.delete' => 'Grup silindi',
        'group.pin' => 'Grup PIN\'i belirlendi',
        'instructor.create' => 'Öğretim elemanı hesabı açıldı',
        'instructor.reset' => 'Öğretim elemanı parolası sıfırlandı',
        'instructor.delete' => 'Öğretim elemanı hesabı kapatıldı',
        'exam.create' => 'Sınav oluşturuldu',
        'exam.update' => 'Sınav güncellendi',
        'exam.delete' => 'Sınav silindi',
        'release.approve' => 'Yayın onaylandı',
        'release.halt' => 'Yayından kaldırıldı',
        'release.unapprove' => 'Yayın onayı geri alındı',
        'acc.upload' => 'Ders dosyasına belge yüklendi',
        'acc.copyFromExam' => 'Ders dosyasına sınav belgesi alındı',
        'acc.deleteFile' => 'Ders dosyasından belge silindi',
        'acc.addSection' => 'Ders dosyasına bölüm eklendi',
        'acc.deleteSection' => 'Ders dosyasından bölüm silindi',
        'acc.saveOutput' => 'Ders dosyası PDF olarak saklandı',
        'acc.deleteOutput' => 'Saklanan ders dosyası silindi',
        'courseDoc.add' => 'Ders evrakı eklendi',
        'courseDoc.delete' => 'Ders evrakı kaldırıldı',
        'file.upload' => 'Dosya yüklendi',
        'file.delete' => 'Dosya silindi',
        'qr.regenerate' => 'Yeni QR kod üretildi',
        'settings.update' => 'Ayarlar güncellendi',
        'student.import' => 'Öğrenci eklendi',
        'student.delete' => 'Öğrenci silindi',
        'student.removeFromGroup' => 'Öğrenci gruptan çıkarıldı',
    ];

    public function label()
    {
        return self::LABEL[$this->action] ?? $this->action;
    }
}
