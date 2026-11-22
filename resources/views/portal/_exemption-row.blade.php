{{-- Öğrencinin aldığı bir ders. Alan adları (items[i][...]) sayfadaki betikle verilir; target_id eşleştirmeden gelir. --}}
@php
    $locked = ! $student->isEditableByStudent();
@endphp
<div class="mx-line" data-key="{{ $row ? 'r'.$row->id : '' }}">
    <input type="hidden" data-f="id" value="{{ $row?->id }}">
    <input type="hidden" data-f="target_id" value="{{ $row?->requested_course_id }}">
    <label class="mx-f mx-f-inst"><span>Kurum</span>
        <select data-f="institution" required @disabled($locked)>
            @foreach ($universities as $u)<option value="{{ $u }}" @selected(($row?->institution ?? $defaultInstitution) === $u)>{{ $u }}</option>@endforeach
        </select>
    </label>
    <label class="mx-f"><span>Ders kodu</span><input data-f="code" value="{{ $row?->code }}" maxlength="32" placeholder="ör. MAT1001" @disabled($locked)></label>
    <label class="mx-f mx-f-name"><span>Dersin adı</span><input data-f="name" value="{{ $row?->name }}" maxlength="200" placeholder="Transkriptteki adıyla" required @disabled($locked)></label>
    <label class="mx-f"><span>Kredi</span><input data-f="credit" value="{{ $row?->credit }}" inputmode="decimal" placeholder="—" @disabled($locked)></label>
    <label class="mx-f"><span>AKTS</span><input data-f="ects" value="{{ $row?->ects }}" inputmode="decimal" placeholder="—" required @disabled($locked)></label>
    <label class="mx-f"><span>Notum</span><input data-f="grade_raw" value="{{ $row?->grade_raw }}" maxlength="16" placeholder="AA, 85…" required @disabled($locked)></label>
    <span class="mx-line-end">
        <span class="mx-target-tag small" title="Karşılığı olan AÜ dersi"></span>
        @if ($row && ! $row->passed)<span class="badge bad" title="Başarısız ders muafiyete esas alınmaz">başarısız</span>@endif
        @unless ($locked)<button type="button" class="icon-x" data-del-line title="Dersi sil" aria-label="Dersi sil">✕</button>@endunless
    </span>
</div>
