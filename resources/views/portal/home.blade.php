@extends('layouts.app')
@section('title', \App\Models\Student::PORTAL_MODES[$mode])
@section('content')
@php
    $st = $student->application_status;
    $types = \App\Models\ExemptionApplication::TYPES;
    $typeHelp = [
        'normal' => 'Daha önce aldığınız derslerden muafiyet istersiniz.',
        'yaz_okulu' => 'Başka üniversitenin yaz okulunda alacağınız derslerin AÜ karşılığını önceden onaylatırsınız (en fazla '.(float) config('intibak.summer_school_max_ects').' AKTS).',
        'hazirlik' => 'Yeni kayıtlı öğrenci, geçerli bir dil sınavı puanıyla hazırlık sınıfından muaf olmak ister.',
    ];
@endphp
<div class="page-head">
    <div><h1>{{ \App\Models\Student::PORTAL_MODES[$mode] }}</h1><div class="sub">Merhaba, {{ $student->first_name }} · {{ $currentTerm ?? '' }} · {{ $student->program?->full_name }}</div></div>
</div>
@error('type')<div class="alert bad" style="white-space:normal">{{ $message }}</div>@enderror

@if ($mode === 'yeni')
    @if ($st === 'sonuclandi')
        <div class="alert ok" style="white-space:normal">Son başvurunuz sonuçlandı. Kararı görmek ya da itiraz etmek için <a href="{{ route('portal.applications.show', $application) }}">Muafiyet Başvurularım</a> sayfasını açın.</div>
    @endif
    <div class="card" id="basvuru-turu">
        <div class="card-head"><div><h2>Yeni başvuru</h2><div class="sub">Başvuru türünü seçip başlayın. Başvurunuzu istediğiniz zaman kaydedip sonra tamamlayabilirsiniz.</div></div></div>
        <form method="POST" action="{{ route('portal.applications.start') }}">
            @csrf
            @foreach ($types as $k => $v)
                <label class="type-opt" style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--line);border-radius:9px;margin-bottom:8px;font-weight:400;cursor:pointer">
                    <input type="radio" name="type" value="{{ $k }}" @checked($loop->first) @disabled(! $windows[$k]['open']) style="margin-top:3px">
                    <span><b>{{ $v }}</b>@unless ($windows[$k]['open']) <span class="badge warn">alım kapalı</span>@endunless
                        <span class="small muted" style="display:block">{{ $typeHelp[$k] }} {{ $windows[$k]['open'] ? '' : $windows[$k]['message'] }}</span></span>
                </label>
            @endforeach
            <p class="row" style="margin:12px 0 0"><button><x-icon name="plus" :size="14" /> Başvuruya başla</button>
                @if ($st === 'sonuclandi')<span class="small muted">Önceki başvurularda talep ettiğiniz dersler (kabul ya da ret) yeniden istenemez.</span>@endif</p>
        </form>
    </div>
@else
    @include('portal._status')
    @if ($application->return_note && $st === 'taslak')
        <div class="alert warn" style="white-space:normal"><b>Komisyon başvurunuzu düzeltmeniz için geri açtı{{ $application->returned_at ? ' ('.$application->returned_at->format('d.m.Y').')' : '' }}.</b><br>Komisyonun notu: {{ $application->return_note }}</div>
    @endif

    <div class="card">
        <div class="card-head"><div><h2>{{ $application->type_label }}</h2><div class="sub">Başvurumun aşamaları · açılış {{ $application->created_at->format('d.m.Y') }}</div></div>
            @if ($st === 'taslak')<a class="btn" href="{{ route('portal.courses') }}"><x-icon name="edit" :size="14" /> Başvuruyu düzenle</a>@endif</div>
        @if ($reopened && ! $application->return_note)<div class="alert warn" style="margin-bottom:12px">Komisyon başvurunuzu düzeltme için size geri açtı. Gerekli değişiklikleri yapıp yeniden gönderin.</div>@endif
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
        @if ($st === 'taslak')
            <form method="POST" action="{{ route('portal.applications.start') }}" class="row" id="basvuru-turu" style="margin-top:12px">
                @csrf
                <label for="app-type" class="small">Başvuru türü</label>
                <select id="app-type" name="type">@foreach ($types as $k => $v)<option value="{{ $k }}" @selected($application->type === $k)>{{ $v }}</option>@endforeach</select>
                <button class="light">Türü değiştir</button>
            </form>
            <p class="small muted" style="margin-bottom:0">{{ $windows[$application->type]['message'] }}</p>
        @endif
    </div>
