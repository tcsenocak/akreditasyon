@extends('layouts.app')
@section('title', 'Muafiyet Başvurularım')
@section('content')
<div class="page-head">
    <div><h1>Muafiyet Başvurularım</h1><div class="sub">Gönderdiğiniz ve karara bağlanan tüm başvurularınız. Ayrıntı, karar ve itiraz için başvuruyu açın.</div></div>
    <a class="btn" href="{{ route('portal.home') }}"><x-icon name="plus" :size="14" /> {{ \App\Models\Student::PORTAL_MODES[$student->portalMode()] }}</a>
</div>
<div class="card table-wrap">
    <table>
        <tr><th>#</th><th>Başvuru türü</th><th>Gönderim</th><th>Durum</th><th>Sonuç</th><th>İtiraz</th><th></th></tr>
        @forelse ($applications as $app)
            @php
                $file = $app->exemptionFile;
                $done = $app->status === 'sonuclandi';
            @endphp
            <tr>
                <td>{{ $applications->count() - $loop->index }}</td>
                <td><a href="{{ route('portal.applications.show', $app) }}"><b>{{ $app->type_label }}</b></a></td>
                <td>{{ $app->submitted_at?->format('d.m.Y') ?? '—' }}</td>
                <td><span class="badge {{ $done ? 'ok' : 'warn' }}">{{ \App\Models\Student::APPLICATION_STATUSES[$app->status] ?? $app->status }}</span>
                    @if ($done && $app->decisionDate())<div class="small muted">{{ $app->decisionDate()->format('d.m.Y') }}</div>@endif</td>
                <td class="small">
                    @if (! $done)
                        <span class="muted">karar bekleniyor</span>
                    @elseif ($app->isPrep())
                        <span class="badge {{ $app->decision === 'kabul' ? 'ok' : 'bad' }}">{{ \App\Models\ExemptionApplication::DECISIONS[$app->decision] ?? '—' }}</span>
                    @elseif ($file)
                        {{ $file->accepted_items_count }} / {{ $file->items_count }} ders kabul · {{ (float) $file->exempt_ects }} AKTS
                    @else
                        —
                    @endif
                </td>
                <td class="small">
                    @if ($app->objected_at)
                        <span class="badge {{ $app->objection_responded_at ? 'ok' : 'warn' }}">{{ $app->objection_responded_at ? 'cevaplandı' : 'değerlendiriliyor' }}</span>
                    @elseif ($app->canObject())
                        <a class="btn small light" href="{{ route('portal.applications.show', $app) }}#itiraz">İtiraz et</a>
                        <div class="muted">son gün {{ $app->objectionDeadline()->format('d.m.Y') }}</div>
                    @elseif ($done)
                        <span class="muted">süre doldu</span>
                    @endif
                </td>
                <td><a class="btn small light" href="{{ route('portal.applications.show', $app) }}">Ayrıntı</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Henüz gönderilmiş bir başvurunuz yok. Başvurunuzu gönderdiğinizde burada görünür.</td></tr>
        @endforelse
    </table>
</div>
@endsection
