@extends('layouts.app')
@section('title', $program->full_name)
@section('content')
<div class="obs-hero">
    <a class="obs-back" href="{{ route('programs.index') }}" aria-label="Programlara dön">←</a>
    <div class="obs-eyebrow"><span></span>ÖĞRETİM PLANI · DERS BİLGİ PAKETİ</div>
    <div class="obs-title">
        <span class="obs-title-icon"><x-icon name="building" :size="20" /></span>
        <h1>{{ $program->name }} Programı @if ($program->obs_program_id)<span class="obs-id">({{ $program->obs_program_id }})</span>@endif</h1>
    </div>
    <p class="obs-lead">Programın bilgileri ile giriş yılına göre müfredat dersleri ve AKTS kredileri aşağıdaki sekmelerde listelenir.</p>
    <nav class="obs-tabs">
        <a href="{{ route('programs.show', ['program' => $program, 'plan' => $curriculum?->id, 'sekme' => 'birim']) }}" @class(['active' => $tab === 'birim'])><x-icon name="info" :size="14" /> Birim Bilgileri</a>
        <a href="{{ route('programs.show', ['program' => $program, 'plan' => $curriculum?->id]) }}" @class(['active' => $tab === 'mufredat'])><x-icon name="book" :size="14" /> Müfredat Dersleri ve AKTS Kredileri</a>
        <a href="{{ route('programs.show', ['program' => $program, 'plan' => $curriculum?->id, 'sekme' => 'icerik']) }}" @class(['active' => $tab === 'icerik'])><x-icon name="file" :size="14" /> Ders İçerikleri</a>
    </nav>
</div>

@if ($tab === 'birim')
    <div class="card obs-panel">
        <dl class="obs-facts">
            <dt>Program</dt><dd>{{ $program->full_name }}</dd>
            <dt>Fakülte</dt><dd>{{ $program->faculty }}</dd>
            <dt>Bölüm</dt><dd>{{ $program->department }}</dd>
            <dt>Öğretim dili</dt><dd>{{ $program->language === 'EN' ? 'İngilizce' : 'Türkçe' }}</dd>
            <dt>Ders kodu öneki</dt><dd>{{ $program->code_prefix ?: '—' }}</dd>
            @if ($program->obs_program_id)
                <dt>OBS kaynağı</dt><dd><a href="https://obs.atauni.edu.tr/moduller/dbp/eobs/birimDetay/{{ $program->obs_program_id }}" target="_blank" rel="noopener">OBS Ders Bilgi Paketi ({{ $program->obs_program_id }})</a></dd>
            @endif
        </dl>
        <h2 style="margin-top:18px">Öğretim planları</h2>
        <div class="table-wrap">
            <table>
                <tr><th>Plan</th><th>Kaynak</th><th class="num">Ders</th><th class="num">AKTS</th><th></th></tr>
                @foreach ($curricula as $c)
                    <tr>
                        <td><a href="{{ route('programs.show', ['program' => $program, 'plan' => $c->id]) }}">{{ $c->title }}</a></td>
                        <td class="small muted">{{ $c->source }}</td>
                        <td class="num">{{ $c->courses_count }}</td>
                        <td class="num">{{ (float) $c->total_ects }}</td>
                        <td>
                            @if ($c->is_active)<span class="badge ok">aktif plan</span>@else
                                <form method="POST" action="{{ route('curricula.activate', $c) }}" class="inline">@csrf<button class="small light">Aktif yap</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
