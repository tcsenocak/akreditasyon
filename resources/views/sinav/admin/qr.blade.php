@extends('sinav.admin.layout')
@section('title', 'QR Kod')
@section('sinav-content')
<h1>Öğrenci girişi QR kodu</h1>
<div class="card">
  <p class="small">Tek bir QR kod tüm öğrenciler içindir: okutan kişi öğrenci giriş ekranına gelir ve öğrenci numarasıyla giriş yapar. QR kodun içinde öğrenci bilgisi veya yetki bulunmaz.</p>
  <div class="row" style="align-items:flex-start;gap:24px">
    <div id="qr-print" style="text-align:center">
      <canvas id="qr-canvas" width="320" height="320" style="background:#fff;border-radius:8px" role="img" aria-label="Öğrenci girişi QR kodu"></canvas>
      <p class="mono small" style="word-break:break-all;max-width:320px">{{ $url }}</p>
    </div>
    <div class="stack">
      <p class="small">Kod: <strong class="mono">{{ $code }}</strong></p>
      <button type="button" class="btn" id="qr-png">PNG olarak indir</button>
      <button type="button" class="btn secondary" onclick="window.print()">Yazdır</button>
      @if (\App\Services\Sinav\Access::isCommission())
      <form method="post" action="{{ route('sinav.admin.qr.regenerate') }}" onsubmit="return confirm('Yeni QR kod üretilsin mi? Basılmış eski QR kodlar öğrencilere &quot;artık geçerli değil&quot; uyarısı gösterir (giriş yine de yapılabilir).')">
        @csrf
        <button class="btn danger">Yeni QR üret</button>
      </form>
      @endif
    </div>
  </div>
</div>
<style>@media print { .sn-nav, .sn-menu-btn, .card > p, .stack, h1 { display:none !important } .card { border:0 } }</style>
@endsection
@push('sinav-scripts')
<script src="{{ asset('vendor/sinav/vendor/qrcode.min.js') }}"></script>
<script>
  (function () {
    var url = @json($url);
    var canvas = document.getElementById('qr-canvas');
    SinavQR.toCanvas(canvas, url, { width: 320, margin: 2 }, function (err) { if (err) alert('QR çizilemedi.'); });
    document.getElementById('qr-png').addEventListener('click', function () {
      SinavQR.toDataURL(url, { width: 1000, margin: 2 }, function (err, data) {
        if (err) return alert('QR üretilemedi.');
        var a = document.createElement('a'); a.href = data; a.download = 'ogrenci-giris-qr.png'; a.click();
      });
    });
  })();
</script>
@endpush
