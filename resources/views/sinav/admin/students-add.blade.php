@extends('sinav.admin.layout')
@section('title', 'Öğrenci ekle')
@section('sinav-content')
<h1>Öğrenci ekle</h1>
@if ($courses->isEmpty())
  <div class="alert info">Öğrenci ekleyebilmek için önce öğrenci listesiyle bir sınav oluşturun.</div>
@else
@php
  $selCourse = (int) old('course_id', $courseId ?: $courses->first()->id);
  $selGroup = (int) old('group_id', $groupId);
@endphp
<form method="post" action="{{ route('sinav.admin.students.preview') }}" enctype="multipart/form-data" class="card">
  @csrf
  <input type="hidden" name="term_id" value="{{ $term->id }}">
  <div class="grid2">
    <div class="field"><label for="s-course">Ders</label>
      <select id="s-course" name="course_id" class="input" data-group-filter="s-group">
        @foreach ($courses as $c)<option value="{{ $c->id }}" @if ($selCourse === $c->id) selected @endif>{{ $c->code }} — {{ $c->name }}</option>@endforeach
      </select></div>
    <div class="field"><label for="s-group">Grup</label>
      <select id="s-group" name="group_id" class="input">
        @foreach ($groups as $g)
          <option value="{{ $g->id }}" data-course="{{ $g->course_id }}" @if ($selGroup === $g->id) selected @endif>{{ $g->name }}</option>
        @endforeach
      </select></div>
  </div>
  <div class="field">
    <label for="s-text">Öğrenci listesi</label>
    <textarea id="s-text" name="text" rows="9" class="input mono">{{ old('text', session('sinav_import_text')) }}</textarea>
    <div class="hint">Tek numara yazabilir ya da Excel'den listeyi kopyalayıp yapıştırabilirsiniz. Her satırdan öğrenci numarası ve hemen ardından gelen ad soyad alınır; sıra no gibi diğer sütunlar yok sayılır. Hepsi yukarıda seçili ders ve gruba eklenir.</div>
  </div>
  <div class="field">
    <label for="s-file">veya Excel (.xlsx) / CSV dosyası seçin</label>
    <input id="s-file" type="file" name="file" accept=".xlsx,.csv,.txt">
    <div class="hint">Dosya seçerseniz yukarıdaki kutu yerine dosyanın içeriği kullanılır. En fazla 2 MB.</div>
  </div>
  <div class="row between">
    <a class="btn secondary" href="{{ route('sinav.admin.students.template') }}">Boş Excel şablonunu indir</a>
    <div class="row">
      <a class="btn secondary" href="{{ route('sinav.admin.students') }}">Vazgeç</a>
      <button class="btn">Önizle</button>
    </div>
  </div>
</form>
@endif
@endsection
