@extends('sinav.admin.layout')
@section('title', 'Ders Dosyası')
@section('sinav-content')
@php $U = \App\Services\Sinav\Util::class; @endphp
<h1>Ders Dosyası (Akreditasyon)</h1>
<p class="muted small" style="max-width:860px">Her bölüme PDF, görsel (PNG/JPG) veya Excel (.xlsx) yükleyin; en altta tek PDF olarak birleştirip indirin. {{ round(config('sinav.compress_above') / 1048576) }} MB'tan büyük PDF ve görseller yüklenmeden önce kendiliğinden sıkıştırılır. Bu dosyalar yalnızca yöneticiye açıktır, öğrenciler göremez. İcmal ve program çıktısı raporu öğrenci adı ve notu içerdiği için akreditasyon arşivi olarak saklanır; kurumunuzun saklama süresine uyun.</p>
<div class="grid2">
  <form method="get" class="field">
    <label for="ders-sec">Ders</label>
    <select id="ders-sec" name="ders" class="input" onchange="this.form.submit()">
      @forelse ($courses as $c)<option value="{{ $c->id }}" @if ($course && $course->id === $c->id) selected @endif>{{ $c->label() }}</option>
      @empty<option value="">Ders yok</option>@endforelse
    </select>
  </form>
</div>

@if ($course)
<div class="progress hidden" role="status" id="acc-progress"></div>
<div class="alert error hidden" role="alert" id="acc-error"></div>

@foreach ($sections as $i => $s)
  @php
    $own = $files->where('section_id', $s->id)->values();
    $def = $s->definition();
    $examType = $s->examType();
  @endphp
  <div class="card">
    <div class="row between">
      <div>
        <h2>{{ $i + 1 }}. {{ $s->title }} @if ($own->isEmpty())<span class="badge amber">Boş</span>@else<span class="badge green">{{ $own->count() }} dosya</span>@endif</h2>
        @if ($def)<p class="muted small">{{ $def['hint'] }}</p>@endif
      </div>
      <div class="row">
        <form class="inline" method="post" action="{{ route('sinav.admin.acc.section.move', $s) }}">@csrf<input type="hidden" name="dir" value="-1"><button class="btn secondary sm" aria-label="{{ $s->title }} bölümünü yukarı taşı" @if ($i === 0) disabled @endif>↑</button></form>
        <form class="inline" method="post" action="{{ route('sinav.admin.acc.section.move', $s) }}">@csrf<input type="hidden" name="dir" value="1"><button class="btn secondary sm" aria-label="{{ $s->title }} bölümünü aşağı taşı" @if ($i === $sections->count() - 1) disabled @endif>↓</button></form>
        @if ($s->kind === 'custom')
          <form class="inline" method="post" action="{{ route('sinav.admin.acc.section.destroy', $s) }}" onsubmit="return confirm('{{ addslashes($s->title) }} bölümü ve içindeki {{ $own->count() }} dosya silinsin mi?')">@csrf<button class="btn danger sm">Bölümü sil</button></form>
        @endif
      </div>
    </div>

    @if ($own->isNotEmpty())
      <ul class="list" style="margin:8px 0">
        @foreach ($own as $j => $f)
          <li>
            <div class="row between">
              <span class="small"><strong>{{ $f->name }}</strong> · {{ $U::formatSize($f->size) }}
                @if ($f->source === 'xlsx')<span class="badge">Excel → tablo</span>@endif
                @if ($f->source === 'exam')<span class="badge">Sınavdan</span>@endif</span>
              <span class="row">
                <form class="inline" method="post" action="{{ route('sinav.admin.acc.file.move', $f) }}">@csrf<input type="hidden" name="dir" value="-1"><button class="btn secondary sm" aria-label="{{ $f->name }} yukarı" @if ($j === 0) disabled @endif>↑</button></form>
                <form class="inline" method="post" action="{{ route('sinav.admin.acc.file.move', $f) }}">@csrf<input type="hidden" name="dir" value="1"><button class="btn secondary sm" aria-label="{{ $f->name }} aşağı" @if ($j === $own->count() - 1) disabled @endif>↓</button></form>
                <button type="button" class="btn secondary sm" data-preview="{{ route('sinav.admin.acc.file', $f) }}" data-mime="{{ $f->mime }}">Önizle</button>
                <form class="inline" method="post" action="{{ route('sinav.admin.acc.file.destroy', $f) }}" onsubmit="return confirm('{{ addslashes($f->name) }} silinsin mi?')">@csrf<button class="btn danger sm">Sil</button></form>
              </span>
            </div>
            <div class="viewer" data-preview-host></div>
          </li>
        @endforeach
      </ul>
    @endif

    <div class="row" style="align-items:flex-end;gap:14px">
      <div class="field" style="margin:0">
        <label for="up{{ $s->id }}">Dosya ekle (PDF, PNG, JPG, XLSX)</label>
        <input id="up{{ $s->id }}" type="file" multiple accept="application/pdf,image/png,image/jpeg,.xlsx"
          data-acc-upload="{{ route('sinav.admin.acc.upload', $s) }}" data-section-title="{{ $s->title }}">
      </div>
    </div>
    @if ($examType)
      <details class="box">
        <summary>Sınavlardan al</summary>
        <p class="small" style="margin-top:8px">Bu dersin bu türdeki sınavlarına yüklediğiniz dosyalar. Seçtiğiniz dosya bu bölüme kopyalanır.</p>
        @forelse ($examFiles[$examType] ?? [] as $x)
          <form method="post" action="{{ route('sinav.admin.acc.fromExam', $s) }}" class="row between" style="margin-bottom:6px">
            @csrf
            <input type="hidden" name="exam_file_id" value="{{ $x['file']->id }}">
            <span class="small"><strong>{{ $x['exam']->title }}</strong> · {{ $x['file']->name }} · {{ $U::formatSize($x['file']->size) }}</span>
            <button class="btn sm">Ekle</button>
          </form>
        @empty
          <p class="small muted">Bu türde sınava yüklenmiş dosya yok.</p>
        @endforelse
      </details>
    @endif
    <details class="box">
      <summary>Adı değiştir</summary>
      <form method="post" action="{{ route('sinav.admin.acc.section.rename', $s) }}" class="row" style="margin-top:8px">
        @csrf
        <input name="title" class="input" style="max-width:360px" maxlength="120" value="{{ $s->title }}" aria-label="Bölüm adı">
        <button class="btn sm">Kaydet</button>
      </form>
    </details>
  </div>
