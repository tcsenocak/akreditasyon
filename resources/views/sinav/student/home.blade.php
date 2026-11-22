@extends('sinav.student.layout', ['signedIn' => true])
@section('content')
@php $U = \App\Services\Sinav\Util::class; @endphp
<h1 style="margin-bottom:2px">Derslerim</h1>
<p class="small muted">Öğrenci no: {{ $student->student_no }}</p>
@if (count($courses) === 0)<div class="st-box"><p>Kayıtlı dersiniz bulunmuyor.</p></div>@endif
@foreach ($courses as $c)
  <section class="st-box" aria-label="{{ $c['course']->code }} {{ $c['course']->name }}">
    <h2>{{ $c['course']->name }}</h2>
    <p class="small muted">{{ $c['course']->code }} · {{ $c['group']->name }} grubu</p>
    @if ($c['releases']->isEmpty())<p class="small">Bu derste henüz sınav tanımlanmadı.</p>@endif
    <div class="stack">
      @foreach ($c['releases'] as $r)
        @php $st = $r->status(); @endphp
        @if ($st === 'live')
          <a class="st-live" href="{{ route('sinav.exam', $r->id) }}">
            <span><strong style="display:block">{{ optional($r->exam)->title }}</strong>
              <span class="small">Cevap anahtarı yayınlandı.@if ($r->publish_until) {{ $U::fmt($r->publish_until) }} tarihine kadar açık.@endif Görmek için dokunun.</span></span>
            <span aria-hidden="true" style="font-size:1.6rem">›</span>
          </a>
        @else
          <div class="st-off">
            <strong style="display:block">{{ optional($r->exam)->title }}</strong>
            <span class="small muted">
              @if ($st === 'scheduled') Cevap anahtarı {{ $U::fmt($r->publish_at) }} tarihinde yayınlanacak.
              @elseif ($st === 'draft') Cevap anahtarı henüz yayınlanmadı.
              @elseif ($st === 'halted') Cevap anahtarı şu anda yayında değil.
              @else Cevap anahtarının görüntüleme süresi doldu.
              @endif
            </span>
          </div>
        @endif
      @endforeach
    </div>
    @if ($c['docs']->isNotEmpty())
      <div style="border-top:1px solid var(--line);margin-top:12px;padding-top:10px">
        <h3>Ders evrakları</h3>
        @foreach ($c['docs'] as $d)
          <div><a href="{{ $d->url }}" target="_blank" rel="noopener noreferrer" style="display:flex;justify-content:space-between;padding:8px 4px">{{ $d->title }} <span aria-hidden="true">↗</span></a></div>
        @endforeach
      </div>
    @endif
  </section>
@endforeach
<section class="st-box" aria-label="Başka ders ekle">
  <h2 style="font-size:1rem">Başka dersiniz mi var?</h2>
  <p class="small muted">@if ($lockedCount > 0)PIN'ini girmediğiniz {{ $lockedCount }} dersiniz daha var. @endif O dersin öğretim elemanından aldığınız PIN'i girin.</p>
  @if (session('error'))<p role="alert" class="alert error">{{ session('error') }}</p>@endif
  @if (session('success'))<p role="status" class="alert success">{{ session('success') }}</p>@endif
  <form method="post" action="{{ route('sinav.pin') }}" class="row" style="flex-wrap:nowrap">
    @csrf
    <input name="pin" type="password" class="st-input" style="font-size:1rem" inputmode="numeric" autocomplete="off" maxlength="8" placeholder="Dersin PIN'i" aria-label="Başka dersin PIN'i" required>
    <button class="st-btn" style="width:auto;padding:12px 18px" data-busy-text="…">Ekle</button>
  </form>
</section>
<p style="text-align:center"><a href="{{ route('sinav.home') }}">Yenile</a></p>
@endsection
