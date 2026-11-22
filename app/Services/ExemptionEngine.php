<?php

namespace App\Services;

use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use App\Models\Equivalence;
use App\Models\ExemptionFile;
use App\Models\ExemptionItem;
use App\Models\Student;
use App\Models\TranscriptCourse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Muafiyet önerilerini üretir ve yarıyıl intibakını hesaplar.
 *
 * Ders Muafiyeti ve İntibak İşlemleri Uygulama Esasları (15.03.2024):
 *  - Md. 4/ç  Muafiyet: AKTS değeri ve içerik uyumuna göre denklik
 *  - Md. 5/3  Birden fazla derse karşılık muadil ders
 *  - Md. 5/8  YDS ≥ 50: ortak zorunlu yabancı dil + en fazla 2 mesleki yabancı dil
 *  - Md. 6/3  G/M → CC
 *  - Md. 6/5  AİİT, Türk Dili, Yabancı Dil, İSG: AKTS/içerik aranmaz; açıköğretim yıllık ders iki yarıyıl sayılır
 *  - Md. 6/6  Temel bilgisayar muafiyet sınavı ≥ 50, Tablo I ile harf
 *  - Md. 6/7  Hazırlık başarısı → ortak zorunlu yabancı dil muafiyeti
 *  - Md. 7    Muaf AKTS / 30 → yarıyıl sayısı, intibak izleyen yarıyıla
 */
class ExemptionEngine
{
    private const ORTAK_ZORUNLU = ['AIIT', 'TD', 'YD', 'ISG'];

    public function __construct(private GradeService $grades) {}

