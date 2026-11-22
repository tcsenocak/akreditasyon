{{--
  Yönetim sayfalarının ortak çerçevesi.
  - Varsayılan: sistemin kendi düzeni (sinav.admin.standalone): lacivert kenar menü + üst çubuk.
  - config('sinav.host_layout') doluysa: o uygulamanın düzeni (ör. Ders Programı'nın "layouts.app")
    kullanılır; sınav sayfası onun config('sinav.host_section') bölümüne basılır.
  Sayfalar içeriklerini "sinav-content" bölümüne, betiklerini "sinav-scripts" yığınına koyar.
--}}
@extends(config('sinav.host_layout') ?: 'sinav.admin.standalone')


@section(config('sinav.host_section', 'content'))
<div class="sn{{ config('sinav.host_layout') ? ' sn-embed' : '' }}" id="sinav-root">
  @if (config('sinav.host_layout'))
    <link rel="stylesheet" href="{{ asset('vendor/sinav/sinav.css') }}?v=5">
  @endif
  <div class="sn-content" id="icerik">
    @if (session('success'))<div class="alert success" role="status">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert error" role="alert">{{ session('error') }}</div>@endif
    @yield('sinav-content')
  </div>
  <script>
    window.SINAV = {
      csrf: @json(csrf_token()),
      chunkUrl: @json(route('sinav.admin.chunk')),
      chunkBytes: {{ (int) config('sinav.chunk_bytes') }},
      assets: @json(asset('vendor/sinav')),
      compressAbove: {{ (int) config('sinav.compress_above', 5242880) }},
      maxOriginal: {{ (int) config('sinav.max_original', 314572800) }},
      maxExamFile: {{ (int) config('sinav.max_exam_file') }},
      maxAccFile: {{ (int) config('sinav.max_acc_file') }},
      maxAccOutput: {{ (int) config('sinav.max_acc_output') }},
    };
  </script>
  <script src="{{ asset('vendor/sinav/compress.js') }}?v=5"></script>
  <script src="{{ asset('vendor/sinav/admin.js') }}?v=4"></script>
  @stack('sinav-scripts')
</div>
@endsection
