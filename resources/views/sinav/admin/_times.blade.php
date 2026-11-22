{{-- Bir sınav zamanı: gün + başlangıç + bitiş + yayın saati. $p: "common" veya "groups[5]"; $t: ['date','start','end','publish'] --}}
@php $touched = $t['publish'] !== '' && $t['publish'] !== $t['end']; @endphp
<div class="grid2" data-times>
  <div class="field"><label for="{{ $key }}-date">Sınav günü</label>
    <input id="{{ $key }}-date" type="date" name="{{ $p }}[date]" class="input" value="{{ $t['date'] }}"></div>
  <div class="row" style="gap:16px">
    <div class="field"><label for="{{ $key }}-start">Başlangıç saati</label>
      @include('sinav.admin._time', ['id' => $key . '-start', 'name' => $p . '[start]', 'value' => $t['start'], 'label' => 'Başlangıç', 'role' => 'start'])</div>
    <div class="field"><label for="{{ $key }}-end">Bitiş saati</label>
      @include('sinav.admin._time', ['id' => $key . '-end', 'name' => $p . '[end]', 'value' => $t['end'], 'label' => 'Bitiş', 'role' => 'end'])</div>
  </div>
  <div class="field"><label for="{{ $key }}-pub">Cevap anahtarı yayın saati</label>
    @include('sinav.admin._time', ['id' => $key . '-pub', 'name' => $p . '[publish]', 'value' => $t['publish'], 'label' => 'Yayın saati', 'role' => 'publish'])
    <input type="hidden" data-touched value="{{ $touched ? '1' : '' }}">
    <div class="hint" data-pub-hint>{{ $touched ? 'Elle değiştirildi (aynı gün).' : 'Bitiş saatiyle aynı ayarlanır; isterseniz değiştirin (aynı gün).' }}</div>
  </div>
</div>
