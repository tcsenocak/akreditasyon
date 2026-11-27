@extends('layouts.app')
@section('title', $application->type_label)
@section('content')
@php
    $done = $application->status === 'sonuclandi';
@endphp
<div class="page-head">
    <div><h1>{{ $application->type_label }}</h1>
        <div class="sub">Gönderim {{ $application->submitted_at?->format('d.m.Y H:i') ?? '—' }}
            · <span class="badge {{ $done ? 'ok' : 'warn' }}">{{ \App\Models\Student::APPLICATION_STATUSES[$application->status] ?? $application->status }}</span>
            @if ($done && $application->decisionDate()) · karar {{ $application->decisionDate()->format('d.m.Y') }}@endif</div>
    </div>
    <a class="btn light" href="{{ route('portal.applications.index') }}">← Muafiyet Başvurularım</a>
</div>

@if ($application->isPrep())
    <div class="card">
        <h2>Hazırlık sınıfı muafiyeti</h2>
        <p>{{ $application->language_exam }} {{ $application->language_score }}{{ $application->language_exam_date ? ' · sınav tarihi '.$application->language_exam_date->format('d.m.Y') : '' }}</p>
        @if ($application->decision)
            <p><span class="badge {{ $application->decision === 'kabul' ? 'ok' : 'bad' }}">{{ \App\Models\ExemptionApplication::DECISIONS[$application->decision] }}</span>
                {{ $application->decided_at ? ' · '.$application->decided_at->format('d.m.Y') : '' }}</p>
            @if ($application->decision_note)<p>{{ $application->decision_note }}</p>@endif
        @else
            <p class="small muted">Komisyon kararı bekleniyor.</p>
        @endif
    </div>
@endif

@if ($decided)
    <div class="card table-wrap">
        <h2>Muafiyet ve intibak sonucu</h2>
        <p>Muaf olunan toplam <b>{{ (float) $decided->exempt_ects }} AKTS</b> · intibak edildiğiniz yarıyıl: <b>{{ $decided->effective_semester }}. yarıyıl</b>
            @if ($decided->decision_no)· Yönetim kurulu kararı: {{ $decided->decision_date?->format('d.m.Y') }} / {{ $decided->decision_no }}@endif</p>
        <table>
            <tr><th>Aldığınız ders(ler)</th><th>Muaf olduğunuz AÜ dersi</th><th class="num">Yarıyıl</th><th class="num">AKTS</th><th>Harf notu</th></tr>
            @forelse ($decided->acceptedItems->sortBy(fn ($i) => $i->course->semester) as $item)
                <tr>
                    <td>{{ $item->sources->map(fn ($s) => $s->label)->implode(' + ') ?: (\App\Models\ExemptionItem::RULES[$item->rule] ?? '') }}</td>
                    <td>{{ $item->course->label }}</td>
                    <td class="num">{{ $item->course->semester }}</td>
                    <td class="num">{{ (float) $item->course->ects }}</td>
                    <td><b>{{ $item->letter }}</b></td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Kabul edilen ders yok.</td></tr>
            @endforelse
        </table>
        @php
            $rejected = $decided->items->where('status', 'ret');
        @endphp
        @if ($rejected->isNotEmpty())
            <h3 style="margin-top:14px">Kabul edilmeyen talepler</h3>
            <ul>@foreach ($rejected as $item)<li>{{ $item->course?->label }}@if ($item->reject_reason) — <span class="muted">{{ \App\Models\ExemptionItem::REJECT_REASONS[$item->reject_reason] ?? '' }}</span>@endif</li>@endforeach</ul>
        @endif
        <p class="small muted">İntibak edilen öğrenci önce alt yarıyıllardan muaf olmadığı derslere kayıt yaptırır (Md. 7/2).</p>
    </div>
@elseif ($requests->isNotEmpty())
    <div class="card table-wrap">
        <h2>Muafiyet taleplerim <span class="small muted">(komisyon kararı bekleniyor)</span></h2>
        <table>
            <tr><th>Aldığım ders</th><th>Muaf olmak istediğim AÜ dersi</th><th class="num">AKTS</th><th>Not</th></tr>
            @foreach ($requests->sortBy(fn ($c) => $c->requestedCourse?->semester) as $c)
                <tr><td>{{ $c->label }} <span class="small muted">{{ $c->institution }}</span></td><td>{{ $c->requestedCourse?->label }}</td><td class="num">{{ (float) $c->ects }}</td><td>{{ $c->grade_raw }}</td></tr>
            @endforeach
        </table>
    </div>
