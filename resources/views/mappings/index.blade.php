@extends('layouts.app')
@section('title', 'Plan intibakı')
@section('content')
<div class="page-head">
    <div><h1>Öğretim planı değişikliği intibakı</h1><div class="sub">Önlisans ve Lisans Öğretim Planları İntibak Usul ve Esasları (19.08.2022) — eski plandaki öğrencilerin yeni plana aktarılması</div></div>
</div>
<div class="alert info">Bir programın eski planını <a href="{{ route('programs.index') }}">Programlar</a> sayfasından “eski plan olarak ekle” seçeneğiyle yükleyin. Yeni plan her zaman programın güncel (aktif) planıdır; aşağıdan yalnızca eski planı seçip onaylayın: sistem kök kodu/adı aynı dersleri kilitli eşleştirir (Md. 6/1-a), kalanları komisyon eşleştirir.</div>
@foreach ($programs as $program)
    <div class="card">
        <h2>{{ $program->full_name }}</h2>
        @php($active = $program->curricula->firstWhere('is_active', true))
        @php($older = $program->curricula->reject(fn ($c) => $active && $c->id === $active->id)->sortByDesc('start_year'))
        @if (! $active || $older->isEmpty())
            <p class="muted small">Tek öğretim planı var ({{ $program->curricula->first()?->title }}). İntibak için eski planı yükleyin.</p>
        @else
            <form method="GET" class="row" data-active="{{ $active->title }}"
                  onsubmit="var o=this.old.options[this.old.selectedIndex].text; if(!confirm('Öğrenciler güncel plana aktarılacak. Eski plan: '+o+' → Yeni (güncel) plan: '+this.dataset.active+'. Onaylıyor musunuz?')) return false; this.action='{{ url('plan-intibak') }}/'+this.old.value+'/{{ $active->id }}'; return true;">
                <label style="margin:0">Eski plan</label>
                <select name="old">@foreach ($older as $c)<option value="{{ $c->id }}">{{ $c->title }}</option>@endforeach</select>
                <span>→ Güncel plan: <b>{{ $active->title }}</b> <span class="badge ok">aktif</span></span>
                <button>Eşleştirmeyi aç</button>
            </form>
        @endif
    </div>
@endforeach
@endsection
