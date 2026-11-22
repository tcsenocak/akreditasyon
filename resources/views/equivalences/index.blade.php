@extends('layouts.app')
@section('title', 'Eşdeğerlik havuzu')
@section('content')
<div class="page-head">
    <div><h1>Eşdeğerlik havuzu</h1><div class="sub">Yönetim kurulu kararıyla kesinleşen ders eşleştirmeleri. Aynı kurumdan/aynı adla gelen derslerde otomatik önerilir.</div></div>
</div>

<form class="card row" method="GET" style="flex-wrap:wrap">
    <label for="f-kurum" style="margin:0">Üniversite</label>
    <select id="f-kurum" name="kurum" style="max-width:340px">
        <option value="" @selected($institution === '')>Tüm üniversiteler</option>
        @foreach ($universities as $u)<option value="{{ $u }}" @selected($institution === $u)>{{ $u }}</option>@endforeach
    </select>
    <select name="program"><option value="">Tüm programlar</option>@foreach ($programs as $p)<option value="{{ $p->id }}" @selected((string) $programId === (string) $p->id)>{{ $p->full_name }}</option>@endforeach</select>
    <input name="q" value="{{ $search }}" placeholder="Ders ara"><button class="light"><x-icon name="search" :size="14" /> Süz</button>
</form>

<details class="card" @if ($errors->any()) open @endif>
    <summary><b>Havuza kayıt ekle</b> <span class="small muted">— önceki yönetim kurulu kararlarını elle girmek için</span></summary>
    <form method="POST" action="{{ route('equivalences.store') }}" class="form-grid" style="margin-top:12px">
        @csrf
        <label>Üniversite
            <select name="institution" required>
                @foreach ($universities as $u)<option value="{{ $u }}" @selected(old('institution', config('universiteler.default')) === $u)>{{ $u }}</option>@endforeach
            </select>
        </label>
        <label>Dış ders kodu <input name="ext_code" value="{{ old('ext_code') }}" maxlength="32"></label>
        <label>Dış ders adı <input name="ext_name" value="{{ old('ext_name') }}" required maxlength="255"></label>
        <label>Dış ders AKTS <input name="ext_ects" type="number" step="0.5" min="0" max="60" value="{{ old('ext_ects') }}"></label>
        <label>Karşılığı olan AÜ dersi (aktif plan)
            <select name="target_course_id" required>
                <option value="">Seçin…</option>
                @foreach ($programs as $p)
                    @if ($p->activeCurriculum)
                        <optgroup label="{{ $p->full_name }}">
                            @foreach ($p->activeCurriculum->courses as $c)<option value="{{ $c->id }}" @selected((string) old('target_course_id') === (string) $c->id)>{{ $c->semester ? $c->semester.'. yy · ' : '' }}{{ $c->label }} ({{ (float) $c->ects }} AKTS)</option>@endforeach
                        </optgroup>
                    @endif
                @endforeach
            </select>
        </label>
        <label>Karar (tarih/sayı) <input name="decision_ref" value="{{ old('decision_ref') }}" maxlength="255"></label>
        <div><button><x-icon name="plus" :size="14" /> Ekle</button></div>
    </form>
</details>

<div class="card table-wrap">
    <table>
        <tr><th>Kurum</th><th>Dış ders</th><th class="num">AKTS</th><th>→ AÜ dersi</th><th>Program</th><th class="num">Kullanım</th><th>Karar</th><th></th></tr>
        @forelse ($equivalences as $e)
            <tr>
                <td>{{ $e->institution ?: '—' }}</td>
                <td>{{ trim($e->ext_code.' '.$e->ext_name) }}</td>
                <td class="num">{{ $e->ext_ects }}</td>
                <td><b>{{ trim($e->target_code.' '.$e->target_name) }}</b></td>
                <td class="small">{{ $e->program?->full_name }}</td>
                <td class="num">{{ $e->times_used }}</td>
                <td class="small">{{ $e->decision_ref }}</td>
                <td><form method="POST" action="{{ route('equivalences.destroy', $e) }}" onsubmit="return confirm('Kayıt silinsin mi?')">@csrf @method('DELETE')<button class="small danger">✕</button></form></td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">{{ $institution !== '' ? $institution.' için havuzda kayıt yok.' : 'Havuz boş.' }} Muafiyet dosyaları “Yönetim kurulu kararı” durumuna alındıkça dolar.</td></tr>
        @endforelse
    </table>
    {{ $equivalences->links() }}
</div>
@endsection
