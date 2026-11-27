@extends('layouts.app')
@section('title', 'Hesabı etkinleştir')
@section('content')
<div class="card login">
    <div class="login-brand">
        <span class="brand-icon" style="width:36px;height:36px;border-radius:9px;background:var(--brand);color:#fff;display:grid;place-items:center"><x-icon name="key" :size="18" /></span>
        <div><h1 style="margin:0;font-size:17px">Hesabınızı etkinleştirin</h1><div class="sub">Muafiyet, İntibak ve Sınav · Atatürk Üniversitesi</div></div>
    </div>
    @if ($valid)
        <p class="small">Giriş e-postanız: <b>{{ $email }}</b>. Kendi parolanızı belirleyin; hesabınız etkinleşir ve başvuru sayfanız açılır.</p>
        <form method="POST" action="{{ route('activation.store', $token) }}" class="form-grid" style="grid-template-columns:1fr">
            @csrf
            <div><label for="password">Parola (en az 8 karakter)</label><input id="password" name="password" type="password" required minlength="8" autofocus autocomplete="new-password"></div>
            <div><label for="password_confirmation">Parola (tekrar)</label><input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password"></div>
            <div><button style="width:100%;justify-content:center">Hesabımı etkinleştir</button></div>
        </form>
    @else
        <div class="alert bad" style="white-space:normal">Bu bağlantı geçersiz ya da süresi dolmuş. Muafiyet ve intibak komisyonundan yeni bir bağlantı isteyin.</div>
        <p class="small"><a href="{{ route('login') }}">Giriş sayfasına dön</a></p>
    @endif
</div>
@endsection
