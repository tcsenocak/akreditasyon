<?php

namespace App\Http\Controllers;

use App\Models\Curriculum;
use App\Models\ExemptionFile;
use App\Models\ExemptionItem;
use App\Models\Student;
use App\Services\ExemptionEngine;
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

    public function index(): View
    {
        return view('files.index', [
            'files' => ExemptionFile::query()->with(['student', 'curriculum.program'])->withCount('acceptedItems')->latest('updated_at')->paginate(30),
        ]);
    }

    /**
     * Öğrencinin gönderdiği eşleştirmelerden değerlendirme dosyası açar (komisyon veri girmez).
     */
    public function store(Request $request, Student $student): RedirectResponse
    {
        $data = $request->validate(['curriculum_id' => ['nullable', 'exists:curricula,id']]);
        $curriculumId = $data['curriculum_id'] ?? $student->curriculum_id;
        abort_unless($curriculumId, 422, 'Öğrencinin öğretim planı belirli değil.');
        $file = ExemptionFile::query()->create([
            'student_id' => $student->id,
            'curriculum_id' => $curriculumId,
            'options' => ['exam_scores' => []],
        ]);
        $result = $this->engine->buildFromRequests($file);
        if (in_array($student->application_status, ['taslak', 'gonderildi'], true)) {
            $student->update(['application_status' => 'inceleniyor']);
        }

        return redirect()->route('files.show', $file)->with('status', "Değerlendirme dosyası açıldı; öğrencinin {$result['created']} talebi kontrollerle birlikte listelendi.");
    }

    public function show(ExemptionFile $file): View
    {
        $file->load(['student.transcript', 'student.documents', 'curriculum.program', 'curriculum.courses.info', 'items.course.info', 'items.sources']);
        $itemsByCourse = $file->items->keyBy('curriculum_course_id');
        $usedSourceIds = $file->items->where('status', '!=', 'ret')->flatMap(fn ($i) => $i->sources->pluck('id'))->unique();
        $unusedSources = $file->student->transcript->filter(fn ($t) => $t->passed && ! $usedSourceIds->contains($t->id));
        $plan = $file->curriculum->courses->where('category', 'plan')->groupBy('semester')->sortKeys();
        $extraItems = $file->items->filter(fn ($i) => $i->course && $i->course->category !== 'plan');
        $examCourses = $file->curriculum->courses->where('common_group', 'BIL');

        return view('files.show', compact('file', 'itemsByCourse', 'unusedSources', 'plan', 'extraItems', 'examCourses'));
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
        $file->update($data + ['options' => $options]);

        $message = 'Dosya güncellendi.';
        if ($file->status === 'karar' && ! $wasFinal) {
            $count = $this->engine->rememberDecisions($file);
            $message .= " Kabul edilen {$count} eşleştirme eşdeğerlik havuzuna eklendi.";
            $file->student->update(['application_status' => 'sonuclandi']);
        }

        return back()->with('status', $message);
    }

    public function destroy(ExemptionFile $file): RedirectResponse
    {
        $student = $file->student;
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
     * Komisyon değerlendirmesi: kabul/ret, harf notu ve satır notu (içerik değerlendirmesi).
     */
    public function updateItem(Request $request, ExemptionItem $item): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(ExemptionItem::STATUSES))],
            'letter' => ['nullable', Rule::in(array_keys(config('intibak.letters')))],
            'commission_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $item->update($data);
        $this->engine->recalculate($item->file);

        return back();
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
