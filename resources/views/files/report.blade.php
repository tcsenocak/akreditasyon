@extends('layouts.app')
@section('title', $kind === 'ogrenci' ? 'Öğrenci bildirimi' : 'Komisyon raporu')
@section('content')
@php($student = $file->student)
<div class="no-print row" style="margin-bottom:12px">
    <button onclick="window.print()">Yazdır / PDF</button>
    <a class="btn light" href="{{ route('files.show', $file) }}">← Dosyaya dön</a>
    <a class="btn light" href="{{ route('files.report', [$file, 'tur' => $kind === 'ogrenci' ? 'komisyon' : 'ogrenci']) }}">{{ $kind === 'ogrenci' ? 'Komisyon raporu' : 'Öğrenci bildirimi' }}</a>
</div>
<div class="card">
    <div style="text-align:center;margin-bottom:12px">
        <b>T.C.<br>ATATÜRK ÜNİVERSİTESİ<br>{{ mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $file->curriculum->program->faculty), 'UTF-8') }}</b><br>
        @if ($kind === 'ogrenci')
            <b>DERS MUAFİYETİ VE İNTİBAK SONUÇ BİLDİRİMİ</b>
        @else
            <b>MUAFİYET VE İNTİBAK KOMİSYONU RAPORU</b>
        @endif
    </div>
    <table style="margin-bottom:12px">
        <tr><th style="width:200px">Öğrenci</th><td>{{ $student->full_name }} {{ $student->student_no ? '('.$student->student_no.')' : '' }}</td></tr>
        <tr><th>Kayıt türü</th><td>{{ \App\Models\Student::ADMISSION_TYPES[$student->admission_type] ?? $student->admission_type }} {{ $student->entry_term ? '— '.$student->entry_term : '' }}</td></tr>
        <tr><th>Geldiği kurum / program</th><td>{{ $student->previous_institution }} {{ $student->previous_faculty ? '/ '.$student->previous_faculty : '' }} {{ $student->previous_program ? '/ '.$student->previous_program : '' }}</td></tr>
        <tr><th>Kayıtlı olduğu program</th><td>{{ $file->curriculum->program->full_name }} ({{ $file->curriculum->title }})</td></tr>
        <tr><th>Muaf olunan toplam AKTS</th><td><b>{{ (float) $file->exempt_ects }}</b></td></tr>
        <tr><th>İntibak edildiği yarıyıl</th><td><b>{{ $file->effective_semester }}. yarıyıl</b> <span class="small muted">(⌊{{ (float) $file->exempt_ects }} ÷ 30⌋ + 1{{ $file->placed_semester_override ? '; komisyon kararıyla '.$file->placed_semester_override : '' }} — Uygulama Esasları Md. 7/1)</span></td></tr>
        @if ($file->decision_no)<tr><th>Yönetim kurulu kararı</th><td>{{ $file->decision_date?->format('d.m.Y') }} tarih ve {{ $file->decision_no }} sayılı</td></tr>@endif
    </table>

    <h3>Muaf sayılan dersler</h3>
    <table>
        <tr><th>Önceki kurumda alınan ders</th><th class="num">AKTS</th><th>Not</th><th>AÜ dersi</th><th>Kod</th><th class="num">Yy</th><th class="num">AKTS</th><th>Harf</th>@if($kind !== 'ogrenci')<th>Dayanak</th>@endif</tr>
        @foreach ($items as $item)
            <tr>
                <td>{{ $item->sources->map(fn ($s) => $s->label)->implode(' + ') ?: (\App\Models\ExemptionItem::RULES[$item->rule] ?? '') }}</td>
                <td class="num">{{ $item->sources->isNotEmpty() ? (float) $item->sources->sum('ects') : '' }}</td>
                <td>{{ $item->sources->pluck('grade_raw')->implode(', ') }}</td>
                <td>{{ $item->course->name }}</td>
                <td>{{ $item->course->code }}</td>
                <td class="num">{{ $item->course->semester }}</td>
                <td class="num">{{ (float) $item->course->ects }}</td>
                <td><b>{{ $item->letter }}</b></td>
                @if($kind !== 'ogrenci')<td class="small">{{ \App\Models\ExemptionItem::RULES[$item->rule] ?? $item->rule }}</td>@endif
            </tr>
        @endforeach
        <tr><th colspan="6" class="num">Toplam</th><th class="num">{{ (float) $items->sum(fn ($i) => $i->course->ects) }}</th><th colspan="{{ $kind !== 'ogrenci' ? 2 : 1 }}"></th></tr>
    </table>

    @if ($notExempt->isNotEmpty())
        <h3>Alt yarıyıllardan alınması gereken dersler (Md. 7/2)</h3>
        <table>
            <tr><th class="num">Yy</th><th>Kod</th><th>Ders</th><th class="num">AKTS</th></tr>
            @foreach ($notExempt as $course)
                <tr><td class="num">{{ $course->semester }}</td><td>{{ $course->code }}</td><td>{{ $course->name }}</td><td class="num">{{ (float) $course->ects }}</td></tr>
            @endforeach
        </table>
    @endif

    @if ($file->commission_note)
        <h3>Komisyon notu</h3><p>{{ $file->commission_note }}</p>
    @endif

    @if ($kind === 'ogrenci')
        <p class="small">Muafiyet ve intibak işlemine itirazlar, bu bildirimin tebliğinden itibaren beş iş günü içinde Dekanlığa yapılır (Uygulama Esasları Md. 5/9). Muaf olunan bir derse daha sonra kayıt yaptırmak isteyen öğrenci, mali mevzuat hükümlerine bağlı olarak kayıt yaptırabilir (Md. 5/7).</p>
    @else
        <p class="small">Rapor, Atatürk Üniversitesi Ders Muafiyeti ve İntibak İşlemleri Uygulama Esasları (Senato 15.03.2024) uyarınca hazırlanmış olup birim yönetim kurulunun onayına sunulur.</p>
        <table style="margin-top:40px">
            <tr><td style="text-align:center;border:none">Komisyon Üyesi<br><br><br>.............................</td><td style="text-align:center;border:none">Komisyon Üyesi<br><br><br>.............................</td><td style="text-align:center;border:none">Komisyon Başkanı<br><br><br>.............................</td></tr>
        </table>
    @endif
</div>
@endsection