@endif

@if ($summerRows->isNotEmpty())
    <div class="card">
        <h2>Yaz okulu notlarım</h2>
        <p class="small muted">Yaz okulunu tamamladıktan sonra onaylanan derslerden aldığınız notları yazın ve yaz okulu transkriptinizi yükleyin. Komisyon harf notlarını kesinleştirir.
            @if ($application->summer_grades_at)<br><b>{{ $application->summer_grades_at->format('d.m.Y') }} tarihinde gönderdiniz; gerekirse düzeltip yeniden gönderebilirsiniz.</b>@endif</p>
        <form method="POST" action="{{ route('portal.applications.summer-grades', $application) }}" enctype="multipart/form-data">
            @csrf
            <table>
                <tr><th>Yaz okulunda aldığım ders</th><th>AÜ karşılığı</th><th style="width:120px">Notum</th></tr>
                @foreach ($summerRows as $row)
                    <tr><td>{{ $row->label }} <span class="small muted">{{ $row->institution }}</span></td><td>{{ $row->requestedCourse?->label }}</td>
                        <td><input name="grades[{{ $row->id }}]" value="{{ old('grades.'.$row->id, $row->grade_raw) }}" maxlength="16" placeholder="AA, 85…" aria-label="{{ $row->name }} notu"></td></tr>
                @endforeach
            </table>
            <p class="row" style="margin-top:10px"><label class="small" style="margin:0">Yaz okulu transkripti <input type="file" name="transkript" accept=".pdf,.jpg,.jpeg,.png" @unless ($application->summer_grades_at) required @endunless></label>
                <button>Notlarımı gönder</button></p>
        </form>
    </div>
@endif

@if ($application->documents->isNotEmpty())
    <div class="card table-wrap">
        <h2>Yüklediğim belgeler</h2>
        <table>
            @foreach ($application->documents as $doc)
                <tr><td class="small" style="width:40%">{{ \App\Models\StudentDocument::TYPES[$doc->type] ?? $doc->type }}{{ $doc->institution ? ' — '.$doc->institution : '' }}</td>
                    <td><a href="{{ route('documents.download', $doc) }}"><x-icon name="download" :size="13" /> {{ $doc->original_name }}</a></td></tr>
            @endforeach
        </table>
    </div>
@endif

@if ($done)
    <div class="card" id="itiraz">
        <h2>Karara itiraz</h2>
        @if ($application->objected_at)
            <p class="small muted">{{ $application->objected_at->format('d.m.Y H:i') }} tarihinde itiraz ettiniz:</p>
            <blockquote style="margin:0 0 10px;padding-left:12px;border-left:3px solid var(--line)">{{ $application->objection_text }}</blockquote>
            @if ($application->objection_response)
                <p><b>Komisyonun cevabı</b> ({{ $application->objection_responded_at?->format('d.m.Y') }}): {{ $application->objection_response }}</p>
            @else
                <p class="small">İtirazınız komisyonda değerlendiriliyor.</p>
            @endif
        @elseif ($application->canObject())
            <p>Bu karara itiraz için son gün: <b>{{ $application->objectionDeadline()->format('d.m.Y') }}</b> (karardan sonra {{ config('intibak.objection_business_days') }} iş günü, Uygulama Esasları Md. 5/9). İtirazınızı gerekçesiyle yazın; hangi ders(ler) için itiraz ettiğinizi belirtin.</p>
            <form method="POST" action="{{ route('portal.applications.objection', $application) }}">
                @csrf
                <textarea name="objection_text" required minlength="20" maxlength="3000" style="width:100%;min-height:110px;font-family:inherit" aria-label="İtiraz gerekçesi">{{ old('objection_text') }}</textarea>
                <p><button onclick="return confirm('İtirazınız komisyona iletilecek. Bir başvuruya bir kez itiraz edilebilir. Devam edilsin mi?')">İtirazımı gönder</button></p>
            </form>
        @elseif ($application->objectionDeadline())
            <p class="small muted">İtiraz süresi {{ $application->objectionDeadline()->format('d.m.Y') }} tarihinde doldu.</p>
        @endif
    </div>
@endif
@endsection
