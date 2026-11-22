@extends('sinav.admin.layout')
@section('title', 'Sınavlar')
@section('sinav-content')
@php $U = \App\Services\Sinav\Util::class; @endphp
<div class="page-head">
  <h1>{{ \App\Services\Sinav\Access::isCommission() ? 'Sınavlar' : 'Sınavlarım' }}</h1>
  <a class="btn" href="{{ route('sinav.admin.exams.create') }}">Yeni sınav</a>
</div>
@if ($exams->isEmpty())<div class="alert info">Henüz sınav yok. "Yeni sınav" ile öğrenci listesini yükleyip ilk sınavınızı oluşturun.</div>@endif

@foreach ($exams as $e)
  @php $c = $courses->firstWhere('id', $e->course_id); @endphp
  <div class="card">
    <div class="row between">
      <h2>{{ $c ? $c->label() : '?' }} — {{ $e->title }} <span class="badge">{{ $e->typeLabel() }}</span></h2>
      @if ($term->editable())
        <div class="row">
          <a class="btn sm" href="{{ route('sinav.admin.keys', ['sinav' => $e->id]) }}">Cevap anahtarı</a>
          <a class="btn secondary sm" href="{{ route('sinav.admin.exams.edit', $e) }}">Düzenle</a>
        </div>
      @endif
    </div>
    <div class="table-wrap">
      <table class="t">
        <thead><tr><th>Grup</th><th>Sınav</th><th>Cevap anahtarı yayını</th><th>Durum</th></tr></thead>
        <tbody>
        @foreach ($releases->where('exam_id', $e->id) as $r)
          @php $g = $groups->firstWhere('id', $r->group_id); @endphp
          <tr>
            <th scope="row">{{ $g ? $g->name : '?' }}</th>
            <td>{{ $U::fmt($r->exam_starts_at) }} – {{ $U::input($r->exam_ends_at, 'time') }}</td>
            <td>{{ $U::fmt($r->publish_at) }}@if ($r->publish_until) – {{ $U::fmt($r->publish_until) }}@endif</td>
            <td><span class="badge {{ $r->statusTone() }}">{{ $r->statusLabel() }}</span></td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @if ($term->editable())
      <details class="box">
        <summary style="color:var(--danger)">Sınavı sil</summary>
        <p class="small" style="margin-top:8px"><strong>{{ $c ? $c->code : '' }} — {{ $e->title }}</strong> sınavı; yüklenen dosyalar, sürüm geçmişi ve yayın kayıtlarıyla birlikte silinecek. Öğrenciler bu sınavı artık göremez. Bu işlem geri alınamaz.</p>
        <form method="post" action="{{ route('sinav.admin.exams.destroy', $e) }}" class="row">
          @csrf
          <label class="small" for="sil{{ $e->id }}">Onay için <strong>SİL</strong> yazın</label>
          <input id="sil{{ $e->id }}" name="confirm" class="input" style="max-width:120px" autocomplete="off" data-must="SİL">
          <button class="btn danger" data-confirm-btn disabled>Sınavı sil</button>
        </form>
      </details>
    @endif
  </div>
@endforeach
@endsection
