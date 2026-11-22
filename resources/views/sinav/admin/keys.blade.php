@extends('sinav.admin.layout')
@section('title', 'Cevap Anahtarları')
@section('sinav-content')
@php $U = \App\Services\Sinav\Util::class; @endphp
<h1>Cevap Anahtarları</h1>
<form method="get" class="field" style="max-width:620px">
  <label for="sinav-sec">Sınav</label>
  <select id="sinav-sec" name="sinav" class="input" onchange="this.form.submit()">
    <option value="">Sınav seçin…</option>
    @foreach ($exams as $e)
      @php $c = $courses->firstWhere('id', $e->course_id); @endphp
      <option value="{{ $e->id }}" @if ($exam && $exam->id === $e->id) selected @endif>{{ $c ? $c->label() : '?' }} — {{ $e->title }}</option>
    @endforeach
  </select>
  <noscript><button class="btn secondary sm">Göster</button></noscript>
</form>
@if ($exams->isEmpty())
  <div class="alert info">Henüz sınav yok. Önce <a href="{{ route('sinav.admin.exams') }}">Sınavlar</a> bölümünden bir sınav oluşturun.</div>
@endif

@if ($exam)
  <div class="card">
    <h2>Dosyalar (PDF, PNG, JPG)</h2>
    <p class="muted small">{{ round(config('sinav.compress_above') / 1048576) }} MB'tan büyük PDF ve görseller yüklenmeden önce bu bilgisayarda kendiliğinden sıkıştırılır; sıkıştırılmış hali en fazla {{ round(config('sinav.max_exam_file') / 1048576) }} MB olabilir. Yüklenen dosya, siz "Yayın Takvimi"nden onaylayıp yayın zamanı gelene kadar öğrencilere görünmez.</p>
    @if ($term->editable())
      <div class="box" data-exam-upload="{{ route('sinav.admin.files.upload', $exam) }}">
        <div class="row" style="align-items:flex-end;gap:14px">
          <div class="field" style="margin:0"><label for="k-kind">Dosya türü</label>
            <select id="k-kind" class="input" data-kind>
              @foreach (\App\Models\Sinav\ExamFile::KIND_LABEL as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
            </select></div>
          <div class="field" style="margin:0"><label for="k-file">Dosya seçin</label>
            <input id="k-file" type="file" accept="application/pdf,image/png,image/jpeg" multiple data-file></div>
        </div>
        <div class="progress hidden" role="status" data-progress></div>
        <div class="alert error hidden" role="alert" data-error></div>
      </div>
    @endif

    @if ($files->isEmpty())<p class="small">Henüz dosya yüklenmedi.</p>@endif
    @foreach ($files as $f)
      @php $sel = $f->groupIds() ?: $examGroupIds; @endphp
      <div class="box">
        <div class="row between">
          <span><span class="badge {{ $f->kind === 'key' ? 'green' : '' }}">{{ $f->kindLabel() }}</span> <strong>{{ $f->name }}</strong> <span class="muted small">· {{ $U::formatSize($f->size) }}</span></span>
          <span class="row">
            <button type="button" class="btn secondary sm" data-preview="{{ route('sinav.admin.files.show', $f) }}" data-mime="{{ $f->mime }}">Önizle</button>
            <a class="btn secondary sm" href="{{ route('sinav.admin.files.show', $f) }}" target="_blank" rel="noopener">Tam ekran aç</a>
            @if ($term->editable())
              <form class="inline" method="post" action="{{ route('sinav.admin.files.destroy', $f) }}"
                onsubmit="return confirm('{{ addslashes($f->name) }} silinsin mi? Daha önce onaylanmış bir yayında bu dosya varsa, öğrenciler onu bir sonraki onaya kadar görmeye devam eder; kaldırmak için yayını yeniden onaylayın veya yayından kaldırın.')">@csrf<button class="btn danger sm">Sil</button></form>
            @endif
          </span>
        </div>
        @if (count($examGroupIds) > 1)
          <form method="post" action="{{ route('sinav.admin.files.groups', $f) }}" class="row" style="margin-top:8px">
            @csrf
            <span class="small">Görecek gruplar:</span>
            @foreach ($examGroupIds as $gid)
              @php $g = $groups->firstWhere('id', $gid); @endphp
              <label class="check"><input type="checkbox" name="group_ids[]" value="{{ $gid }}" @if (in_array($gid, $sel, true)) checked @endif @if (!$term->editable()) disabled @endif> {{ $g ? $g->name : '?' }}</label>
            @endforeach
            @if ($term->editable())<button class="btn secondary sm">Kaydet</button>@endif
          </form>
        @endif
        <div class="viewer" data-preview-host></div>
      </div>
    @endforeach
  </div>

  <div class="card">
    <h2>Onaylanmış sürümler</h2>
    @if ($versions->isEmpty())<p class="muted small">Henüz onaylanmış sürüm yok.</p>@endif
    <ul class="small" style="padding-left:18px">
      @foreach ($versions as $v)
        @php $g = $groups->firstWhere('id', $v->group_id); @endphp
        <li><strong>{{ $g ? $g->name : '?' }} grubu — Sürüm {{ $v->version }}</strong> · {{ $U::fmt($v->created_at) }}@if ($v->note) · {{ $v->note }}@endif
          <span class="muted">@if ($v->files) · Dosyalar: {{ implode(', ', array_column($v->files, 'name')) }}@endif</span></li>
      @endforeach
    </ul>
  </div>
@endif
@endsection