@endforeach

<div class="card">
  <h2>Yeni bölüm ekle</h2>
  <form method="post" action="{{ route('sinav.admin.acc.section.store', $course) }}" class="row">
    @csrf
    <label for="yeni-bolum" class="label" style="margin:0">Bölüm adı</label>
    <input id="yeni-bolum" name="title" class="input" style="max-width:320px" maxlength="120" placeholder="Örn. Ödevler, Proje, Laboratuvar Föyleri">
    <button class="btn">Ekle</button>
  </form>
</div>

@php
  $build = [
    'sections' => $sections->map(function ($s) use ($files) {
        return ['title' => $s->title, 'files' => $files->where('section_id', $s->id)->values()->map(function ($f) {
            return ['name' => $f->name, 'mime' => $f->mime, 'url' => route('sinav.admin.acc.file', $f)];
        })->all()];
    })->values()->all(),
    'cover' => array_values(array_filter([$course->code . ' — ' . $course->name, $course->instructor ? 'Öğretim Elemanı: ' . $course->instructor : '', 'Ders Dosyası'])),
    'fileName' => $U::safeFileName($course->code . '_' . $course->name) . '_Ders_Dosyasi.pdf',
    'saveUrl' => route('sinav.admin.acc.output.store', $course),
    'xlsxUrl' => route('sinav.admin.acc.xlsxRows'),
  ];
@endphp
<div class="card">
  <h2>Tek PDF oluştur</h2>
  <p class="muted small">Bölümler yukarıdaki sırayla, dosyalar bölüm içindeki sırayla birleştirilir. Boş bölümler atlanır. Toplam {{ $files->count() }} dosya, {{ $U::formatSize($files->sum('size')) }}.</p>
  <p><label class="check"><input type="checkbox" id="acc-cover"> Kapak sayfası ekle (ders kodu, adı, öğretim elemanı)</label></p>
  <p><label class="check"><input type="checkbox" id="acc-titles"> Her bölümün başına bölüm adı sayfası ekle</label></p>
  <button type="button" class="btn" id="acc-build" @if ($files->isEmpty()) disabled @endif>PDF oluştur, indir ve sakla</button>
</div>

<div class="card">
  <h2>Saklanan ders dosyaları</h2>
  @if ($outputs->isEmpty())<p class="muted small">Henüz oluşturulmuş PDF yok.</p>@endif
  @foreach ($outputs as $o)
    <div class="row between box">
      <span class="small"><strong>{{ $o->name }}</strong> · {{ $o->page_count }} sayfa · {{ $U::formatSize($o->size) }} · {{ $U::fmt($o->created_at) }}</span>
      <span class="row">
        <a class="btn secondary sm" href="{{ route('sinav.admin.acc.output', $o) }}">İndir</a>
        <form class="inline" method="post" action="{{ route('sinav.admin.acc.output.destroy', $o) }}" onsubmit="return confirm('{{ addslashes($o->name) }} sistemden silinsin mi? Bilgisayarınıza indirdiğiniz kopya etkilenmez.')">@csrf<button class="btn danger sm">Sil</button></form>
      </span>
    </div>
  @endforeach
</div>
<script type="application/json" id="acc-data">{!! json_encode($build, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endif
@endsection
@push('sinav-scripts')
<script src="{{ asset('vendor/sinav/vendor/pdf-lib.min.js') }}"></script>
<script src="{{ asset('vendor/sinav/vendor/fontkit.umd.min.js') }}"></script>
<script src="{{ asset('vendor/sinav/acc.js') }}?v=2"></script>
@endpush
