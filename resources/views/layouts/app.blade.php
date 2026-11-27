<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Muafiyet ve İntibak') — Muafiyet, İntibak ve Sınav · Atatürk Üniversitesi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=13">
</head>
@auth
@php
    $user = auth()->user();
    $isStudent = $user->isStudent();
    $isInstructor = $user->isInstructor();
    $isChair = $user->isAdmin();
@endphp
<body class="shell">
<aside class="sidebar" id="sidebar">
    <a href="{{ $isStudent ? route('portal.home') : ($isInstructor ? route('sinav.admin.dashboard') : route('dashboard')) }}" class="brand">
        <span class="brand-icon"><x-icon name="layers" :size="18" /></span>
        <span><b>Muafiyet, İntibak ve Sınav</b><small>Atatürk Üniversitesi</small></span>
    </a>
    <nav class="nav">
        @if ($isStudent)
            @php
                $portalMode = $user->student?->portalMode() ?? 'yeni';
            @endphp
            <div class="nav-group">BAŞVURU</div>
            @include('partials.nav-item', ['route' => 'portal.home', 'label' => \App\Models\Student::PORTAL_MODES[$portalMode], 'icon' => $portalMode === 'yeni' ? 'plus' : ($portalMode === 'duzenle' ? 'edit' : 'clipboard'), 'patterns' => ['portal.courses*', 'portal.matches*', 'portal.documents*', 'portal.petition']])
            @include('partials.nav-item', ['route' => 'portal.applications.index', 'label' => 'Muafiyet Başvurularım', 'icon' => 'folder', 'patterns' => ['portal.applications.index', 'portal.applications.show']])
            <div class="nav-group">HESAP</div>
            @include('partials.nav-item', ['route' => 'portal.password', 'label' => 'Parolam', 'icon' => 'key', 'patterns' => ['portal.password*']])
        @elseif ($isInstructor)
            @include('partials.nav-item', ['route' => 'sinav.admin.dashboard', 'label' => 'Ana Sayfa', 'icon' => 'home'])
            <div class="nav-group">PROGRAMLARIM</div>
            @include('partials.nav-item', ['route' => 'sinav.admin.courses', 'label' => 'Derslerim', 'icon' => 'book', 'patterns' => ['sinav.admin.docs.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.exams', 'label' => 'Sınavlarım', 'icon' => 'clipboard', 'patterns' => ['sinav.admin.exams.*']])
            <div class="nav-group">CEVAP ANAHTARI</div>
            @include('partials.nav-item', ['route' => 'sinav.admin.keys', 'label' => 'Cevap Anahtarları', 'icon' => 'upload', 'patterns' => ['sinav.admin.files.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.publish', 'label' => 'Yayın Takvimi', 'icon' => 'send', 'patterns' => ['sinav.admin.releases.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.groups', 'label' => 'Gruplar ve PIN', 'icon' => 'key', 'patterns' => ['sinav.admin.groups.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.students', 'label' => 'Öğrencilerim', 'icon' => 'users'])
            @include('partials.nav-item', ['route' => 'sinav.admin.qr', 'label' => 'Öğrenci QR Kodu', 'icon' => 'qr'])
            <div class="nav-group">ARŞİV</div>
            @include('partials.nav-item', ['route' => 'sinav.admin.acc', 'label' => 'Ders Dosyası (Akreditasyon)', 'icon' => 'folder', 'patterns' => ['sinav.admin.acc.*']])
        @else
            @include('partials.nav-item', ['route' => 'dashboard', 'label' => 'Ana Sayfa', 'icon' => 'home'])
            <div class="nav-group">MUAFİYET-İNTİBAK</div>
            @include('partials.nav-item', ['route' => 'students.index', 'label' => 'Öğrenciler', 'icon' => 'users', 'patterns' => ['students.*']])
            @include('partials.nav-item', ['route' => 'applications.index', 'label' => 'Başvurular', 'icon' => 'send', 'patterns' => ['applications.*'], 'badge' => $openApplications ?? 0])
            @include('partials.nav-item', ['route' => 'files.index', 'label' => 'Değerlendirme Dosyaları', 'icon' => 'clipboard', 'patterns' => ['files.*']])
            @include('partials.nav-item', ['route' => 'programs.index', 'label' => 'Öğretim Planları', 'icon' => 'book', 'patterns' => ['programs.*', 'curricula.*']])
            @include('partials.nav-item', ['route' => 'mappings.index', 'label' => 'Plan İntibakı', 'icon' => 'shuffle', 'patterns' => ['mappings.*']])
            @include('partials.nav-item', ['route' => 'equivalences.index', 'label' => 'Eşdeğerlik Havuzu', 'icon' => 'database', 'patterns' => ['equivalences.*']])
            @include('partials.nav-item', ['route' => 'rules', 'label' => 'Mevzuat', 'icon' => 'scale'])
            @if ($isChair)
            <div class="nav-group">SINAV CEVAP ANAHTARI</div>
            @include('partials.nav-item', ['route' => 'sinav.admin.dashboard', 'label' => 'Sınav Paneli', 'icon' => 'home'])
            @include('partials.nav-item', ['route' => 'sinav.admin.exams', 'label' => 'Sınavlar', 'icon' => 'clipboard', 'patterns' => ['sinav.admin.exams.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.keys', 'label' => 'Cevap Anahtarları', 'icon' => 'key', 'patterns' => ['sinav.admin.files.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.publish', 'label' => 'Yayın Takvimi', 'icon' => 'send', 'patterns' => ['sinav.admin.releases.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.courses', 'label' => 'Dersler', 'icon' => 'book', 'patterns' => ['sinav.admin.courses.*', 'sinav.admin.docs.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.groups', 'label' => 'Gruplar ve PIN', 'icon' => 'layers', 'patterns' => ['sinav.admin.groups.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.students', 'label' => 'Sınav Öğrencileri', 'icon' => 'users', 'patterns' => ['sinav.admin.students.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.instructors', 'label' => 'Öğretim Elemanları', 'icon' => 'users', 'patterns' => ['sinav.admin.instructors.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.qr', 'label' => 'Öğrenci QR Kodu', 'icon' => 'qr', 'patterns' => ['sinav.admin.qr.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.acc', 'label' => 'Ders Dosyası (Akreditasyon)', 'icon' => 'folder', 'patterns' => ['sinav.admin.acc.*']])
            <div class="nav-group">SİSTEM</div>
            @include('partials.nav-item', ['route' => 'users.index', 'label' => 'Komisyon Üyeleri', 'icon' => 'users', 'patterns' => ['users.*']])
            @include('partials.nav-item', ['route' => 'audit.index', 'label' => 'İşlem Geçmişi', 'icon' => 'history', 'patterns' => ['audit.*']])
            @include('partials.nav-item', ['route' => 'settings.index', 'label' => 'Ayarlar ve Başvuru Takvimi', 'icon' => 'settings', 'patterns' => ['settings.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.settings', 'label' => 'Sınav Ayarları', 'icon' => 'settings', 'patterns' => ['sinav.admin.settings.*']])
            @include('partials.nav-item', ['route' => 'sinav.admin.audit', 'label' => 'Sınav İşlem Geçmişi', 'icon' => 'history'])
            @endif
        @endif
    </nav>
    <div class="sidebar-foot">v1.0 · Atatürk Üniversitesi Mühendislik Fakültesi</div>
</aside>
<div class="main-col">
    <header class="topbar">
        <button type="button" class="icon-btn menu-btn" onclick="document.body.classList.toggle('nav-open')" aria-label="Menü"><x-icon name="menu" /></button>
        @unless ($isInstructor || request()->routeIs('sinav.*'))
            <span class="pill pill-brand"><x-icon name="calendar" :size="12" />{{ $currentTerm }}</span>
        @endunless
        @if ($isStudent && $user->student)
            @php($st = $user->student->application_status)
            <span class="pill {{ in_array($st, ['gonderildi', 'sonuclandi'], true) ? 'pill-ok' : 'pill-muted' }}">{{ \App\Models\Student::APPLICATION_STATUSES[$st] ?? $st }}</span>
        @elseif ($isInstructor)
            <span class="pill pill-ok">Öğretim Elemanı</span>
        @else
            <span class="pill pill-ok">{{ $isChair ? 'Komisyon başkanı' : 'Komisyon üyesi' }}</span>
        @endif
        <div class="spacer"></div>
        <details class="user-menu">
            <summary><span class="avatar">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span><span class="user-name">{{ $user->display_name }}</span><x-icon name="chevron" :size="14" /></summary>
            <div class="user-dropdown">
                <div class="small muted">{{ $user->email }}</div>
                @unless ($isInstructor)<a href="{{ route('portal.password') }}"><x-icon name="key" :size="14" /> Parolamı değiştir</a>@endunless
                @if ($isInstructor)<a href="{{ route('sinav.admin.password') }}"><x-icon name="key" :size="14" /> Parolamı değiştir</a>@endif
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="link-btn"><x-icon name="logout" :size="14" /> Çıkış</button></form>
            </div>
        </details>
    </header>
    <main class="content">
        @if (session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
        @if ($errors->any())
            <div class="alert bad">@foreach ($errors->all() as $error){{ $error }}@if (! $loop->last)<br>@endif @endforeach</div>
        @endif
        @yield('content')
    </main>
</div>
<div class="backdrop" onclick="document.body.classList.remove('nav-open')"></div>
</body>
@else
<body class="guest">
<main class="content">
    @if (session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
    @if ($errors->any())
        <div class="alert bad">@foreach ($errors->all() as $error){{ $error }}@if (! $loop->last)<br>@endif @endforeach</div>
    @endif
    @yield('content')
</main>
</body>
@endauth
</html>
