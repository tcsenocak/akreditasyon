<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\ExemptionFile;
use App\Models\ExemptionItem;
use App\Models\Student;
use App\Models\User;
use App\Services\ExemptionEngine;
use App\Services\Notifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExemptionFileController extends Controller
{
    public function __construct(private ExemptionEngine $engine) {}

    /**
     * Dosyalar: atanan üye, bekleme süresi; "Bana atananlar" ve "Karar bekleyen" süzgeçleri.
     */
    public function index(Request $request): View
    {
        $filter = (string) $request->query('suz');
        $files = ExemptionFile::query()->with(['student', 'curriculum.program', 'assignee', 'application'])->withCount(['acceptedItems', 'items as pending_count' => fn ($q) => $q->where('status', 'oneri')])
            ->when($filter === 'bana', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($filter === 'bekleyen', fn ($q) => $q->where('status', '!=', 'karar'))
            ->when($filter === 'atanmamis', fn ($q) => $q->whereNull('assigned_to')->where('status', '!=', 'karar'))
            ->orderByRaw("status = 'karar'")->oldest('created_at')->paginate(30)->withQueryString();

        return view('files.index', ['files' => $files, 'filter' => $filter]);
    }

    /**
     * Yönetim kurulu gündemi için seçilen dosyaların toplu karar listesi (yazdırılır / PDF).
     */
    public function board(Request $request): View
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']], ['ids.required' => 'Listeye almak için en az bir dosya seçin.']);
        $files = ExemptionFile::query()->with(['student', 'curriculum.program', 'application', 'acceptedItems.course', 'acceptedItems.sources', 'items.course'])
            ->whereIn('id', $data['ids'])->get()->sortBy(fn ($f) => $f->student->student_no ?? $f->student->full_name)->values();

        return view('files.board', ['files' => $files]);
    }

    /**
     * Dosyayı bir komisyon üyesine atar (boş: atamayı kaldırır).
     */
    public function assign(Request $request, ExemptionFile $file): RedirectResponse
    {
        $data = $request->validate(['assigned_to' => ['nullable', Rule::exists('users', 'id')->whereIn('role', array_keys(User::COMMISSION_ROLES))]]);
        $file->update(['assigned_to' => $data['assigned_to'] ?? null]);
        $assignee = $file->assignee()->first();
        AuditLog::record('atama', 'Dosya #'.$file->id.' '.($assignee ? $assignee->display_name.' üzerine atandı' : 'atamadan çıkarıldı'), $file->student);
        if ($assignee && ! $assignee->is($request->user())) {
            Notifier::commission($file->student, 'Size dosya atandı', [$file->student->full_name.' öğrencisinin değerlendirme dosyası size atandı.'], $assignee);
        }

        return back()->with('status', $assignee ? 'Dosya '.$assignee->display_name.' üzerine atandı.' : 'Atama kaldırıldı.');
    }

    /**
     * Öğrencinin gönderdiği eşleştirmelerden değerlendirme dosyası açar (komisyon veri girmez).
     */
    public function store(Request $request, Student $student): RedirectResponse
    {
        $data = $request->validate(['curriculum_id' => ['nullable', 'exists:curricula,id']]);
        $curriculumId = $data['curriculum_id'] ?? $student->curriculum_id;
        abort_unless($curriculumId, 422, 'Öğrencinin öğretim planı belirli değil.');
        if ($student->currentApplication?->isPrep()) {
            return redirect()->route('applications.show', $student->currentApplication)
                ->with('status', 'Hazırlık muafiyeti başvurusunda ders dosyası açılmaz; karar bu sayfadan verilir.');
        }
        if ($existing = ExemptionFile::query()->where('student_id', $student->id)->where('application_id', $student->current_application_id)->whereNotNull('application_id')->latest()->first()) {
            return redirect()->route('files.show', $existing)->with('status', 'Bu başvurunun değerlendirme dosyası zaten açık.');
        }
        $file = ExemptionFile::query()->create([
            'student_id' => $student->id,
            'application_id' => $student->current_application_id,
            'curriculum_id' => $curriculumId,
            'options' => ['exam_scores' => []],
        ]);
        $result = $this->engine->buildFromRequests($file);
        if (in_array($student->application_status, ['taslak', 'gonderildi'], true)) {
            $student->update(['application_status' => 'inceleniyor']);
        }
        AuditLog::record('dosya', 'Değerlendirme dosyası #'.$file->id.' açıldı ('.$result['created'].' talep)', $student);

        return redirect()->route('files.show', $file)->with('status', "Değerlendirme dosyası açıldı; öğrencinin {$result['created']} talebi kontrollerle birlikte listelendi.");
    }

    public function show(ExemptionFile $file): View
    {
        $file->load(['assignee', 'student', 'application.transcript', 'application.documents', 'curriculum.program', 'curriculum.courses.info', 'items.course.info', 'items.sources', 'items.decider']);
        $itemsByCourse = $file->items->keyBy('curriculum_course_id');
        $usedSourceIds = $file->items->where('status', '!=', 'ret')->flatMap(fn ($i) => $i->sources->pluck('id'))->unique();
        $rows = $file->application ? $file->application->transcript : $file->student->transcript;
        $documents = $file->application ? $file->application->documents : $file->student->documents;
        $unusedSources = $rows->filter(fn ($t) => $t->passed && ! $usedSourceIds->contains($t->id));
        $languageNote = $this->engine->languageNote($file->student);
        $trToEn = $this->engine->isTurkishToEnglish($file);
        $plan = $file->curriculum->courses->where('category', 'plan')->groupBy('semester')->sortKeys();
        $extraItems = $file->items->filter(fn ($i) => $i->course && $i->course->category !== 'plan');
        $examCourses = $file->curriculum->courses->where('common_group', 'BIL');

        $commission = User::query()->whereIn('role', array_keys(User::COMMISSION_ROLES))->orderBy('name')->get();

        return view('files.show', compact('commission', 'file', 'itemsByCourse', 'unusedSources', 'plan', 'extraItems', 'examCourses', 'documents', 'languageNote', 'trToEn'));
    }

    public function update(Request $request, ExemptionFile $file): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(ExemptionFile::STATUSES))],
            'decision_no' => ['nullable', 'string', 'max:64'],
            'decision_date' => ['nullable', 'date'],
            'placed_semester_override' => ['nullable', 'integer', 'between:1,8'],
            'commission_note' => ['nullable', 'string', 'max:5000'],
            'exam_scores' => ['nullable', 'array'],
            'exam_scores.*' => ['nullable', 'numeric', 'between:0,100'],
        ]);
        $options = $file->options ?? [];
        if (array_key_exists('exam_scores', $data)) {
            $options['exam_scores'] = array_filter($data['exam_scores'] ?? [], fn ($v) => $v !== null && $v !== '');
        }
        unset($data['exam_scores']);
        $wasFinal = $file->status === 'karar';
        $oldStatus = $file->status;
        if (! $request->user()->isAdmin() && ($data['status'] === 'karar') !== $wasFinal) {
            return back()->withErrors(['status' => 'Kesin kararı (yönetim kurulu kararı) yalnız komisyon başkanı işleyebilir veya geri alabilir.']);
        }
        $file->update($data + ['options' => $options]);

        $message = 'Dosya güncellendi.';
        if ($file->status === 'karar' && ! $wasFinal) {
            $count = $this->engine->rememberDecisions($file);
            $message .= " Kabul edilen {$count} eşleştirme eşdeğerlik havuzuna eklendi.";
            $file->application?->update(['status' => 'sonuclandi', 'decided_by' => $request->user()->id, 'decided_at' => now()]);
            if (! $file->application_id || $file->student->current_application_id === $file->application_id) {
                $file->student->update(['application_status' => 'sonuclandi']);
            }
            AuditLog::record('kesin_karar', 'Dosya #'.$file->id.' kesin karar: '.(float) $file->exempt_ects.' AKTS, '.$file->effective_semester.'. yarıyıl'.($file->decision_no ? ', karar '.$file->decision_no : ''), $file->student);
            Notifier::student($file->student, 'Muafiyet kararınız açıklandı', [
                'Muafiyet ve intibak başvurunuz sonuçlandı.',
                'Muaf olunan toplam: '.(float) $file->exempt_ects.' AKTS · intibak yarıyılı: '.$file->effective_semester.'.',
                'Ayrıntıları ve itiraz süresini Muafiyet Başvurularım sayfasında görebilirsiniz.',
            ]);
        } elseif ($oldStatus !== $file->status) {
            AuditLog::record('durum', 'Dosya #'.$file->id.' durumu: '.ExemptionFile::STATUSES[$oldStatus].' → '.ExemptionFile::STATUSES[$file->status], $file->student);
        }

        return back()->with('status', $message);
    }

    public function destroy(ExemptionFile $file): RedirectResponse
    {
        $student = $file->student;
        AuditLog::record('silme', 'Değerlendirme dosyası #'.$file->id.' silindi', $student);
        $file->delete();

        return redirect()->route('students.show', $student)->with('status', 'Muafiyet dosyası silindi.');
    }

    /**
     * Öğrenci başvuruyu düzeltip yeniden gönderdiyse talepler yeniden okunur; komisyonun kararlı satırları korunur.
     */
    public function generate(ExemptionFile $file): RedirectResponse
    {
        $result = $this->engine->buildFromRequests($file);

        return back()->with('status', "Öğrenci talepleri yeniden okundu: {$result['created']} talep, {$result['kept']} komisyon kararı korundu.");
    }

    /**
     * Tüm satırların kararları tek düğmeyle kaydedilir (kabul/ret/bekliyor, harf notu, ret gerekçesi, not).
     */
    public function saveDecisions(Request $request, ExemptionFile $file): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.status' => ['required', Rule::in(array_keys(ExemptionItem::STATUSES))],
            'items.*.letter' => ['nullable', Rule::in(array_keys(config('intibak.letters')))],
            'items.*.reject_reason' => ['nullable', Rule::in(array_keys(ExemptionItem::REJECT_REASONS))],
            'items.*.commission_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $changed = 0;
        foreach ($file->items()->whereIn('id', array_keys($data['items']))->get() as $item) {
            if ($this->applyDecision($item, $data['items'][$item->id], $request->user()->id)) {
                $changed++;
            }
        }
        $this->engine->recalculate($file);
        if ($changed > 0) {
            $counts = $file->items()->get()->countBy('status');
            AuditLog::record('karar', 'Dosya #'.$file->id.': '.$changed.' satır kaydedildi (kabul '.($counts['kabul'] ?? 0).', ret '.($counts['ret'] ?? 0).', bekleyen '.($counts['oneri'] ?? 0).')', $file->student);
        }

        return back()->with('status', $changed > 0 ? "{$changed} satırdaki karar kaydedildi." : 'Değişiklik yok.');
    }

    /**
     * Tek satırın kararı (eski tek tek kaydetme düğmesiyle uyumluluk için).
     */
    public function updateItem(Request $request, ExemptionItem $item): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(ExemptionItem::STATUSES))],
            'letter' => ['nullable', Rule::in(array_keys(config('intibak.letters')))],
            'reject_reason' => ['nullable', Rule::in(array_keys(ExemptionItem::REJECT_REASONS))],
            'commission_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->applyDecision($item, $data, $request->user()->id);
        $this->engine->recalculate($item->file);

        return back();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyDecision(ExemptionItem $item, array $data, int $userId): bool
    {
        $item->fill([
            'status' => $data['status'],
            'letter' => $data['letter'] ?? $item->letter,
            'reject_reason' => $data['status'] === 'ret' ? ($data['reject_reason'] ?? null) : null,
            'commission_note' => $data['commission_note'] ?? null,
        ]);
        if (! $item->isDirty()) {
            return false;
        }
        $item->decided_by = $userId;
        $item->save();

        return true;
    }

    public function destroyItem(ExemptionItem $item): RedirectResponse
    {
        $file = $item->file;
        $item->delete();
        $this->engine->recalculate($file);

        return back()->with('status', 'Satır silindi.');
    }

    public function bulk(Request $request, ExemptionFile $file): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:accept_strong,accept_all,reject_pending']]);
        $query = $file->items()->where('status', 'oneri');
        match ($data['action']) {
            'accept_strong' => $query->where('score', '>=', config('intibak.match_strong'))->where('ects_ok', true)->update(['status' => 'kabul']),
            'accept_all' => $query->update(['status' => 'kabul']),
            'reject_pending' => $query->update(['status' => 'ret']),
        };
        $this->engine->recalculate($file);

        return back()->with('status', 'Toplu işlem uygulandı.');
    }

    public function report(Request $request, ExemptionFile $file): View
    {
        $file->load(['student', 'curriculum.program', 'acceptedItems.course', 'acceptedItems.sources']);
        $items = $file->acceptedItems->sortBy(fn ($i) => sprintf('%02d-%s', $i->course->semester ?? 99, $i->course->code));
        $notExempt = $file->curriculum->courses()->where('category', 'plan')
            ->whereNotIn('id', $items->pluck('curriculum_course_id'))
            ->where('semester', '<', $file->effective_semester ?? 1)->orderBy('semester')->get();

        return view('files.report', [
            'file' => $file,
            'items' => $items,
            'notExempt' => $notExempt,
            'kind' => $request->query('tur', 'komisyon'),
        ]);
    }

    public function export(ExemptionFile $file): StreamedResponse
    {
        $file->load(['student', 'curriculum.program', 'acceptedItems.course', 'acceptedItems.sources']);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Muafiyet');
        $sheet->fromArray([
            ['Öğrenci', $file->student->full_name, 'No', $file->student->student_no],
            ['Program', $file->curriculum->label],
            ['Önceki kurum', $file->student->previous_institution],
            ['Muaf AKTS', $file->exempt_ects, 'İntibak yarıyılı', $file->effective_semester],
            [],
            ['Önceki kurumda alınan ders(ler)', 'AKTS', 'Not', 'Muaf olunan AÜ dersi', 'Kod', 'Yarıyıl', 'AKTS', 'Harf notu', 'Dayanak'],
        ]);
        $row = 7;
        foreach ($file->acceptedItems->sortBy(fn ($i) => $i->course->semester) as $item) {
            $sheet->fromArray([[
                $item->sources->map(fn ($s) => $s->label)->implode(' + ') ?: ExemptionItem::RULES[$item->rule],
                $item->sources->sum('ects'),
                $item->sources->map(fn ($s) => $s->grade_raw)->implode(', '),
                $item->course->name,
                $item->course->code,
                $item->course->semester,
                $item->course->ects,
                $item->letter,
                ExemptionItem::RULES[$item->rule] ?? $item->rule,
            ]], null, 'A'.$row++);
        }
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $name = 'muafiyet-'.str($file->student->full_name)->slug().'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * @return Collection<int, Curriculum>
     */
    public static function curriculaOptions(): Collection
    {
        return Curriculum::query()->with('program')->orderByDesc('is_active')->get();
    }
}
