@extends('layouts.app')
@section('title', 'Ayarlar')
@section('content')
<div class="page-head"><div><h1>Ayarlar</h1></div></div>
<div class="card" id="basvuru-takvimi">
    <div class="card-head"><div><h2>Başvuru alımı</h2><div class="sub">Öğrenciler başvurularını her zaman hazırlayıp kaydedebilir; <b>gönderme</b> ve <b>yeni başvuru açma</b> yalnız alım açıkken yapılabilir.</div></div></div>
    <form method="POST" action="{{ route('settings.window') }}" class="row" style="margin-bottom:14px">
        @csrf @method('PUT')
        @foreach (\App\Services\ApplicationWindow::MODES as $k => $v)
            <label class="row" style="gap:6px;margin:0"><input type="radio" name="application_mode" value="{{ $k }}" @checked($mode === $k)> {{ $v }}</label>
        @endforeach
        <button class="light">Kaydet</button>
    </form>
    <h3>Başvuru takvimi</h3>
    <p class="small muted">Örnek: dönem başlamadan iki hafta önce açılan dönem başı başvurusu, yaz okulundan önce açılan yaz okulu başvurusu. Yalnız “takvimdeki tarihlerde” seçiliyken uygulanır.</p>
    <form method="POST" action="{{ route('settings.periods.store') }}" class="row" style="margin-bottom:12px">
        @csrf
        <input name="name" placeholder="ör. 2027-2028 Güz dönem başı" required maxlength="80">
        <select name="type">@foreach (\App\Models\ExemptionApplication::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
        <label class="small" style="margin:0">Başlangıç <input type="date" name="starts_on" required></label>
        <label class="small" style="margin:0">Bitiş <input type="date" name="ends_on" required></label>
        <button>Ekle</button>
    </form>
    <table>
        <tr><th>Dönem</th><th>Tür</th><th>Tarihler</th><th>Durum</th><th></th></tr>
        @forelse ($periods as $period)
            <tr>
                <td>{{ $period->name }}</td>
                <td>{{ \App\Models\ExemptionApplication::SHORT_TYPES[$period->type] ?? $period->type }}</td>
                <td>{{ $period->starts_on->format('d.m.Y') }} – {{ $period->ends_on->format('d.m.Y') }}</td>
                <td>@if ($period->isCurrent())<span class="badge ok">açık</span>@elseif ($period->starts_on->isFuture())<span class="badge warn">yaklaşan</span>@else<span class="badge">geçti</span>@endif</td>
                <td><form method="POST" action="{{ route('settings.periods.destroy', $period) }}" onsubmit="return confirm('Dönem silinsin mi?')">@csrf @method('DELETE')<button class="small danger" aria-label="Dönemi sil">✕</button></form></td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Takvim boş.</td></tr>
        @endforelse
    </table>
</div>
<div class="grid grid-2">
    <div class="card">
        <h2>Not dönüşüm tablosu</h2>
        <p class="small muted">Diğer kurumların farklı not sistemleri için AÜ harf notu karşılığı (Muafiyet Esasları Md. 6/2). Burada tanımlanan notlar yerleşik kuralların önüne geçer.</p>
        <form method="POST" action="{{ route('settings.grades.store') }}" class="row" style="margin-bottom:12px">
            @csrf
            <input name="source_grade" placeholder="Kaynak not (örn. B1, 85-89, A-)" required>
            <select name="letter">@foreach (array_keys(config('intibak.letters')) as $l)<option>{{ $l }}</option>@endforeach</select>
            <input name="note" placeholder="Açıklama / kurum">
            <button>Ekle</button>
        </form>
        <table>
            <tr><th>Kaynak</th><th>AÜ harf</th><th>Not</th><th></th></tr>
            @forelse ($conversions as $c)
                <tr><td>{{ $c->source_grade }}</td><td><b>{{ $c->letter }}</b></td><td class="small">{{ $c->note }}</td><td><form method="POST" action="{{ route('settings.grades.destroy', $c) }}">@csrf @method('DELETE')<button class="small danger">✕</button></form></td></tr>
            @empty
                <tr><td colspan="4" class="muted">Özel dönüşüm yok.</td></tr>
            @endforelse
        </table>
    </div>
    <div class="card">
        <h2>Yerleşik kurallar</h2>
        <table>
            <tr><th>Yarıyıl başına AKTS (Md. 7/1)</th><td>{{ config('intibak.ects_per_semester') }}</td></tr>
            <tr><th>YDS eşiği (Md. 5/8)</th><td>{{ config('intibak.yds_min_score') }}</td></tr>
            <tr><th>Mesleki yabancı dil en fazla</th><td>{{ config('intibak.professional_language_max_courses') }} ders</td></tr>
            <tr><th>Muafiyet sınavı geçme puanı (Md. 6/6)</th><td>{{ config('intibak.exam_pass_score') }}</td></tr>
            <tr><th>Yaz okulunda en fazla</th><td>{{ config('intibak.summer_school_max_ects') }} AKTS</td></tr>
            <tr><th>DC/DD koşullu notlar kabul</th><td>{{ config('intibak.accept_conditional_pass') ? 'Evet' : 'Hayır' }}</td></tr>
            <tr><th>Güçlü eşleşme eşiği</th><td>%{{ config('intibak.match_strong') * 100 }}</td></tr>
            <tr><th>Olası eşleşme eşiği</th><td>%{{ config('intibak.match_possible') * 100 }}</td></tr>
            <tr><th>100'lük → harf</th><td class="small">@foreach (config('intibak.score_table') as $r){{ $r['letter'] }} ≥ {{ $r['min'] }}@if(! $loop->last), @endif @endforeach</td></tr>
            <tr><th>G/M/Geçer</th><td>CC (Md. 6/3)</td></tr>
        </table>
        <p class="small muted">Bu değerler <code>config/intibak.php</code> dosyasından değiştirilir.</p>
    </div>
</div>
@endsection
