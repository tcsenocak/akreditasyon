{{-- Saat seçimi: iki açılır liste (saat, dakika). $name: "common[start]" gibi; $value: "10:30" --}}
@php
  [$hh, $mm] = $value ? explode(':', $value) + ['', ''] : ['', ''];
  $base = preg_replace('/\]$/', '', $name);
@endphp
<span class="time" data-time="{{ $role ?? '' }}">
  <select id="{{ $id }}" name="{{ $base }}_h]" class="input" aria-label="{{ $label }}: saat">
    <option value="">--</option>
    @for ($i = 0; $i < 24; $i++)@php $v = sprintf('%02d', $i); @endphp<option value="{{ $v }}" @if ($hh === $v) selected @endif>{{ $v }}</option>@endfor
  </select>
  <strong aria-hidden="true">:</strong>
  <select name="{{ $base }}_m]" class="input" aria-label="{{ $label }}: dakika">
    <option value="">--</option>
    @for ($i = 0; $i < 60; $i++)@php $v = sprintf('%02d', $i); @endphp<option value="{{ $v }}" @if ($mm === $v) selected @endif>{{ $v }}</option>@endfor
  </select>
</span>
