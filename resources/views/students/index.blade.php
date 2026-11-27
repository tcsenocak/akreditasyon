@extends('layouts.app')
@section('title', 'Öğrenciler')
@section('content')
<div class="page-head">
    <div><h1>Öğrenciler</h1><div class="sub">Sisteme kayıtlı öğrenciler. Bir öğrenciye tıklayınca yaptığı tüm başvurular listelenir; işlem bekleyenler için Başvurular sayfasını kullanın.</div></div>
    <div class="row">
        <form class="row" method="GET">
            <select name="durum"><option value="">Tüm durumlar</option>@foreach (\App\Models\Student::APPLICATION_STATUSES as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach</select>
            <input name="q" value="{{ $search }}" placeholder="Ad, soyad, numara"><button class="light">Süz</button>
        </form>
        <a class="btn" href="{{ route('students.create') }}">+ Yeni öğrenci</a>
    </div>
</div>
<div class="card table-wrap">
    <table>
        <tr><th>No</th><th>Ad Soyad</th><th>Hesap</th><th>Son başvuru</th><th>Geliş</th><th>Önceki kurum</th><th>AÜ programı</th><th class="num">Başvuru</th></tr>
        @forelse ($students as $student)
            <tr>
                <td>{{ $student->student_no }}</td>
                <td><a href="{{ route('students.show', $student) }}">{{ $student->full_name }}</a></td>
                <td>@if (! $student->user)<span class="badge bad">hesap yok</span>@elseif ($student->user->must_change_password)<span class="badge warn">etkinleştirilmedi</span>@else<span class="badge ok">etkin</span>@endif</td>
                <td><span class="badge {{ $student->application_status === 'gonderildi' ? 'warn' : ($student->application_status === 'sonuclandi' ? 'ok' : '') }}">{{ $student->application_status === 'taslak' && ! $student->submitted_at ? 'Başvuru yok' : (\App\Models\Student::APPLICATION_STATUSES[$student->application_status] ?? $student->application_status) }}</span>
                    @if ($student->submitted_at)<div class="small muted">{{ $student->submitted_at->format('d.m.Y') }}</div>@endif
                    @if ($student->submitted_at && in_array($student->application_status, ['gonderildi', 'inceleniyor'], true))
                        @php
                            $days = (int) $student->submitted_at->diffInDays(now());
                        @endphp
                        <span class="badge {{ $days >= 14 ? 'bad' : ($days >= 7 ? 'warn' : '') }}">{{ $days }} gündür bekliyor</span>
                    @endif</td>
                <td>{{ \App\Models\Student::ADMISSION_TYPES[$student->admission_type] ?? $student->admission_type }}</td>
                <td>{{ $student->previous_institution }}<div class="small muted">{{ $student->previous_program }}</div></td>
                <td>{{ $student->program?->full_name ?? '—' }}</td>
                <td class="num">{{ $student->submitted_applications_count }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Kayıt yok.</td></tr>
        @endforelse
    </table>
    {{ $students->links() }}
</div>
@endsection
