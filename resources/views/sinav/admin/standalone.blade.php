<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex">
<title>@yield('title', 'Yönetim') · Sınav Sistemi</title>
@if (config('sinav.font_cdn'))
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap">
@endif
<link rel="stylesheet" href="{{ asset('vendor/sinav/sinav.css') }}?v=5">
</head>
<body class="sn">
@php
  $user = auth(config('sinav.guard'))->user();
  $userName = $user ? (string) ($user->name ?? $user->email ?? '') : '';
  $initial = $userName !== '' ? mb_strtoupper(mb_substr($userName, 0, 1)) : '?';
@endphp
<div class="sn-admin">
  <nav id="sn-nav" class="sn-nav" aria-label="Sınav sistemi menüsü">
    <div class="sn-brand">
      <span class="logo">@include('sinav._icon', ['i' => 'clipboard'])</span>
      <span><strong>{{ config('sinav.brand_title') }}</strong><small>{{ config('sinav.brand_subtitle') }}</small></span>
    </div>
    <div class="sn-menu">
      @foreach (\App\Services\Sinav\Menu::groups() as $gi => $group)
        @if ($gi === 0)
          @foreach ($group['items'] as $item)
            <a href="{{ $item['url'] }}" class="{{ $item['active'] ? 'active' : '' }}">@include('sinav._icon', ['i' => $item['icon']]) {{ $item['label'] }}</a>
          @endforeach
        @else
          <details open data-menu-group="{{ $gi }}">
            <summary>{{ $group['title'] }} @include('sinav._icon', ['i' => 'chevron'])</summary>
            @foreach ($group['items'] as $item)
              <a href="{{ $item['url'] }}" class="{{ $item['active'] ? 'active' : '' }}">@include('sinav._icon', ['i' => $item['icon']]) {{ $item['label'] }}</a>
            @endforeach
          </details>
        @endif
      @endforeach
      <details open data-menu-group="ogrenci">
        <summary>Öğrenci @include('sinav._icon', ['i' => 'chevron'])</summary>
        <a href="{{ route('sinav.home') }}" target="_blank" rel="noopener">@include('sinav._icon', ['i' => 'phone']) Öğrenci ekranı</a>
      </details>
    </div>
    <div class="sn-navfoot">{{ config('sinav.footer_text') }}</div>
  </nav>
  <div class="sn-main">
    <header class="sn-top">
      <div class="left">
        <button type="button" class="sn-menu-btn" aria-label="Menüyü aç" onclick="document.getElementById('sn-nav').classList.toggle('open')">@include('sinav._icon', ['i' => 'menu'])</button>
      </div>
      <details class="sn-user">
        <summary><span class="sn-avatar">{{ $initial }}</span><span class="sn-name">{{ $userName }}</span>@include('sinav._icon', ['i' => 'chevron'])</summary>
        <div class="menu">
          <div class="who">{{ \App\Services\Sinav\Util::adminName() }}</div>
          <a href="{{ route('sinav.admin.settings') }}">@include('sinav._icon', ['i' => 'settings']) Ayarlar</a>
          <a href="{{ route('sinav.home') }}" target="_blank" rel="noopener">@include('sinav._icon', ['i' => 'external']) Öğrenci ekranı</a>
          @if (Route::has('logout'))
            <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">@include('sinav._icon', ['i' => 'logout']) Çıkış yap</button></form>
          @endif
        </div>
      </details>
    </header>
    @yield('content')
  </div>
</div>
<script>
  // Menü gruplarının açık/kapalı durumu bu tarayıcıda hatırlanır.
  (function () {
    try {
      document.querySelectorAll('[data-menu-group]').forEach(function (d) {
        var k = 'sinav-menu-' + d.getAttribute('data-menu-group');
        if (localStorage.getItem(k) === '0') d.removeAttribute('open');
        d.addEventListener('toggle', function () { localStorage.setItem(k, d.open ? '1' : '0'); });
      });
    } catch (e) { /* depolama kapalı olabilir */ }
    document.addEventListener('click', function (e) {
      document.querySelectorAll('details.sn-user[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
    });
  })();
</script>
</body>
</html>
