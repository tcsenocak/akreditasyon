@extends('layouts.app')
@section('title', 'Başvurum')
@section('content')
<div class="page-head">
    <div><h1>Merhaba, {{ $student->first_name }}</h1><div class="sub">{{ $currentTerm ?? '' }} · {{ $student->program?->full_name }} · muafiyet ve intibak başvurusu</div></div>
</div>
@include('portal._status')

<div class="card">
    <div class="card-head"><div><h2>Başvurumun aşamaları</h2><div class="sub">Başvurunuzun nerede olduğunu buradan izleyin</div></div></div>
    @if ($reopened)<div class="alert warn" style="margin-bottom:12px">Komisyon başvurunuzu düzeltme için size geri açtı. Gerekli değişiklikleri yapıp yeniden gönderin.</div>@endif
    <ol class="st-steps">
        @foreach ($stages as $i => $stage)
            <li class="st-{{ $stage['state'] }}">
                <span class="st-dot">{{ $stage['state'] === 'done' ? '✓' : $i + 1 }}</span>
                <div>
                    <b>{{ $stage['label'] }}</b>
                    @if ($stage['date'] && $stage['state'] !== 'todo')<span class="small muted"> · {{ $stage['date']->format('d.m.Y') }}</span>@endif
                    @if ($stage['state'] === 'current')<div class="small">{{ $stage['text'] }}</div>@endif
                </div>
            </li>
        @endforeach
    </ol>
</div>

<div class="grid grid-5">
    <div class="stat"><span class="stat-icon sky"><x-icon name="book" :size="18" /></span><div>Girdiğim ders<b>{{ $student->transcript->count() }}</b></div></div>
    <div class="stat"><span class="stat-icon violet"><x-icon name="link" :size="18" /></span><div>Eşleştirme<b>{{ $student->transcript->whereNotNull('requested_course_id')->count() }}</b></div></div>
    <div class="stat"><span class="stat-icon green"><x-icon name="file" :size="18" /></span><div>Belge<b>{{ $student->documents->count() }}</b></div></div>
    <div class="stat"><span class="stat-icon"><x-icon name="clipboard" :size="18" /></span><div>Eksik adım<b>{{ collect($checklist)->where('required', true)->where('done', false)->count() }}</b></div></div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><div><h2>Yapılacaklar</h2><div class="sub">Tüm zorunlu adımlar tamamlanınca başvurunuzu gönderebilirsiniz</div></div></div>
        <ul style="list-style:none;padding:0;margin:0">
            @foreach ($checklist as $item)
                <li style="margin-bottom:6px">{!! $item['done'] ? '<span class="badge ok">✓</span>' : ($item['required'] ? '<span class="badge bad">eksik</span>' : '<span class="badge warn">önerilir</span>') !!} {{ $item['label'] }}</li>
            @endforeach
        </ul>
        <p class="row" style="margin-top:12px">
            <a class="btn" href="{{ route('portal.courses') }}"><x-icon name="book" :size="14" /> Muafiyet başvuruma git</a>
        </p>
        <p class="small muted">Muafiyet için dilekçe, not döküm belgesi ve onaylı ders içerikleri gerekir (Uygulama Esasları Md. 5/2). Başvurular akademik takvimdeki ders kayıt tarihleri içinde yapılır (Md. 5/1).</p>
    </div>

    <form method="POST" action="{{ route('portal.profile') }}" class="card">
        @csrf @method('PUT')
        <h2>Bilgilerim</h2>
        @php
            $locked = ! $student->isEditableByStudent();
        @endphp
        <fieldset @disabled($locked) style="border:0;padding:0;margin:0">
        <div class="form-grid">
            <div><label for="student_no">AÜ öğrenci no</label><input id="student_no" name="student_no" value="{{ old('student_no', $student->student_no) }}"></div>
            <div><label for="phone">Telefon</label><input id="phone" name="phone" value="{{ old('phone', $student->phone) }}"></div>
            <div class="full"><label for="program_id">AÜ programı</label>
                <select id="program_id" name="program_id">@foreach ($programs as $p)<option value="{{ $p->id }}" @selected($student->program_id === $p->id)>{{ $p->full_name }}</option>@endforeach</select>
            </div>
            <div><label for="admission_type">Kayıt türü</label>
                <select id="admission_type" name="admission_type">@foreach (\App\Models\Student::ADMISSION_TYPES as $k => $v)<option value="{{ $k }}" @selected($student->admission_type === $k)>{{ $v }}</option>@endforeach</select>
            </div>
            <div><label for="entry_term">Kayıt dönemi</label><input id="entry_term" name="entry_term" value="{{ old('entry_term', $student->entry_term) }}" placeholder="2026-2027 Güz"></div>
            <div><label for="previous_institution">Önceki üniversite</label><input id="previous_institution" name="previous_institution" value="{{ old('previous_institution', $student->previous_institution) }}" required></div>
            <div><label for="previous_faculty">Önceki fakülte/MYO</label><input id="previous_faculty" name="previous_faculty" value="{{ old('previous_faculty', $student->previous_faculty) }}"></div>
            <div><label for="previous_program">Önceki bölüm/program</label><input id="previous_program" name="previous_program" value="{{ old('previous_program', $student->previous_program) }}"></div>
            <div><label for="yds_exam">Yabancı dil sınavı</label><input id="yds_exam" name="yds_exam" value="{{ old('yds_exam', $student->yds_exam) }}" placeholder="YDS, YÖKDİL..."></div>
            <div><label for="yds_score">Puan</label><input id="yds_score" type="number" step="0.01" min="0" max="100" name="yds_score" value="{{ old('yds_score', $student->yds_score) }}"></div>
            <div class="row full"><input type="checkbox" id="prep" name="prep_completed" value="1" @checked($student->prep_completed)><label for="prep" style="margin:0">Yabancı dil hazırlığını başarıyla tamamladım / muafiyet sınavını geçtim</label></div>
        </div>
        @unless ($locked)<p><button>Kaydet</button></p>@endunless
        </fieldset>
    </form>
