@extends('sinav.admin.layout')
@section('title', 'Öğrenciler')
@section('sinav-content')
<div class="page-head">
  <h1>{{ \App\Services\Sinav\Access::isCommission() ? 'Sınav Öğrencileri' : 'Öğrencilerim' }}</h1>
  @if ($groups->isNotEmpty() && \App\Services\Sinav\Access::isCommission())
    <a class="btn" href="{{ route('sinav.admin.students.add', ['ders' => $courseFilter, 'grup' => $groupFilter]) }}">Öğrenci ekle</a>
  @endif
</div>
@if ($groups->isEmpty() && \App\Services\Sinav\Access::isCommission())<div class="alert info">Öğrenci ekleyebilmek için önce öğrenci listesiyle bir sınav oluşturun.</div>@endif

<form method="get" class="grid3">
  <div class="field"><label for="ders">Ders</label>
    <select id="ders" name="ders" class="input" onchange="this.form.grup.value='';this.form.submit()">
      @foreach ($courses as $c)<option value="{{ $c->id }}" @if ($courseFilter === $c->id) selected @endif>{{ $c->code }} — {{ $c->name }}</option>@endforeach
    </select></div>
  <div class="field"><label for="grup">Grup</label>
    <select id="grup" name="grup" class="input" onchange="this.form.submit()">
      <option value="">Tüm gruplar</option>
      @foreach ($groups->where('course_id', $courseFilter) as $g)<option value="{{ $g->id }}" @if ($groupFilter === $g->id) selected @endif>{{ $g->name }}</option>@endforeach
    </select></div>
  <div class="field"><label for="ara">Öğrenci no ara</label><input id="ara" type="search" name="ara" class="input" inputmode="numeric" value="{{ $search }}"></div>
  <div><button class="btn secondary">Listele</button></div>
</form>

@if ($courses->isEmpty())
  <div class="alert info">Henüz ders yok.</div>
@else
<p class="muted small" role="status">{{ count($filtered) }} öğrenci listeleniyor. <span id="secili-sayi">0</span> seçili.</p>

@if (count($filtered) === 0)
  <div class="alert info">Kayıt bulunamadı.</div>
@else
<form method="post" id="ogr-form">
  @csrf
  <input type="hidden" name="term_id" value="{{ $term->id }}">
  @if (\App\Services\Sinav\Access::isCommission())
    <div class="row" id="secim-islemleri" style="margin-bottom:10px">
      @if ($activeGroup)
        <input type="hidden" name="group_id" value="{{ $activeGroup->id }}">
        <button class="btn secondary" formaction="{{ route('sinav.admin.students.remove') }}"
          onclick="return confirm('Seçilen öğrenciler &quot;{{ $label[$activeGroup->id] }}&quot; grubundan çıkarılsın mı? Başka dersi kalmayan öğrencinin kaydı da silinir.')">Seçilenleri "{{ $label[$activeGroup->id] }}" grubundan çıkar</button>
      @endif
      <details class="box" style="margin:0">
        <summary style="color:var(--danger)">Seçilenleri tamamen sil</summary>
        <p class="small" style="margin-top:8px">Seçilen öğrenciler sınav sisteminden tamamen silinir: tüm ders üyelikleri kaldırılır. Bu işlem geri alınamaz.</p>
        <label class="small" for="sil-onay">Onay için <strong>SİL</strong> yazın</label>
        <div class="row"><input id="sil-onay" name="confirm" class="input" style="max-width:120px" autocomplete="off" data-must="SİL">
        <button class="btn danger" data-confirm-btn disabled formaction="{{ route('sinav.admin.students.destroy') }}">Sil</button></div>
      </details>
    </div>
  @endif
  <div class="card table-wrap" style="padding:0">
    <table class="t">
      <thead><tr>
        <th style="width:40px"><input type="checkbox" aria-label="Listelenenlerin tümünü seç" data-select-all="ids[]"></th>
        <th>Öğrenci no</th><th>Grup</th>
      </tr></thead>
      <tbody>
      @foreach ($filtered as $no => $m)
        <tr>
          <td><input type="checkbox" name="ids[]" value="{{ $m['id'] }}" aria-label="{{ $no }} seç"></td>
          <th scope="row" class="mono">{{ $no }}</th>
          <td>@foreach (array_intersect($m['groups'], $courseGroupIds) as $gid)<span class="badge">{{ $label[$gid] ?? '?' }}</span> @endforeach</td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</form>
@endif
@endif
@endsection
