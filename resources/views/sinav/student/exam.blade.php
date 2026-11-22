@extends('sinav.student.layout', ['signedIn' => true])
@section('content')
@php $U = \App\Services\Sinav\Util::class; @endphp
<p><a href="{{ route('sinav.home') }}">‹ Derslerime dön</a></p>
<div class="st-box protect" id="exam-box">
  <p class="small muted" style="margin:0">{{ optional($release->course)->code }} — {{ optional($release->course)->name }}</p>
  <h1 style="margin-bottom:2px">{{ optional($release->exam)->title }}</h1>
  <p class="small muted">{{ optional($release->exam)->typeLabel() }} · Cevap anahtarı</p>

  @if (!$readable)
    <p role="status" class="alert warn">Cevap anahtarı şu anda yayında değil.</p>
  @else
    @if ($release->version > 1)
      <p role="status" class="alert warn">Cevap anahtarı {{ $release->content_updated_at ? $U::fmt($release->content_updated_at) . ' tarihinde ' : '' }}güncellenmiştir (sürüm {{ $release->version }}).</p>
    @endif
    @if ($files->isEmpty())<p>Bu sınav için yayınlanmış içerik yok.</p>@endif
    @foreach ($files as $f)
      <figure class="viewer" style="margin:0 0 16px" data-protected-file="{{ route('sinav.file', $f->id) }}" data-mime="{{ $f->mime }}" data-name="{{ $f->name }}">
        <figcaption class="small" style="margin-bottom:6px"><strong>{{ $f->kindLabel() }}</strong> · {{ $f->name }} · {{ $U::formatSize($f->size) }}</figcaption>
        <p role="status" class="small" data-state>Dosya yükleniyor…</p>
        <div data-pages></div>
      </figure>
    @endforeach
  @endif
</div>
@endsection
@push('scripts')
<div id="sinav-viewer-config" hidden
  data-watermark="{{ $watermark }}"
  data-pdfjs="{{ asset('vendor/sinav/vendor/pdfjs/pdf.min.js') }}"
  data-worker="{{ asset('vendor/sinav/vendor/pdfjs/pdf.worker.min.js') }}"
  data-wasm="{{ asset('vendor/sinav/vendor/pdfjs/wasm') }}/"
  data-fonts="{{ asset('vendor/sinav/vendor/pdfjs/standard_fonts') }}/"></div>
<script type="module" src="{{ asset('vendor/sinav/viewer.js') }}?v=2"></script>
@endpush
