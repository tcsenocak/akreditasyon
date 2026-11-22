@extends('layouts.app')
@section('title', 'Öğrenciler')
@section('content')
<div class="page-head">
    <div><h1>Öğrenciler ve başvurular</h1></div>
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
        <tr><th>No</th><th>Ad Soyad</th><th>Başvuru</th><th>Geliş</th><th>Önceki kurum</th><th>AÜ programı</th><th class="num">Ders</th><th class="num">Belge</th><th class="num">Dosya</th></tr>
        @forelse ($students as $student)
            <tr>
                <td>{{ $student->student_no }}</td>
                <td><a href="{{ route('students.show', $student) }}">{{ $student->full_name }}</a></td>
                <td><span class="badge {{ $student->application_status === 'gonderildi' ? 'warn' : ($student->application_status === 'sonuclandi' ? 'ok' : '') }}">{{ \App\Models\Student::APPLICATION_STATUSES[$student->application_status] ?? $student->application_status }}</span>
                    @if ($student->submitted_at)<div class="small muted">{{ $student->submitted_at->format('d.m.Y') }}</div>@endif</td>
                <td>{{ \App\Models\Student::ADMISSION_TYPES[$student->admission_type] ?? $student->admission_type }}</td>
                <td>{{ $student->previous_institution }}<div class="small muted">{{ $student->previous_program }}</div></td>
                <td>{{ $student->program?->full_name ?? '—' }}</td>
                <td class="num">{{ $student->transcript_count }}</td>
                <td class="num">{{ $student->documents_count }}</td>
                <td class="num">{{ $student->exemption_files_count }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">Kayıt yok.</td></tr>
        @endforelse
    </table>
    {{ $students->links() }}
</div>
@endsection
