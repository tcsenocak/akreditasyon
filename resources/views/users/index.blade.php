@extends('layouts.app')
@section('title', 'Komisyon Üyeleri')
@section('content')
<div class="page-head">
    <div><h1>Komisyon Üyeleri</h1><div class="sub">Her üye kendi hesabıyla girer; başkanın parolasını paylaşmasına gerek kalmaz. Üyeler talepleri değerlendirip karar satırlarını kaydeder; <b>kesin kararı (yönetim kurulu kararı)</b>, silme işlemlerini, plan aktarımını, ayarları ve sınav modülünü yalnız başkan yönetir.</div></div>
</div>

@if (session('account_password'))
    <div class="alert info" style="white-space:normal">
        Giriş bilgileri — <b>bu parola yalnızca şimdi gösterilir</b>, kişiye iletin:<br>
        Adres: <code>{{ route('login') }}</code><br>
        E-posta: <code>{{ session('account_password')['email'] }}</code><br>
        Geçici parola: <code style="font-size:15px">{{ session('account_password')['password'] }}</code><br>
        <span class="small">İlk girişte kendi parolasını belirlemek zorundadır.</span>
    </div>
@endif

<form method="POST" action="{{ route('users.store') }}" class="card">
    @csrf
    <h2>Üye ekle</h2>
    <div class="form-grid">
        <div><label for="u-title">Unvan</label>
            <select id="u-title" name="title" required>@foreach (\App\Models\User::TITLES as $t => $faculty)<option value="{{ $t }}" @selected(old('title') === $t)>{{ $t }}</option>@endforeach</select>
        </div>
        <div><label for="u-name">Ad soyad</label><input id="u-name" name="name" required maxlength="120" value="{{ old('name') }}"></div>
        <div><label for="u-email">E-posta</label><input id="u-email" type="email" name="email" required maxlength="190" value="{{ old('email') }}"></div>
        <div><label for="u-role">Rol</label>
            <select id="u-role" name="role">@foreach (\App\Models\User::COMMISSION_ROLES as $k => $v)<option value="{{ $k }}" @selected(old('role', 'commission') === $k)>{{ $v }}</option>@endforeach</select>
        </div>
    </div>
    <p class="small muted">Komisyon başkanı yalnız öğretim üyesi (Prof. Dr., Doç. Dr., Dr. Öğr. Üyesi) olabilir. Başkan izinliyken yerine bakacak öğretim üyesine de başkan rolü verilebilir.</p>
    <p><button><x-icon name="plus" :size="14" /> Ekle</button></p>
</form>

<div class="card table-wrap">
    <table>
        <tr><th>Unvan</th><th>Ad soyad</th><th>E-posta</th><th>Rol</th><th></th></tr>
        @foreach ($users as $u)
            @php
                $form = 'user-'.$u->id;
            @endphp
            <tr>
                <td><select name="title" form="{{ $form }}" aria-label="Unvan">@unless ($u->title)<option value="">— seçin —</option>@endunless @foreach (\App\Models\User::TITLES as $t => $faculty)<option value="{{ $t }}" @selected($u->title === $t)>{{ $t }}</option>@endforeach</select></td>
                <td><input name="name" form="{{ $form }}" value="{{ $u->name }}" required maxlength="120" aria-label="Ad soyad"></td>
                <td><input type="email" name="email" form="{{ $form }}" value="{{ $u->email }}" required maxlength="190" aria-label="E-posta"></td>
                <td><select name="role" form="{{ $form }}" aria-label="Rol">@foreach (\App\Models\User::COMMISSION_ROLES as $k => $v)<option value="{{ $k }}" @selected($u->role === $k)>{{ $v }}</option>@endforeach</select>
                    @if ($u->is(auth()->user()))<span class="badge">siz</span>@endif
                    @if ($u->must_change_password)<span class="badge warn">geçici parola</span>@endif</td>
                <td class="row" style="flex-wrap:nowrap">
                    <form method="POST" action="{{ route('users.update', $u) }}" id="{{ $form }}">@csrf @method('PUT')<button class="small light">Kaydet</button></form>
                    <form method="POST" action="{{ route('users.reset', $u) }}" onsubmit="return confirm('Yeni geçici parola üretilecek. Devam?')">@csrf<button class="small light" title="Geçici parola üret"><x-icon name="key" :size="13" /></button></form>
                    @unless ($u->is(auth()->user()))
                        <form method="POST" action="{{ route('users.destroy', $u) }}" onsubmit="return confirm('{{ $u->display_name }} silinsin mi?')">@csrf @method('DELETE')<button class="small danger" aria-label="Sil">✕</button></form>
                    @endunless
                </td>
            </tr>
        @endforeach
    </table>
</div>
@endsection
