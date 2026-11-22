@extends('layouts.app')
@section('title', 'Muafiyet dosyaları')
@section('content')
<div class="page-head"><div><h1>Muafiyet dosyaları</h1></div><a class="btn" href="{{ route('students.create') }}">+ Yeni öğrenci</a></div>
<div class="card table-wrap">
    <table>
        <tr><th>#</th><th>Öğrenci</th><th>Program</th><th class="num">Kabul</th><th class="num">Muaf AKTS</th><th class="num">Yarıyıl</th><th>Durum</th><th>Karar</th><th>Güncelleme</th></tr>
        @forelse ($files as $file)
            <tr>
                <td><a href="{{ route('files.show', $file) }}">{{ $file->id }}</a></td>
                <td><a href="{{ route('files.show', $file) }}">{{ $file->student->full_name }}</a> <span class="small muted">{{ $file->student->student_no }}</span></td>
                <td>{{ $file->curriculum->program->full_name }}</td>
                <td class="num">{{ $file->accepted_items_count }}</td>
                <td class="num">{{ (float) $file->exempt_ects }}</td>
                <td class="num">{{ $file->effective_semester }}</td>
                <td><span class="badge {{ $file->status === 'karar' ? 'ok' : 'warn' }}">{{ \App\Models\ExemptionFile::STATUSES[$file->status] }}</span></td>
                <td class="small">{{ $file->decision_no }} {{ $file->decision_date?->format('d.m.Y') }}</td>
                <td class="small muted">{{ $file->updated_at->format('d.m.Y H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">Dosya yok. Öğrenci sayfasından muafiyet dosyası açın.</td></tr>
        @endforelse
    </table>
    {{ $files->links() }}
</div>
@endsection
