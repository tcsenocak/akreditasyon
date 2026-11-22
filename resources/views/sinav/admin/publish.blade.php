@extends('sinav.admin.layout')
@section('title', 'Yayın Takvimi')
@section('sinav-content')
@php $U = \App\Services\Sinav\Util::class; @endphp
<h1>Yayın Takvimi</h1>
<p class="muted small">Cevap anahtarı ancak siz kontrol edip onayladıktan sonra ve yayın zamanı gelince öğrencilere açılır. Zaman denetimini sunucunun saati yapar; öğrencinin cihaz saatini değiştirmesi sonucu etkilemez.</p>
@if ($releases->isEmpty())
  <div class="alert info">Yayınlanacak sınav yok. Önce <a href="{{ route('sinav.admin.exams') }}">Sınavlar</a> bölümünden sınav oluşturun.</div>
@endif
@foreach ($releases as $r)
  @php
    $e = $exams->firstWhere('id', $r->exam_id);
    $c = $courses->firstWhere('id', $r->course_id);
    $g = $groups->firstWhere('id', $r->group_id);
    $st = $r->status();
    $pending = $e && $r->hasPendingChange($e);
  @endphp
  <div class="card">
    <div class="row between">
      <div>
        <h2>{{ $c ? $c->code : '?' }} — {{ $e ? $e->title : '?' }} · {{ $g ? $g->name : '?' }} grubu <span class="badge {{ $r->statusTone() }}">{{ $r->statusLabel() }}</span></h2>
        <p class="muted small">Sınav bitişi: {{ $U::fmt($r->exam_ends_at) }} · Yayın: {{ $U::fmt($r->publish_at) }}@if ($r->publish_until) – {{ $U::fmt($r->publish_until) }}@endif @if ($r->version > 0) · Sürüm {{ $r->version }}@endif</p>
        @if ($pending)
          <p class="small" style="color:#b45309;font-weight:600">Taslakta onaylanmamış değişiklik var. Öğrenciler hâlâ onaylı sürüm {{ $r->version }}'i görüyor.</p>
        @endif
      </div>
      @if ($e)
        <div class="row">
          @if (in_array($st, ['live', 'scheduled'], true))
            <form class="inline" method="post" action="{{ route('sinav.admin.releases.halt', $r) }}">@csrf<button class="btn danger sm">Yayından kaldır</button></form>
          @endif
          @if ($st === 'scheduled' && !$pending)
            <form class="inline" method="post" action="{{ route('sinav.admin.releases.unapprove', $r) }}">@csrf<button class="btn secondary sm">Onayı geri al</button></form>
          @endif
          @if (in_array($st, ['draft', 'halted'], true) || $pending)
            <a class="btn sm" href="{{ route('sinav.admin.releases.review', $r) }}">{{ $st === 'halted' ? 'Kontrol et ve yeniden öğrenciye aç' : ($pending ? 'Değişikliği kontrol et ve öğrenciye aç' : 'Kontrol et ve öğrenciye aç') }}</a>
          @endif
        </div>
      @endif
    </div>
  </div>
@endforeach
@endsection
