<?php

/**
 * Atatürk Üniversitesi muafiyet ve intibak kuralları.
 *
 * Dayanaklar:
 *  - Ders Muafiyeti ve İntibak İşlemleri Uygulama Esasları (Senato 15.03.2024)
 *  - Önlisans ve Lisans Öğretim Planları İntibak Usul ve Esasları (19.08.2022)
 *  - Ön Lisans ve Lisans Eğitim-Öğretim ve Sınav Yönetmeliği Md. 27 (harf notları)
 *  - Başarı Değerlendirme Sistemleri Uygulama Esasları Tablo I (100'lük → harf)
 */
return [

    // Öğrenciler kendi hesaplarını açabilsin mi? Kapalıyken hesapları komisyon açar (öğrenci sayfası → Öğrenci hesabı).
    'student_registration' => (bool) env('STUDENT_REGISTRATION', false),

    // Md. 7/1: her yarıyıl 30 AKTS kabul edilir.
    'ects_per_semester' => 30,

    'semester_count' => 8,

    // Yönetmelik Md. 27/4-a
    'letters' => [
        'AA' => 4.0, 'BA' => 3.5, 'BB' => 3.0, 'CB' => 2.5, 'CC' => 2.0,
        'DC' => 1.5, 'DD' => 1.0, 'FD' => 0.5, 'FF' => 0.0,
    ],

    // Md. 27/4-b-1: kesin başarılı notlar; DC/DD koşullu (AGNO ≥ 2,00 şartı).
    'definite_pass_letters' => ['AA', 'BA', 'BB', 'CB', 'CC'],
    'conditional_pass_letters' => ['DC', 'DD'],

    // Diğer kurumda DC/DD ile geçilen dersler muafiyete esas alınsın mı?
    'accept_conditional_pass' => true,

    // Başarı Değerlendirme Uygulama Esasları Tablo I ve Muafiyet Esasları Md. 6/6
    'score_table' => [
        ['min' => 88, 'letter' => 'AA'],
        ['min' => 81, 'letter' => 'BA'],
        ['min' => 74, 'letter' => 'BB'],
        ['min' => 67, 'letter' => 'CB'],
        ['min' => 60, 'letter' => 'CC'],
        ['min' => 53, 'letter' => 'DC'],
        ['min' => 50, 'letter' => 'DD'],
        ['min' => 0, 'letter' => 'FF'],
    ],

    // Md. 6/3: G (Geçer) veya M (Muaf) → CC
    'pass_marks_to_cc' => ['G', 'M', 'P', 'S', 'GEÇER', 'GECER', 'MUAF', 'BAŞARILI', 'BASARILI', 'YETERLİ', 'YETERLI', 'EX', 'EXEMPT', 'PASS'],

    'fail_marks' => ['FF', 'FD', 'F', 'Z', 'DZ', 'YZ', 'K', 'NA', 'BZ', 'GR', 'U', 'W', 'I', 'E'],

    // Md. 5/8: YDS eşiği ve mesleki yabancı dil sınırı (yarıyıllık programda en fazla 2 ders)
    'yds_min_score' => 50,
    'professional_language_max_courses' => 2,

    // Günlük yedeklerin klasörü (php artisan yedek:al); proje klasörünün dışında tutulur.
    'backup_path' => env('BACKUP_PATH', dirname(base_path()).DIRECTORY_SEPARATOR.'intibak-yedekler'),

    // İtiraz süresi: karardan sonra iş günü (Uygulama Esasları Md. 5/9)
    'objection_business_days' => 5,

    // Yaz okulunda alınabilecek en fazla AKTS; yaz okulu ön onay başvurusu bu sınırı aşamaz.
    'summer_school_max_ects' => 20,

    // Md. 6/6: temel bilgisayar muafiyet sınavı geçme puanı
    'exam_pass_score' => 50,

    // Eşleştirme eşikleri (ad benzerliği, 0-1)
    'match_strong' => 0.78,
    'match_possible' => 0.55,

    // Kaynak AKTS hedef AKTS'nin bu oranından azsa "AKTS yetersiz" uyarısı
    'ects_tolerance' => 1.0,

    // Usul ve Esaslar Md. 7/1-c-ii: AKTS eşitleme dersleri (1-6 AKTS, yaz okulunda alınmış sayılır)
    'balancing_courses' => ['Topluma Katkı', 'Araştırmaya Katkı', 'Eğitime Katkı'],
    'balancing_min_ects' => 1,
    'balancing_max_ects' => 6,

    'planlar_url' => 'https://atauni.edu.tr/muhendislik-fakultesi/lisans-programlari/',

    // HTTPS doğrulaması için CA paketi (Windows kök sertifikalarından dışa aktarılmış)
    'ca_bundle' => env('HTTP_CA_BUNDLE', storage_path('app/certs/windows-root.pem')),

    // Eski öğretim planları (ders bilgi paketi, .doc)
    'ders_bilgi_paketi_url' => 'https://atauni.edu.tr/muhendislik-fakultesi/ders-icerikleri-2018-ve-sonrasi/',

    // OBS Bologna (Ders Bilgi Paketi) — giriş yılına göre müfredat ve ders içerikleri
    'obs' => [
        'base_url' => 'https://obs.atauni.edu.tr',
        'faculty_id' => 767,          // Mühendislik Fakültesi
        'unit_type' => 2,             // lisans
        'request_delay_ms' => 250,    // sunucuyu yormamak için istekler arası bekleme
        'skip_evening' => true,       // İkinci öğretim (İ.Ö.) programları aynı müfredatla tekrar çekilmez
    ],
];
