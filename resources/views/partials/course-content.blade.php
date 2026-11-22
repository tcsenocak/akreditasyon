@php($info = $course->info)
@if ($info || $course->content)
    <details class="small" style="margin-top:2px">
        <summary style="font-weight:400">İçerik</summary>
        @if ($info)
            @if ($info->description)<div><b>Tanım:</b> {{ $info->description }}</div>@endif
            @if ($info->objective)<div><b>Amaç:</b> {{ $info->objective }}</div>@endif
            @if ($info->prerequisites && $info->prerequisites !== '-')<div><b>Ön koşul:</b> {{ $info->prerequisites }}</div>@endif
            @if ($info->weekly_topics)
                <div><b>Haftalık konular:</b></div>
                <ol style="margin:2px 0 4px 18px;padding:0">@foreach ($info->weekly_topics as $topic)<li>{{ $topic }}</li>@endforeach</ol>
            @endif
            @if ($info->outcomes)
                <div><b>Öğrenme çıktıları:</b></div>
                <ul style="margin:2px 0 4px 18px;padding:0">@foreach ($info->outcomes as $outcome)<li>{{ $outcome }}</li>@endforeach</ul>
            @endif
            <div class="muted">Kaynak: OBS Ders Bilgi Paketi{{ $info->offering_label ? ' — '.$info->offering_label : '' }}</div>
        @else
            <div>{{ $course->content }}</div>
        @endif
    </details>
@endif
