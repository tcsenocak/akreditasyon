@extends('layouts.app')
@section('title', 'Parola')
@section('content')
<div class="card" style="max-width:460px;margin:24px auto">
    <h1>Parolanızı belirleyin</h1>
    @if ($forced)
        <p class="sub">Komisyonun verdiği geçici parolayla giriş yaptınız. Devam etmek için kendi parolanızı belirleyin.</p>
    @endif
    <form method="POST" action="{{ route('portal.password.update') }}" class="form-grid" style="grid-template-columns:1fr">
        @csrf @method('PUT')
        <div><label for="current_password">{{ $forced ? 'Geçici parola' : 'Mevcut parola' }}</label><input id="current_password" type="password" name="current_password" required autocomplete="current-password"></div>
        <div><label for="password">Yeni parola (en az 8 karakter)</label><input id="password" type="password" name="password" required autocomplete="new-password"></div>
        <div><label for="password_confirmation">Yeni parola (tekrar)</label><input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
        <div><button>Kaydet</button></div>
    </form>
</div>
@endsection