    /**
     * Önerileri üretir. Komisyonun kabul/ret kararı verdiği satırlar korunur.
     *
     * @return array{created: int, kept: int}
     */
    public function generate(ExemptionFile $file): array
    {
        $file->loadMissing(['student.transcript', 'curriculum.courses.info']);
        $student = $file->student;
        $options = $file->options ?? [];

        return DB::transaction(function () use ($file, $student, $options): array {
            $file->items()->where('status', 'oneri')->delete();
            $kept = $file->items()->with('sources')->get();
            $usedTargets = $kept->pluck('curriculum_course_id')->all();
            $usedSources = $kept->flatMap(fn (ExemptionItem $item) => $item->sources->pluck('id'))->all();

            $sources = $student->transcript->filter(fn (TranscriptCourse $c) => $c->passed && ! in_array($c->id, $usedSources, true))->values();
            $targets = $file->curriculum->courses
                ->filter(fn (CurriculumCourse $c) => ! in_array($c->category, ['alternative', 'preparatory'], true) && ! in_array($c->id, $usedTargets, true))
                ->values();

            $proposals = [];
            $claimSource = function (TranscriptCourse $source) use (&$sources): void {
                $sources = $sources->reject(fn (TranscriptCourse $s) => $s->id === $source->id)->values();
            };
            $claimTarget = function (CurriculumCourse $target) use (&$targets): void {
                $targets = $targets->reject(fn (CurriculumCourse $t) => $t->id === $target->id)->values();
            };

            // 1) Eşdeğerlik havuzu (önceki komisyon kararları)
            foreach ($sources as $source) {
                $target = $this->findInPool($source, $targets, $student->previous_institution, $file->curriculum->program_id);
                if ($target) {
                    $proposals[] = $this->proposal($target, [$source], 'havuz', 1.0, 'Havuzda kayıtlı önceki karar');
                    $claimTarget($target);
                }
            }
            $sources = $sources->reject(fn (TranscriptCourse $s) => collect($proposals)->contains(fn ($p) => in_array($s->id, array_map(fn ($x) => $x->id, $p['sources']), true)))->values();

            // 2) Ortak zorunlu dersler (Md. 6/5)
            foreach ($targets->filter(fn (CurriculumCourse $t) => in_array($t->common_group, self::ORTAK_ZORUNLU, true)) as $target) {
                $match = $this->bestCommonSource($target, $sources);
                if ($match) {
                    $reason = $match['annual'] ? 'Yıllık ders iki yarıyıl için sayıldı (Md. 6/5)' : null;
                    $proposals[] = $this->proposal($target, [$match['source']], 'ortak_zorunlu', 1.0, $reason, true);
                    $claimTarget($target);
                    if (! $match['annual']) {
                        $claimSource($match['source']);
                    }
                }
            }
            // yıllık kaynak dersler artık kullanılmaz
            $sources = $sources->reject(fn (TranscriptCourse $s) => collect($proposals)->where('rule', 'ortak_zorunlu')->contains(fn ($p) => $p['sources'][0]->id === $s->id))->values();

            // 3) Aynı kod veya birebir aynı ad
            foreach ($sources as $source) {
                $root = TextNormalizer::rootCode($source->code);
                $target = $targets->first(fn (CurriculumCourse $t) => ! $t->is_placeholder && (
                    ($root && $t->root_code === $root && TextNormalizer::similarity($t->name, $source->name) >= 0.5)
                    || $t->normalized_name === $source->normalized_name
                    || ($t->name_en && TextNormalizer::normalize($t->name_en) === $source->normalized_name)
                ));
                if ($target) {
                    $proposals[] = $this->proposal($target, [$source], 'ayni_ders', 1.0, null);
                    $claimTarget($target);
                    $claimSource($source);
                }
            }

            // 3b) Öğrencinin dilekçede belirttiği talep (Md. 5/2): talep edilen AÜ dersiyle eşleştir
            foreach ($sources as $source) {
                $target = $source->requested_course_id ? $targets->firstWhere('id', $source->requested_course_id) : null;
                if (! $target) {
                    continue;
                }
                $score = max(
                    TextNormalizer::similarity($source->name, $target->name),
                    $target->name_en ? TextNormalizer::similarity($source->name, $target->name_en) : 0.0,
                );
                $proposals[] = $this->proposal($target, [$source], 'esdeger', $score, 'Öğrenci talebi (Md. 5/2)');
                $claimTarget($target);
                $claimSource($source);
            }

            // 4) Ad benzerliği + AKTS (greedy en iyi eşleşme)
            $pairs = [];
            foreach ($sources as $source) {
                foreach ($targets->reject(fn (CurriculumCourse $t) => $t->is_placeholder) as $target) {
                    $score = max(
                        TextNormalizer::similarity($source->name, $target->name),
                        $target->name_en ? TextNormalizer::similarity($source->name, $target->name_en) : 0.0,
                    );
                    // Kaynak dersin içeriği girildiyse AÜ ders içeriğiyle karşılaştır (Md. 4/ç içerik uyumu).
                    $content = $source->content ? TextNormalizer::contentSimilarity($source->content, $target->contentText()) : null;
                    if ($content !== null) {
                        $score = round(max($score, 0.55 * $score + 0.45 * min(1.0, $content / 0.45)), 3);
                    }
                    if ($score >= config('intibak.match_possible')) {
                        $pairs[] = ['source' => $source, 'target' => $target, 'score' => $score, 'content' => $content];
                    }
                }
            }
            usort($pairs, fn ($a, $b) => $b['score'] <=> $a['score'] ?: ($a['target']->category === 'plan' ? -1 : 1));
            $takenSources = [];
            $takenTargets = [];
            foreach ($pairs as $pair) {
                if (isset($takenSources[$pair['source']->id]) || isset($takenTargets[$pair['target']->id])) {
                    continue;
                }
                $takenSources[$pair['source']->id] = true;
                $takenTargets[$pair['target']->id] = true;
                $proposals[] = $this->proposal($pair['target'], [$pair['source']], 'esdeger', $pair['score'],
                    $pair['content'] !== null ? 'İçerik benzerliği %'.round($pair['content'] * 100) : null);
            }
            $sources = $sources->reject(fn (TranscriptCourse $s) => isset($takenSources[$s->id]))->values();
            $targets = $targets->reject(fn (CurriculumCourse $t) => isset($takenTargets[$t->id]))->values();

            // 4b) AKTS ve kredisi yetmeyen derslerde aynı adlı dersleri (I+II...) birlikte say
            [$proposals, $usedFree] = $this->combineSameNamed($proposals, $sources);
            $sources = $sources->reject(fn (TranscriptCourse $s) => isset($usedFree[$s->id]))->values();

            // 5) Kalan başarılı dersler için seçmeli yuvası önerisi (komisyon onayı gerekir)
            foreach ($targets->filter(fn (CurriculumCourse $t) => $t->is_placeholder && $t->category === 'plan')->sortBy('semester') as $slot) {
                $candidate = $sources->first(fn (TranscriptCourse $s) => $this->covers([$s], $slot)
                    && (($slot->type === 'ÜS') === ($this->looksNonTechnical($s->name))));
                if ($candidate) {
                    $proposals[] = $this->proposal($slot, [$candidate], 'esdeger', 0.4, 'Seçmeli ders yuvası — içerik komisyonca değerlendirilmeli', false, 'oneri');
                    $claimTarget($slot);
                    $claimSource($candidate);
                }
            }

            // 6) YDS ve hazırlık (Md. 5/8, 6/7)
            $remainingYd = $targets->filter(fn (CurriculumCourse $t) => $t->common_group === 'YD');
            $ydsOk = ($student->yds_score ?? 0) >= config('intibak.yds_min_score');
            foreach ($remainingYd as $target) {
                if ($student->prep_completed) {
                    $proposals[] = $this->proposal($target, [], 'hazirlik', 1.0, 'Hazırlık sınıfı başarıyla tamamlandı', true, 'kabul', 'CC');
                    $claimTarget($target);
                } elseif ($ydsOk) {
                    $proposals[] = $this->proposal($target, [], 'yds', 1.0, ($student->yds_exam ?: 'YDS').' puanı '.$student->yds_score, true, 'kabul', 'CC');
                    $claimTarget($target);
                }
            }
            if ($ydsOk) {
                $limit = (int) config('intibak.professional_language_max_courses');
                foreach ($targets->filter(fn (CurriculumCourse $t) => $t->common_group === 'MYD' && $t->category === 'plan')->sortBy('semester')->take($limit) as $target) {
                    $proposals[] = $this->proposal($target, [], 'yds', 1.0, 'Mesleki yabancı dil (en fazla '.$limit.' ders, Md. 5/8)', true, 'kabul', 'CC');
                    $claimTarget($target);
                }
            }

            // 7) Muafiyet sınavı puanları (Md. 6/6)
            foreach (($options['exam_scores'] ?? []) as $courseId => $score) {
                $target = $targets->firstWhere('id', (int) $courseId);
                if ($target && $score !== null && $score !== '') {
                    $passed = (float) $score >= config('intibak.exam_pass_score');
                    $proposals[] = $this->proposal(
                        $target, [], 'muafiyet_sinavi', 1.0,
                        'Sınav puanı '.$score.($passed ? '' : ' — 50 altı, muaf sayılmaz'),
                        true, $passed ? 'kabul' : 'ret', $this->grades->letterFromScore((float) $score), (float) $score,
                    );
                    $claimTarget($target);
                }
            }

            foreach ($proposals as $proposal) {
                $this->store($file, $proposal);
            }

            $this->recalculate($file);

            return ['created' => count($proposals), 'kept' => $kept->count()];
        });
    }

