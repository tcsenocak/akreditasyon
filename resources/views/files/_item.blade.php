@php
    $course = $item->course;
    $letters = array_keys(config('intibak.letters'));
    $sourceContent = $item->sources->pluck('content')->filter()->implode("\n\n");
    $targetContent = $course->contentText();
@endphp
<tr class="st-{{ $item->status }}">
    <td>
        <b>{{ $course->code }}</b> {{ $course->name }}
        <div class="small muted">{{ (float) $course->credit }} kr · {{ (float) $course->ects }} AKTS · {{ $course->type }}</div>
    </td>
    <td>
        @forelse ($item->sources as $source)
            <div>{{ $source->label }}</div>
            <div class="small muted">{{ $source->credit ?? '–' }} kr · {{ $source->ects ?? '–' }} AKTS · {{ $source->grade_raw }} → {{ $source->letter }}{{ $source->department ? ' · '.$source->department : '' }}</div>
        @empty
            <span class="small">{{ \App\Models\ExemptionItem::RULES[$item->rule] ?? $item->rule }}</span>
        @endforelse
    </td>
    <td class="small">
        @if ($item->sources->isNotEmpty())
            @if ($item->ects_ok)<span class="badge ok">AKTS/kredi uygun</span>@else<span class="badge bad">AKTS/kredi yetersiz</span>@endif
            <span class="badge {{ $item->score >= config('intibak.match_strong') ? 'ok' : ($item->score >= config('intibak.match_possible') ? 'warn' : 'bad') }}">ad %{{ round($item->score * 100) }}</span>
            @if ($item->content_score !== null)<span class="badge {{ $item->content_score >= 0.35 ? 'ok' : 'warn' }}">içerik %{{ round($item->content_score * 100) }}</span>@endif
        @endif
        @if ($item->reason)<div class="muted" style="margin-top:3px">{{ $item->reason }}</div>@endif
        @if ($sourceContent || $targetContent)
            <details style="margin-top:4px"><summary>İçerik karşılaştır</summary>
                <div class="compare">
                    <div><b>Öğrencinin ders içeriği</b>{!! $sourceContent ? nl2br(e($sourceContent)) : '<span class="muted">Öğrenci içerik girmemiş; yüklenen onaylı ders içeriği belgesine bakın.</span>' !!}</div>
                    <div><b>AÜ ders içeriği</b>
                        @if ($course->info)
                            @if ($course->info->description){{ $course->info->description }}<br>@endif
                            @if ($course->info->weekly_topics)<ol style="margin:4px 0 0 16px;padding:0">@foreach ($course->info->weekly_topics as $t)<li>{{ $t }}</li>@endforeach</ol>@endif
                        @else
                            {!! $targetContent ? nl2br(e($targetContent)) : '<span class="muted">İçerik yok.</span>' !!}
                        @endif
                    </div>
                </div>
            </details>
        @endif
    </td>
    <td class="no-print dec-cell">
        @php
            $n = 'items['.$item->id.']';
        @endphp
        <div class="row" style="flex-wrap:nowrap">
            <select name="{{ $n }}[status]" form="decisions-form" data-dec-status aria-label="Karar">@foreach (\App\Models\ExemptionItem::STATUSES as $k => $v)<option value="{{ $k }}" @selected($item->status === $k)>{{ $k === 'oneri' ? 'Karar bekliyor' : $v }}</option>@endforeach</select>
            <select name="{{ $n }}[letter]" form="decisions-form" title="Harf notu" aria-label="Harf notu">@foreach ($letters as $l)<option @selected($item->letter === $l)>{{ $l }}</option>@endforeach</select>
        </div>
        <select name="{{ $n }}[reject_reason]" form="decisions-form" data-dec-reason aria-label="Ret gerekçesi" style="width:100%;margin-top:6px" @if ($item->status !== 'ret') hidden @endif>
            <option value="">Ret gerekçesi seçin</option>
            @foreach (\App\Models\ExemptionItem::REJECT_REASONS as $k => $v)<option value="{{ $k }}" @selected($item->reject_reason === $k)>{{ $v }}</option>@endforeach
        </select>
        <input name="{{ $n }}[commission_note]" form="decisions-form" value="{{ $item->commission_note }}" placeholder="İçerik / değerlendirme notu" style="width:100%;margin-top:6px" aria-label="Komisyon notu">
        @if ($item->decider)<div class="small muted" style="margin-top:4px">son işlem: {{ $item->decider->display_name }}</div>@endif
    </td>
</tr>
