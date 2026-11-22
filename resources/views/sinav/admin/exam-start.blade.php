@extends('sinav.admin.layout')
@section('title', 'Yeni sınav')
@section('sinav-content')
<h1>Yeni sınav</h1>
<p class="muted">Sınıf listesini yükleyin. Ders kodu, ders adı, grup ve öğrenciler listeden okunur. Sonraki adımda sınav saatini ve öğrenci PIN'ini yazıp kaydedersiniz.</p>

<form method="post" action="{{ route('sinav.admin.exams.list') }}" enctype="multipart/form-data" class="card" id="liste-formu">
  @csrf
  <h2>Öğrenci listesiyle</h2>
  <div class="field">
    <label for="l-file">Sınıf listesi (Excel .xlsx, CSV veya PDF)</label>
    <input id="l-file" type="file" name="list" accept=".xlsx,.csv,.txt,.pdf" data-class-list>
    <div class="hint">OBS'den aldığınız sınıf listesi olabilir. Listede ders kodu yoksa sonraki adımda yazarsınız. En fazla 2 MB.</div>
    <div class="hint" data-list-status role="status"></div>
  </div>
  <details>
    <summary>veya listeyi yapıştırın</summary>
    <div class="field" style="margin-top:8px">
      <label for="l-text">Liste</label>
      <textarea id="l-text" name="list_text" rows="8" class="input mono" placeholder="Ders Kodu: MUH104&#10;Ders Adı: Malzeme Bilimi&#10;Öğrenci No	Ad Soyad&#10;220101001	Ayşe Yılmaz"></textarea>
      <div class="hint">Excel'den kopyalayıp yapıştırabilirsiniz. Her satırdan öğrenci numarası ve ardından gelen ad soyad alınır.</div>
    </div>
  </details>
  <input type="hidden" name="list_name" value="">
  <div class="row" style="margin-top:12px">
    <a class="btn secondary" href="{{ route('sinav.admin.exams') }}">Vazgeç</a>
    <button class="btn">Listeyi oku ve devam et</button>
  </div>
</form>

@if ($coursesWithGroups->isNotEmpty())
<form method="get" action="{{ route('sinav.admin.exams.create') }}" class="card">
  <h2>Öğrencileri yüklenmiş dersim için</h2>
  <p class="muted small">Aynı derse yeni sınav (final, bütünleme…) eklerken listeyi yeniden yüklemeniz gerekmez.</p>
  <div class="field" style="max-width:520px">
    <label for="k-ders">Ders</label>
    <select id="k-ders" name="ders" class="input">
      @foreach ($coursesWithGroups as $c)<option value="{{ $c->id }}">{{ $c->code }} — {{ $c->name }}</option>@endforeach
    </select>
  </div>
  <button class="btn secondary">Devam et</button>
</form>
@endif
@endsection
