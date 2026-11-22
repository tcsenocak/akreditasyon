<?php

// Sınav Cevap Anahtarı Sistemi ayarları.
// Değerler .env dosyasından okunur; burada yalnızca varsayılanlar durur.

return [

    // Sistemin adres öneki: https://siteniz/sinav (öğrenci), https://siteniz/sinav/yonetim (yönetici)
    'prefix' => env('SINAV_PREFIX', 'sinav'),

    // Yönetici paneline girebilecek kullanıcıların e-posta adresleri (virgülle ayrılır).
    // Kullanıcılar mevcut sisteminizin kendi giriş ekranından girer; bu liste yalnızca
    // kimin sınav paneline erişebileceğini belirler.
    'admin_emails' => array_values(array_filter(array_map(function ($e) {
        return mb_strtolower(trim($e));
    }, explode(',', (string) env('SINAV_ADMIN_EMAILS', ''))))),

    // Mevcut sisteminizdeki giriş sayfasının rota adı (Laravel varsayılanı "login").
    'login_route' => env('SINAV_LOGIN_ROUTE', 'login'),

    // Yönetici kimliğinin hangi "guard" ile denetleneceği (çoğu sistemde "web").
    'guard' => env('SINAV_GUARD', 'web'),

    // ---------- Görünüm ve başka bir uygulamaya gömme ----------

    // Kenar menünün üstündeki ad ve alt yazı, menünün altındaki sürüm satırı.
    'brand_title' => env('SINAV_BRAND_TITLE', 'Sınav Sistemi'),
    'brand_subtitle' => env('SINAV_BRAND_SUBTITLE', 'Atatürk Üniversitesi'),
    'footer_text' => env('SINAV_FOOTER', 'v1.0 · © '.date('Y').' ATABAUM'),

    // Yönetim sayfaları başka bir uygulamanın düzeni içinde gösterilecekse o düzenin adı
    // (ör. "layouts.app") ve içeriğin basılacağı bölüm. Boşsa sistemin kendi düzeni
    // (lacivert kenar menü + üst çubuk) kullanılır.
    'host_layout' => env('SINAV_HOST_LAYOUT', 'layouts.app'),
    'host_section' => env('SINAV_HOST_SECTION', 'content'),

    // Yetki: bir Gate adı verilirse (ör. "sinav-yonetimi") yönetici yetkisine bu Gate
    // karar verir; boşsa SINAV_ADMIN_EMAILS listesi kullanılır.
    'admin_gate' => env('SINAV_ADMIN_GATE', 'sinav-yonetimi'),

    // Inter yazı tipini fonts.bunny.net'ten yükle (kendi düzeninde). false: sistem yazı tipi.
    'font_cdn' => env('SINAV_FONT_CDN', false),

    // Dosyaların saklanacağı disk (config/filesystems.php). "local" = storage/app, dışarıdan erişilemez.
    'disk' => env('SINAV_DISK', 'local'),

    // Öğrenci oturumu en fazla kaç saat sürer.
    'session_hours' => 12,

    // Öğrenci cevap anahtarını yayından sonra kaç gün görebilir.
    'answer_visible_days' => 3,

    // Boyut sınırları (bayt)
    'max_exam_file' => 10 * 1024 * 1024,
    'max_acc_file' => 20 * 1024 * 1024,
    'max_acc_output' => 30 * 1024 * 1024,
    'max_xlsx' => 5 * 1024 * 1024,
    'max_student_list' => 2 * 1024 * 1024,

    // Bu boyuttan büyük PDF'ler (ve görseller) yüklenmeden önce tarayıcıda sıkıştırılır.
    // Sıkıştırılmış dosya yine yukarıdaki sınırları aşmamalıdır.
    'compress_above' => 5 * 1024 * 1024,

    // Sıkıştırılmak üzere seçilebilecek en büyük özgün dosya (tarayıcının belleği için üst sınır).
    'max_original' => 300 * 1024 * 1024,

    // Tarayıcıdan sunucuya parça parça yükleme: her parça bu boyutta gönderilir.
    // Sunucunun upload_max_filesize sınırı (çoğunlukla 2 MB) aşılmasın diye küçük tutulur.
    'chunk_bytes' => 512 * 1024,

    // Ekranda gösterilen ve girilen saatlerin saat dilimi.
    'timezone' => 'Europe/Istanbul',
];
