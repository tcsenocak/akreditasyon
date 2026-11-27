@extends('layouts.app')
@section('title', 'Dilekçem')
@section('content')
@php
    $rows = $student->transcript->whereNotNull('requested_course_id')->sortBy(fn ($r) => $r->requestedCourse?->semester ?? 99)->values();
    $others = $student->transcript->whereNull('requested_course_id');
    $institutions = $student->transcript->pluck('institution')->filter()->unique()->values();
    $faculty = $student->program?->faculty ?? 'Mühendislik Fakültesi';
@endphp
<div class="no-print row" style="margin-bottom:12px">
    <button onclick="window.print()"><x-icon name="print" :size="14" /> Yazdır / PDF olarak kaydet</button>
    <a class="btn light" href="{{ route('portal.courses') }}#mx-docs">← Muafiyet başvuruma dön</a>
</div>
<div class="alert info no-print" style="white-space:normal">Dilekçeniz girdiğiniz bilgilerden hazırlandı. Yazdırın (ya da PDF olarak kaydedin), <b>imzalayın</b>, taratıp “Belgeler” bölümüne <b>Muafiyet dilekçesi</b> olarak yükleyin. Ders ya da bilgi değişirse dilekçeyi yeniden alın.</div>

<div class="card petition">
    <p style="text-align:center;font-weight:700;margin:0 0 18px">ATATÜRK ÜNİVERSİTESİ<br>{{ mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $faculty), 'UTF-8') }} DEKANLIĞINA</p>

    @if ($application->isPrep())
        <p>Fakülteniz {{ $student->program?->full_name }} programına yeni kayıt yaptıran {{ $student->student_no ? $student->student_no.' numaralı ' : '' }}öğrencinizim. {{ $application->language_exam_date?->format('d.m.Y') ? $application->language_exam_date->format('d.m.Y').' tarihinde girdiğim ' : 'Girdiğim ' }}{{ $application->language_exam ?: '……………' }} sınavından {{ $application->language_score ?? '……' }} puan aldım. Sınav sonuç belgem ekte sunulmuştur. Yabancı dil hazırlık sınıfından muaf olmak istiyorum.</p>
    @elseif ($application->isSummer())
        <p>Fakülteniz {{ $student->program?->full_name }} programı {{ $student->student_no ? $student->student_no.' numaralı ' : '' }}öğrencisiyim. {{ $institutions->implode(', ') ?: '……………' }} yaz okulunda aşağıda belirtilen dersleri almak istiyorum. Bu derslerin programımdaki karşılıklarının onaylanması ve başarılı olmam hâlinde muaf sayılmam hususunda gereğini arz ederim.</p>
    @else
        <p>Fakülteniz {{ $student->program?->full_name }} programı {{ $student->student_no ? $student->student_no.' numaralı ' : '' }}öğrencisiyim. Daha önce {{ $institutions->implode(', ') ?: ($student->previous_institution ?: '……………') }}{{ $student->previous_program ? ' '.$student->previous_program : '' }} bünyesinde alarak başarılı olduğum aşağıda belirtilen derslerden, karşılarında yazılı derslerden muaf olmak istiyorum. Not döküm belgem (transkript) ve onaylı ders içerikleri ekte sunulmuştur.</p>
    @endif
    <p>Gereğini bilgilerinize arz ederim.</p>

    @unless ($application->isPrep())
        <table class="petition-table">
            <tr><th>#</th><th>Kurum</th><th>Aldığım ders</th><th class="num">Kr / AKTS</th>@unless ($application->isSummer())<th>Not</th>@endunless<th>Muaf olmak istediğim AÜ dersi</th><th class="num">AKTS</th></tr>
            @forelse ($rows as $i => $row)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td class="small">{{ $row->institution }}</td>
                    <td>{{ $row->label }}</td>
                    <td class="num">{{ $row->credit !== null ? (float) $row->credit : '–' }} / {{ $row->ects !== null ? (float) $row->ects : '–' }}</td>
                    @unless ($application->isSummer())<td>{{ $row->grade_raw }}</td>@endunless
                    <td>{{ $row->requestedCourse?->label }}</td>
                    <td class="num">{{ (float) $row->requestedCourse?->ects }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Henüz eşleştirme yapmadınız.</td></tr>
            @endforelse
        </table>
        @if ($others->isNotEmpty())
            <p class="small">Karşılık gösterilmeyen diğer dersler: {{ $others->map(fn ($r) => $r->label)->implode(', ') }}</p>
        @endif
    @endunless

    <div class="petition-sign">
        <div>
            <b>Adres / iletişim</b><br>
            E-posta: {{ $email }}<br>
            Telefon: {{ $student->phone ?: '……………………' }}
        </div>
        <div style="text-align:center">
            {{ now()->format('d.m.Y') }}<br><br>
            {{ $student->full_name }}<br>
            <span class="small">(İmza)</span>
        </div>
    </div>

    <p class="small" style="margin-top:18px"><b>Ekler:</b>
        @if ($application->isPrep())
            Yabancı dil sınavı sonuç belgesi.
        @elseif ($application->isSummer())
            Yaz okulunda alınacak derslerin içerikleri.
        @else
            {{ $institutions->count() > 1 ? 'Her kurum için not döküm belgesi ve onaylı ders içerikleri ('.$institutions->count().' kurum).' : 'Not döküm belgesi (transkript), onaylı ders içerikleri.' }}
        @endif
    </p>
</div>
@endsection
