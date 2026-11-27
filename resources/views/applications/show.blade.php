@extends('layouts.app')
@section('title', 'Başvuru — '.$student->full_name)
@section('content')
@php
    $st = $application->status;
    $isChair = auth()->user()->isAdmin();
    $requests = $application->transcript->whereNotNull('requested_course_id');
@endphp
<div class="page-head">
    <div>
        <h1>{{ $application->type_label }} <span class="badge {{ $st === 'sonuclandi' ? 'ok' : 'warn' }}" style="vertical-align:middle">{{ \App\Models\Student::APPLICATION_STATUSES[$st] ?? $st }}</span></h1>
        <div class="sub"><a href="{{ route('students.show', $student) }}">{{ $student->full_name }}</a> {{ $student->student_no }} · {{ $student->program?->full_name }} · {{ $number }}. başvurusu
            · gönderim {{ $application->submitted_at?->format('d.m.Y H:i') ?? '—' }}</div>
    </div>
    <div class="row">
        <a class="btn light" href="{{ route('applications.index') }}">← Başvurular</a>
        @if ($file)<a class="btn" href="{{ route('files.show', $file) }}"><x-icon name="clipboard" :size="14" /> Değerlendirme dosyası #{{ $file->id }}</a>@endif
    </div>
</div>

@if ($application->hasOpenObjection())
    <div class="alert warn" style="white-space:normal"><b>Öğrenci bu karara itiraz etti ({{ $application->objected_at->format('d.m.Y H:i') }}).</b> <a href="#itiraz">İtirazı okuyup cevaplayın.</a></div>
@endif
@unless ($isCurrent)
    <div class="alert info" style="white-space:normal">Bu, öğrencinin önceki bir başvurusudur; salt okunur. Yalnız itiraz cevaplanabilir.</div>
