@extends('sinav.admin.layout')
@section('title', $exam ? 'Sınavı düzenle' : 'Yeni sınav')
@section('sinav-content')
<h1>{{ $exam ? 'Sınavı düzenle' : 'Yeni sınav' }}</h1>
@php
  $oldCommon = old('common');
  if ($oldCommon) {
      $fix = function ($a) {
          $t = function ($k) use ($a) { return !empty($a[$k . '_h']) ? $a[$k . '_h'] . ':' . (($a[$k . '_m'] ?? '') !== '' ? $a[$k . '_m'] : '00') : ''; };
          return ['date' => $a['date'] ?? '', 'start' => $t('start'), 'end' => $t('end'), 'publish' => $t('publish')];
      };
      $common = $fix($oldCommon);
      $rows = [];
      foreach ((array) old('groups', []) as $gid => $r) {
          $rows[$gid] = ['on' => !empty($r['on']), 'custom' => !empty($r['custom']), 'times' => $fix($r)];
      }
  }
@endphp
@if (!$exam && !$list && $formGroups->isEmpty())
  <div class="alert info">Bu dersin öğrenci grubu yok. <a href="{{ route('sinav.admin.exams.create') }}">Öğrenci listesiyle</a> yeni sınav oluşturun.</div>
@else
<form method="post" action="{{ $exam ? route('sinav.admin.exams.update', $exam) : route('sinav.admin.exams.store') }}" class="card" id="sinav-formu" novalidate>
  @csrf
  @if ($list)
    <input type="hidden" name="from_list" value="1">
    <fieldset class="box" style="padding:12px">
      <legend class="label">Listeden okunan ders</legend>
      <p class="small" style="margin-top:0"><strong>{{ count($list['students']) }} öğrenci</strong> okundu.@if ($list['skipped']) {{ $list['skipped'] }} satır tekrar ettiği için atlandı.@endif
        @if ($listCourse) Bu ders sistemde kayıtlı; yeni öğrenciler dersin grubuna eklenir.@endif</p>
      <div class="grid2">
        <div class="field"><label for="f-code">Ders kodu</label>
          <input id="f-code" name="code" class="input" maxlength="40" value="{{ old('code', $list['code']) }}" required>
          @if ($list['code'] === '')<div class="hint">Listede bulunamadı, yazın. Örnek: MUH104</div>@endif</div>
        <div class="field"><label for="f-cname">Ders adı</label>
          <input id="f-cname" name="course_name" class="input" maxlength="200" value="{{ old('course_name', $listCourse ? $listCourse->name : $list['name']) }}" @if ($listCourse) readonly @endif>
          @if (!$listCourse && $list['name'] === '')<div class="hint">Listede bulunamadı, yazın.</div>@endif</div>
        <div class="field"><label for="f-gname">Grup / şube</label>
          <input id="f-gname" name="group_name" class="input" maxlength="60" value="{{ old('group_name', $list['group']) }}"></div>
        <div class="field"><label for="f-pin">Öğrenci PIN'i</label>
          <input id="f-pin" name="pin" class="input mono" inputmode="numeric" maxlength="8" autocomplete="off" value="{{ old('pin') }}" placeholder="{{ $listGroup && $listGroup->hasPin() ? 'Mevcut PIN geçerli' : '6-8 rakam' }}">
          <div class="hint">{{ $listGroup && $listGroup->hasPin() ? 'Bu grubun PIN\'i var; değiştirmek istemiyorsanız boş bırakın.' : 'Öğrenciler numarası ve bu PIN\'le girer. Siz belirleyin; 1234 gibi kolay PIN kabul edilmez.' }}</div></div>
      </div>
    </fieldset>
  @else
    <input type="hidden" name="course_id" value="{{ $courseId }}">
    @php $ec = $coursesWithGroups->firstWhere('id', $courseId); @endphp
    <p><strong>Ders:</strong> {{ $ec ? $ec->label() : '' }}@if ($exam) <span class="muted small">(sınavın dersi sonradan değiştirilemez)</span>@endif</p>
  @endif

  <div class="grid2">
    <div class="field"><label for="f-type">Sınav türü</label>
      <select id="f-type" name="type" class="input">
        @foreach (\App\Models\Sinav\Exam::TYPES as $k => $v)<option value="{{ $k }}" @if (old('type', $exam ? $exam->type : 'vize') === $k) selected @endif>{{ $v }}</option>@endforeach
      </select></div>
    <div class="field"><label for="f-title">Sınav adı (isteğe bağlı)</label>
      <input id="f-title" name="title" class="input" maxlength="120" value="{{ old('title', $exam ? $exam->title : '') }}"><div class="hint">Boş bırakırsanız türünden yazılır: "Vize Sınavı".</div></div>
  </div>

  <fieldset class="box" style="padding:12px">
    <legend class="label">Sınav zamanı (Türkiye saati)</legend>
    @include('sinav.admin._times', ['p' => 'common', 't' => $common, 'key' => 'ortak'])
    <p class="muted small" style="margin:0">Cevap anahtarı yayın saatinden önce gösterilmez ve yayından {{ config('sinav.answer_visible_days', 3) }} gün sonra öğrencilere kapanır. Yayın saati sınav bitişinden önce olamaz.</p>
  </fieldset>

  @unless ($list)
  <fieldset style="border:0;padding:0;margin:12px 0">
    <legend class="label">Sınava giren gruplar</legend>
    @foreach ($formGroups as $g)
      @php $row = $rows[$g->id] ?? ['on' => false, 'custom' => false, 'times' => $common]; @endphp
      <div class="box" data-group-row>
        <div class="row between">
          <label class="check"><input type="checkbox" name="groups[{{ $g->id }}][on]" value="1" @if ($row['on']) checked @endif> {{ $g->name }} grubu</label>
          <label class="check"><input type="checkbox" name="groups[{{ $g->id }}][custom]" value="1" data-custom-toggle @if ($row['custom']) checked @endif> Bu grubun saati farklı</label>
        </div>
        @unless ($g->hasPin())
          <div class="field" style="margin:8px 0 0;max-width:260px">
            <label for="pin-{{ $g->id }}">Öğrenci PIN'i (bu grubun PIN'i yok)</label>
            <input id="pin-{{ $g->id }}" name="groups[{{ $g->id }}][pin]" class="input mono" inputmode="numeric" maxlength="8" autocomplete="off" value="{{ old('groups.' . $g->id . '.pin') }}" placeholder="6-8 rakam">
          </div>
        @endunless
        <div data-custom-times @if (!$row['custom']) class="hidden" @endif style="margin-top:8px">
          @include('sinav.admin._times', ['p' => 'groups[' . $g->id . ']', 't' => $row['times'], 'key' => 'g' . $g->id])
        </div>
      </div>
    @endforeach
  </fieldset>
  @endunless

  <div class="field"><label for="f-desc">Açıklama (isteğe bağlı)</label>
    <textarea id="f-desc" name="description" rows="2" class="input" maxlength="500">{{ old('description', $exam ? $exam->description : '') }}</textarea></div>
  <div class="row">
    <a class="btn secondary" href="{{ route('sinav.admin.exams') }}">Vazgeç</a>
    <button class="btn">{{ $exam ? 'Kaydet' : 'Sınavı oluştur' }}</button>
  </div>
</form>
@endif
@endsection
