@extends('layouts.app')
@section('title', 'Eşleştirmelerim')
@section('content')
@php
    $editable = $student->isEditableByStudent();
    $targetIds = $targets->pluck('id')->map(fn ($id) => (int) $id)->all();
@endphp
<div class="page-head">
    <div><h1>Eşleştirmelerim</h1><div class="sub">Öğretim planınızdan muaf olmak istediğiniz AÜ dersinin yanındaki <b>+</b> düğmesine basın; ders aşağıya kart olarak iner. Kartta, karşılığı olan kendi derslerinize tıklayın (Uygulama Esasları Md. 5/2). Karar komisyonundur.</div></div>
    @if ($editable)
        <form method="POST" action="{{ route('portal.matches.auto') }}">@csrf<button class="light">⟳ Otomatik eşleştirme öner</button></form>
    @endif
</div>
@include('portal._status')

@if (! $student->curriculum)
    <div class="alert warn">AÜ programınız henüz belirlenmemiş. Komisyon programınızı tanımlayınca öğretim planınız burada görünecek.</div>
@else
<div class="card obs-panel">
    <div class="card-head"><div><h2>Öğretim planınız</h2><div class="sub">{{ $student->curriculum->program?->full_name }} · {{ $student->curriculum->title }}</div></div></div>
    <div class="obs-grid">
        @foreach ($table['semesters'] as $semester => $s)
            <section class="obs-sem">
                <table>
                    <thead>
                        <tr class="obs-sem-head"><th colspan="5">{{ $semester }}. Yarıyıl</th></tr>
                        <tr><th style="width:34px"></th><th style="width:90px">D. KOD.</th><th>DERSİN ADI</th><th class="num">KR</th><th class="num">AKTS</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($s['rows'] as $course)
                            @php
                                $elective = \App\Services\CurriculumTable::isElective($course);
                            @endphp
                            <tr class="obs-row" data-plan-row="{{ $course->id }}">
                                <td>
                                    @if ($editable && in_array((int) $course->id, $targetIds, true))
                                        <button type="button" class="mx-add" data-add="{{ $course->id }}" title="Eşleştirmeye ekle" aria-label="{{ $course->name }} dersini eşleştirmeye ekle">+</button>
                                    @else
                                        <span @class(['obs-type', 'sec' => $elective])>{{ $elective ? 'S' : 'Z' }}</span>
                                    @endif
                                </td>
                                <td class="mono small">{{ $course->code ?: '—' }}</td>
                                <td>{{ $course->name }} <span class="badge ok mx-done" data-done="{{ $course->id }}" hidden>eşleşti</span></td>
                                <td class="num">{{ (float) $course->credit ?: '—' }}</td>
                                <td class="num">{{ number_format((float) $course->ects, 1) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endforeach
    </div>
</div>

<form method="POST" action="{{ route('portal.matches.save') }}" class="card" id="mx-form">
    @csrf
    <div class="card-head">
        <div><h2>Eşleştirme kartlarım</h2><div class="sub">Her kart bir AÜ dersidir. Karşılığı olan derslerinize tıklayın; birden çok ders seçebilirsiniz (ör. Matematik I + II → Matematik I). Önce AKTS'ye, yetmezse krediye bakılır.</div></div>
        @if ($editable)<button id="mx-save">Eşleştirmeleri kaydet</button>@endif
    </div>
    <div id="mx-cards" class="mx-cards"></div>
    <p id="mx-empty" class="muted small" style="margin:0">Henüz eşleştirme yok. Yukarıdaki öğretim planından bir dersin yanındaki <b>+</b> düğmesine basın.</p>
    <div id="mx-inputs"></div>
    <div class="mx-free">
        <h3>Eşleştirilmemiş derslerim</h3>
        <div id="mx-free-list" class="mx-chips"></div>
    </div>
</form>
@endif

<div class="card table-wrap">
    <h2>Özet ve ön kontrol <span class="small muted">(kaydedilmiş eşleştirmeler)</span></h2>
    <p>Ön kontrolü geçen eşleştirmeler: <b>{{ $estimatedEcts }} AKTS</b> → tahmini intibak: <b>{{ $estimatedSemester }}. yarıyıl</b> <span class="small muted">(kesin karar komisyonundur, Md. 7/1)</span></p>
    <table>
        <tr><th>AÜ dersi</th><th>Derslerim</th><th>Ön kontrol</th></tr>
        @forelse ($groups as $g)
            <tr>
                <td>{{ $g['target']->semester ? $g['target']->semester.'. yy · ' : '' }}{{ $g['target']->label }}<div class="small muted">{{ (float) $g['target']->credit }} kr / {{ (float) $g['target']->ects }} AKTS</div></td>
                <td class="small">{{ $g['sources']->map(fn ($s) => $s->name)->implode(' + ') }}<div class="muted">{{ $g['load']['credit'] }} kr / {{ $g['load']['ects'] }} AKTS</div></td>
                <td>
                    @if ($g['common'])<span class="badge ok">ortak zorunlu</span>
                    @elseif ($g['ok'] && $g['load']['ects_enough'])<span class="badge ok">AKTS yeterli</span>
                    @elseif ($g['ok'])<span class="badge ok">kredi yeterli</span>
                    @else<span class="badge bad">AKTS ve kredi yetersiz</span>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">Henüz kaydedilmiş eşleştirme yok.</td></tr>
        @endforelse
    </table>
    @if ($student->prep_completed || $student->yds_score >= config('intibak.yds_min_score'))
        <p class="small"><span class="badge brand">dil</span> Beyanınıza göre ortak zorunlu yabancı dil dersleri için muafiyet talebi otomatik eklenir; belgenizi yüklemeyi unutmayın.</p>
    @endif
</div>

@if ($student->curriculum)
@php
    $mxTargets = $targets->mapWithKeys(fn ($t) => [$t->id => [
        'code' => $t->code, 'name' => $t->name, 'credit' => (float) $t->credit, 'ects' => (float) $t->ects,
        'semester' => $t->semester, 'common' => in_array($t->common_group, ['AIIT', 'TD', 'YD', 'ISG'], true),
    ]]);
    $mxSources = $student->transcript->map(fn ($c) => [
        'id' => $c->id, 'label' => $c->label, 'credit' => (float) $c->credit, 'ects' => (float) $c->ects,
        'letter' => $c->letter, 'passed' => (bool) $c->passed,
    ])->values();
    $mxMatches = (object) $student->transcript->filter(fn ($c) => $c->requested_course_id)->mapWithKeys(fn ($c) => [$c->id => $c->requested_course_id])->all();
@endphp
<script>
(function () {
    const editable = @json($editable);
    const targets = @json($mxTargets);
    const sources = @json($mxSources);
    const matches = @json($mxMatches);
    const order = Object.values(matches).filter((v, i, a) => a.indexOf(v) === i).map(Number);
    const cards = document.getElementById('mx-cards');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const num = (n) => String(Math.round(Number(n || 0) * 10) / 10).replace('.', ',');
    let dirty = false;

    function render() {
        order.sort((a, b) => ((targets[a]?.semester ?? 99) - (targets[b]?.semester ?? 99)) || String(targets[a]?.code).localeCompare(String(targets[b]?.code), 'tr'));
        cards.innerHTML = order.map(card).join('');
        document.getElementById('mx-empty').hidden = order.length > 0;
        const free = sources.filter((s) => !matches[s.id]);
        document.getElementById('mx-free-list').innerHTML = free.length
            ? free.map((s) => '<span class="mx-chip' + (s.passed ? '' : ' off') + '">' + esc(s.label) + ' <small>' + num(s.ects) + ' AKTS · ' + esc(s.letter || '?') + '</small></span>').join('')
            : '<span class="small muted">Tüm başarılı dersleriniz eşleştirildi.</span>';
        document.querySelectorAll('[data-done]').forEach((b) => { b.hidden = !order.includes(Number(b.dataset.done)); });
        document.querySelectorAll('[data-add]').forEach((b) => { b.classList.toggle('on', order.includes(Number(b.dataset.add))); });
        document.getElementById('mx-inputs').innerHTML = sources.map((s) => '<input type="hidden" name="matches[' + s.id + ']" value="' + (matches[s.id] || '') + '">').join('');
    }

    function card(tid) {
        const t = targets[tid];
        if (!t) { return ''; }
        const mine = sources.filter((s) => Number(matches[s.id]) === tid);
        const ects = mine.reduce((a, s) => a + s.ects, 0);
        const credit = mine.reduce((a, s) => a + s.credit, 0);
        const ok = mine.length && (t.common || ects >= t.ects || credit >= t.credit);
        const status = !mine.length ? '<span class="badge warn">ders seçin</span>'
            : t.common ? '<span class="badge ok">ortak zorunlu</span>'
            : ok ? '<span class="badge ok">' + (ects >= t.ects ? 'AKTS yeterli' : 'kredi yeterli') + '</span>'
            : '<span class="badge bad">AKTS ve kredi yetersiz</span>';
        const chips = sources.map((s) => {
            const here = Number(matches[s.id]) === tid;
            const other = matches[s.id] && !here ? targets[matches[s.id]] : null;
            const cls = 'mx-chip' + (here ? ' on' : '') + (other ? ' taken' : '') + (s.passed ? '' : ' off');
            const title = !s.passed ? 'Başarısız ders muafiyete esas alınmaz' : other ? 'Şu an ' + (other.code || other.name) + ' kartında; tıklarsanız buraya taşınır' : '';
            return '<button type="button" class="' + cls + '" data-src="' + s.id + '" data-tid="' + tid + '"' + (s.passed && editable ? '' : ' disabled') + ' title="' + esc(title) + '">' +
                (here ? '✓ ' : '') + esc(s.label) + ' <small>' + num(s.ects) + ' AKTS · ' + num(s.credit) + ' kr · ' + esc(s.letter || '?') + (other ? ' · → ' + esc(other.code || other.name) : '') + '</small></button>';
        }).join('');

        return '<div class="mx-card" id="mx-card-' + tid + '">' +
            '<div class="mx-card-head"><div><span class="mono small">' + esc(t.code || '') + '</span> <b>' + esc(t.name) + '</b>' +
            '<div class="small muted">' + (t.semester ? t.semester + '. yarıyıl · ' : '') + num(t.credit) + ' kredi · ' + num(t.ects) + ' AKTS</div></div>' +
            '<div class="row">' + status + (editable ? '<button type="button" class="small light" data-remove="' + tid + '" title="Kartı kaldır">✕</button>' : '') + '</div></div>' +
            '<div class="mx-load">Seçilen: <b>' + num(ects) + ' AKTS</b> / ' + num(credit) + ' kredi</div>' +
            '<div class="mx-chips">' + (chips || '<span class="small muted">Önce Aldığım Dersler sayfasından derslerinizi ekleyin.</span>') + '</div></div>';
    }

    document.addEventListener('click', (e) => {
        const add = e.target.closest('[data-add]');
        if (add) {
            const tid = Number(add.dataset.add);
            if (!order.includes(tid)) { order.push(tid); dirty = true; render(); }
            const el = document.getElementById('mx-card-' + tid);
            el.scrollIntoView({behavior: 'smooth', block: 'center'});
            el.classList.add('flash'); setTimeout(() => el.classList.remove('flash'), 900);
            return;
        }
        const chip = e.target.closest('[data-src]');
        if (chip && editable) {
            const sid = Number(chip.dataset.src), tid = Number(chip.dataset.tid);
            if (Number(matches[sid]) === tid) { delete matches[sid]; } else { matches[sid] = tid; }
            dirty = true; render();
            return;
        }
        const rm = e.target.closest('[data-remove]');
        if (rm) {
            const tid = Number(rm.dataset.remove);
            Object.keys(matches).forEach((k) => { if (Number(matches[k]) === tid) { delete matches[k]; } });
            order.splice(order.indexOf(tid), 1);
            dirty = true; render();
        }
    });
    document.getElementById('mx-form').addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    render();
})();
</script>
@endif
@endsection
