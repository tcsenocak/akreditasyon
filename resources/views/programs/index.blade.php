@extends('layouts.app')
@section('title', 'Programlar')
@section('content')
<div class="page-head">
    <div><h1>Lisans programları ve öğretim planları</h1><div class="sub">Programa tıklayınca giriş yılına göre müfredat dersleri ve AKTS kredileri OBS Ders Bilgi Paketi düzeninde açılır</div></div>
    <div class="row">
        <form method="POST" action="{{ route('programs.obs') }}" onsubmit="return confirm('OBS Ders Bilgi Paketi\'nden tüm programların giriş yılı müfredatları çekilecek (1-2 dakika sürebilir). En yeni giriş yılı aktif plan olur. Devam?')">
            @csrf <button>⟳ OBS'den güncelle</button>
        </form>
        <form method="POST" action="{{ route('programs.download') }}" onsubmit="return confirm('Fakülte sayfasındaki Excel planlar indirilip aktif plan olarak güncellenecek. Devam?')">
            @csrf <button class="light">Fakülte sitesi Excel planları</button>
        </form>
    </div>
</div>

<div class="card">
    <h2>Öğretim planı yükle (.xlsx veya ders bilgi paketi .doc)</h2>
    <form method="POST" action="{{ route('programs.import') }}" enctype="multipart/form-data" class="row">
        @csrf
        <input type="file" name="files[]" accept=".xlsx,.xls,.doc" multiple required>
        <label class="row" style="margin:0;font-weight:400"><input type="checkbox" name="passive" value="1"> Eski plan olarak ekle (aktif yapma)</label>
        <button>Aktar</button>
    </form>
    <p class="small muted">AÜ öğretim planı şablonu (iki sütunlu yarıyıl tabloları, seçmeli havuzları) okunur. Eski bir planı “eski plan” olarak ekleyip <a href="{{ route('mappings.index') }}">Plan intibakı</a> ekranında yeni planla eşleştirebilirsiniz.</p>
</div>

<div class="card">
    <h2>Programlar</h2>
    <div class="obs-programs">
        @forelse ($programs as $program)
            @php($active = $program->curricula->firstWhere('is_active', true))
            <a class="obs-program" href="{{ route('programs.show', $program) }}">
                <b>{{ $program->full_name }}</b>
                <span class="small muted">{{ $program->code_prefix }} · {{ $program->curricula->count() }} öğretim planı</span>
                @if ($active)<div class="small" style="margin-top:6px">Aktif plan: {{ $active->title }} · {{ (float) $active->total_ects }} AKTS</div>@endif
            </a>
        @empty
            <p class="muted">Henüz program yok. Yukarıdan planları güncelleyin veya Excel yükleyin.</p>
        @endforelse
    </div>
</div>
@endsection
