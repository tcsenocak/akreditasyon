@extends('sinav.admin.layout')
@section('title', 'Dersler')
@section('sinav-content')
<div class="page-head"><h1>{{ \App\Services\Sinav\Access::isCommission() ? 'Dersler' : 'Derslerim' }}</h1></div>
@php $isCommission = \App\Services\Sinav\Access::isCommission(); @endphp
@if (!$isCommission)<div class="alert info">Dersleriniz listeleniyor. Yeni ders, öğrenci listesiyle sınav oluşturduğunuzda kendiliğinden eklenir; ders evrakı bağlantılarını buradan ekleyebilirsiniz.</div>@endif


@if ($courses->isEmpty())
  <div class="alert info">Henüz ders yok. Dersler, <a href="{{ route('sinav.admin.exams.create') }}">yeni sınav</a> oluştururken yüklediğiniz öğrenci listesinden tanımlanır.</div>
@endif

@foreach ($courses as $c)
  <div class="card">
    <div class="row between">
      <div>
        <h2>{{ $c->code }} — {{ $c->name }} @if (!$c->active)<span class="badge">Pasif</span>@endif</h2>
        <p class="muted small">{{ $c->instructorUser ? 'Sorumlu: '.$c->instructorUser->name : ($c->instructor ?: 'Öğretim elemanı atanmadı') }} · {{ $c->groups_count }} grup · {{ $c->docs->count() }} evrak</p>
      </div>
      <div class="row">
        <a class="btn secondary sm" href="{{ route('sinav.admin.courses', ['evrak' => $c->id]) }}#ders-{{ $c->id }}">Ders evrakı</a>
        @if ($isCommission)
          <a class="btn secondary sm" href="{{ route('sinav.admin.courses', ['duzenle' => $c->id]) }}#ders-{{ $c->id }}">Düzenle</a>
          <form class="inline" method="post" action="{{ route('sinav.admin.courses.destroy', $c) }}" onsubmit="return confirm('{{ $c->code }} dersi grupları ve evrak bağlantılarıyla birlikte silinsin mi?')">@csrf<button class="btn danger sm">Sil</button></form>
        @endif
      </div>
    </div>

    @if ($editCourse && $editCourse->id === $c->id)
      <form id="ders-{{ $c->id }}" method="post" action="{{ route('sinav.admin.courses.update', $c) }}" class="box" style="margin-top:10px">
        @csrf
        <div class="grid2">
          <div class="field"><label for="e-name">Ders adı</label><input id="e-name" name="name" class="input" maxlength="200" value="{{ $c->name }}"></div>
          <div class="field"><label for="e-ins">Öğretim elemanı (ders dosyası kapağında yazar)</label><input id="e-ins" name="instructor" class="input" maxlength="200" value="{{ $c->instructor }}"></div>
          <div class="field"><label for="e-user">Sorumlu öğretim elemanı (hesap)</label>
        <select id="e-user" name="instructor_user_id" class="input">
          <option value="">Atanmadı (yalnızca komisyon yönetir)</option>
          @foreach ($instructors as $u)<option value="{{ $u->id }}" @if ((int) $c->instructor_user_id === $u->id) selected @endif>{{ $u->name }} · {{ $u->email }}</option>@endforeach
        </select>
        <div class="hint">Atanan hoca bu dersin sınavlarını, PIN'lerini ve ders dosyasını yönetir. Hesap açmak için <a href="{{ route('sinav.admin.instructors') }}">Öğretim Elemanları</a>.</div></div>
        </div>
        <div class="field"><label for="e-desc">Açıklama</label><textarea id="e-desc" name="description" rows="2" class="input" maxlength="2000">{{ $c->description }}</textarea></div>
        <label class="check"><input type="checkbox" name="active" value="1" @if ($c->active) checked @endif> Etkin</label>
        <div class="row" style="margin-top:10px">
          <button class="btn">Kaydet</button>
          <a class="btn secondary" href="{{ route('sinav.admin.courses') }}">Vazgeç</a>
        </div>
      </form>
    @endif

    @if ($docsCourse && $docsCourse->id === $c->id)
      <div id="ders-{{ $c->id }}" style="margin-top:10px">
        <h3>Ders evrakı</h3>
        <p class="muted small">Ders içeriği, izlence, notlar gibi belgelerin bağlantıları. Dosyanın kendisi Google Drive'da (veya başka bir yerde) durur; öğrenciler "Derslerim" ekranında bağlantıyı görür. Drive'da paylaşımı "Bağlantıya sahip olan herkes" yapmayı unutmayın.</p>
        @if ($c->docs->isNotEmpty())
          <ul class="list" style="margin-bottom:10px">
            @foreach ($c->docs as $d)
              <li class="row between">
                <span><a href="{{ $d->url }}" target="_blank" rel="noopener noreferrer">{{ $d->title }}</a> @if ($d->isDrive())<span class="badge blue">Drive</span>@endif</span>
                <form class="inline" method="post" action="{{ route('sinav.admin.docs.destroy', $d) }}" onsubmit="return confirm('Bağlantı kaldırılsın mı?')">@csrf<button class="btn danger sm">Kaldır</button></form>
              </li>
            @endforeach
          </ul>
        @endif
        <form method="post" action="{{ route('sinav.admin.docs.store', $c) }}" class="grid2">
          @csrf
          <div class="field"><label for="d-title">Başlık</label><input id="d-title" name="title" class="input" maxlength="150" placeholder="Ders İzlencesi" value="{{ old('title') }}"></div>
          <div class="field"><label for="d-url">Bağlantı (https://…)</label><input id="d-url" name="url" class="input" placeholder="https://drive.google.com/…" value="{{ old('url') }}"></div>
          <div><button class="btn">Bağlantı ekle</button> <a class="btn secondary" href="{{ route('sinav.admin.courses') }}">Kapat</a></div>
        </form>
      </div>
    @endif
  </div>
@endforeach
@endsection
