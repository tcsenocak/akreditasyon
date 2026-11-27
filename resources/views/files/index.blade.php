@extends('layouts.app')
@section('title', 'Değerlendirme dosyaları')
@section('content')
<div class="page-head">
    <div><h1>Değerlendirme dosyaları</h1><div class="sub">Değerlendirmeye alınan başvuruların dosyaları: öğrenci, tür, bekleyen ve kabul edilen ders sayısı, durum. Bekleyenler en eskiden başlar. Yönetim kurulu için dosyaları işaretleyip toplu karar listesi alın.</div></div>
    <form class="row" method="GET">
        <select name="suz" onchange="this.form.submit()" aria-label="Süz">
            @foreach (['' => 'Tüm dosyalar', 'bekleyen' => 'Kesin kararı verilmemiş', 'bana' => 'Bana atananlar', 'atanmamis' => 'Atanmamış (bekleyen)'] as $k => $v)<option value="{{ $k }}" @selected($filter === $k)>{{ $v }}</option>@endforeach
        </select>
    </form>
</div>
<form method="GET" action="{{ route('files.board') }}" target="_blank" class="card table-wrap" id="board-form">
    <table>
        <tr><th style="width:28px"><input type="checkbox" aria-label="Tümünü seç" onclick="document.querySelectorAll('[name=&quot;ids[]&quot;]').forEach((c) => { c.checked = this.checked; })"></th><th>#</th><th>Öğrenci</th><th>Tür</th><th>Atanan</th><th class="num">Bekleyen</th><th class="num">Kabul</th><th class="num">Muaf AKTS</th><th>Durum</th><th>Bekleme</th></tr>
        @forelse ($files as $file)
            @php
                $since = $file->application?->submitted_at ?? $file->created_at;
                $days = (int) $since->diffInDays(now());
            @endphp
            <tr>
                <td><input type="checkbox" name="ids[]" value="{{ $file->id }}" aria-label="Dosya {{ $file->id }}" @checked($file->status === 'komisyon')></td>
                <td><a href="{{ route('files.show', $file) }}">{{ $file->id }}</a></td>
                <td><a href="{{ route('files.show', $file) }}">{{ $file->student->full_name }}</a> <span class="small muted">{{ $file->student->student_no }}</span><div class="small muted">{{ $file->curriculum->program->full_name }}</div></td>
                <td class="small">{{ $file->application ? (\App\Models\ExemptionApplication::SHORT_TYPES[$file->application->type] ?? '') : '' }}</td>
                <td class="small">{{ $file->assignee?->display_name ?? '—' }}</td>
                <td class="num">{{ $file->pending_count }}</td>
                <td class="num">{{ $file->accepted_items_count }}</td>
                <td class="num">{{ (float) $file->exempt_ects }}</td>
                <td><span class="badge {{ $file->status === 'karar' ? 'ok' : 'warn' }}">{{ \App\Models\ExemptionFile::STATUSES[$file->status] }}</span>
                    @if ($file->decision_no)<div class="small muted">{{ $file->decision_no }} {{ $file->decision_date?->format('d.m.Y') }}</div>@endif</td>
                <td>@if ($file->status === 'karar')<span class="small muted">—</span>@else<span class="badge {{ $days >= 14 ? 'bad' : ($days >= 7 ? 'warn' : '') }}">{{ $days }} gün</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="10" class="muted">Dosya yok. Başvurular sayfasından “Değerlendirmeye al” ile dosya açın.</td></tr>
        @endforelse
    </table>
    @if ($files->isNotEmpty())
        <p class="row" style="margin-top:10px"><button><x-icon name="print" :size="14" /> Seçilenlerden toplu karar listesi</button>
            <span class="small muted">“Komisyonda” durumundaki dosyalar önceden işaretlidir.</span></p>
    @endif
    {{ $files->links() }}
</form>
@endsection
