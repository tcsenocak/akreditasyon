@extends('sinav.admin.layout')
@section('title', 'Yayın öncesi kontrol')
@section('sinav-content')
@php $U = \App\Services\Sinav\Util::class; @endphp
<h1>Yayın öncesi kontrol</h1>
<div class="card">
  <dl class="kv">
    <dt>Ders</dt><dd>{{ $course ? $course->label() : '?' }}</dd>
    <dt>Grup</dt><dd>{{ $group ? $group->name : '?' }}</dd>
    <dt>Sınav</dt><dd>{{ $exam->title }} ({{ $exam->typeLabel() }})</dd>
    <dt>Sınav tarihi</dt><dd>{{ $U::fmt($release->exam_starts_at) }} – {{ $U::fmt($release->exam_ends_at) }}</dd>
    <dt>Yayın tarihi</dt><dd>{{ $U::fmt($release->publish_at) }}{{ $release->publish_until ? ' – ' . $U::fmt($release->publish_until) : '' }}</dd>
    <dt>Dosyalar</dt><dd>
      @if ($check['files']->isEmpty()) Yok
      @else {{ $check['files']->map(function ($f) use ($U) { return $f->kindLabel() . ': ' . $f->name . ' (' . $U::formatSize($f->size) . ')'; })->implode(' · ') }}
      @endif</dd>
  </dl>
  @foreach ($check['errors'] as $t)<div class="alert error">{{ $t }}</div>@endforeach
  @foreach ($check['warnings'] as $t)<div class="alert warn">{{ $t }}</div>@endforeach
  @if (!$check['errors'] && !$check['warnings'])<div class="alert success">Eksik veya tutarsızlık bulunmadı.</div>@endif
  <form method="post" action="{{ route('sinav.admin.releases.approve', $release) }}">
    @csrf
    @if ($release->version > 0)
      <div class="field"><label for="not">Değişiklik notu (isteğe bağlı)</label>
        <input id="not" name="note" class="input" maxlength="500">
        <div class="hint">Sürüm geçmişine kaydedilir. Örn. 12. sorunun cevabı B yerine C olarak düzeltildi.</div></div>
    @endif
    <div class="row">
      <a class="btn secondary" href="{{ route('sinav.admin.publish') }}">Vazgeç</a>
      <button class="btn" @if ($check['errors'] || !$term->editable()) disabled @endif>Onayla ve öğrenciye aç</button>
    </div>
  </form>
</div>
@endsection
