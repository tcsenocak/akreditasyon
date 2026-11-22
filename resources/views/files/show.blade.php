@extends('layouts.app')
@section('title', 'Değerlendirme — '.$file->student->full_name)
@section('content')
@php
    $counts = $file->items->countBy('status');
    $pending = $counts['oneri'] ?? 0;
@endphp
<div class="page-head">
    <div>
        <h1>Değerlendirme #{{ $file->id }} — <a href="{{ route('students.show', $file->student) }}">{{ $file->student->full_name }}</a></h1>
        <div class="sub">{{ $file->curriculum->label }} · {{ \App\Models\Student::ADMISSION_TYPES[$file->student->admission_type] ?? '' }} · önceki kurum: {{ $file->student->previous_institution ?: '—' }}</div>
    </div>
    <div class="row no-print">
        <a class="btn light" href="{{ route('files.report', $file) }}" target="_blank"><x-icon name="print" :size="14" /> Komisyon raporu</a>
        <a class="btn light" href="{{ route('files.report', [$file, 'tur' => 'ogrenci']) }}" target="_blank"><x-icon name="file" :size="14" /> Öğrenci bildirimi</a>
        <a class="btn light" href="{{ route('files.export', $file) }}"><x-icon name="download" :size="14" /> Excel</a>
    </div>
</div>

