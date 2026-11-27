@extends('layouts.app')
@section('title', $student->full_name)
@section('content')
@php
    $isChair = auth()->user()->isAdmin();
    $link = session('account_link');
    $submitted = $student->applications->filter(fn ($a) => $a->status !== 'taslak' || $a->submitted_at)->sortByDesc('id')->values();
    $draft = $student->currentApplication;
    $draftStarted = $draft && $draft->status === 'taslak' && ! $draft->submitted_at && $student->transcript->isNotEmpty();
@endphp
<div class="page-head">
    <div>
        <h1>{{ $student->full_name }} <span class="muted small">{{ $student->student_no }}</span></h1>
        <div class="sub">
            {{ \App\Models\Student::ADMISSION_TYPES[$student->admission_type] ?? $student->admission_type }}
            · AÜ: <b>{{ $student->program?->full_name ?? 'program seçilmedi' }}</b> {{ $student->curriculum ? '('.$student->curriculum->title.')' : '' }}
        </div>
    </div>
    <div class="row">
        <a class="btn light" href="{{ route('students.edit', $student) }}"><x-icon name="edit" :size="14" /> Kimlik / program</a>
        @if ($isChair)
        <form method="POST" action="{{ route('students.destroy', $student) }}" onsubmit="return confirm('Öğrenci ve tüm dosyaları silinecek. Emin misiniz?')">@csrf @method('DELETE')<button class="danger">Sil</button></form>
        @endif
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><div><h2>Öğrenci paneli hesabı</h2><div class="sub">Parolayı öğrenci kendisi belirler; komisyon parola iletmez</div></div></div>
        @if ($link)
            @if ($link['sent'])
                <div class="alert ok" style="white-space:normal">Etkinleştirme bağlantısı <b>{{ $link['email'] }}</b> adresine e-postayla gönderildi. Öğrenci bağlantıdan parolasını belirleyip sisteme girer; bağlantı {{ \App\Services\AccountActivation::VALID_DAYS }} gün geçerlidir.</div>
            @else
                <div class="alert info" style="white-space:normal">
                    E-posta sunucusu tanımlı olmadığı için bağlantı <b>otomatik gönderilemedi</b>. Aşağıdaki bağlantıyı öğrenciye ({{ $link['email'] }}) siz iletin — <b>yalnızca şimdi gösterilir</b>, {{ \App\Services\AccountActivation::VALID_DAYS }} gün geçerlidir:<br>
                    <input readonly value="{{ $link['url'] }}" onclick="this.select()" style="width:100%;margin:6px 0;font-family:monospace;font-size:12px" aria-label="Etkinleştirme bağlantısı">
                    <button type="button" class="small light" onclick="navigator.clipboard.writeText(this.previousElementSibling.value); this.textContent = 'Kopyalandı'">Bağlantıyı kopyala</button>
                    <span class="small">Öğrenci bu bağlantıdan kendi parolasını belirler; ayrıca parola göndermeniz gerekmez.</span>
                </div>
            @endif
        @endif
        @if ($student->user)
            <p>Hesap: <b>{{ $student->user->email }}</b>
                @if ($student->user->activation_token && $student->user->must_change_password)<span class="badge warn">etkinleştirme bekleniyor</span>
                @elseif ($student->user->must_change_password)<span class="badge warn">geçici parola — henüz değiştirilmedi</span>
                @else<span class="badge ok">etkin</span>@endif</p>
            <div class="row">
                <form method="POST" action="{{ route('students.account.reset', $student) }}" onsubmit="return confirm('Öğrenciye yeni bir etkinleştirme / parola belirleme bağlantısı gönderilecek; önceki bağlantı geçersiz olur. Devam?')">@csrf<button class="small light"><x-icon name="send" :size="13" /> {{ $student->user->must_change_password ? 'Etkinleştirme bağlantısını yeniden gönder' : 'Parola belirleme bağlantısı gönder' }}</button></form>
                @if ($isChair)
                <form method="POST" action="{{ route('students.account.destroy', $student) }}" onsubmit="return confirm('Öğrenci hesabı kapatılsın mı? Başvuru kayıtları silinmez.')">@csrf @method('DELETE')<button class="small danger">Hesabı kapat</button></form>
                @endif
            </div>
        @else
            <form method="POST" action="{{ route('students.account.store', $student) }}" class="row">
                @csrf
                <input type="email" name="email" placeholder="öğrencinin e-postası" required value="{{ old('email') }}">
                <button class="light">Hesap aç</button>
            </form>
        @endif
    </div>

    <div class="card">
        <div class="card-head"><div><h2>Öğrencinin beyanı</h2><div class="sub">Öğrenci tarafından doldurulur</div></div></div>
        <table>
            <tr><th style="width:42%">Önceki kurum</th><td>{{ $student->previous_institution ?: '—' }}</td></tr>
            <tr><th>Fakülte / bölüm</th><td>{{ $student->previous_faculty }} {{ $student->previous_program ? '/ '.$student->previous_program : '' }}</td></tr>
            <tr><th>Kayıt dönemi</th><td>{{ $student->entry_term ?: '—' }}</td></tr>
            <tr><th>Yabancı dil</th><td>{{ $student->yds_score ? ($student->yds_exam ?: 'YDS').' '.$student->yds_score : '—' }} @if ($student->prep_completed)<span class="badge ok">hazırlık başarılı</span>@endif</td></tr>
            <tr><th>İletişim</th><td>{{ $student->user?->email }} {{ $student->phone ? '· '.$student->phone : '' }}</td></tr>
        </table>
        @if ($student->notes)<p class="small"><b>Komisyon notu:</b> {{ $student->notes }}</p>@endif
    </div>
