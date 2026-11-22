@extends('layouts.app')
@section('title', 'Giriş')
@section('content')
<div class="card login">
    <div class="login-brand">
        <span class="brand-icon" style="width:36px;height:36px;border-radius:9px;background:var(--brand);color:#fff;display:grid;place-items:center"><x-icon name="layers" :size="18" /></span>
        <div><h1 style="margin:0;font-size:17px">Muafiyet, İntibak ve Sınav</h1><div class="sub">Atatürk Üniversitesi · Mühendislik Fakültesi</div></div>
    </div>
    <form method="POST" action="{{ route('login.attempt') }}" class="form-grid" style="grid-template-columns:1fr">
        @csrf
        <div><label for="email">E-posta</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus></div>
        <div><label for="password">Parola</label><input id="password" name="password" type="password" required></div>
        <div class="row"><input type="checkbox" name="remember" id="remember" value="1"><label for="remember" style="margin:0;font-weight:400">Beni hatırla</label></div>
        <div><button style="width:100%;justify-content:center">Giriş yap</button></div>
    </form>
    <p class="small muted" style="margin-top:14px">Öğrenci hesapları muafiyet ve intibak komisyonu tarafından açılır; geçici parolanızla ilk girişte kendi parolanızı belirlersiniz.</p>
    @if (config('intibak.student_registration'))
        <p class="small">Hesabınız yok mu? <a href="{{ route('register') }}">Hesap oluşturun</a></p>
    @endif
</div>
<p class="small muted" style="text-align:center">v1.0 · © {{ now()->year }} ATABAUM</p>
@endsection