<div class="grid grid-5">
    <div class="stat"><span class="stat-icon amber"><x-icon name="clipboard" :size="18" /></span><div>Karar bekleyen<b>{{ $pending }}</b></div></div>
    <div class="stat"><span class="stat-icon green"><x-icon name="check" :size="18" /></span><div>Kabul<b>{{ $counts['kabul'] ?? 0 }}</b></div></div>
    <div class="stat"><span class="stat-icon"><x-icon name="layers" :size="18" /></span><div>Muaf AKTS<b>{{ rtrim(rtrim(number_format($file->exempt_ects, 1, ',', ''), '0'), ',') }}</b></div></div>
    <div class="stat"><span class="stat-icon violet"><x-icon name="calendar" :size="18" /></span><div>İntibak yarıyılı<b>{{ $file->effective_semester }}.</b></div></div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><div><h2>Komisyon işlemleri</h2><div class="sub">⌊{{ (float) $file->exempt_ects }} ÷ {{ config('intibak.ects_per_semester') }}⌋ + 1 = {{ $file->placed_semester }}. yarıyıl (Md. 7/1)</div></div></div>
        <div class="row no-print">
            <form method="POST" action="{{ route('files.bulk', $file) }}">@csrf<input type="hidden" name="action" value="accept_strong"><button class="light" title="Ad benzerliği yüksek ve AKTS/kredi yeterli talepler">Kontrolü geçenleri kabul et</button></form>
            <form method="POST" action="{{ route('files.bulk', $file) }}" onsubmit="return confirm('Bekleyen tüm talepler reddedilsin mi?')">@csrf<input type="hidden" name="action" value="reject_pending"><button class="danger">Bekleyenleri reddet</button></form>
            <form method="POST" action="{{ route('files.generate', $file) }}" onsubmit="return confirm('Öğrencinin güncel eşleştirmeleri yeniden okunacak; komisyonun kabul/ret verdiği satırlar korunur. Devam?')">@csrf<button class="light"><x-icon name="refresh" :size="14" /> Öğrenci taleplerini yeniden oku</button></form>
        </div>
        <p class="small muted">Komisyon öğrenci adına ders ya da eşleştirme girmez. Eksik ya da hatalı talep için öğrenci sayfasından başvuruyu “Hazırlanıyor” yapıp öğrenciye geri gönderin.</p>
        @if ($unusedSources->isNotEmpty())
            <details><summary>Öğrencinin eşleştirmediği başarılı dersler ({{ $unusedSources->count() }})</summary>
                <ul class="small">@foreach ($unusedSources as $s)<li>{{ $s->label }} · {{ $s->credit ?? '–' }} kr / {{ $s->ects ?? '–' }} AKTS · {{ $s->grade_raw }}</li>@endforeach</ul>
            </details>
        @endif
        <h3>Öğrencinin belgeleri</h3>
        <div class="row">
            @forelse ($file->student->documents as $doc)
                <a class="btn light small" href="{{ route('documents.download', $doc) }}"><x-icon name="download" :size="13" /> {{ \App\Models\StudentDocument::TYPES[$doc->type] ?? $doc->type }}</a>
            @empty
                <span class="small muted">Belge yok.</span>
            @endforelse
        </div>
    </div>

    <form method="POST" action="{{ route('files.update', $file) }}" class="card no-print">
        @csrf @method('PUT')
        <div class="card-head"><div><h2>Yönetim kurulu kararı</h2><div class="sub">Karar verilince kabul edilen eşleştirmeler havuza yazılır, öğrenci sonucu panelinde görür</div></div></div>
        <div class="form-grid">
            <div><label>Durum</label><select name="status">@foreach (\App\Models\ExemptionFile::STATUSES as $k => $v)<option value="{{ $k }}" @selected($file->status === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label>Karar no</label><input name="decision_no" value="{{ $file->decision_no }}"></div>
            <div><label>Karar tarihi</label><input type="date" name="decision_date" value="{{ $file->decision_date?->format('Y-m-d') }}"></div>
            <div><label>Yarıyıl (komisyon belirlerse)</label><input type="number" name="placed_semester_override" min="1" max="8" value="{{ $file->placed_semester_override }}" placeholder="{{ $file->placed_semester }}"></div>
            @foreach ($examCourses as $course)
                <div><label>Muafiyet sınavı: {{ $course->label }}</label><input type="number" step="0.01" min="0" max="100" name="exam_scores[{{ $course->id }}]" value="{{ $file->options['exam_scores'][$course->id] ?? '' }}" placeholder="0-100"></div>
            @endforeach
            <div class="full"><label>Komisyon notu</label><textarea name="commission_note" style="min-height:60px;font-family:inherit">{{ $file->commission_note }}</textarea></div>
        </div>
        <p style="margin:12px 0 0"><button>Kaydet</button></p>
    </form>
</div>

<div class="card table-wrap">
    <div class="card-head"><div><h2>Talepler ve değerlendirme</h2><div class="sub">Öğrencinin eşleştirmeleri öğretim planı sırasıyla; kontroller sistem tarafından eklenir, karar komisyonundur</div></div></div>
    @foreach ($plan as $semester => $courses)
        @php($semItems = $courses->map(fn ($c) => $itemsByCourse->get($c->id))->filter())
        @continue($semItems->isEmpty())
        <div class="semester">
            <h3><span>{{ $semester }}. yarıyıl</span><span class="small muted">muaf {{ $courses->filter(fn ($c) => optional($itemsByCourse->get($c->id))->status === 'kabul')->sum('ects') }} / {{ $courses->sum('ects') }} AKTS</span></h3>
            <table>
                <tr><th style="width:24%">AÜ dersi</th><th style="width:26%">Öğrencinin dersi</th><th>Sistem kontrolü</th><th style="width:250px">Komisyon kararı</th></tr>
                @foreach ($semItems as $item)
                    @include('files._item', ['item' => $item])
                @endforeach
            </table>
        </div>
    @endforeach
    @if ($extraItems->isNotEmpty())
        <h3>Seçmeli havuzlarından talepler</h3>
        <table>
            <tr><th style="width:24%">AÜ dersi</th><th style="width:26%">Öğrencinin dersi</th><th>Sistem kontrolü</th><th style="width:250px">Komisyon kararı</th></tr>
            @foreach ($extraItems as $item)
                @include('files._item', ['item' => $item])
            @endforeach
        </table>
    @endif
    @if ($file->items->isEmpty())
        <p class="muted">Öğrencinin eşleştirmesi yok.</p>
    @endif
</div>
<form method="POST" action="{{ route('files.destroy', $file) }}" onsubmit="return confirm('Dosya silinsin mi?')" class="no-print">@csrf @method('DELETE')<button class="danger small">Dosyayı sil</button></form>
@endsection
