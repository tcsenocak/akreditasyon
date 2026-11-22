@extends('layouts.app')
@section('title', $student->full_name)
@section('content')
@php
    $st = $student->application_status;
    $requests = $student->transcript->whereNotNull('requested_course_id');
@endphp
<div class="page-head">
    <div>
        <h1>{{ $student->full_name }} <span class="muted small">{{ $student->student_no }}</span></h1>
        <div class="sub">
            {{ \App\Models\Student::ADMISSION_TYPES[$student->admission_type] ?? $student->admission_type }}
            · AÜ: <b>{{ $student->program?->full_name ?? 'program seçilmedi' }}</b> {{ $student->curriculum ? '('.$student->curriculum->title.')' : '' }}
        </div>
    </div>
    <div class="row">
        <a class="btn light" href="{{ route('students.edit', $student) }}"><x-icon name="edit" :size="14" /> Kimlik / program</a>
        <form method="POST" action="{{ route('students.destroy', $student) }}" onsubmit="return confirm('Öğrenci ve tüm dosyaları silinecek. Emin misiniz?')">@csrf @method('DELETE')<button class="danger">Sil</button></form>
    </div>
</div>

<div class="grid grid-5">
    <div class="stat"><span class="stat-icon"><x-icon name="clipboard" :size="18" /></span><div>Başvuru<b style="font-size:15px">{{ \App\Models\Student::APPLICATION_STATUSES[$st] ?? $st }}</b></div></div>
    <div class="stat"><span class="stat-icon sky"><x-icon name="book" :size="18" /></span><div>Ders<b>{{ $student->transcript->count() }}</b></div></div>
    <div class="stat"><span class="stat-icon violet"><x-icon name="link" :size="18" /></span><div>Eşleştirme<b>{{ $requests->count() }}</b></div></div>
    <div class="stat"><span class="stat-icon green"><x-icon name="file" :size="18" /></span><div>Belge<b>{{ $student->documents->count() }}</b></div></div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><div><h2>Değerlendirme</h2><div class="sub">Öğrencinin eşleştirmeleri, kontrollerle birlikte komisyon dosyasına alınır</div></div></div>
        @if ($student->exemptionFiles->isEmpty())
            <form method="POST" action="{{ route('files.store', $student) }}" class="row">
                @csrf
                <button @disabled($requests->isEmpty() && ! $student->yds_score && ! $student->prep_completed)><x-icon name="clipboard" :size="14" /> Değerlendirmeye al</button>
                @if ($st === 'taslak')<span class="small muted">Öğrenci başvurusunu henüz göndermedi.</span>@endif
            </form>
        @else
            <table>
                @foreach ($student->exemptionFiles as $file)
                    <tr>
                        <td><a href="{{ route('files.show', $file) }}">Dosya #{{ $file->id }}</a> <span class="small muted">{{ $file->curriculum->title }}</span></td>
                        <td class="num">{{ (float) $file->exempt_ects }} AKTS</td>
                        <td class="num">{{ $file->effective_semester }}. yy</td>
                        <td><span class="badge {{ $file->status === 'karar' ? 'ok' : 'warn' }}">{{ \App\Models\ExemptionFile::STATUSES[$file->status] }}</span></td>
                    </tr>
                @endforeach
            </table>
        @endif

        <h3>Başvuru durumu</h3>
        <form method="POST" action="{{ route('students.application', $student) }}" class="row">
            @csrf @method('PUT')
            <select name="application_status">@foreach (\App\Models\Student::APPLICATION_STATUSES as $k => $v)<option value="{{ $k }}" @selected($st === $k)>{{ $v }}</option>@endforeach</select>
            <button class="light">Değiştir</button>
            @if ($student->submitted_at)<span class="small muted">gönderim: {{ $student->submitted_at->format('d.m.Y H:i') }}</span>@endif
        </form>
        <p class="small muted">Eksik/hatalı bilgi varsa “Hazırlanıyor” yapın; öğrenci düzeltip yeniden gönderir.</p>

        <h3>Öğrenci paneli hesabı</h3>
        @if (session('account_password'))
            <div class="alert info" style="white-space:normal">
                Giriş bilgileri — <b>bu parola yalnızca şimdi gösterilir</b>, öğrenciye iletin:<br>
                Adres: <code>{{ route('login') }}</code><br>
                E-posta: <code>{{ session('account_password')['email'] }}</code><br>
                Geçici parola: <code style="font-size:15px">{{ session('account_password')['password'] }}</code><br>
                <span class="small">Öğrenci ilk girişte kendi parolasını belirlemek zorundadır.</span>
            </div>
        @endif
        @if ($student->user)
            <p class="small">Hesap: <b>{{ $student->user->email }}</b>
                @if ($student->user->must_change_password)<span class="badge warn">geçici parola — henüz değiştirilmedi</span>@else<span class="badge ok">etkin</span>@endif</p>
            <div class="row">
                <form method="POST" action="{{ route('students.account.reset', $student) }}" onsubmit="return confirm('Yeni geçici parola üretilecek; eski parola geçersiz olur. Devam?')">@csrf<button class="small light"><x-icon name="key" :size="13" /> Parolayı sıfırla</button></form>
                <form method="POST" action="{{ route('students.account.destroy', $student) }}" onsubmit="return confirm('Öğrenci hesabı kapatılsın mı? Başvuru kayıtları silinmez.')">@csrf @method('DELETE')<button class="small danger">Hesabı kapat</button></form>
            </div>
        @else
            <form method="POST" action="{{ route('students.account.store', $student) }}" class="row">
                @csrf
                <input type="email" name="email" placeholder="öğrencinin e-postası" required value="{{ old('email') }}">
                <button class="light">Hesap aç</button>
            </form>
        @endif
    </div>

    <div class="card">
        <div class="card-head"><div><h2>Öğrencinin beyanı</h2><div class="sub">Öğrenci tarafından doldurulur</div></div></div>
        <table>
            <tr><th style="width:42%">Önceki kurum</th><td>{{ $student->previous_institution ?: '—' }}</td></tr>
            <tr><th>Fakülte / bölüm</th><td>{{ $student->previous_faculty }} {{ $student->previous_program ? '/ '.$student->previous_program : '' }}</td></tr>
            <tr><th>Kayıt dönemi</th><td>{{ $student->entry_term ?: '—' }}</td></tr>
            <tr><th>Yabancı dil</th><td>{{ $student->yds_score ? ($student->yds_exam ?: 'YDS').' '.$student->yds_score : '—' }} @if ($student->prep_completed)<span class="badge ok">hazırlık başarılı</span>@endif</td></tr>
            <tr><th>İletişim</th><td>{{ $student->user?->email }} {{ $student->phone ? '· '.$student->phone : '' }}</td></tr>
        </table>
        <h3>Belgeler</h3>
        <table>
            @forelse ($student->documents as $doc)
                <tr>
                    <td class="small">{{ \App\Models\StudentDocument::TYPES[$doc->type] ?? $doc->type }}</td>
                    <td><a href="{{ route('documents.download', $doc) }}"><x-icon name="download" :size="13" /> {{ $doc->original_name }}</a> <span class="small muted">{{ $doc->size_label }}</span></td>
                </tr>
            @empty
                <tr><td class="muted">Belge yüklenmemiş.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($catalogPrograms->isNotEmpty())