</div>

@if (! $decided && $student->transcript->whereNotNull('requested_course_id')->isNotEmpty())
    <div class="card table-wrap">
        <h2>Muafiyet taleplerim <span class="small muted">({{ $student->application_status === 'taslak' ? 'henüz gönderilmedi' : 'komisyon kararı bekleniyor' }})</span></h2>
        <table>
            <tr><th>Aldığım ders</th><th>Muaf olmak istediğim AÜ dersi</th><th class="num">AKTS</th><th>Durum</th></tr>
            @foreach ($student->transcript->whereNotNull('requested_course_id')->sortBy(fn ($c) => $c->requestedCourse?->semester) as $c)
                <tr><td>{{ $c->label }}</td><td>{{ $c->requestedCourse?->label }}</td><td class="num">{{ (float) $c->ects }}</td><td><span class="badge">{{ $student->application_status === 'taslak' ? 'taslak' : 'bekliyor' }}</span></td></tr>
            @endforeach
        </table>
    </div>
@endif

@if ($decided)
    <div class="card">
        <h2>Muafiyet ve intibak sonucu</h2>
        <p>Muaf olunan toplam <b>{{ (float) $decided->exempt_ects }} AKTS</b> · intibak edildiğiniz yarıyıl: <b>{{ $decided->effective_semester }}. yarıyıl</b>
            @if ($decided->decision_no)· Yönetim kurulu kararı: {{ $decided->decision_date?->format('d.m.Y') }} / {{ $decided->decision_no }}@endif</p>
        <table>
            <tr><th>Aldığınız ders(ler)</th><th>Muaf olduğunuz AÜ dersi</th><th class="num">Yarıyıl</th><th class="num">AKTS</th><th>Harf notu</th></tr>
            @foreach ($decided->acceptedItems->sortBy(fn ($i) => $i->course->semester) as $item)
                <tr>
                    <td>{{ $item->sources->map(fn ($s) => $s->label)->implode(' + ') ?: (\App\Models\ExemptionItem::RULES[$item->rule] ?? '') }}</td>
                    <td>{{ $item->course->label }}</td>
                    <td class="num">{{ $item->course->semester }}</td>
                    <td class="num">{{ (float) $item->course->ects }}</td>
                    <td><b>{{ $item->letter }}</b></td>
                </tr>
            @endforeach
        </table>
        @php
            $rejected = $decided->items->where('status', 'ret');
        @endphp
        @if ($rejected->isNotEmpty())
            <h3 style="margin-top:14px">Kabul edilmeyen talepler</h3>
            <ul>@foreach ($rejected as $item)<li>{{ $item->course?->label }}</li>@endforeach</ul>
        @endif
        <p class="small muted">İntibak edilen öğrenci önce alt yarıyıllardan muaf olmadığı derslere kayıt yaptırır (Md. 7/2). Karara itiraz için bildirimden itibaren beş iş günü içinde Dekanlığa başvurabilirsiniz (Md. 5/9).</p>
    </div>
@endif
@endsection
