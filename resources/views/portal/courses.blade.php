@extends('layouts.app')
@section('title', 'Muafiyet başvurum')
@section('content')
@php
    $editable = $student->isEditableByStudent();
    $other = \App\Http\Controllers\StudentPortalController::OTHER_DEPARTMENT;
    $auNames = $auPrograms->map(fn ($p) => $p->full_name)->all();
    $currentInstitution = old('institution', $programInfo['institution'] ?? $student->previous_institution);
    $currentInstitution = in_array($currentInstitution, $universities, true) ? $currentInstitution : config('universiteler.default');
    $currentDept = old('department', $programInfo ? (in_array($programInfo['name'], $auNames, true) ? $programInfo['name'] : $other) : '');
    $mxTargets = $targets->mapWithKeys(fn ($t) => [$t->id => [
        'code' => $t->code, 'name' => $t->name, 'credit' => (float) $t->credit, 'ects' => (float) $t->ects,
        'semester' => $t->semester, 'common' => in_array($t->common_group, ['AIIT', 'TD', 'YD', 'ISG'], true),
    ]]);
    $missing = collect($checklist)->where('required', true)->where('done', false);
@endphp
<div class="page-head">
    <div><h1>Muafiyet Başvurum</h1><div class="sub">Tek sayfada dört adım: <b>1.</b> aldığınız dersleri girin, <b>2.</b> öğretim planınızdan muaf olmak istediğiniz dersleri eşleştirin, <b>3.</b> dilekçe ve belgelerinizi yükleyin, <b>4.</b> kaydedip gönderin. Karar komisyonundur.</div></div>
</div>
@include('portal._status')