    /**
     * Öğrenci için otomatik eşleştirme önerisi: motor geçici bir dosyada çalıştırılır, sonuç geri alınır.
     *
     * @return list<array{target_id: int, source_ids: list<int>, rule: string, score: float}>
     */
    public function suggestRequests(Student $student, Curriculum $curriculum): array
    {
        DB::beginTransaction();
        try {
            $file = ExemptionFile::query()->create(['student_id' => $student->id, 'curriculum_id' => $curriculum->id, 'options' => ['exam_scores' => []]]);
            $this->generate($file);

            return $file->items()->with('sources')->get()
                ->filter(fn (ExemptionItem $i) => $i->sources->isNotEmpty())
                ->map(fn (ExemptionItem $i) => [
                    'target_id' => $i->curriculum_course_id,
                    'source_ids' => $i->sources->pluck('id')->all(),
                    'rule' => $i->rule,
                    'score' => $i->score,
                ])->values()->all();
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Komisyon dosyasını öğrencinin belirlediği eşleştirmelerden kurar (Md. 5/2).
     * Her satıra AKTS/kredi yükü, ad ve içerik benzerliği, havuz bilgisi ve not dönüşümü eklenir;
     * karar komisyondadır (satırlar "öneri" olarak gelir). Kararlı satırlar korunur.
     *
     * @return array{created: int, kept: int}
     */
    public function buildFromRequests(ExemptionFile $file): array
    {
        $file->loadMissing(['student.transcript', 'curriculum.courses.info']);
        $student = $file->student;

        return DB::transaction(function () use ($file, $student): array {
            $file->items()->where('status', 'oneri')->delete();
            $kept = $file->items()->get();
            $decided = $kept->pluck('curriculum_course_id')->all();
            $targets = $file->curriculum->courses->keyBy('id');
            $created = 0;

            $requests = $student->transcript->whereNotNull('requested_course_id')->groupBy('requested_course_id');
            foreach ($requests as $targetId => $sources) {
                $target = $targets->get((int) $targetId);
                if (! $target || in_array($target->id, $decided, true)) {
                    continue;
                }
                $sources = $sources->values();
                $nameScore = $sources->map(fn (TranscriptCourse $s) => max(
                    TextNormalizer::similarity($s->name, $target->name),
                    $target->name_en ? TextNormalizer::similarity($s->name, $target->name_en) : 0.0,
                ))->max();
                $content = TextNormalizer::contentSimilarity($sources->pluck('content')->filter()->implode("\n"), $target->contentText());
                $common = in_array($target->common_group, self::ORTAK_ZORUNLU, true);

                $reasons = [];
                if ($common) {
                    $reasons[] = 'Ortak zorunlu ders: AKTS ve içerik aranmaz (Md. 6/5)';
                }
                if ($sources->count() > 1) {
                    $reasons[] = $sources->count().' ders birlikte';
                }
                if ($sources->contains(fn (TranscriptCourse $s) => ! $s->passed)) {
                    $reasons[] = 'Kaynak derslerden biri başarısız — muafiyete esas alınamaz';
                }
                if ($this->inPool($sources->all(), $target, $student->previous_institution, $file->curriculum->program_id)) {
                    $reasons[] = 'Eşdeğerlik havuzunda önceki kabul kararı var';
                }
                if ($content !== null) {
                    $reasons[] = 'İçerik benzerliği %'.round($content * 100);
                }

                $proposal = $this->proposal($target, $sources->all(), 'ogrenci', round($nameScore, 3), implode('; ', $reasons) ?: null, $common, 'oneri');
                $proposal['content_score'] = $content;
                if ($sources->contains(fn (TranscriptCourse $s) => ! $s->passed)) {
                    $proposal['ects_ok'] = false;
                }
                $this->store($file, $proposal);
                $created++;
            }

            // Öğrencinin beyan ettiği yabancı dil durumu (belgesi komisyonca kontrol edilir)
            $ydsOk = ($student->yds_score ?? 0) >= config('intibak.yds_min_score');
            if ($student->prep_completed || $ydsOk) {
                $taken = $file->items()->pluck('curriculum_course_id')->all();
                foreach ($targets->filter(fn (CurriculumCourse $t) => $t->common_group === 'YD' && ! in_array($t->id, $taken, true)) as $target) {
                    $this->store($file, $this->proposal(
                        $target, [], $student->prep_completed ? 'hazirlik' : 'yds', 1.0,
                        $student->prep_completed ? 'Öğrenci beyanı: hazırlık başarılı — belgeyi kontrol edin' : 'Öğrenci beyanı: '.($student->yds_exam ?: 'YDS').' '.$student->yds_score.' — belgeyi kontrol edin',
                        true, 'oneri', 'CC',
                    ));
                    $created++;
                }
            }

            // Komisyonun yaptığı temel bilgisayar muafiyet sınavı (Md. 6/6)
            $taken = $file->items()->pluck('curriculum_course_id')->all();
            foreach (($file->options['exam_scores'] ?? []) as $courseId => $score) {
                $target = $targets->get((int) $courseId);
                if (! $target || in_array($target->id, $taken, true) || $score === null || $score === '') {
                    continue;
                }
                $passed = (float) $score >= config('intibak.exam_pass_score');
                $this->store($file, $this->proposal(
                    $target, [], 'muafiyet_sinavi', 1.0, 'Sınav puanı '.$score.($passed ? '' : ' — 50 altı, muaf sayılmaz'),
                    true, $passed ? 'kabul' : 'ret', $this->grades->letterFromScore((float) $score), (float) $score,
                ));
                $created++;
            }

            $this->recalculate($file);

            return ['created' => $created, 'kept' => $kept->count()];
        });
    }

    /**
     * @param  list<TranscriptCourse>  $sources
     */
    private function inPool(array $sources, CurriculumCourse $target, ?string $institution, int $programId): bool
    {
        $names = array_map(fn (TranscriptCourse $s) => $s->normalized_name, $sources);

        return Equivalence::query()->whereIn('ext_normalized_name', $names)
            ->where('target_normalized_name', $target->normalized_name)
            ->where(fn ($q) => $q->where('program_id', $programId)->orWhereNull('program_id'))
            ->exists();
    }

    /**
     * Kabul edilen derslerin AKTS toplamı ve intibak yarıyılı (Md. 7/1).
     */
    public function recalculate(ExemptionFile $file): void
    {
        $ects = (float) $file->acceptedItems()->join('curriculum_courses', 'curriculum_courses.id', '=', 'exemption_items.curriculum_course_id')->sum('curriculum_courses.ects');
        $file->exempt_ects = $ects;
        $file->placed_semester = self::semesterFor($ects, $file->curriculum->semester_count ?? 8);
        $file->save();
    }

    public static function semesterFor(float $ects, int $semesterCount = 8): int
    {
        $completed = (int) floor($ects / (int) config('intibak.ects_per_semester', 30));

        return max(1, min($semesterCount, $completed + 1));
    }

    /**
     * Kabul edilen eşleştirmeleri eşdeğerlik havuzuna yazar.
     */
    public function rememberDecisions(ExemptionFile $file): int
    {
        $file->loadMissing(['student', 'curriculum']);
        $count = 0;
        foreach ($file->acceptedItems()->with(['course', 'sources'])->get() as $item) {
            if (! in_array($item->rule, ['ogrenci', 'esdeger', 'manuel', 'havuz'], true)) {
                continue;
            }
            foreach ($item->sources as $source) {
                $equivalence = Equivalence::query()->firstOrNew([
                    'normalized_institution' => $this->normalizeInstitution($file->student->previous_institution),
                    'ext_normalized_name' => $source->normalized_name,
                    'program_id' => $file->curriculum->program_id,
                    'target_normalized_name' => $item->course->normalized_name,
                ]);
                $equivalence->fill([
                    'institution' => $file->student->previous_institution,
                    'ext_code' => $source->code,
                    'ext_name' => $source->name,
                    'ext_ects' => $source->ects,
                    'target_code' => $item->course->code,
                    'target_name' => $item->course->name,
                    'decision_ref' => $file->decision_no,
                ]);
                $equivalence->times_used = $equivalence->exists ? $equivalence->times_used + 1 : 1;
                $equivalence->save();
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<TranscriptCourse>  $sources
     * @return array<string, mixed>
     */
    private function proposal(
        CurriculumCourse $target,
        array $sources,
        string $rule,
        float $score,
        ?string $reason,
        bool $skipEctsCheck = false,
        ?string $status = null,
        ?string $letter = null,
        ?float $examScore = null,
    ): array {
        $load = $this->courseLoad($sources, $target);
        $ectsOk = $skipEctsCheck || $load['ects_enough'] || $load['credit_enough'];
        $reasons = array_filter([$reason]);
        if (! $skipEctsCheck) {
            if (! $load['ects_enough'] && $load['credit_enough']) {
                $reasons[] = $load['has_ects']
                    ? "AKTS düşük ({$load['ects']} < {$target->ects}) ancak kredi yeterli ({$load['credit']} ≥ {$target->credit})"
                    : "AKTS bilgisi yok, kredi yeterli ({$load['credit']} ≥ {$target->credit})";
            } elseif (! $ectsOk) {
                $reasons[] = 'AKTS ve kredi yetersiz ('
                    .($load['has_ects'] ? "AKTS {$load['ects']} < {$target->ects}" : 'AKTS yok').', '
                    .($load['has_credit'] ? "kredi {$load['credit']} < {$target->credit}" : 'kredi yok').')';
            }
        }
        if (collect($sources)->contains(fn (TranscriptCourse $s) => in_array($s->letter, config('intibak.conditional_pass_letters'), true))) {
            $reasons[] = 'Kaynakta DC/DD koşullu not var';
        }

        if ($status === null) {
            $status = match (true) {
                in_array($rule, ['havuz', 'ortak_zorunlu'], true) => 'kabul',
                $rule === 'ayni_ders' && $ectsOk => 'kabul',
                $rule === 'esdeger' && $score >= config('intibak.match_strong') && $ectsOk => 'kabul',
                default => 'oneri',
            };
        }

        $letter ??= $this->grades->combine(array_map(fn (TranscriptCourse $s) => ['letter' => $s->letter, 'ects' => $s->ects], $sources));

        return [
            'target' => $target,
            'sources' => $sources,
            'rule' => $rule,
            'score' => $score,
            'status' => $status,
            'ects_ok' => $ectsOk,
            'reason' => $reasons ? implode('; ', $reasons) : null,
            'letter' => $letter,
            'exam_score' => $examScore,
        ];
    }

    /**
     * Kaynak derslerin hedef derse yükünü karşılaştırır: önce AKTS, AKTS tutmazsa ulusal kredi.
     *
     * @param  iterable<TranscriptCourse>  $sources
     * @return array{ects: float, credit: float, has_ects: bool, has_credit: bool, ects_enough: bool, credit_enough: bool}
     */
    public function courseLoad(iterable $sources, CurriculumCourse $target): array
    {
        $sources = collect($sources);
        $ects = (float) $sources->sum(fn (TranscriptCourse $s) => (float) ($s->ects ?? 0));
        $credit = (float) $sources->sum(fn (TranscriptCourse $s) => (float) ($s->credit ?? 0));
        $hasEcts = $sources->isNotEmpty() && $sources->every(fn (TranscriptCourse $s) => $s->ects !== null);
        $hasCredit = $sources->isNotEmpty() && $sources->every(fn (TranscriptCourse $s) => $s->credit !== null);

        return [
            'ects' => $ects,
            'credit' => $credit,
            'has_ects' => $hasEcts,
            'has_credit' => $hasCredit,
            'ects_enough' => $hasEcts && $ects >= $target->ects * (float) config('intibak.ects_tolerance'),
            'credit_enough' => $hasCredit && $target->credit > 0 && $credit >= $target->credit,
        ];
    }

    /**
     * AKTS'si ve kredisi yetmeyen eşleşmelerde aynı adlı dersleri sırayla birleştirir:
     * örn. kaynakta Matematik I + Matematik II, toplam AKTS/kredi yetiyorsa AÜ Matematik I yerine sayılır.
     *
     * @param  list<array<string, mixed>>  $proposals
     * @param  Collection<int, TranscriptCourse>  $freeSources
     * @return array{0: list<array<string, mixed>>, 1: array<int, true>}
     */
    private function combineSameNamed(array $proposals, Collection $freeSources): array
    {
        $isWeak = fn (array $p): bool => in_array($p['rule'], ['ayni_ders', 'esdeger'], true)
            && ! $p['ects_ok'] && count($p['sources']) === 1 && ! $p['target']->is_placeholder;
        $usedFree = [];
        $result = [];
        $handled = [];

        foreach ($proposals as $i => $proposal) {
            if (isset($handled[$i]) || ! $isWeak($proposal)) {
                continue;
            }
            $base = TextNormalizer::splitSequence($proposal['target']->name)['base'];
            $group = array_keys(array_filter($proposals, fn (array $q, int $j): bool => ! isset($handled[$j]) && $isWeak($q)
                && $this->sameBase($q['target']->name, $base), ARRAY_FILTER_USE_BOTH));
            foreach ($group as $j) {
                $handled[$j] = true;
            }

            $sequence = fn (string $name): int => TextNormalizer::splitSequence($name)['sequence'] ?? 0;
            $targets = collect($group)->map(fn (int $j) => $proposals[$j]['target'])->sortBy(fn ($t) => $t->sequence ?? 0)->values();
            $pool = collect($group)->map(fn (int $j) => $proposals[$j]['sources'][0])
                ->merge($freeSources->filter(fn (TranscriptCourse $s) => ! isset($usedFree[$s->id]) && $this->sameBase($s->name, $base)))
                ->unique('id')->sortBy(fn (TranscriptCourse $s) => $sequence($s->name))->values()->all();

            $combined = [];
            foreach ($targets as $target) {
                $taken = [];
                while ($pool !== [] && ! $this->covers($taken, $target)) {
                    $taken[] = array_shift($pool);
                }
                if (count($taken) < 2 || ! $this->covers($taken, $target)) {
                    $pool = array_merge($taken, $pool);
                    break;
                }
                $names = implode(' + ', array_map(fn (TranscriptCourse $s) => $s->name, $taken));
                $combined[] = $this->proposal($target, $taken, 'esdeger', 0.9, "Aynı adlı dersler birlikte sayıldı ({$names}); tek tek AKTS/kredi yetersiz");
            }

            if ($combined === []) {
                foreach ($group as $j) {
                    $result[] = $proposals[$j];
                }

                continue;
            }

            $coveredTargets = collect($combined)->map(fn ($p) => $p['target']->id)->all();
            $usedIds = collect($combined)->flatMap(fn ($p) => array_map(fn ($s) => $s->id, $p['sources']))->all();
            foreach ($combined as $p) {
                $result[] = $p;
            }
            foreach ($group as $j) {
                $original = $proposals[$j];
                if (! in_array($original['target']->id, $coveredTargets, true) && ! in_array($original['sources'][0]->id, $usedIds, true)) {
                    $result[] = $original;
                }
            }
            foreach ($usedIds as $id) {
                if ($freeSources->contains('id', $id)) {
                    $usedFree[$id] = true;
                }
            }
        }

        foreach ($proposals as $i => $proposal) {
            if (! isset($handled[$i])) {
                $result[] = $proposal;
            }
        }

        return [$result, $usedFree];
    }

    /**
     * @param  list<TranscriptCourse>  $sources
     */
    private function covers(array $sources, CurriculumCourse $target): bool
    {
        if ($sources === []) {
            return false;
        }
        $load = $this->courseLoad($sources, $target);

        return $load['ects_enough'] || $load['credit_enough'];
    }

    private function sameBase(string $name, string $base): bool
    {
        return TextNormalizer::similarity(TextNormalizer::splitSequence($name)['base'], $base) >= config('intibak.match_strong');
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function store(ExemptionFile $file, array $proposal): ExemptionItem
    {
        $item = $file->items()->create([
            'curriculum_course_id' => $proposal['target']->id,
            'letter' => $proposal['letter'],
            'rule' => $proposal['rule'],
            'score' => $proposal['score'],
            'status' => $proposal['status'],
            'ects_ok' => $proposal['ects_ok'],
            'reason' => $proposal['reason'] ? mb_substr($proposal['reason'], 0, 250) : null,
            'exam_score' => $proposal['exam_score'],
            'content_score' => $proposal['content_score'] ?? null,
        ]);
        $item->sources()->sync(array_map(fn (TranscriptCourse $s) => $s->id, $proposal['sources']));

        return $item;
    }

    /**
     * @param  Collection<int, CurriculumCourse>  $targets
     */
    private function findInPool(TranscriptCourse $source, Collection $targets, ?string $institution, int $programId): ?CurriculumCourse
    {
        $query = Equivalence::query()->where('ext_normalized_name', $source->normalized_name)
            ->where(fn ($q) => $q->where('program_id', $programId)->orWhereNull('program_id'));
        $normalizedInstitution = $this->normalizeInstitution($institution);
        $candidates = $query->get()->sortByDesc(fn (Equivalence $e) => ($e->normalized_institution === $normalizedInstitution ? 1000 : 0) + $e->times_used);

        foreach ($candidates as $equivalence) {
            $target = $targets->first(fn (CurriculumCourse $t) => $t->normalized_name === $equivalence->target_normalized_name
                || ($equivalence->target_code && $t->code === $equivalence->target_code));
            if ($target) {
                return $target;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, TranscriptCourse>  $sources
     * @return array{source: TranscriptCourse, annual: bool}|null
     */
    private function bestCommonSource(CurriculumCourse $target, Collection $sources): ?array
    {
        $candidates = $sources->filter(fn (TranscriptCourse $s) => TextNormalizer::commonGroup($s->name, $s->code) === $target->common_group);
        if ($candidates->isEmpty()) {
            return null;
        }
        $wanted = $target->sequence;
        $exact = $candidates->first(fn (TranscriptCourse $s) => TextNormalizer::splitSequence($s->name)['sequence'] === $wanted);
        if ($exact) {
            return ['source' => $exact, 'annual' => false];
        }
        $annual = $candidates->first(fn (TranscriptCourse $s) => TextNormalizer::splitSequence($s->name)['sequence'] === null);
        if ($annual && $wanted !== null) {
            return ['source' => $annual, 'annual' => true];
        }

        return $annual ? ['source' => $annual, 'annual' => false] : null;
    }

    private function looksNonTechnical(string $name): bool
    {
        return (bool) preg_match('/tarih|sanat|muzik|spor|psikoloji|sosyoloji|felsefe|edebiyat|iletisim|girisim|kariyer|gonullu|hukuk|etik|kultur|beden|resim|fotograf|tiyatro|dil|ingilizce|almanca|history|art|music|sport|psychology|sociology|philosophy|career|ethics|culture/', TextNormalizer::ascii($name));
    }

    private function normalizeInstitution(?string $institution): ?string
    {
        return $institution ? TextNormalizer::normalize($institution) : null;
    }
}
