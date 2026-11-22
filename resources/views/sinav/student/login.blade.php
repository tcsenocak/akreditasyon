@extends('sinav.student.layout')
@section('content')
<div class="st-box">

  <h1>Öğrenci Girişi</h1>
  @if ($staleQr)
    <p role="status" class="alert warn">Okuttuğunuz QR kod artık geçerli değil. Güncel QR kodu öğretim elemanınızdan isteyin; aşağıdan yine de giriş yapabilirsiniz.</p>
  @endif
  @if ($notice)<p role="status" class="alert warn">{{ $notice }}</p>@endif
  <form method="post" action="{{ route('sinav.login') }}" novalidate>
    @csrf
    <label for="ogrenci-no" class="label" style="margin-top:10px">Öğrenci numaranız</label>
    <input id="ogrenci-no" name="student_no" class="st-input" inputmode="numeric" autocomplete="off" maxlength="14" aria-describedby="giris-yardim" value="{{ old('student_no') }}" required>
    <label for="ders-pin" class="label" style="margin-top:14px">Dersin PIN'i</label>
    <input id="ders-pin" name="pin" type="password" class="st-input" inputmode="numeric" autocomplete="off" maxlength="8" aria-describedby="giris-yardim" required>
    @if (session('error'))<p role="alert" class="alert error" style="margin-top:10px">{{ session('error') }}</p>@endif
    <button class="st-btn" style="margin-top:14px" data-busy-text="Giriş yapılıyor…">Giriş yap</button>
  </form>
  <p id="giris-yardim" class="small muted" style="margin-top:14px">PIN'i dersin öğretim elemanı verir. Girdiğiniz PIN'in dersindeki yayınlanmış cevap anahtarlarını görürsünüz; başka dersiniz için girişten sonra o dersin PIN'ini ekleyebilirsiniz.</p>
</div>
@endsection