<details class="card" @if (! $programInfo && $editable) open @endif>
    <summary>
        <b>Geldiğiniz kurum ve bölüm:</b>
        @if ($programInfo)<span style="color:var(--ink)">{{ $programInfo['institution'] }} — {{ $programInfo['name'] }}</span> @if ($editable)<span class="small">(değiştir)</span>@endif
        @else<span class="muted">seçilmedi</span>@endif
    </summary>
    @if ($editable)
    <form method="POST" action="{{ route('portal.courses.department') }}" class="form-grid" style="margin-top:12px">
        @csrf
        <div>
            <label for="kurum">Kurum (üniversite)</label>
            <select id="kurum" name="institution" required>
                @foreach ($universities as $u)<option value="{{ $u }}" @selected($currentInstitution === $u)>{{ $u }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="bolum">Bölüm / program</label>
            <select id="bolum" name="department" required>
                <option value="">Bölümünüzü seçin</option>
                @foreach ($auPrograms as $p)<option value="{{ $p->full_name }}" @selected($currentDept === $p->full_name)>{{ $p->full_name }}</option>@endforeach
                <option value="{{ $other }}" @selected($currentDept === $other)>Diğer (bölümümü yazacağım)</option>
            </select>
        </div>
        <div id="bolum-diger" @if ($currentDept !== $other) hidden @endif>
            <label for="bolum-diger-ad">Bölümünüzün adı</label>
            <input id="bolum-diger-ad" name="department_other" maxlength="200" value="{{ old('department_other', $currentDept === $other ? ($programInfo['name'] ?? '') : '') }}" placeholder="ör. Fizik Bölümü">
        </div>
        <div style="align-self:end"><button>Kaydet</button></div>
    </form>
    <p class="small muted" style="margin-bottom:0">Buradaki kurum, ders satırlarında varsayılan kurum olarak gelir; her satırda değiştirebilirsiniz.</p>
    @endif
</details>

{{-- Şablon yükleme formu: alanları 1. bölümün içinde (form özniteliğiyle) --}}
<form method="POST" action="{{ route('portal.courses.import') }}" enctype="multipart/form-data" id="tpl-form">@csrf</form>

<form method="POST" action="{{ route('portal.courses.rows') }}" id="mx-form">
    @csrf
    <section class="card">
        <div class="card-head"><div><h2>1. Aldığım dersler</h2><div class="sub">Transkriptinizdeki dersleri yazın. Not olarak AA…FF, 100'lük puan, 4'lük katsayı ya da G/M yazabilirsiniz; sistem AÜ harf notuna çevirir.</div></div></div>
        <div class="mx-line-head"><span>Kurum</span><span>Ders kodu</span><span>Dersin adı</span><span>Kredi</span><span>AKTS</span><span>Notum</span><span>Karşılığı</span></div>
        <div id="mx-lines">
            @foreach ($student->transcript as $row)
                @include('portal._exemption-row', ['row' => $row])
            @endforeach
        </div>
        <p id="mx-lines-empty" class="muted small" @if ($student->transcript->isNotEmpty()) hidden @endif>Henüz ders girmediniz.</p>
        @if ($editable)
            <div class="mx-line-tools">
                <button type="button" class="light" data-new-line><x-icon name="plus" :size="14" /> Ders ekle</button>
                <details>
                    <summary class="small">Çok dersiniz varsa şablonla yükleyin</summary>
                    <div class="row" style="margin-top:8px">
                        <a class="btn light small" href="{{ route('portal.courses.template') }}"><x-icon name="download" :size="14" /> Şablonu indir</a>
                        <input type="file" name="file" accept=".xlsx,.xls" form="tpl-form" required aria-label="Doldurulmuş şablon">
                        <button class="light small" form="tpl-form"><x-icon name="upload" :size="14" /> Şablonu yükle</button>
                    </div>
                </details>
            </div>
        @endif
    </section>

    @if (! $student->curriculum)
        <div class="alert warn">AÜ programınız henüz belirlenmemiş. Komisyon programınızı tanımlayınca öğretim planınız burada görünecek ve eşleştirme yapabileceksiniz.</div>
    @else
    <section class="card obs-panel">
        <div class="card-head"><div><h2>2. Eşleştirme — öğretim planınız</h2><div class="sub">{{ $student->curriculum->program?->full_name }} · {{ $student->curriculum->title }}. Muaf olmak istediğiniz dersin yanındaki <span class="mx-add-demo">+</span> düğmesine basın; ders aşağıdaki listeye düşer, orada karşılığı olan dersinizi işaretleyin.</div></div></div>
        <div class="obs-grid">
            @foreach ($table['semesters'] as $semester => $s)
                <section class="obs-sem">
                    <table>
                        <thead>
                            <tr class="obs-sem-head"><th colspan="5">{{ $semester }}. Yarıyıl</th></tr>
                            <tr><th style="width:40px"></th><th style="width:86px">D. KOD.</th><th>DERSİN ADI</th><th class="num">KR</th><th class="num">AKTS</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($s['rows'] as $course)
                                @php
                                    $elective = \App\Services\CurriculumTable::isElective($course);
                                    $pickable = $editable && in_array((int) $course->id, $targetIds, true);
                                @endphp
                                <tr class="obs-row" data-row="{{ $course->id }}">
                                    <td>
                                        @if ($pickable)
                                            <button type="button" class="mx-add" data-add="{{ $course->id }}" title="Muaf olmak istediğim dersler listesine ekle" aria-label="{{ $course->name }} dersini listeye ekle">+</button>
                                        @else
                                            <span @class(['obs-type', 'sec' => $elective]) title="{{ $elective ? 'Seçmeli' : 'Zorunlu' }}">{{ $elective ? 'S' : 'Z' }}</span>
                                        @endif
                                    </td>
                                    <td class="mono small">{{ $course->code ?: '—' }}</td>
                                    <td>{{ $course->name }}</td>
                                    <td class="num">{{ (float) $course->credit ?: '—' }}</td>
                                    <td class="num">{{ number_format((float) $course->ects, 1) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endforeach
        </div>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Muaf olmak istediğim dersler</h2><div class="sub">Her kart bir AÜ dersidir. Karşılığı olan aldığınız derse dokunun; birden çok ders seçebilirsiniz (ör. Matematik I + Matematik II → Matematik I). Önce AKTS'ye, yetmezse krediye bakılır.</div></div></div>
        <div id="mx-cards" class="mx-cards"></div>
        <p id="mx-empty" class="muted small" style="margin:0">Henüz ders seçmediniz. Yukarıdaki öğretim planından bir dersin yanındaki <span class="mx-add-demo">+</span> düğmesine basın.</p>
    </section>
    @endif

    <div id="mx-deleted"></div>
    <input type="hidden" name="gonder" value="0" id="mx-send">
</form>

@include('portal._documents')

<section class="card mx-actions" id="mx-actions">
    <div>
        <h2 style="margin-bottom:6px">4. Kaydet ve gönder</h2>
        <ul class="mx-checklist">
            @foreach ($checklist as $item)
                <li @class(['ok' => $item['done'], 'opt' => ! $item['required']])>{{ $item['done'] ? '✓' : ($item['required'] ? '•' : '○') }} {{ $item['label'] }}{{ $item['required'] ? '' : ' (önerilir)' }}</li>
            @endforeach
        </ul>
        @if (! $editable)
            <div class="small muted">Başvurunuz gönderildi; değişiklik yapılamaz. Durumunu <a href="{{ route('portal.home') }}">Başvurum</a> sayfasından izleyebilirsiniz.</div>
        @elseif ($missing->isEmpty())
            <div class="small">Her şey hazır. Gönderdikten sonra değişiklik yapamazsınız.</div>
        @endif
    </div>
    @if ($editable)
        <div class="row">
            <button class="light" form="mx-form">Kaydet</button>
            <button type="submit" form="mx-form" data-send>Kaydet ve gönder</button>
        </div>
    @endif
</section>

@if ($editable)
<template id="mx-row-tpl">
    @include('portal._exemption-row', ['row' => null])
</template>
<script>
(function () {
    const targets = @json($mxTargets);
    const lines = document.getElementById('mx-lines');
    const cards = document.getElementById('mx-cards');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const num = (n) => String(Math.round(Number(n || 0) * 10) / 10).replace('.', ',');
    const val = (line, f) => line.querySelector('[data-f="' + f + '"]').value.trim();
    const dec = (s) => { const n = parseFloat(String(s).replace(',', '.')); return isNaN(n) ? 0 : n; };
    let seq = 1, dirty = false;
    const open = [];

    function allLines() { return [...lines.querySelectorAll('.mx-line')]; }
    allLines().forEach((l) => { const t = Number(val(l, 'target_id')); if (t && targets[t] && !open.includes(t)) { open.push(t); } });

    function render() {
        allLines().forEach((l, i) => {
            if (!l.dataset.key) { l.dataset.key = 'n' + (seq++); }
            l.querySelectorAll('[data-f]').forEach((el) => { el.name = 'items[' + i + '][' + el.dataset.f + ']'; });
            const t = targets[Number(val(l, 'target_id'))];
            l.querySelector('.mx-target-tag').innerHTML = t ? '→ <b>' + esc(t.code || t.name) + '</b>' : '<span class="muted">eşleşmedi</span>';
            l.classList.toggle('matched', !!t);
        });
        document.getElementById('mx-lines-empty').hidden = allLines().length > 0;
        if (!cards) { return; }
        open.sort((a, b) => ((targets[a].semester ?? 99) - (targets[b].semester ?? 99)) || String(targets[a].code).localeCompare(String(targets[b].code), 'tr'));
        cards.innerHTML = open.map(card).join('');
        document.getElementById('mx-empty').hidden = open.length > 0;
        document.querySelectorAll('[data-add]').forEach((b) => {
            const on = open.includes(Number(b.dataset.add));
            b.classList.toggle('on', on);
            b.textContent = on ? '✓' : '+';
            b.title = on ? 'Listede — karta gitmek için dokunun' : 'Muaf olmak istediğim dersler listesine ekle';
            b.closest('tr').classList.toggle('mx-in', on);
        });
    }

    function card(tid) {
        const t = targets[tid];
        const mine = allLines().filter((l) => Number(val(l, 'target_id')) === tid);
        const ects = mine.reduce((a, l) => a + dec(val(l, 'ects')), 0);
        const credit = mine.reduce((a, l) => a + dec(val(l, 'credit')), 0);
        const ok = mine.length && (t.common || ects >= t.ects || credit >= t.credit);
        const status = !mine.length ? '<span class="badge warn">karşılığını seçin</span>'
            : t.common ? '<span class="badge ok">ortak zorunlu</span>'
            : ok ? '<span class="badge ok">' + (ects >= t.ects ? 'AKTS yeterli' : 'kredi yeterli') + '</span>'
            : '<span class="badge bad">AKTS ve kredi yetersiz</span>';
        const named = allLines().filter((l) => val(l, 'name') !== '');
        const chips = named.map((l) => {
            const cur = Number(val(l, 'target_id'));
            const here = cur === tid, other = cur && !here ? targets[cur] : null;
            return '<button type="button" class="mx-chip' + (here ? ' on' : '') + (other ? ' taken' : '') + '" data-pick="' + l.dataset.key + '" data-tid="' + tid + '"' +
                (other ? ' title="Şu an ' + esc(other.code || other.name) + ' için seçili; dokunursanız buraya taşınır"' : '') + '>' +
                '<span class="mx-chip-name">' + (here ? '✓ ' : '') + esc((val(l, 'code') ? val(l, 'code') + ' ' : '') + val(l, 'name')) + '</span>' +
                '<span class="mx-chip-meta">' + num(dec(val(l, 'ects'))) + ' AKTS · ' + esc(val(l, 'institution')) + (other ? ' · → ' + esc(other.code || other.name) : '') + '</span></button>';
        }).join('');

        return '<div class="mx-card" id="mx-card-' + tid + '">' +
            '<div class="mx-card-head"><div><span class="mono small">' + esc(t.code || '') + '</span> <b>' + esc(t.name) + '</b>' +
            '<div class="small muted">' + (t.semester ? t.semester + '. yarıyıl · ' : '') + num(t.credit) + ' kredi · ' + num(t.ects) + ' AKTS</div></div>' +
            '<button type="button" class="icon-x" data-close="' + tid + '" title="Listeden çıkar" aria-label="Listeden çıkar">✕</button></div>' +
            '<div class="mx-load">' + status + ' <span>Seçilen: <b>' + num(ects) + ' AKTS</b> / ' + num(credit) + ' kredi</span></div>' +
            '<div class="mx-chips">' + (chips || '<span class="small muted">Önce “1. Aldığım dersler” bölümüne dersinizi yazın.</span>') + '</div></div>';
    }

    function newLine() {
        const line = document.getElementById('mx-row-tpl').content.cloneNode(true).querySelector('.mx-line');
        lines.appendChild(line);
        dirty = true; render();
        line.querySelector('[data-f="name"]').focus();
    }

    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-new-line]')) { newLine(); return; }
        const add = e.target.closest('[data-add]');
        if (add) {
            const tid = Number(add.dataset.add);
            if (!open.includes(tid)) { open.push(tid); dirty = true; render(); }
            const el = document.getElementById('mx-card-' + tid);
            el.scrollIntoView({behavior: 'smooth', block: 'center'});
            el.classList.add('flash'); setTimeout(() => el.classList.remove('flash'), 900);
            return;
        }
        const pick = e.target.closest('[data-pick]');
        if (pick) {
            const line = lines.querySelector('[data-key="' + pick.dataset.pick + '"]');
            const input = line.querySelector('[data-f="target_id"]');
            input.value = Number(input.value) === Number(pick.dataset.tid) ? '' : pick.dataset.tid;
            dirty = true; render();
            return;
        }
        const close = e.target.closest('[data-close]');
        if (close) {
            const tid = Number(close.dataset.close);
            allLines().forEach((l) => { if (Number(val(l, 'target_id')) === tid) { l.querySelector('[data-f="target_id"]').value = ''; } });
            open.splice(open.indexOf(tid), 1);
            dirty = true; render();
            return;
        }
        const del = e.target.closest('[data-del-line]');
        if (del) {
            const line = del.closest('.mx-line');
            if (val(line, 'name') && !confirm('“' + val(line, 'name') + '” silinsin mi?')) { return; }
            const id = val(line, 'id');
            if (id) { document.getElementById('mx-deleted').insertAdjacentHTML('beforeend', '<input type="hidden" name="deleted[]" value="' + esc(id) + '">'); }
            line.remove();
            dirty = true; render();
            return;
        }
        // Kaydetmeden önce boş bırakılmış yeni satırlar atılır (zorunlu alan uyarısı vermesin).
        if (e.target.closest('button[form="mx-form"]')) {
            allLines().forEach((l) => { if (!val(l, 'id') && ['code', 'name', 'credit', 'ects', 'grade_raw'].every((f) => val(l, f) === '')) { l.remove(); } });
            render();
        }
        const send = e.target.closest('[data-send]');
        if (send) {
            if (!confirm('Dersleriniz ve eşleştirmeleriniz kaydedilip başvurunuz komisyona gönderilecek. Gönderdikten sonra değişiklik yapamazsınız. Devam edilsin mi?')) { e.preventDefault(); return; }
            document.getElementById('mx-send').value = '1';
        }
    });
    lines.addEventListener('input', () => { dirty = true; render(); });
    lines.addEventListener('change', () => { dirty = true; render(); });
    document.getElementById('mx-form').addEventListener('submit', () => { dirty = false; render(); });
    document.getElementById('tpl-form').addEventListener('submit', (e) => {
        if (dirty && !confirm('Kaydedilmemiş değişiklikleriniz var; şablon yüklenince sayfa yenilenir ve bu değişiklikler kaybolur. Devam edilsin mi?')) { e.preventDefault(); return; }
        dirty = false;
    });
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    // Belgeler sayfa yenilenmeden yüklenir/silinir; böylece girilen dersler kaybolmaz.
    document.addEventListener('submit', async (e) => {
        const form = e.target.closest('form[data-ajax]');
        if (!form) { return; }
        e.preventDefault();
        if (form.dataset.confirm && !confirm(form.dataset.confirm)) { return; }
        const status = document.querySelector('#mx-docs [data-ajax-status]');
        if (status) { status.textContent = 'Gönderiliyor…'; }
        try {
            const res = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const first = data.errors ? Object.values(data.errors)[0][0] : (data.message || 'İşlem yapılamadı.');
                if (status) { status.textContent = first; }
                return;
            }
            const html = new DOMParser().parseFromString(await (await fetch(location.pathname, {headers: {'Accept': 'text/html'}})).text(), 'text/html');
            ['mx-docs', 'mx-actions'].forEach((id) => { const fresh = html.getElementById(id); if (fresh) { document.getElementById(id).replaceWith(fresh); } });
            const st = document.querySelector('#mx-docs [data-ajax-status]');
            if (st) { st.textContent = data.message || 'Tamam.'; }
        } catch (err) {
            if (status) { status.textContent = 'Bağlantı hatası; tekrar deneyin.'; }
        }
    });

    const bolum = document.getElementById('bolum');
    if (bolum) {
        bolum.addEventListener('change', () => {
            const on = bolum.value === @json($other);
            document.getElementById('bolum-diger').hidden = !on;
            document.getElementById('bolum-diger-ad').required = on;
        });
    }
    if (!allLines().length) { newLine(); dirty = false; }
    render();
})();
</script>
@endif
@endsection
