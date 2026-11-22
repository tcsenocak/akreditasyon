@extends('sinav.admin.layout')
@section('title', 'Ayarlar')
@section('sinav-content')
<h1>Ayarlar</h1>
<form method="post" action="{{ route('sinav.admin.settings.save') }}" class="card" style="max-width:640px">
  @csrf
  <div class="field"><label for="inst">Kurum adı</label><input id="inst" name="institution_name" class="input" maxlength="200" value="{{ $settings['institution_name'] }}">
    <div class="hint">Öğrenci ekranının üst kısmında küçük yazıyla görünür.</div></div>
  <div class="field"><label for="title">Site başlığı</label><input id="title" name="site_title" class="input" maxlength="200" value="{{ $settings['site_title'] }}"></div>
  <button class="btn">Kaydet</button>
</form>
<div class="card" style="max-width:640px">
  <h2>Sunucu bilgisi</h2>
  <dl class="kv">
    <dt>PHP yükleme sınırı</dt><dd>{{ $uploadMax }} (dosyalar {{ round(config('sinav.chunk_bytes') / 1024) }} KB'lık parçalar halinde gönderildiği için bu sınır sorun olmaz)</dd>
    <dt>POST sınırı</dt><dd>{{ $postMax }}</dd>
    <dt>Saat dilimi</dt><dd>Gösterim: {{ config('sinav.timezone') }} · Uygulama: {{ config('app.timezone') }}</dd>
    <dt>Sunucu saati (Türkiye)</dt><dd>{{ \App\Services\Sinav\Util::fmt(now()) }}</dd>
    <dt>Yöneticiler</dt><dd>{{ implode(', ', config('sinav.admin_emails')) }}</dd>
  </dl>
</div>
@endsection
