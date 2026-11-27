@extends('layouts.app')
@section('title', 'Ana sayfa')
@section('content')
<div class="page-head">
    <div>
        <h1>Merhaba, {{ auth()->user()->name }}</h1>
        <div class="sub">{{ $currentTerm ?? '' }} dönemi · Muafiyet ve İntibak Komisyonu</div>
    </div>
    <a class="btn" href="{{ route('students.create') }}"><x-icon name="plus" :size="14" /> Öğrenci hesabı aç</a>
</div>

<div class="grid grid-5">
    <div class="stat"><span class="stat-icon amber"><x-icon name="clipboard" :size="18" /></span><div>Bekleyen başvuru<b>{{ $pending->count() }}</b></div></div>
    <div class="stat"><span class="stat-icon"><x-icon name="users" :size="18" /></span><div>Öğrenci<b>{{ $stats['students'] }}</b></div></div>
    <div class="stat"><span class="stat-icon sky"><x-icon name="building" :size="18" /></span><div>Lisans programı<b>{{ $stats['programs'] }}</b></div></div>
    <div class="stat"><span class="stat-icon violet"><x-icon name="book" :size="18" /></span><div>Plan dersi<b>{{ number_format($stats['courses'], 0, ',', '.') }}</b></div></div>
    <div class="stat"><span class="stat-icon green"><x-icon name="database" :size="18" /></span><div>Eşdeğerlik kaydı<b>{{ $stats['pool'] }}</b></div></div>
</div>

@if ($objections->isNotEmpty())
    <div class="alert warn" style="white-space:normal"><b>{{ $objections->count() }} itiraz cevap bekliyor:</b>
        @foreach ($objections as $app)<a href="{{ route('applications.show', $app) }}#itiraz">{{ $app->student->full_name }}</a> ({{ $app->objected_at->format('d.m.Y') }})@if (! $loop->last), @endif @endforeach
    </div>
@endif

<div class="card table-wrap">
    <div class="card-head">
        <div><h2>Değerlendirme bekleyen başvurular</h2><div class="sub">Öğrencilerin gönderdiği başvurular, gönderim sırasıyla</div></div>
        <a class="btn light small" href="{{ route('applications.index') }}">Tümü</a>
    </div>
    @if ($pending->isEmpty())
        <p class="muted" style="margin:0">Bekleyen başvuru yok.</p>
    @else
        <table>
            <tr><th>Gönderim</th><th>Öğrenci</th><th>Tür</th><th>Program</th><th class="num">Ders</th><th class="num">Belge</th><th></th></tr>
            @foreach ($pending as $app)
                <tr>
                    <td class="small">{{ $app->submitted_at?->format('d.m.Y H:i') }}</td>
                    <td><a href="{{ route('applications.show', $app) }}">{{ $app->student->full_name }}</a></td>
                    <td class="small">{{ \App\Models\ExemptionApplication::SHORT_TYPES[$app->type] ?? $app->type }}</td>
                    <td>{{ $app->student->program?->full_name }}</td>
                    <td class="num">{{ $app->transcript_count }}</td>
                    <td class="num">{{ $app->documents_count }}</td>
                    <td>@if ($app->isPrep())<a class="btn small" href="{{ route('applications.show', $app) }}">Karar ver</a>
                        @else<form method="POST" action="{{ route('files.store', $app->student) }}">@csrf<button class="small"><x-icon name="clipboard" :size="13" /> Değerlendirmeye al</button></form>@endif</td>
                </tr>
            @endforeach
        </table>
    @endif
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><div><h2>Süreç</h2><div class="sub">Görev dağılımı</div></div></div>
        <ol style="margin:0;padding-left:18px">
            <li><b>Komisyon</b> öğrenci hesabını açar (ad, program, e-posta); öğrenci e-postasına gelen bağlantıyla parolasını belirler.</li>
            <li><b>Öğrenci</b> önceki öğrenim bilgilerini girer, derslerini kurum/bölüm listesinden seçer, notlarını yazar.</li>
            <li><b>Öğrenci</b> derslerini AÜ dersleriyle eşleştirir (otomatik öneri alabilir), dilekçe ve belgelerini yükleyip gönderir.</li>
            <li><b>Komisyon</b> başvuruyu değerlendirmeye alır: AKTS/kredi ve içerik kontrolleriyle her talebi kabul/ret eder, notu ve yarıyılı belirler.</li>
            <li><b>Yönetim kurulu kararı</b> girilince sonuç öğrencinin panelinde görünür, kabul edilen eşleştirmeler havuza yazılır.</li>
        </ol>
    </div>
    <div class="card table-wrap">
        <div class="card-head"><div><h2>Son değerlendirmeler</h2></div><a class="btn light small" href="{{ route('files.index') }}">Tümü</a></div>
        @if ($recentFiles->isEmpty())
            <p class="muted" style="margin:0">Henüz değerlendirme yok.</p>
        @else
            <table>
                <tr><th>Öğrenci</th><th class="num">Muaf AKTS</th><th class="num">Yy</th><th>Durum</th></tr>
                @foreach ($recentFiles as $file)
                    <tr>
                        <td><a href="{{ route('files.show', $file) }}">{{ $file->student->full_name }}</a><div class="small muted">{{ $file->curriculum->program->full_name }}</div></td>
                        <td class="num">{{ rtrim(rtrim(number_format($file->exempt_ects, 1, ',', ''), '0'), ',') }}</td>
                        <td class="num">{{ $file->effective_semester }}</td>
                        <td><span class="badge {{ $file->status === 'karar' ? 'ok' : 'warn' }}">{{ \App\Models\ExemptionFile::STATUSES[$file->status] }}</span></td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
</div>
@endsection
