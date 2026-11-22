<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $config['site_title'] ?: 'Sınav Cevap Anahtarı Sistemi' }}</title>
<link rel="stylesheet" href="{{ asset('vendor/sinav/sinav.css') }}?v=5">
@if (config('sinav.font_cdn'))<link rel="stylesheet" href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap">@endif
</head>
<body class="sn">
{{-- Bilgisayardan açılınca yalnızca bu blok görünür (karar tarayıcıda verilir; bir caydırıcıdır). --}}
<div id="phone-only" class="hidden" style="min-height:100vh;display:none;align-items:center;justify-content:center;text-align:center;padding:24px">
  <div>
    <h1>Yalnızca telefondan</h1>
    <p style="max-width:440px">Sınav cevap anahtarlarına yalnızca telefonunuzun tarayıcısından ulaşabilirsiniz. Telefonunuzun kamerasıyla aşağıdaki kodu okutun.</p>
    <canvas id="phone-qr" width="200" height="200" style="background:#fff;border-radius:8px" role="img" aria-label="Bu sayfanın QR kodu"></canvas>
    <p class="st-foot"><a href="{{ route('sinav.admin.dashboard') }}">Yönetici girişi</a></p>
  </div>
</div>
<div id="st-app">
  <header class="st-head">
    <div class="in">
      <div class="brand">
        <span class="logo">@include('sinav._icon', ['i' => 'clipboard'])</span>
        <span>
          <strong>{{ $config['site_title'] ?: 'Sınav Cevap Anahtarı Sistemi' }}</strong>
          <small>{{ $config['institution_name'] ?: config('sinav.brand_subtitle') }}</small>
        </span>
      </div>
      @if (!empty($signedIn))
        <form method="post" action="{{ route('sinav.logout') }}">@csrf<button>Çıkış</button></form>
      @endif
    </div>
  </header>
  <main class="st-main" id="st-main">
    @yield('content')
  </main>
  {{-- Yazdır / "PDF olarak kaydet" ile içerik alınamasın: baskıda sayfa boş çıkar. --}}
  <p class="no-print-msg">Bu sayfa yazdırılamaz.</p>
  <footer class="st-foot"><a href="{{ route('sinav.admin.dashboard') }}">Yönetici girişi</a></footer>
</div>
<script src="{{ asset('vendor/sinav/vendor/qrcode.min.js') }}"></script>
<script src="{{ asset('vendor/sinav/student.js') }}?v=2"></script>
@stack('scripts')
</body>
</html>