@else
    <div class="card obs-panel">
        @if (!$curriculum)
            <p class="muted" style="margin:0">Bu programın öğretim planı yok. <a href="{{ route('programs.index') }}">Programlar</a> sayfasından OBS'den güncelleyin.</p>
        @else
            <form method="GET" class="obs-year">
                <label for="plan-sec">AKADEMİK YIL</label>
                <select id="plan-sec" name="plan" onchange="this.form.submit()">
                    @foreach ($curricula as $c)
                        <option value="{{ $c->id }}" @selected($c->id === $curriculum->id)>{{ $c->start_year ? $c->start_year.' Akademik Yılı' : $c->title }}{{ $c->is_active ? ' · aktif plan' : '' }}{{ str_contains((string) $c->source, 'OBS') ? '' : ' · '.$c->title }}</option>
                    @endforeach
                </select>
                <noscript><button class="small">Göster</button></noscript>
                @if ($tab === 'icerik')<input type="hidden" name="sekme" value="icerik">@endif
                <span class="small muted">Öğrencinin giriş yılını seçin. {{ $curriculum->courses_count }} ders · {{ (float) $curriculum->total_ects }} AKTS</span>
            </form>
            @if ($curricula->count() > 1)
                <div class="obs-plans">
                    <span class="small muted">Müfredatlar:</span>
                    @foreach ($curricula as $c)
                        <a href="{{ route('programs.show', ['program' => $program, 'plan' => $c->id, 'sekme' => $tab === 'icerik' ? 'icerik' : null]) }}" @class(['obs-plan', 'on' => $c->id === $curriculum->id])>{{ $c->start_year ?: $c->title }}@if (! str_contains((string) $c->source, 'OBS')) <span class="muted">({{ str_contains((string) $c->source, '.doc') ? 'ders bilgi paketi' : 'Excel' }})</span>@endif @if ($c->is_active)<span class="badge ok">aktif</span>@endif</a>
                    @endforeach
                </div>
            @endif

            @if ($tab === 'icerik')
                <p class="small muted">Derse tıklayınca içeriği açılır. İçerikler OBS Ders Bilgi Paketi'nden alınmıştır.</p>
                @php($lastSemester = false)
                @foreach ($contents as $course)
                    @php($sem = \App\Services\CurriculumTable::semesterOf($course))
                    @if ($sem !== $lastSemester)
                        <h3 class="obs-content-sem">{{ $sem ? $sem.'. Yarıyıl' : 'Yarıyılı belirtilmeyen seçmeliler' }}</h3>
                        @php($lastSemester = $sem)
                    @endif
                    <details class="obs-content">
                        <summary>
                            <span @class(['obs-type', 'sec' => \App\Services\CurriculumTable::isElective($course)])>{{ \App\Services\CurriculumTable::isElective($course) ? 'S' : 'Z' }}</span>
                            <span class="mono small">{{ $course->code }}</span> <b>{{ $course->name }}</b>
                            <span class="small muted">{{ (float) $course->credit }} kredi · {{ number_format((float) $course->ects, 1) }} AKTS</span>
                            @unless ($course->info || $course->content)<span class="badge">içerik yok</span>@endunless
                        </summary>
                        <div class="obs-content-body">
                            @if ($course->info)
                                @if ($course->info->objective)<p><b>Amaç:</b> {{ $course->info->objective }}</p>@endif
                                @if ($course->info->description)<p><b>İçerik:</b> {{ $course->info->description }}</p>@endif
                                @if ($course->info->weekly_topics)<p><b>Haftalık konular:</b></p><ol>@foreach ($course->info->weekly_topics as $topic)<li>{{ $topic }}</li>@endforeach</ol>@endif
                                @if ($course->info->outcomes)<p><b>Öğrenme çıktıları:</b></p><ul>@foreach ($course->info->outcomes as $outcome)<li>{{ $outcome }}</li>@endforeach</ul>@endif
                            @elseif ($course->content)
                                <p>{{ $course->content }}</p>
                            @else
                                <p class="muted">OBS'de bu ders için içerik girilmemiş.</p>
                            @endif
                            <a class="small" href="{{ route('curricula.course', [$curriculum, $course]) }}">Ders detayını aç →</a>
                        </div>
                    </details>
                @endforeach
            @else

            <div class="obs-grid">
                @foreach ($table['semesters'] as $semester => $s)
                    <section class="obs-sem">
                        <table>
                            <thead>
                                <tr class="obs-sem-head"><th colspan="5">{{ $semester }}. Yarıyıl</th></tr>
                                <tr><th style="width:34px"></th><th style="width:96px">D. KOD.</th><th>DERSİN ADI</th><th class="num">KREDİ</th><th class="num">AKTS</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($s['rows'] as $course)
                                    @php($elective = \App\Services\CurriculumTable::isElective($course))
                                    <tr class="obs-row" title="Teori: {{ (float) $course->theory }}, Uygulama: {{ (float) $course->practice }}, Lab: {{ (float) $course->lab }}">
                                        <td><span @class(['obs-type', 'sec' => $elective])>{{ $elective ? 'S' : 'Z' }}</span></td>
                                        <td class="mono small">{{ $course->code ?: '—' }}</td>
                                        <td><a href="{{ route('curricula.course', [$curriculum, $course]) }}">{{ $course->name }}</a>@if ($course->is_placeholder) <span class="small muted">(seçmeli yuvası)</span>@endif</td>
                                        <td class="num">{{ (float) $course->credit ?: '—' }}</td>
                                        <td class="num">{{ number_format((float) $course->ects, 1) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr><td><span class="obs-type">Z</span></td><td colspan="3">Toplam Zorunlu</td><td class="num">{{ number_format($s['required'], 1) }}</td></tr>
                                @if ($s['elective'] > 0)<tr><td><span class="obs-type sec">S</span></td><td colspan="3">Toplam Seçmeli (seçenekler)</td><td class="num">{{ number_format($s['elective'], 1) }}</td></tr>@endif
                                <tr class="obs-planned"><td></td><td colspan="3">Yarıyıl planı</td><td class="num">{{ number_format($s['planned'], 1) }}</td></tr>
                            </tfoot>
                        </table>
                    </section>
                @endforeach
            </div>

            @if ($table['pools']->isNotEmpty())
                <h2 style="margin-top:18px">Yarıyılı belirtilmeyen seçmeli dersler</h2>
                <div class="table-wrap">
                    <table>
                        <tr><th style="width:96px">D. KOD.</th><th>DERSİN ADI</th><th>Havuz</th><th class="num">KREDİ</th><th class="num">AKTS</th></tr>
                        @foreach ($table['pools'] as $course)
                            <tr>
                                <td class="mono small">{{ $course->code ?: '—' }}</td>
                                <td><a href="{{ route('curricula.course', [$curriculum, $course]) }}">{{ $course->name }}</a></td>
                                <td class="small muted">{{ \App\Models\CurriculumCourse::CATEGORIES[$course->category] ?? $course->category }}</td>
                                <td class="num">{{ (float) $course->credit }}</td>
                                <td class="num">{{ number_format((float) $course->ects, 1) }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif

            @endif

            <div class="row" style="margin-top:14px;justify-content:space-between">
                <span class="small muted">Z: zorunlu, S: seçmeli. Satırın üzerine gelince teori/uygulama/lab saatleri görünür; dersin adına tıklayınca ayrıntısı açılır. Kaynak: {{ $curriculum->source }}</span>
                <div class="row">
                    @unless ($curriculum->is_active)
                        <form method="POST" action="{{ route('curricula.activate', $curriculum) }}" class="inline">@csrf<button class="small light">Bu planı aktif yap</button></form>
                    @endunless
                    <form method="POST" action="{{ route('curricula.destroy', $curriculum) }}" class="inline" onsubmit="return confirm('Bu öğretim planı ve bağlı muafiyet dosyaları silinecek. Emin misiniz?')">@csrf @method('DELETE')<button class="small danger">Planı sil</button></form>
                </div>
            </div>
        @endif
    </div>
@endif
@endsection