@endunless

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><div><h2>Değerlendirme</h2><div class="sub">{{ $application->isPrep() ? 'Hazırlık muafiyetinde ders dosyası açılmaz; karar buradan verilir' : 'Öğrencinin eşleştirmeleri, kontrollerle birlikte komisyon dosyasına alınır' }}</div></div></div>
        @if ($application->isPrep())
            <p>Dil sınavı: <b>{{ $application->language_exam ?: 'girilmedi' }} {{ $application->language_score }}</b>{{ $application->language_exam_date ? ' · '.$application->language_exam_date->format('d.m.Y') : '' }}</p>
            @if ($application->decision)
                <p><span class="badge {{ $application->decision === 'kabul' ? 'ok' : 'bad' }}">{{ \App\Models\ExemptionApplication::DECISIONS[$application->decision] }}</span> <span class="small muted">{{ $application->decider?->display_name }} · {{ $application->decided_at?->format('d.m.Y') }}</span></p>
                @if ($application->decision_note)<p class="small">{{ $application->decision_note }}</p>@endif
            @endif
            @if ($isChair && $st !== 'taslak')
                <form method="POST" action="{{ route('applications.decide', $application) }}" class="form-grid">
                    @csrf @method('PUT')
                    <div><label for="decision">Karar</label><select id="decision" name="decision">@foreach (\App\Models\ExemptionApplication::DECISIONS as $k => $v)<option value="{{ $k }}" @selected($application->decision === $k)>{{ $v }}</option>@endforeach</select></div>
                    <div class="full"><label for="decision_note">Not (öğrenci görür)</label><input id="decision_note" name="decision_note" value="{{ $application->decision_note }}" maxlength="2000"></div>
                    <div><button>Kararı kaydet</button></div>
                </form>
            @elseif (! $isChair)
                <p class="small muted">Hazırlık muafiyeti kararını komisyon başkanı işler.</p>
            @endif
        @elseif ($file)
            <table>
                <tr><td><a href="{{ route('files.show', $file) }}">Dosya #{{ $file->id }}</a> <span class="small muted">{{ $file->assignee?->display_name }}</span></td>
                    <td class="num">{{ (float) $file->exempt_ects }} AKTS</td><td class="num">{{ $file->effective_semester }}. yy</td>
                    <td><span class="badge {{ $file->status === 'karar' ? 'ok' : 'warn' }}">{{ \App\Models\ExemptionFile::STATUSES[$file->status] }}</span></td></tr>
            </table>
        @elseif ($isCurrent)
            <form method="POST" action="{{ route('files.store', $student) }}" class="row">
                @csrf
                <button @disabled($requests->isEmpty() || $st === 'taslak')><x-icon name="clipboard" :size="14" /> Değerlendirmeye al</button>
                @if ($st === 'taslak')<span class="small muted">Öğrenci başvurusunu henüz göndermedi.</span>@endif
            </form>
        @endif

        @if ($isCurrent)
            <h3>Başvuru durumu</h3>
            <form method="POST" action="{{ route('students.application', $student) }}" class="form-grid" id="status-form">
                @csrf @method('PUT')
                <div><select name="application_status" aria-label="Başvuru durumu" onchange="document.getElementById('return-note').hidden = this.value !== 'taslak' || @json($st === 'taslak')">@foreach (\App\Models\Student::APPLICATION_STATUSES as $k => $v)<option value="{{ $k }}" @selected($st === $k)>{{ $k === 'taslak' ? 'Hazırlanıyor (düzeltme için öğrenciye geri aç)' : $v }}</option>@endforeach</select></div>
                <div class="full" id="return-note" hidden><label for="note">Öğrenciye not: neyin eksik ya da hatalı olduğunu yazın</label><textarea id="note" name="note" maxlength="2000" style="min-height:60px;font-family:inherit" placeholder="ör. Ankara Üniversitesi transkripti yüklenmemiş; Fizik I için onaylı ders içeriği gerekli.">{{ old('note') }}</textarea></div>
                <div><button class="light">Değiştir</button>
                @if ($application->submitted_at && $st !== 'sonuclandi')<span class="small muted">{{ (int) $application->submitted_at->diffInDays(now()) }} gündür bekliyor</span>@endif</div>
            </form>
            @if ($application->return_note)<p class="small">Son geri açma notu ({{ $application->returned_at?->format('d.m.Y') }}): {{ $application->return_note }}</p>@endif
            <p class="small muted">Eksik/hatalı bilgi varsa “Hazırlanıyor” yapın ve notu yazın; öğrenci notu görür, düzeltip yeniden gönderir.</p>
        @endif
    </div>

    <div class="card">
        <div class="card-head"><div><h2>Öğrencinin beyanı ve belgeleri</h2><div class="sub">Öğrenci tarafından doldurulur</div></div></div>
        <table>
            <tr><th style="width:42%">Önceki kurum</th><td>{{ $student->previous_institution ?: '—' }}</td></tr>
            <tr><th>Fakülte / bölüm</th><td>{{ $student->previous_faculty }} {{ $student->previous_program ? '/ '.$student->previous_program : '' }}</td></tr>
            <tr><th>Kayıt türü / dönemi</th><td>{{ \App\Models\Student::ADMISSION_TYPES[$student->admission_type] ?? $student->admission_type }} {{ $student->entry_term ? '· '.$student->entry_term : '' }}</td></tr>
            <tr><th>Yabancı dil</th><td>{{ $student->yds_score ? ($student->yds_exam ?: 'YDS').' '.$student->yds_score : '—' }} @if ($student->prep_completed)<span class="badge ok">hazırlık başarılı</span>@endif</td></tr>
            <tr><th>İletişim</th><td>{{ $student->user?->email }} {{ $student->phone ? '· '.$student->phone : '' }}</td></tr>
        </table>
        <h3>Belgeler</h3>
        <table>
            @forelse ($application->documents as $doc)
                <tr>
                    <td class="small">{{ \App\Models\StudentDocument::TYPES[$doc->type] ?? $doc->type }}{{ $doc->institution ? ' — '.$doc->institution : '' }}</td>
                    <td><a href="{{ route('documents.download', $doc) }}"><x-icon name="download" :size="13" /> {{ $doc->original_name }}</a> <span class="small muted">{{ $doc->size_label }}</span></td>
                </tr>
            @empty
                <tr><td class="muted">Belge yüklenmemiş.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($application->objected_at)
    <div class="card" id="itiraz">
        <h2>İtiraz <span class="small muted">{{ $application->objected_at->format('d.m.Y H:i') }}</span>
            <span class="badge {{ $application->objection_responded_at ? 'ok' : 'bad' }}">{{ $application->objection_responded_at ? 'cevaplandı' : 'cevap bekliyor' }}</span></h2>
        <blockquote style="margin:0 0 10px;padding-left:12px;border-left:3px solid var(--line)">{{ $application->objection_text }}</blockquote>
        <form method="POST" action="{{ route('applications.objection', $application) }}">
            @csrf @method('PUT')
            <label for="resp">Komisyonun cevabı (öğrenci görür)</label>
            <textarea id="resp" name="objection_response" required maxlength="3000" style="width:100%;min-height:70px;font-family:inherit">{{ old('objection_response', $application->objection_response) }}</textarea>
            <p><button class="{{ $application->objection_responded_at ? 'light' : '' }}">Cevabı kaydet</button> @if ($application->objection_responded_at)<span class="small muted">son cevap {{ $application->objection_responded_at->format('d.m.Y') }}</span>@endif</p>
        </form>
        <p class="small muted" style="margin-bottom:0">İtiraz üzerine karar değişecekse değerlendirme dosyasında ilgili satırları güncelleyin; cevabı kaydedince itiraz Başvurular listesinden düşer.</p>
    </div>
@endif

@unless ($application->isPrep())
<div class="card table-wrap">
    <div class="card-head">
        <div><h2>Öğrencinin dersleri ve eşleştirmeleri</h2><div class="sub">{{ $application->transcript->count() }} ders · {{ $application->transcript->where('passed', true)->count() }} başarılı · {{ $requests->count() }} eşleştirilmiş — salt okunur</div></div>
        @if ($isCurrent)<form method="POST" action="{{ route('transcript.reconvert', $student) }}">@csrf<button class="small light" title="Ayarlar'daki not dönüşümü değiştiyse"><x-icon name="refresh" :size="13" /> Notları yeniden çevir</button></form>@endif
    </div>
    <table>
        <tr><th>Kurum / bölüm</th><th>Kod</th><th>Ders</th><th class="num">Kr</th><th class="num">AKTS</th><th>Not</th><th>AÜ harf</th><th>Talep edilen AÜ dersi</th></tr>
        @forelse ($application->transcript as $course)
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
@endunless
@endsection
