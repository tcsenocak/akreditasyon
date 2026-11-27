@extends('layouts.app')
@section('title', 'Plan eşleştirme')
@section('content')
<div class="page-head">
    <div><h1>{{ $old->program->full_name }}</h1><div class="sub"><b>{{ $old->start_year ?: $old->title }}</b> planının yerine <b>{{ $new->start_year ?: $new->title }}</b> planı uygulanıyor{{ $new->is_active ? ' (aktif plan)' : '' }} · {{ $old->title }} → {{ $new->title }}@if ($updatedAt) · son güncelleme {{ $updatedAt->format('d.m.Y') }}@endif</div></div>
    <div class="row">
        <a class="btn light" href="{{ route('mappings.index') }}">← Geri</a>
        <form method="POST" action="{{ route('mappings.auto', [$old, $new]) }}">@csrf<button>⟳ Sistem eşleştirmesini çalıştır</button></form>
    </div>
</div>

<div class="row" style="margin-bottom:12px">
    <span class="badge ok">{{ $mappings->where('locked', true)->count() }} kilitli</span>
    <span class="badge brand">{{ $mappings->where('locked', false)->count() }} komisyon</span>
    <span class="badge {{ $unmatchedOld->isEmpty() ? 'ok' : 'bad' }}">{{ $unmatchedOld->count() }} eşleşmeyen eski ders (Md. 6/1-ç)</span>
    <span class="badge">{{ $unmatchedNew->count() }} karşılığı olmayan yeni ders</span>
</div>

<div class="grid grid-2">
    <form method="POST" action="{{ route('mappings.store', [$old, $new]) }}" class="card">
        @csrf
        <h2>Komisyon eşleştirmesi ekle</h2>
        <div class="form-grid">
            <div class="full"><label>Değişiklik türü</label>
                <select name="change_type">@foreach (\App\Models\CourseMapping::CHANGE_TYPES as $k => $v)@if($k !== 'ayni')<option value="{{ $k }}">{{ $v }}</option>@endif @endforeach</select>
            </div>
            <div><label>Eski plan ders(ler)i</label>
                <select name="old_ids[]" multiple size="10" style="width:100%">
                    @foreach ($unmatchedOld as $c)<option value="{{ $c->id }}">{{ $c->semester ? $c->semester.'. ' : '' }}{{ $c->label }} ({{ (float) $c->ects }})</option>@endforeach
                    <optgroup label="— eşleşmiş dersler —">
                        @foreach ($old->courses->where('is_placeholder', false)->diff($unmatchedOld)->sortBy('semester') as $c)<option value="{{ $c->id }}">{{ $c->semester ? $c->semester.'. ' : '' }}{{ $c->label }}</option>@endforeach
                    </optgroup>
                </select>
            </div>
            <div><label>Yeni plan ders(ler)i</label>
                <select name="new_ids[]" multiple size="10" style="width:100%">
                    @foreach ($new->courses->where('category', '!=', 'university_elective')->sortBy('semester') as $c)<option value="{{ $c->id }}">{{ $c->semester ? $c->semester.'. ' : '' }}{{ $c->label }} ({{ (float) $c->ects }})</option>@endforeach
                </select>
            </div>
            <div><label>Yönetim kurulu kararı</label><input name="decision_ref"></div>
            <div><label>Not</label><input name="note"></div>
        </div>
        <p class="small muted">Üniversite seçmeli dersleri eşleştirme hedefi olamaz (Md. 6/1-c). Kaldırılan dersin notu, içerik aranmaksızın seçilen yeni derse aktarılır (Md. 7/2-a).</p>
        <button>Eşleştir</button>
    </form>

    <div class="card">
        <h2>Öğrenciye uygula</h2>
        <form method="GET" action="{{ route('mappings.student', [$old, $new]) }}" class="form-grid">
            <div class="full"><label>Öğrenci (not dökümü eski plan derslerini içermeli)</label>
                <select name="student_id" required>@foreach ($students as $s)<option value="{{ $s->id }}">{{ $s->full_name }} {{ $s->student_no }}</option>@endforeach</select>
            </div>
            <div><label>Öğrencinin bulunduğu yarıyıl</label><input type="number" name="semester" min="1" max="8" value="3"></div>
            <div class="row" style="align-self:end"><input type="checkbox" name="completed" value="1" id="cmp"><label for="cmp" style="margin:0">Eski planın alt yarıyıl derslerini tamamladı (Md. 7/1-c-iii)</label></div>
            <div class="full"><button @disabled($students->isEmpty())>İntibakı hesapla</button></div>
        </form>
        @if ($unmatchedOld->isNotEmpty())
            <h3>Eşleşmeyen eski dersler</h3>
            <ul class="small">@foreach ($unmatchedOld as $c)<li>{{ $c->semester ? $c->semester.'. yy — ' : '' }}{{ $c->label }} ({{ (float) $c->ects }} AKTS, {{ $c->type }})</li>@endforeach</ul>
        @endif
    </div>
</div>

<div class="card table-wrap">
    <h2>Eşleştirmeler</h2>
    <table>
        <tr><th>Eski plan</th><th></th><th>Yeni plan</th><th>Tür</th><th>Karar / not</th><th></th></tr>
        @forelse ($mappings as $m)
            <tr>
                <td>@foreach ($m->oldCourses as $c)<div>{{ $c->semester ? $c->semester.'. ' : '' }}{{ $c->label }} <span class="small muted">{{ $c->type }} · {{ (float) $c->ects }}</span></div>@endforeach</td>
                <td>→</td>
                <td>@foreach ($m->newCourses as $c)<div>{{ $c->semester ? $c->semester.'. ' : '' }}{{ $c->label }} <span class="small muted">{{ $c->type }} · {{ (float) $c->ects }}</span></div>@endforeach</td>
                <td><span class="badge {{ $m->locked ? 'ok' : 'brand' }}">{{ \App\Models\CourseMapping::CHANGE_TYPES[$m->change_type] ?? $m->change_type }}</span> @if($m->locked)<span class="badge">kilitli</span>@endif</td>
                <td class="small">{{ $m->decision_ref }} {{ $m->note }}</td>
                <td>@unless ($m->locked)<form method="POST" action="{{ route('mappings.destroy', $m) }}">@csrf @method('DELETE')<button class="small danger">✕</button></form>@endunless</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Henüz eşleştirme yok. “Sistem eşleştirmesini çalıştır” ile başlayın.</td></tr>
        @endforelse
    </table>
</div>
@endsection
