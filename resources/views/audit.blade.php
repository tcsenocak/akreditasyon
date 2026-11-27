@extends('layouts.app')
@section('title', 'İşlem geçmişi')
@section('content')
<div class="page-head">
    <div><h1>İşlem geçmişi</h1><div class="sub">Başvuru, karar, atama, itiraz, silme ve kullanıcı işlemleri; kim, ne zaman yaptı.</div></div>
    <form class="row" method="GET">
        <select name="islem" onchange="this.form.submit()" aria-label="İşlem türü"><option value="">Tüm işlemler</option>@foreach (\App\Models\AuditLog::ACTIONS as $k => $v)<option value="{{ $k }}" @selected($action === $k)>{{ $v }}</option>@endforeach</select>
    </form>
</div>
<div class="card table-wrap">
    <table>
        <tr><th>Zaman</th><th>Kim</th><th>İşlem</th><th>Öğrenci</th><th>Açıklama</th></tr>
        @forelse ($logs as $log)
            <tr>
                <td class="small">{{ $log->created_at->format('d.m.Y H:i') }}</td>
                <td class="small">{{ $log->user ? ($log->user->isStudent() ? 'Öğrenci' : $log->user->display_name) : 'Sistem' }}</td>
                <td><span class="badge">{{ \App\Models\AuditLog::ACTIONS[$log->action] ?? $log->action }}</span></td>
                <td class="small">@if ($log->student)<a href="{{ route('students.show', $log->student) }}">{{ $log->student->full_name }}</a>@else — @endif</td>
                <td class="small">{{ $log->description }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Kayıt yok.</td></tr>
        @endforelse
    </table>
    {{ $logs->links() }}
</div>
@endsection
