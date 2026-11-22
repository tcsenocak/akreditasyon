@extends('sinav.admin.layout')
@section('title', 'İşlem Geçmişi')
@section('sinav-content')
<h1>İşlem Geçmişi</h1>
<p class="muted small">Son 100 yönetici işlemi. Kayıtlarda öğrenci numarası veya adı bulunmaz.</p>
@if ($logs->isEmpty())
  <div class="alert info">Henüz kayıt yok.</div>
@else
<div class="card table-wrap" style="padding:0">
  <table class="t">
    <thead><tr><th>Zaman</th><th>İşlem</th><th>İlgili kayıt</th><th>Yönetici</th><th>Sonuç</th></tr></thead>
    <tbody>
    @foreach ($logs as $l)
      <tr>
        <td style="white-space:nowrap">{{ \App\Services\Sinav\Util::fmt($l->created_at) }}</td>
        <td>{{ $l->label() }}@if ($l->note) <span class="muted">({{ $l->note }})</span>@endif</td>
        <td class="mono small">{{ $l->object_type }} #{{ $l->object_id }}</td>
        <td class="small">{{ $l->admin }}</td>
        <td>@if ($l->result === 'ok')<span class="badge green">Başarılı</span>@else<span class="badge red">Hata</span>@endif</td>
      </tr>
    @endforeach
    </tbody>
  </table>
</div>
@endif
@endsection
