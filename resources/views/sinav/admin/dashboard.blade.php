@extends('sinav.admin.layout')
@section('title', 'Genel Bakış')
@section('sinav-content')
@php
  $U = \App\Services\Sinav\Util::class;
  $u = auth(config('sinav.guard'))->user();
  $name = $u ? (string) ($u->name ?? '') : '';
@endphp
<div class="page-head">
  <div>
    <h1>Merhaba{{ $name !== '' ? ', ' . $name : '' }}</h1>
    <p class="sub">Sınav Cevap Anahtarı Sistemi</p>
  </div>
  <a class="btn" href="{{ route('sinav.admin.exams.create') }}">@include('sinav._icon', ['i' => 'plus']) Yeni sınav</a>
</div>

  <div class="stats">
    <div class="stat"><span class="icon tone-indigo">@include('sinav._icon', ['i' => 'users'])</span><div><div class="label">Öğrenci</div><div class="value">{{ $stats['students'] }}</div></div></div>
    <div class="stat"><span class="icon tone-sky">@include('sinav._icon', ['i' => 'book'])</span><div><div class="label">Ders</div><div class="value">{{ $stats['courses'] }}</div></div></div>
    <div class="stat"><span class="icon tone-violet">@include('sinav._icon', ['i' => 'clipboard'])</span><div><div class="label">Sınav</div><div class="value">{{ $stats['exams'] }}</div></div></div>
    <div class="stat"><span class="icon tone-emerald">@include('sinav._icon', ['i' => 'send'])</span><div><div class="label">Yayında</div><div class="value">{{ $stats['releases']['live'] }}</div></div></div>
  </div>

  @if ($groupsWithoutPin->isNotEmpty())
    <div class="alert warn">
      <strong>{{ $groupsWithoutPin->count() }} grubun PIN'i belirlenmedi:</strong>
      {{ $groupsWithoutPin->map(fn ($g) => optional($g->course)->code.' '.$g->name)->implode(', ') }}.
      Bu gruplardaki öğrenciler cevap anahtarlarını göremez. <a href="{{ route('sinav.admin.groups') }}">Gruplar</a> sayfasından PIN belirleyin.
    </div>
  @endif

  <div class="card">
    <div class="card-head"><h2>Cevap Anahtarı Yayınları</h2><p>Yayın durumu</p></div>
    <div class="row between">
      <div class="row">
        <span class="badge green">{{ $stats['releases']['live'] }} yayında</span>
        <span class="badge amber">{{ $stats['releases']['scheduled'] }} planlandı</span>
        <span class="badge">{{ $stats['releases']['draft'] }} onay bekliyor</span>
        @if ($stats['releases']['halted'])<span class="badge red">{{ $stats['releases']['halted'] }} durduruldu</span>@endif
      </div>
      <a class="btn" href="{{ route('sinav.admin.publish') }}">@include('sinav._icon', ['i' => 'edit']) Yayın Takvimi</a>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Yaklaşan Yayınlar</h2><p>Yayın saati henüz gelmemiş cevap anahtarları</p></div>
    @if ($upcoming->isEmpty())
      <p class="empty">Planlanmış yayın yok.</p>
    @else
      <div class="table-wrap" style="margin:-4px -20px -18px">
        <table class="t">
          <thead><tr><th>Yayın</th><th>Sınav</th><th>Grup</th><th>Durum</th></tr></thead>
          <tbody>
          @foreach ($upcoming as $r)
            <tr>
              <td>{{ $U::fmt($r->publish_at) }}</td>
              <td>{{ optional($r->course)->code }} — {{ optional($r->exam)->title }}</td>
              <td>{{ optional($r->group)->name }}</td>
              <td><span class="badge {{ $r->statusTone() }}">{{ $r->statusLabel() }}</span></td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
@endsection
