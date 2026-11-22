@extends('layouts.app')
@section('title', $course->label)
@section('content')
@php($info = $course->info)
@php($program = $curriculum->program)
<div class="obs-hero">
    <a class="obs-back" href="{{ route('programs.show', ['program' => $program, 'plan' => $curriculum->id]) }}" aria-label="Müfredata dön">←</a>
    <div class="obs-eyebrow"><span></span>ÖĞRETİM PLANI · DERS BİLGİ PAKETİ</div>
    <div class="obs-title">
        <span class="obs-title-icon"><x-icon name="book" :size="20" /></span>
        <h1>Ders Detayı</h1>
    </div>
    <p class="obs-lead">{{ $program->full_name }} · {{ $curriculum->start_year ? $curriculum->start_year.' girişli müfredat' : $curriculum->title }}</p>
</div>

<div class="card obs-panel">
    <h2 class="obs-course-name">{{ $course->code ? $course->code.' — ' : '' }}{{ $course->name }}</h2>
    @if ($course->name_en)<div class="muted" style="margin:-8px 0 12px">{{ $course->name_en }}</div>@endif
    <dl class="obs-facts">
        <dt>Yarıyıl</dt><dd>{{ $course->semester ? $course->semester.'. yarıyıl' : '—' }}</dd>
        <dt>Türü</dt><dd>{{ \App\Services\CurriculumTable::isElective($course) ? 'Seçmeli' : 'Zorunlu' }}@if ($course->elective_group) <span class="small muted">({{ $course->elective_group }})</span>@endif</dd>
        <dt>Teori / Uygulama / Lab</dt><dd>{{ (float) $course->theory }} / {{ (float) $course->practice }} / {{ (float) $course->lab }}</dd>
        <dt>Kredi</dt><dd>{{ (float) $course->credit }}</dd>
        <dt>AKTS</dt><dd>{{ number_format((float) $course->ects, 1) }}</dd>
        @if ($info?->language)<dt>Öğretim dili</dt><dd>{{ $info->language }}</dd>@endif
        @if ($info?->prerequisites && $info->prerequisites !== '-')<dt>Ön koşul</dt><dd>{{ $info->prerequisites }}</dd>@endif
    </dl>
</div>

@if ($info)
    @if ($info->objective)<div class="card obs-panel"><h2>Dersin amacı</h2><p style="margin:0">{{ $info->objective }}</p></div>@endif
    @if ($info->description)<div class="card obs-panel"><h2>Dersin içeriği</h2><p style="margin:0">{{ $info->description }}</p></div>@endif
    @if ($info->weekly_topics)
        <div class="card obs-panel">
            <h2>Haftalık konular</h2>
            <table>
                <tr><th style="width:70px">Hafta</th><th>Konu</th></tr>
                @foreach ($info->weekly_topics as $i => $topic)<tr><td>{{ $i + 1 }}</td><td>{{ $topic }}</td></tr>@endforeach
            </table>
        </div>
    @endif
    @if ($info->outcomes)
        <div class="card obs-panel">
            <h2>Öğrenme çıktıları</h2>
            <ol style="margin:0;padding-left:20px">@foreach ($info->outcomes as $outcome)<li>{{ $outcome }}</li>@endforeach</ol>
        </div>
    @endif
@elseif ($course->content)
    <div class="card obs-panel"><h2>Dersin içeriği</h2><p style="margin:0">{{ $course->content }}</p></div>
@else
    <div class="card obs-panel"><p class="muted" style="margin:0">Bu ders için içerik bilgisi yok.</p></div>
@endif

<div class="row small muted" style="justify-content:space-between;margin-bottom:16px">
    <span>
        @if ($info)Kaynak: OBS Ders Bilgi Paketi{{ $info->offering_label ? ' — '.$info->offering_label : '' }}.@endif
        @if ($course->obs_course_id && $program->obs_program_id)
            <a href="https://obs.atauni.edu.tr/moduller/dbp/eobs/dersDetay/{{ $course->obs_course_id }}/{{ $program->obs_program_id }}" target="_blank" rel="noopener">OBS'de aç</a>
        @endif
    </span>
    @if ($otherYears->isNotEmpty())
        <span>Bu ders şu girişli müfredatlarda da var:
            @foreach ($otherYears as $other)<a href="{{ route('programs.show', ['program' => $program, 'plan' => $other->id]) }}">{{ $other->start_year ?: $other->title }}</a>@if (!$loop->last), @endif @endforeach
        </span>
    @endif
</div>
@endsection
