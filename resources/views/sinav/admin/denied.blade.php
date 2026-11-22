<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Yetki yok · Sınav Sistemi</title>
<link rel="stylesheet" href="{{ asset('vendor/sinav/sinav.css') }}?v=5">
</head>
<body class="sn">
<main class="st-main">
  <div class="st-box">
    @if ($reason === 'login')
      <h1>Giriş gerekli</h1>
      <p>Sınav yönetim paneline girmek için önce sistemin kendi giriş sayfasından oturum açın.</p>
    @elseif ($reason === 'commissionOnly')
      <h1>Bu sayfa yalnızca komisyona açık</h1>
      <p>Öğretim elemanı hesabıyla yalnızca size atanmış derslerin sınavlarını, cevap anahtarlarını, PIN'lerini ve ders dosyasını yönetebilirsiniz.</p>
      <p><a href="{{ route('sinav.admin.dashboard') }}">Sınav Paneli'ne dön</a></p>
    @else
      <h1>Bu panele erişim yetkiniz yok</h1>
      <p>Oturum açtığınız hesap ({{ $email ?: 'e-posta yok' }}) sınav paneline tanımlı değil.</p>
      <p class="muted small">Komisyon üyeleri ve komisyonun hesap açtığı öğretim elemanları girebilir.</p>
    @endif
    <p><a href="{{ route('sinav.home') }}">Öğrenci ekranına dön</a></p>
  </div>
</main>
</body>
</html>
