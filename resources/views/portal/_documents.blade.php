{{-- Muafiyet ekranının belgeler bölümü: dilekçe, transkript ve diğer belgeler aynı panelden yüklenir. --}}
@php
    $types = $student->documents->pluck('type');
    $needed = ['dilekce' => true, 'transkript' => true, 'icerik' => false];
@endphp
<section class="card" id="mx-docs">
    <div class="card-head"><div><h2>3. Belgeler ve dilekçeler</h2><div class="sub">Muafiyet dilekçenizi, not döküm belgenizi (transkript) ve onaylı ders içeriklerinizi buradan yükleyin (Uygulama Esasları Md. 5/2). PDF, JPG, PNG, Word veya Excel; her dosya en fazla 15 MB.</div></div></div>
    <div class="mx-doc-needs">
        @foreach ($needed as $type => $required)
            <span @class(['mx-need', 'ok' => $types->contains($type)])>{{ $types->contains($type) ? '✓' : ($required ? '•' : '○') }} {{ \App\Models\StudentDocument::TYPES[$type] }}{{ $required ? '' : ' (önerilir)' }}</span>
        @endforeach
    </div>
    @if ($student->isEditableByStudent())
        <form method="POST" action="{{ route('portal.documents.store') }}" enctype="multipart/form-data" class="mx-doc-form" data-ajax>
            @csrf
            <label class="mx-f"><span>Belge türü</span>
                <select name="type">@foreach (\App\Models\StudentDocument::TYPES as $k => $v)<option value="{{ $k }}" @selected(! $types->contains('dilekce') ? $k === 'dilekce' : (! $types->contains('transkript') && $k === 'transkript'))>{{ $v }}</option>@endforeach</select>
            </label>
            <label class="mx-f"><span>Dosya(lar)</span><input type="file" name="files[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"></label>
            <button class="light"><x-icon name="upload" :size="14" /> Yükle</button>
            <span class="small muted" data-ajax-status role="status"></span>
        </form>
    @endif
    <div class="mx-doc-list">
        @forelse ($student->documents->sortBy('created_at') as $doc)
            <div class="mx-doc">
                <span class="badge">{{ \App\Models\StudentDocument::TYPES[$doc->type] ?? $doc->type }}</span>
                <a href="{{ route('documents.download', $doc) }}">{{ $doc->original_name }}</a>
                <span class="small muted">{{ $doc->size_label }} · {{ $doc->created_at->format('d.m.Y H:i') }}</span>
                @if ($student->isEditableByStudent())
                    <form method="POST" action="{{ route('documents.destroy', $doc) }}" data-ajax data-confirm="“{{ $doc->original_name }}” silinsin mi?">@csrf @method('DELETE')<button class="icon-x" title="Belgeyi sil" aria-label="Belgeyi sil">✕</button></form>
                @endif
            </div>
        @empty
            <p class="small muted" style="margin:0">Henüz belge yüklemediniz.</p>
        @endforelse
    </div>
</section>
