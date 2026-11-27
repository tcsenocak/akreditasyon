@extends('layouts.app')
@section('title', 'Toplu karar listesi')
@section('content')
<div class="no-print row" style="margin-bottom:12px">
    <button onclick="window.print()"><x-icon name="print" :size="14" /> Yazdır / PDF olarak kaydet</button>
    <a class="btn light" href="{{ route('files.index') }}">← Dosyalar</a>
</div>
<div class="card petition">
    <p style="text-align:center;font-weight:700;margin:0 0 6px">T.C.<br>ATATÜRK ÜNİVERSİTESİ<br>MÜHENDİSLİK FAKÜLTESİ<br>MUAFİYET VE İNTİBAK KOMİSYONU KARAR LİSTESİ</p>
    <p style="text-align:center" class="small">{{ now()->format('d.m.Y') }} · {{ $files->count() }} öğrenci</p>
    <p>Aşağıda bilgileri bulunan öğrencilerin ders muafiyeti ve intibak talepleri komisyonumuzca Ders Muafiyeti ve İntibak İşlemleri Uygulama Esasları çerçevesinde incelenmiş; muaf sayılan dersler, harf notları ve intibak yarıyılları aşağıda gösterilmiştir. Fakülte Yönetim Kurulunun bilgi ve onayına sunulur.</p>

    @foreach ($files as $file)
        @php
            $s = $file->student;
            $rejected = $file->items->where('status', 'ret');
            $pending = $file->items->where('status', 'oneri');
        @endphp
        <div class="board-student">
            <h3 style="margin:16px 0 6px">{{ $loop->iteration }}. {{ $s->full_name }} {{ $s->student_no ? '('.$s->student_no.')' : '' }}</h3>
            <div class="small">{{ $file->curriculum->program->full_name }} · {{ \App\Models\Student::ADMISSION_TYPES[$s->admission_type] ?? '' }} · geldiği kurum: {{ $s->previous_institution ?: '—' }}{{ $file->application ? ' · '.$file->application->type_label : '' }}</div>
            @if ($pending->isNotEmpty())<div class="alert warn no-print" style="margin:6px 0">Bu dosyada karar bekleyen {{ $pending->count() }} satır var.</div>@endif
            <table class="petition-table" style="margin-top:6px">
                <tr><th>Önceki kurumda alınan ders(ler)</th><th>Muaf sayılan AÜ dersi</th><th class="num">Yy</th><th class="num">AKTS</th><th>Not</th></tr>
                @forelse ($file->acceptedItems->sortBy(fn ($i) => $i->course->semester) as $item)
                    <tr>
                        <td>{{ $item->sources->map(fn ($x) => $x->label.($x->grade_raw ? ' ('.$x->grade_raw.')' : ''))->implode(' + ') ?: (\App\Models\ExemptionItem::RULES[$item->rule] ?? '') }}</td>
                        <td>{{ $item->course->label }}</td>
                        <td class="num">{{ $item->course->semester }}</td>
                        <td class="num">{{ (float) $item->course->ects }}</td>
                        <td><b>{{ $item->letter }}</b></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Muaf sayılan ders yok.</td></tr>
                @endforelse
            </table>
            <p class="small" style="margin:6px 0 0">Muaf olunan toplam <b>{{ (float) $file->exempt_ects }} AKTS</b> · intibak yarıyılı <b>{{ $file->effective_semester }}.</b>
                @if ($rejected->isNotEmpty())<br>Kabul edilmeyen talepler: {{ $rejected->map(fn ($i) => $i->course?->label.($i->reject_reason ? ' — '.(\App\Models\ExemptionItem::REJECT_REASONS[$i->reject_reason] ?? '') : ''))->implode('; ') }}@endif</p>
        </div>
    @endforeach

    <div class="petition-sign" style="margin-top:36px">
        <div style="text-align:center">Komisyon Üyesi<br><br><br>(İmza)</div>
        <div style="text-align:center">Komisyon Üyesi<br><br><br>(İmza)</div>
        <div style="text-align:center">Komisyon Başkanı<br><br><br>(İmza)</div>
    </div>
</div>
@endsection
