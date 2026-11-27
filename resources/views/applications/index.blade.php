@extends('layouts.app')
@section('title', 'Başvurular')
@section('content')
<div class="page-head">
    <div><h1>Başvurular</h1><div class="sub">İşlem bekleyen başvurular ve itirazlar, en eskiden başlayarak. Karara bağlanan başvuru listeden düşer; öğrenci itiraz ederse yeniden girer.</div></div>
    <form class="row" method="GET">
        <select name="suz" onchange="this.form.submit()" aria-label="Süz">
            @foreach (['' => 'Tümü ('.array_sum($counts).')', 'yeni' => 'Yeni başvurular ('.$counts['yeni'].')', 'surmekte' => 'Değerlendirmesi süren ('.$counts['surmekte'].')', 'itiraz' => 'İtirazlar ('.$counts['itiraz'].')'] as $k => $v)<option value="{{ $k }}" @selected($filter === $k)>{{ $v }}</option>@endforeach
        </select>
    </form>
</div>
<div class="card table-wrap">
    <table>
        <tr><th>İşlem türü</th><th>Öğrenci</th><th>Başvuru türü</th><th>Tarih</th><th>Bekleme</th><th>İnceleyen</th><th></th></tr>
        @forelse ($applications as $app)
            @php
                $objection = $app->hasOpenObjection();
                $since = $objection ? $app->objected_at : ($app->submitted_at ?? $app->created_at);
                $days = (int) $since->diffInDays(now());
                $file = $app->exemptionFile;
            @endphp
            <tr>
                <td>
                    @if ($objection)<span class="badge bad">İtiraz</span>
                    @elseif ($app->status === 'gonderildi')<span class="badge warn">Yeni başvuru</span>
                    @else<span class="badge">Değerlendiriliyor</span>@endif
                </td>
                <td><a href="{{ route('applications.show', $app) }}"><b>{{ $app->student->full_name }}</b></a> <span class="small muted">{{ $app->student->student_no }}</span><div class="small muted">{{ $app->student->program?->full_name }}</div></td>
                <td class="small">{{ $app->type_label }}</td>
                <td class="small">{{ $since->format('d.m.Y H:i') }}</td>
                <td><span class="badge {{ $days >= 14 ? 'bad' : ($days >= 7 ? 'warn' : '') }}">{{ $days }} gün</span></td>
                <td class="small">{{ $file?->assignee?->display_name ?? '—' }}</td>
                <td>
                    @if ($objection)
                        <a class="btn small" href="{{ route('applications.show', $app) }}#itiraz">İtirazı cevapla</a>
                    @elseif ($file)
                        <a class="btn small light" href="{{ route('files.show', $file) }}">Dosyayı aç</a>
                    @elseif ($app->isPrep())
                        <a class="btn small" href="{{ route('applications.show', $app) }}">Karar ver</a>
                    @else
                        <form method="POST" action="{{ route('files.store', $app->student) }}">@csrf<button class="small"><x-icon name="clipboard" :size="13" /> Değerlendirmeye al</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">İşlem bekleyen başvuru ya da itiraz yok.</td></tr>
        @endforelse
    </table>
    {{ $applications->links() }}
</div>
@endsection
