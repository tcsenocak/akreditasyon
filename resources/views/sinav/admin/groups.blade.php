@extends('sinav.admin.layout')
@section('title', 'Gruplar ve PIN')
@section('sinav-content')
@php $isCommission = \App\Services\Sinav\Access::isCommission(); @endphp
<div class="page-head">
  <div>
    <h1>Gruplar ve PIN</h1>
    <p class="sub">Öğrenci, cevap anahtarlarını öğrenci numarası ve grubunun PIN'iyle görür. PIN'i belirlenmemiş gruptaki öğrenciler hiçbir şey göremez.</p>
  </div>
</div>
@if ($courses->isEmpty())
  <div class="alert info">
    @if ($isCommission)
      Henüz ders yok. Gruplar, <a href="{{ route('sinav.admin.exams.create') }}">yeni sınav</a> oluştururken yüklenen öğrenci listesinden tanımlanır.
    @else
      Henüz dersiniz yok. <a href="{{ route('sinav.admin.exams.create') }}">Yeni sınav</a> oluştururken öğrenci listenizi yükleyin.
    @endif
  </div>
@endif
@foreach ($courses as $c)
  <div class="card">
    <div class="card-head"><h2>{{ $c->code }} — {{ $c->name }}</h2><p>{{ $c->groups->count() }} grup</p></div>
    @if ($c->groups->isEmpty())
      <p class="muted small">Bu derste grup yok. Öğrenci ekleyebilmek ve sınav tanımlayabilmek için en az bir grup gerekir.</p>
    @else
      <ul class="list" style="margin-bottom:10px">
        @foreach ($c->groups as $g)
          <li>
            <div class="row between">
              <span>
                <strong>{{ $g->name }} grubu</strong> <span class="muted small">· {{ $g->enrollments_count }} öğrenci</span>
                @if ($g->hasPin())
                  <span class="badge green">PIN var</span>
                @else
                  <span class="badge amber">PIN yok — öğrenciler göremez</span>
                @endif
              </span>
              @if ($isCommission)
                <form class="inline" method="post" action="{{ route('sinav.admin.groups.destroy', $g) }}" onsubmit="return confirm('{{ $g->name }} grubu silinsin mi?')">@csrf<button class="btn danger sm">Grubu sil</button></form>
              @endif
            </div>
            @if ($g->hasPin())
              <details class="box" style="margin:8px 0 0">
                <summary>PIN'i göster</summary>
                <p style="margin:8px 0 0">PIN: <strong class="mono" style="font-size:1.1rem;letter-spacing:.12em">{{ $g->pin() }}</strong>
                  <span class="muted small">· {{ \App\Services\Sinav\Util::fmt($g->pin_updated_at) }} tarihinde belirlendi</span></p>
              </details>
            @endif
            @if ($term->editable())
              <form method="post" action="{{ route('sinav.admin.groups.pin', $g) }}" class="row" style="margin-top:8px">
                @csrf
                <input name="pin" class="input mono" style="max-width:150px" inputmode="numeric" autocomplete="off" maxlength="8" placeholder="6-8 rakam" aria-label="{{ $c->code }} {{ $g->name }} grubu için yeni PIN">
                <button class="btn sm">{{ $g->hasPin() ? 'PIN\'i değiştir' : 'PIN belirle' }}</button>
                <span class="small muted">Boş bırakırsanız 6 haneli rastgele PIN üretilir.@if ($g->hasPin()) Değiştirince eski PIN'le girmiş öğrenciler bu dersi yeniden PIN girene kadar göremez.@endif</span>
              </form>
            @endif
          </li>
        @endforeach
      </ul>
    @endif
  </div>
@endforeach
@endsection