<div class="card table-wrap">
    <div class="card-head"><div><h2>Öğrencinin girdiği bölüm ders listeleri</h2><div class="sub">Öğrenci derslerini bu listelerden seçti. Listeyi kontrol edip onaylayın; onaylanan liste diğer öğrencilere de doğrulanmış olarak görünür.</div></div></div>
    <table>
        <tr><th>Kurum</th><th>Bölüm / program</th><th class="num">Ders</th><th>Durum</th><th></th></tr>
        @foreach ($catalogPrograms as $cp)
            <tr>
                <td>{{ $cp->institution }}</td>
                <td>{{ $cp->name }}<div class="small muted">{{ $cp->faculty }}</div></td>
                <td class="num">{{ $cp->courses_count }}</td>
                <td>@if ($cp->verified)<span class="badge ok">onaylı</span>@else<span class="badge warn">onay bekliyor</span>@endif</td>
                <td><form method="POST" action="{{ route('catalog.verify', $cp) }}">@csrf<button class="small {{ $cp->verified ? 'light' : '' }}">{{ $cp->verified ? 'Onayı kaldır' : 'Onayla' }}</button></form></td>
            </tr>
        @endforeach
    </table>
</div>
@endif

<div class="card table-wrap">
    <div class="card-head">
        <div><h2>Öğrencinin dersleri ve eşleştirmeleri</h2><div class="sub">{{ $student->transcript->count() }} ders · {{ $student->transcript->where('passed', true)->count() }} başarılı · {{ $requests->count() }} eşleştirilmiş — salt okunur</div></div>
        <form method="POST" action="{{ route('transcript.reconvert', $student) }}">@csrf<button class="small light" title="Ayarlar'daki not dönüşümü değiştiyse"><x-icon name="refresh" :size="13" /> Notları yeniden çevir</button></form>
    </div>
    <table>
        <tr><th>Kurum / bölüm</th><th>Kod</th><th>Ders</th><th class="num">Kr</th><th class="num">AKTS</th><th>Not</th><th>AÜ harf</th><th>Talep edilen AÜ dersi</th></tr>
        @forelse ($student->transcript as $course)
            <tr @class(['st-ret' => ! $course->passed])>
                <td class="small">{{ $course->institution }}<div class="muted">{{ $course->department }}</div></td>
                <td>{{ $course->code }}</td>
                <td>{{ $course->name }}
                    @if ($course->catalog_course_id || $course->source_course_id)<span class="badge ok">liste</span>@else<span class="badge warn">beyan</span>@endif
                    @if ($course->content)<details class="small"><summary>içerik</summary>{{ $course->content }}</details>@endif
                </td>
                <td class="num">{{ $course->credit }}</td>
                <td class="num">{{ $course->ects }}</td>
                <td>{{ $course->grade_raw }}</td>
                <td><b>{{ $course->letter ?? '?' }}</b> @if (in_array($course->letter, ['DC', 'DD'], true))<span class="badge warn">koşullu</span>@endif</td>
                <td class="small">{{ $course->requestedCourse?->label ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Öğrenci henüz ders girmedi.</td></tr>
        @endforelse
    </table>
</div>
@endsection
