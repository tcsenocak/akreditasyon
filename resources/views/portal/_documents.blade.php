{{-- Muafiyet ekranının belgeler bölümü: dilekçe, transkript ve diğer belgeler aynı panelden yüklenir. --}}
@php
    $types = $student->documents->pluck('type');
    $prep = $application->isPrep();
    $summer = $application->isSummer();
    $needed = $prep
        ? ['dilekce' => true, 'dil' => true]
        : ($summer ? ['dilekce' => true, 'icerik' => true] : ['dilekce' => true, 'transkript' => true, 'icerik' => false]);
    $multi = ! $prep && $institutions->count() > 1;
    $defaultType = collect(array_keys($needed))->first(fn ($t) => ! $types->contains($t)) ?? 'diger';
@endphp
<section class="card" id="mx-docs">
    <div class="card-head"><div><h2>{{ $prep ? '2.' : '3.' }} Belgeler ve dilekçeler</h2><div class="sub">
        @if ($prep)
            Muafiyet dilekçenizi ve dil sınavı sonuç belgenizi yükleyin.
        @elseif ($summer)
            Dilekçenizi ve yaz okulunda alacağınız derslerin o üniversiteden alınmış içeriklerini yükleyin.
        @else
            Muafiyet dilekçenizi, not döküm belgenizi (transkript) ve onaylı ders içeriklerinizi buradan yükleyin (Uygulama Esasları Md. 5/2).
        @endif
        PDF, JPG, PNG, Word veya Excel; her dosya en fazla 15 MB.</div></div></div>
    @if (! $prep)
        <div @class(['alert', 'warn' => $multi, 'info' => ! $multi]) style="white-space:normal;margin-bottom:10px">
            <b>Birden fazla kurumdan ders saydırıyorsanız</b> her kurum için o kurumun transkriptini ve ders içeriklerini <b>tek bir PDF dosyasında birleştirip</b> ayrı ayrı yükleyin; yüklerken “Kurum” alanından hangi kuruma ait olduğunu seçin.
            @if ($multi)
                Girdiğiniz derslerde {{ $institutions->count() }} kurum var: {{ $institutions->implode(', ') }}.
            @endif
        </div>
    @endif
    @if ($student->isEditableByStudent())
        <p class="row small" style="margin:0 0 10px"><a class="btn light small" href="{{ route('portal.petition') }}" target="_blank"><x-icon name="file" :size="13" /> Dilekçemi hazırla</a>
            <span class="muted">Girdiğiniz derslerden dolu bir dilekçe hazırlanır; yazdırıp imzalayın ve buraya yükleyin.</span></p>
    @endif
    <div class="mx-doc-needs">
        @foreach ($needed as $type => $required)
            <span @class(['mx-need', 'ok' => $types->contains($type)])>{{ $types->contains($type) ? '✓' : ($required ? '•' : '○') }} {{ \App\Models\StudentDocument::TYPES[$type] }}{{ $required ? '' : ' (önerilir)' }}</span>
        @endforeach
    </div>
    @if ($student->isEditableByStudent())
        <form method="POST" action="{{ route('portal.documents.store') }}" enctype="multipart/form-data" class="mx-doc-form" data-ajax>
            @csrf
            <label class="mx-f"><span>Belge türü</span>
                <select name="type">@foreach (\App\Models\StudentDocument::TYPES as $k => $v)<option value="{{ $k }}" @selected($k === $defaultType)>{{ $v }}</option>@endforeach</select>
            </label>
            @if (! $prep && $institutions->isNotEmpty())
                <label class="mx-f"><span>Kurum</span>
                    <select name="institution">
                        @foreach ($institutions as $inst)<option value="{{ $inst }}">{{ $inst }}</option>@endforeach
                        <option value="">Kuruma bağlı değil (dilekçe vb.)</option>
                    </select>
                </label>
            @endif
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
                <span class="small muted">{{ $doc->institution ? $doc->institution.' · ' : '' }}{{ $doc->size_label }} · {{ $doc->created_at->format('d.m.Y H:i') }}</span>
                @if ($student->isEditableByStudent())
                    <form method="POST" action="{{ route('documents.destroy', $doc) }}" data-ajax data-confirm="“{{ $doc->original_name }}” silinsin mi?">@csrf @method('DELETE')<button class="icon-x" title="Belgeyi sil" aria-label="Belgeyi sil">✕</button></form>
                @endif
            </div>
        @empty
            <p class="small muted" style="margin:0">Henüz belge yüklemediniz.</p>
        @endforelse
    </div>
</section>