</div>

<div class="card table-wrap">
    <div class="card-head"><div><h2>Başvuruları</h2><div class="sub">Öğrencinin gönderdiği tüm başvurular; ayrıntı, belgeler, değerlendirme ve itiraz için başvuruyu açın</div></div></div>
    @if ($submitted->isEmpty())
        <p class="muted" style="margin:0">Öğrenci henüz başvuru göndermedi.{{ $draftStarted ? ' Bir başvuru hazırlıyor ('.$student->transcript->count().' ders girdi).' : '' }}</p>
    @else
        @if ($draftStarted)<p class="small muted">Öğrenci ayrıca yeni bir başvuru hazırlıyor ({{ $draft->type_label }}, henüz göndermedi).</p>@endif
        <table>
            <tr><th>#</th><th>Tür</th><th>Gönderim</th><th>Durum</th><th class="num">Ders</th><th class="num">Belge</th><th>Sonuç</th><th>İtiraz</th><th></th></tr>
            @foreach ($submitted as $app)
                @php
                    $file = $app->exemptionFile;
                @endphp
                <tr>
                    <td>{{ $submitted->count() - $loop->index }}</td>
                    <td><a href="{{ route('applications.show', $app) }}"><b>{{ $app->type_label }}</b></a></td>
                    <td class="small">{{ $app->submitted_at?->format('d.m.Y H:i') ?? '—' }}</td>
                    <td><span class="badge {{ $app->status === 'sonuclandi' ? 'ok' : 'warn' }}">{{ $app->status === 'taslak' ? 'Düzeltme için geri açıldı' : (\App\Models\Student::APPLICATION_STATUSES[$app->status] ?? $app->status) }}</span></td>
                    <td class="num">{{ $app->transcript_count }}</td>
                    <td class="num">{{ $app->documents_count }}</td>
                    <td class="small">
                        @if ($app->isPrep())
                            {{ $app->decision ? \App\Models\ExemptionApplication::DECISIONS[$app->decision] : '—' }}
                        @elseif ($file)
                            <a href="{{ route('files.show', $file) }}">Dosya #{{ $file->id }}</a> · {{ $file->accepted_items_count }}/{{ $file->items_count }} kabul · {{ (float) $file->exempt_ects }} AKTS
                        @else
                            —
                        @endif
                    </td>
                    <td class="small">@if ($app->objected_at)<span class="badge {{ $app->objection_responded_at ? 'ok' : 'bad' }}">{{ $app->objection_responded_at ? 'cevaplandı' : 'cevap bekliyor' }}</span>@endif</td>
                    <td><a class="btn small light" href="{{ route('applications.show', $app) }}">Aç</a></td>
                </tr>
            @endforeach
        </table>
    @endif
</div>

@if ($catalogPrograms->isNotEmpty())
<div class="card table-wrap">
    <div class="card-head"><div><h2>Öğrencinin girdiği bölüm ders listeleri</h2><div class="sub">Öğrenci derslerini bu listelerden seçti. Listeyi kontrol edip onaylayın; onaylanan liste diğer öğrencilere de doğrulanmış olarak görünür.</div></div></div>
    <table>
        <tr><th>Kurum</th><th>Bölüm / program</th><th class="num">Ders</th><th>Durum</th><th></th></tr>
        @foreach ($catalogPrograms as $cp)
            <tr>
                <td>{{ $cp->institution }}</td>
                <td>{{ $cp->name }}<div class="small muted">{{ $cp->faculty }}</div></td>
                <td class="num">{{ $cp->courses_count }}</td>
                <td>@if ($cp->verified)<span class="badge ok">onaylı</span>@else<span class="badge warn">onay bekliyor</span>@endif</td>
                <td><form method="POST" action="{{ route('catalog.verify', $cp) }}">@csrf<button class="small {{ $cp->verified ? 'light' : '' }}">{{ $cp->verified ? 'Onayı kaldır' : 'Onayla' }}</button></form></td>
            </tr>
        @endforeach
    </table>
</div>
@endif

<div class="card table-wrap">
    <h2>İşlem geçmişi</h2>
    <table>
        @forelse ($logs as $log)
            <tr><td class="small" style="width:130px">{{ $log->created_at->format('d.m.Y H:i') }}</td><td class="small" style="width:180px">{{ $log->user ? ($log->user->isStudent() ? 'Öğrenci' : $log->user->display_name) : 'Sistem' }}</td><td class="small">{{ $log->description }}</td></tr>
        @empty
            <tr><td class="muted small">Kayıt yok.</td></tr>
        @endforelse
    </table>
</div>
@endsection