@endif

@if ($mode === 'duzenle')
@unless ($application->isPrep())
<div class="grid grid-5">
    <div class="stat"><span class="stat-icon sky"><x-icon name="book" :size="18" /></span><div>Girdiğim ders<b>{{ $student->transcript->count() }}</b></div></div>
    <div class="stat"><span class="stat-icon violet"><x-icon name="link" :size="18" /></span><div>Eşleştirme<b>{{ $student->transcript->whereNotNull('requested_course_id')->count() }}</b></div></div>
    <div class="stat"><span class="stat-icon green"><x-icon name="file" :size="18" /></span><div>Belge<b>{{ $student->documents->count() }}</b></div></div>
    <div class="stat"><span class="stat-icon"><x-icon name="clipboard" :size="18" /></span><div>Eksik adım<b>{{ collect($checklist)->where('required', true)->where('done', false)->count() }}</b></div></div>
</div>
@endunless
@endif

<div class="grid {{ $mode === 'duzenle' ? 'grid-2' : '' }}">
    @if ($mode === 'duzenle')
    <div class="card">
        <div class="card-head"><div><h2>Yapılacaklar</h2><div class="sub">Tüm zorunlu adımlar tamamlanınca başvurunuzu gönderebilirsiniz</div></div></div>
        <ul style="list-style:none;padding:0;margin:0">
            @foreach ($checklist as $item)
                <li style="margin-bottom:6px">{!! $item['done'] ? '<span class="badge ok">✓</span>' : ($item['required'] ? '<span class="badge bad">eksik</span>' : '<span class="badge warn">önerilir</span>') !!} {{ $item['label'] }}</li>
            @endforeach
        </ul>
        <p class="row" style="margin-top:12px">
            <a class="btn" href="{{ route('portal.courses') }}"><x-icon name="edit" :size="14" /> Başvuruyu düzenle</a>
            <a class="btn light" href="{{ route('portal.petition') }}"><x-icon name="file" :size="14" /> Dilekçemi hazırla</a>
        </p>
        <p class="small muted">Muafiyet için dilekçe, not döküm belgesi ve onaylı ders içerikleri gerekir (Uygulama Esasları Md. 5/2). Başvurular akademik takvimdeki ders kayıt tarihleri içinde yapılır (Md. 5/1).</p>
    </div>
    @endif

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

@if ($mode !== 'yeni' && $student->transcript->whereNotNull('requested_course_id')->isNotEmpty())
    <div class="card table-wrap">
        <h2>Muafiyet taleplerim <span class="small muted">({{ $st === 'taslak' ? 'henüz gönderilmedi' : 'komisyon kararı bekleniyor' }})</span></h2>
        <table>
            <tr><th>Aldığım ders</th><th>Muaf olmak istediğim AÜ dersi</th><th class="num">AKTS</th><th>Durum</th></tr>
            @foreach ($student->transcript->whereNotNull('requested_course_id')->sortBy(fn ($c) => $c->requestedCourse?->semester) as $c)
                <tr><td>{{ $c->label }}</td><td>{{ $c->requestedCourse?->label }}</td><td class="num">{{ (float) $c->ects }}</td><td><span class="badge">{{ $st === 'taslak' ? 'taslak' : 'bekliyor' }}</span></td></tr>
            @endforeach
        </table>
    </div>
@endif
@endsection
