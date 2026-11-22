@extends('sinav.admin.layout')
@section('title', 'Parola')
@section('sinav-content')
<div class="card" style="max-width:460px;margin:24px auto">
  <div class="card-head"><h2>Parolanızı belirleyin</h2>
    @if ($forced)<p>Komisyonun verdiği geçici parolayla giriş yaptınız. Devam etmek için kendi parolanızı belirleyin.</p>@endif
  </div>
  @if ($errors->any())
    <div class="alert error">@foreach ($errors->all() as $e){{ $e }} @endforeach</div>
  @endif
  <form method="post" action="{{ route('sinav.admin.password.update') }}">
    @csrf @method('PUT')
    <div class="field"><label for="current_password">{{ $forced ? 'Geçici parola' : 'Mevcut parola' }}</label><input id="current_password" type="password" name="current_password" class="input" required autocomplete="current-password"></div>
    <div class="field"><label for="password">Yeni parola (en az 8 karakter)</label><input id="password" type="password" name="password" class="input" required autocomplete="new-password"></div>
    <div class="field"><label for="password_confirmation">Yeni parola (tekrar)</label><input id="password_confirmation" type="password" name="password_confirmation" class="input" required autocomplete="new-password"></div>
    <button class="btn">Kaydet</button>
  </form>
</div>
@endsection
