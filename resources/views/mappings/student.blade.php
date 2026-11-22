@extends('layouts.app')
@section('title', 'Plan intibakı — '.$student->full_name)
@section('content')
<div class="page-head">
    <div><h1>{{ $student->full_name }} — öğretim planı intibakı</h1><div class="sub">{{ $old->title }} → {{ $new->title }}</div></div>
    <div class="row no-print"><button onclick="window.print()">Yazdır</button><a class="btn light" href="{{ route('mappings.show', [$old, $new]) }}">← Eşleştirmeler</a></div>
</div>

@foreach ($result['warnings'] as $w)<div class="alert bad">{{ $w }}</div>@endforeach

<div class="grid grid-5" style="margin-bottom:16px">
    <div class="stat"><b>{{ $result['old']['ects'] }}</b>Eski plan AKTS</div>
    <div class="stat"><b>{{ $result['old']['agno'] !== null ? number_format($result['old']['agno'], 2, ',', '') : '—' }}</b>Eski AGNO</div>
    <div class="stat"><b>{{ $result['new']['ects'] }}</b>Yeni plan AKTS</div>
    <div class="stat"><b>{{ $result['new']['agno'] !== null ? number_format($result['new']['agno'], 2, ',', '') : '—' }}</b>Yeni AGNO</div>
    <div class="stat"><b>{{ collect($result['balancing'])->sum('ects') }}</b>Eşitleme AKTS</div>
</div>

<div class="card table-wrap">
    <h2>Yeni plana aktarılan dersler</h2>
    <table>
        <tr><th class="num">Yy</th><th>Yeni ders</th><th class="num">AKTS</th><th>Harf</th><th>Durum</th><th>Eski ders(ler)</th><th>Kural</th></tr>
        @foreach (collect($result['rows'])->sortBy(fn ($r) => $r['course']->semester) as $r)
            <tr>
                <td class="num">{{ $r['course']->semester }}</td>
                <td>{{ $r['course']->label }}</td>
                <td class="num">{{ $r['ects'] }}</td>
                <td><b>{{ $r['letter'] }}</b></td>
                <td><span class="badge {{ $r['passed'] ? 'ok' : 'bad' }}">{{ $r['passed'] ? 'başarılı' : 'tekrar alacak' }}</span></td>
                <td class="small">{{ $r['from'] }}</td>
                <td class="small muted">{{ $r['rule'] }}</td>
            </tr>
        @endforeach
    </table>
</div>

@if ($result['balancing'])
    <div class="card">
        <h2>AKTS/AGNO eşitleme dersleri (Md. 7/1-c-ii)</h2>
        <table>
            <tr><th>Ders</th><th class="num">Yarıyıl</th><th class="num">AKTS</th><th>Harf</th><th>Açıklama</th></tr>
            @foreach ($result['balancing'] as $b)
                <tr><td>{{ $b['name'] }}</td><td class="num">{{ $b['semester'] }}</td><td class="num">{{ $b['ects'] }}</td><td><b>{{ $b['letter'] }}</b></td><td class="small muted">{{ $b['note'] }}</td></tr>
            @endforeach
        </table>
        <p class="small muted">Harf notu, eski AGNO'yu korumaya en yakın katsayıya göre önerilmiştir; kesin işlem birim yönetim kurulu kararıyla yapılır (Md. 6/1-d).</p>
    </div>
@endif
@endsection
