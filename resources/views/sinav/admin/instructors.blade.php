@extends('sinav.admin.layout')
@section('title', 'Öğretim Elemanları')
@section('sinav-content')
<div class="page-head">
  <div>
    <h1>Öğretim Elemanları</h1>
    <p class="sub">Hoca hesapları yalnızca kendilerine atanan derslerin sınavlarını, cevap anahtarlarını, grup PIN'lerini ve ders dosyasını yönetir.</p>
  </div>
</div>

@if (session('account_password'))
  @php $acc = session('account_password'); @endphp
  <div class="alert info" role="status">
    <strong>Geçici parola (yalnızca şimdi gösteriliyor):</strong>
    <span class="mono">{{ $acc['email'] }}</span> · <span class="mono" style="font-size:1rem">{{ $acc['password'] }}</span><br>
    Hocaya iletin; ilk girişte kendi parolasını belirlemesi istenecek.
  </div>
@endif
@if ($errors->any())
  <div class="alert error">@foreach ($errors->all() as $e){{ $e }} @endforeach</div>
@endif

<div class="card">
  <div class="card-head"><h2>Yeni öğretim elemanı</h2><p>Giriş e-postası ile hesap açılır; geçici parola üretilir.</p></div>
  <form method="post" action="{{ route('sinav.admin.instructors.store') }}" class="grid3" style="align-items:end">
    @csrf
    <div class="field"><label for="i-name">Ad soyad</label><input id="i-name" name="name" class="input" maxlength="120" value="{{ old('name') }}" required></div>
    <div class="field"><label for="i-email">E-posta</label><input id="i-email" type="email" name="email" class="input" maxlength="190" value="{{ old('email') }}" required></div>
    <div class="field"><button class="btn">@include('sinav._icon', ['i' => 'plus']) Hesap aç</button></div>
  </form>
</div>

<div class="card table-wrap" style="padding:0">
  @if ($instructors->isEmpty())
    <p class="empty">Henüz öğretim elemanı hesabı yok.</p>
  @else
  <table class="t">
    <thead><tr><th>Ad soyad</th><th>E-posta</th><th>Atanmış dersler</th><th>Durum</th><th></th></tr></thead>
    <tbody>
    @foreach ($instructors as $u)
      <tr>
        <td>{{ $u->name }}</td>
        <td class="small">{{ $u->email }}</td>
        <td class="small">
          @forelse ($courses->get($u->id, collect()) as $c)
            <span class="badge">{{ $c->code }}</span>
          @empty
            <span class="muted">Ders atanmadı</span>
          @endforelse
        </td>
        <td>@if ($u->must_change_password)<span class="badge amber">İlk giriş bekleniyor</span>@else<span class="badge green">Etkin</span>@endif</td>
        <td style="white-space:nowrap">
          <form class="inline" method="post" action="{{ route('sinav.admin.instructors.reset', $u) }}" onsubmit="return confirm('{{ addslashes($u->name) }} için yeni geçici parola üretilsin mi?')">@csrf<button class="btn secondary sm">Parola sıfırla</button></form>
          <form class="inline" method="post" action="{{ route('sinav.admin.instructors.destroy', $u) }}" onsubmit="return confirm('{{ addslashes($u->name) }} hesabı kapatılsın mı? Dersleri ve sınavları korunur, yalnızca sorumlu boşalır.')">@csrf<button class="btn danger sm">Hesabı kapat</button></form>
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  @endif
</div>
@endsection
