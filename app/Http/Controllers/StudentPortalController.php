<?php

namespace App\Http\Controllers;

use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use App\Models\ExemptionFile;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\TranscriptCourse;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\CurriculumTable;
use App\Services\ExemptionEngine;
use App\Services\TextNormalizer;
use App\Services\TranscriptService;
use App\Services\TranscriptTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Öğrencinin kendi muafiyet başvurusunu hazırladığı panel.
 */
class StudentPortalController extends Controller
{
    /** Bölüm listesinde olmayan bölüm için seçenek değeri. */
    public const OTHER_DEPARTMENT = '__diger';

    public function __construct(private TranscriptService $transcripts) {}

    public function showRegister(): View
    {
        abort_unless(config('intibak.student_registration'), 404);

        return view('portal.register', ['programs' => Program::sortedTurkish(Program::query()->get())]);
    }

    public function register(Request $request): RedirectResponse
    {
        abort_unless(config('intibak.student_registration'), 404);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'student_no' => ['nullable', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'program_id' => ['required', 'exists:programs,id'],
            'admission_type' => ['required', Rule::in(array_keys(Student::ADMISSION_TYPES))],
            'previous_institution' => ['required', 'string', 'max:200'],
            'previous_program' => ['nullable', 'string', 'max:200'],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $student = Student::query()->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'student_no' => $data['student_no'] ?? null,
                'phone' => $data['phone'] ?? null,
                'program_id' => $data['program_id'],
                'curriculum_id' => Curriculum::query()->where('program_id', $data['program_id'])->where('is_active', true)->value('id'),
                'admission_type' => $data['admission_type'],
                'previous_institution' => $data['previous_institution'],
                'previous_program' => $data['previous_program'] ?? null,
            ]);
            $user = new User(['name' => $student->full_name, 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
            $user->forceFill(['role' => 'student', 'student_id' => $student->id])->save();

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('portal.home')->with('status', 'Hesabınız oluşturuldu. Şimdi aldığınız dersleri girin ve belgelerinizi yükleyin.');
    }

    public function showPassword(): View
    {
        return view('portal.password', ['forced' => (bool) Auth::user()->must_change_password]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8), 'different:current_password'],
        ]);
        $user = $request->user();
        $user->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false])->save();
        $request->session()->regenerate();

        return redirect()->route('portal.home')->with('status', 'Parolanız değiştirildi.');
    }

    public function home(): View
    {
        $student = $this->student()->load(['program', 'curriculum', 'transcript.requestedCourse', 'documents']);
        $file = ExemptionFile::query()->where('student_id', $student->id)->latest()->first();
        $decided = ExemptionFile::query()->with(['acceptedItems.course', 'acceptedItems.sources', 'items.course', 'curriculum.program'])
            ->where('student_id', $student->id)->where('status', 'karar')->latest()->first();
        $status = $student->application_status;
        $order = ['taslak' => 0, 'gonderildi' => 1, 'inceleniyor' => 2, 'sonuclandi' => 3];
        $now = $order[$status] ?? 0;
        $stages = [
            ['label' => 'Başvuru hazırlanıyor', 'date' => $student->created_at, 'text' => 'Derslerinizi girip eşleştirin, belgelerinizi yükleyin.'],
            ['label' => 'Gönderildi', 'date' => $student->submitted_at, 'text' => 'Başvurunuz komisyona ulaştı; artık değişiklik yapılamaz.'],
            ['label' => 'Komisyonda inceleniyor', 'date' => $file?->created_at, 'text' => 'Muafiyet ve intibak komisyonu derslerinizi ve belgelerinizi inceliyor.'],
            ['label' => 'Sonuçlandı', 'date' => $decided?->decision_date, 'text' => 'Yönetim kurulu kararı verildi; sonuç aşağıda.'],
        ];
        foreach ($stages as $i => &$stage) {
            $stage['state'] = $i < $now ? 'done' : ($i === $now ? 'current' : 'todo');
        }
        unset($stage);

        return view('portal.home', [
            'student' => $student,
            'programs' => Program::sortedTurkish(Program::query()->with('curricula')->get()),
            'checklist' => $this->checklist($student),
            'decided' => $decided,
            'stages' => $stages,
            'reopened' => $status === 'taslak' && $student->submitted_at !== null,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $student = $this->editableStudent();
        $data = $request->validate([
            'student_no' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
            'program_id' => ['required', 'exists:programs,id'],
            'admission_type' => ['required', Rule::in(array_keys(Student::ADMISSION_TYPES))],
            'entry_term' => ['nullable', 'string', 'max:40'],
            'previous_institution' => ['required', 'string', 'max:200'],
            'previous_faculty' => ['nullable', 'string', 'max:200'],
            'previous_program' => ['nullable', 'string', 'max:200'],
            'yds_exam' => ['nullable', 'string', 'max:40'],
            'yds_score' => ['nullable', 'numeric', 'between:0,100'],
            'prep_completed' => ['nullable', 'boolean'],
        ]);
        $data['prep_completed'] = $request->boolean('prep_completed');
        if (TextNormalizer::normalize($data['previous_institution']) !== TextNormalizer::normalize((string) $student->previous_institution)
            || TextNormalizer::normalize((string) ($data['previous_program'] ?? '')) !== TextNormalizer::normalize((string) $student->previous_program)) {
            $data['source_program_key'] = null;
        }
        if ((int) $data['program_id'] !== (int) $student->program_id) {
            $data['curriculum_id'] = Curriculum::query()->where('program_id', $data['program_id'])->where('is_active', true)->value('id');
        }
        $student->update($data);

        return back()->with('status', 'Bilgileriniz kaydedildi.');
    }

    /**
     * Muafiyet ekranı ("Aldığım Dersler"): (1) öğrenci daha önce aldığı dersleri girer, (2) öğretim
     * planından + ile seçtiği AÜ dersine karşılık gelen kendi derslerini işaretler, (3) kaydedip gönderir.
     */
    public function courses(CatalogService $catalog): View
    {
        $student = $this->student()->load(['transcript.requestedCourse', 'curriculum.program']);
        $programKey = $this->programKey($student, $catalog);
        $targets = $this->targetsFor($student);
        $targetIds = $targets->pluck('id')->map(fn ($id) => (int) $id)->all();
        $universities = array_keys(config('universiteler.list'));
        $defaultInstitution = in_array($student->previous_institution, $universities, true) ? $student->previous_institution : config('universiteler.default');

        return view('portal.courses', [
            'student' => $student,
            'universities' => $universities,
            'defaultInstitution' => $defaultInstitution,
            'auPrograms' => Program::sortedTurkish(Program::query()->get()),
            'programKey' => $programKey,
            'programInfo' => $programKey ? $catalog->programInfo($programKey) : null,
            'table' => $student->curriculum ? CurriculumTable::build($student->curriculum) : null,
            'targets' => $targets,
            'targetIds' => $targetIds,
            'checklist' => $this->checklist($student->loadMissing('documents')),
        ]);
    }

    /**
     * Aldığı dersleri ve eşleştirmeleri birlikte kaydeder. Her satır öğrencinin aldığı bir derstir;
     * target_id, karşılığında muaf olmak istediği AÜ dersidir (boş olabilir). Listeden kaldırılan
     * satırlar silinir. AÜ dışındaki dersler, komisyon onayı için bölüm ders listesine de eklenir.
     * "gonder" ile gelirse kayıttan sonra başvuru gönderilir.
     */
    public function saveRows(Request $request, CatalogService $catalog): RedirectResponse
    {
        $student = $this->editableStudent()->load('transcript');
        $targetIds = $this->targetsFor($student)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $data = $request->validate([
            'items' => ['array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.target_id' => ['nullable', 'integer', Rule::in($targetIds)],
            'items.*.institution' => ['required', Rule::in(array_keys(config('universiteler.list')))],
            'items.*.code' => ['nullable', 'string', 'max:32'],
            'items.*.name' => ['required', 'string', 'max:200'],
            'items.*.credit' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'items.*.ects' => ['required', 'numeric', 'min:0', 'max:60'],
            'items.*.grade_raw' => ['required', 'string', 'max:16'],
            'deleted' => ['array'],
            'deleted.*' => ['integer'],
        ], [
            'items.*.name.required' => 'Her satırda karşılık gelen dersin adını yazın.',
            'items.*.ects.required' => 'Her satırda AKTS yazın.',
            'items.*.grade_raw.required' => 'Her satırda notunuzu yazın.',
        ]);

        $saved = 0;
        DB::transaction(function () use ($student, $data, $catalog, $request, &$saved): void {
            $own = $student->transcript->keyBy('id');
            foreach ($data['deleted'] ?? [] as $id) {
                $own->get($id)?->delete();
            }
            foreach ($data['items'] ?? [] as $item) {
                $course = isset($item['id']) ? $own->get((int) $item['id']) : null;
                $course ??= new TranscriptCourse(['student_id' => $student->id]);
                $catalogCourseId = null;
                if (! CatalogService::isAtaturk($item['institution'])) {
                    $program = $catalog->upsertProgram($item['institution'], $student->previous_faculty, $student->previous_program ?: 'Bölüm belirtilmemiş', 'ogrenci', $request->user()->id, false);
                    $catalogCourseId = $catalog->upsertCourse($program, ['code' => $item['code'] ?? null, 'name' => $item['name'], 'credit' => $item['credit'] ?? null, 'ects' => $item['ects']])->id;
                }
                $this->transcripts->save($course, [
                    'institution' => $item['institution'],
                    'department' => $student->previous_program,
                    'code' => $item['code'] ?? null,
                    'name' => $item['name'],
                    'credit' => $item['credit'] ?? null,
                    'ects' => $item['ects'],
                    'grade_raw' => $item['grade_raw'],
                    'requested_course_id' => isset($item['target_id']) ? (int) $item['target_id'] : null,
                    'catalog_course_id' => $catalogCourseId,
                    'source_course_id' => null,
                    'source' => 'ogrenci',
                ]);
                $saved++;
            }
        });

        if ($request->boolean('gonder')) {
            return $this->submit();
        }

        return redirect()->route('portal.courses')->with('status', $saved > 0 ? "{$saved} ders kaydedildi." : 'Değişiklikler kaydedildi.');
    }

    /**
     * Öğrencinin geldiği kurum ve bölümü kaydeder. Kurum Türkiye'deki üniversiteler listesinden,
     * bölüm AÜ Mühendislik Fakültesi programlarından seçilir; listede yoksa "Diğer" ile yazılır.
     */
    public function selectDepartment(Request $request, CatalogService $catalog): RedirectResponse
    {
        $student = $this->editableStudent();
        $auPrograms = Program::query()->get();
        $data = $request->validate([
            'institution' => ['required', Rule::in(array_keys(config('universiteler.list')))],
            'department' => ['required', 'string', Rule::in(array_merge($auPrograms->map(fn (Program $p) => $p->full_name)->all(), [self::OTHER_DEPARTMENT]))],
            'department_other' => ['nullable', 'required_if:department,'.self::OTHER_DEPARTMENT, 'string', 'max:200'],
        ], ['department_other.required_if' => 'Bölümünüzün adını yazın.']);
        $isOther = $data['department'] === self::OTHER_DEPARTMENT;
        $name = $isOther ? trim((string) $data['department_other']) : $data['department'];
        $auProgram = $isOther ? null : $auPrograms->first(fn (Program $p) => $p->full_name === $name);

        if ($auProgram && CatalogService::isAtaturk($data['institution'])) {
            $key = 'au:'.$auProgram->id;
        } else {
            $key = 'ext:'.$catalog->upsertProgram($data['institution'], $isOther ? null : $auProgram?->faculty, $name, 'ogrenci', $request->user()->id, false)->id;
        }
        $info = $catalog->programInfo($key);
        $student->update([
            'previous_institution' => $info['institution'],
            'previous_program' => $info['name'],
            'previous_faculty' => $info['faculty'] ?: $student->previous_faculty,
            'source_program_key' => $key,
        ]);

        return redirect()->route('portal.courses')->with('status', 'Bölümünüz kaydedildi: '.$info['institution'].' — '.$info['name']);
    }

    /**
     * Yalnız öğrencinin seçtiği bölümün dersleri içinde kod veya ada göre arama.
     */
    public function search(Request $request, CatalogService $catalog): JsonResponse
    {
        $programKey = $this->programKey($this->student(), $catalog);

        return response()->json($programKey ? $catalog->searchInProgram($programKey, (string) $request->query('q')) : []);
    }

    /**
     * Öğrencinin seçtiği bölüm (kayıtlı değilse beyan ettiği bölüm adından bulunur).
     */
    private function programKey(Student $student, CatalogService $catalog): ?string
    {
        if ($student->source_program_key && $catalog->programInfo($student->source_program_key)) {
            return $student->source_program_key;
        }

        return $catalog->guessProgramKey($student->previous_institution, $student->previous_program);
    }

    /**
     * Ders katalogdan seçilerek eklenir; ad, kod, kredi ve AKTS katalogdan gelir, öğrenci yalnız not ve dönem girer.
     */
    public function storeCourse(Request $request, CatalogService $catalog): RedirectResponse|JsonResponse
    {
        $student = $this->editableStudent();
        $data = $request->validate([
            'course_key' => ['required', 'regex:/^(au|ext):\d+$/'],
            'grade_raw' => ['required', 'string', 'max:16'],
            'semester' => ['nullable', 'integer', 'between:1,12'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'content' => ['nullable', 'string', 'max:5000'],
        ]);
        $programKey = $this->programKey($student, $catalog);
        if (! $programKey || ! $catalog->courseBelongsTo($data['course_key'], $programKey)) {
            return back()->withErrors(['course_key' => 'Yalnız seçtiğiniz bölümün listesindeki dersler eklenebilir.']);
        }
        try {
            $catalogData = $catalog->resolve($data['course_key']);
        } catch (Throwable) {
            return back()->withErrors(['course_key' => 'Ders katalogda bulunamadı; listeden yeniden seçin.']);
        }
        unset($data['course_key']);
        $course = $this->transcripts->save(new TranscriptCourse(['student_id' => $student->id]), array_merge($catalogData, array_filter($data, fn ($v) => $v !== null && $v !== '')) + ['source' => 'katalog']);

        if ($request->expectsJson()) {
            return response()->json(['id' => $course->id, 'letter' => $course->letter, 'passed' => $course->passed]);
        }

        return back()->with('status', $course->name.' eklendi.');
    }

    /**
     * Öğrenci ders adını/kodunu/kredisini değiştiremez; yalnız not, dönem ve içerik güncellenir.
     */
    public function updateCourse(Request $request, TranscriptCourse $transcriptCourse): RedirectResponse
    {
        $student = $this->editableStudent();
        abort_unless($transcriptCourse->student_id === $student->id, 403);
        $data = $request->validate([
            'grade_raw' => ['required', 'string', 'max:16'],
            'semester' => ['nullable', 'integer', 'between:1,12'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'content' => ['nullable', 'string', 'max:5000'],
        ]);
        $this->transcripts->save($transcriptCourse, $data + ['name' => $transcriptCourse->name, 'code' => $transcriptCourse->code, 'credit' => $transcriptCourse->credit, 'ects' => $transcriptCourse->ects]);

        return back()->with('status', 'Ders güncellendi.');
    }

    public function template(TranscriptTemplate $template): StreamedResponse
    {
        $spreadsheet = $template->build($this->student());

        return response()->streamDownload(fn () => (new Xlsx($spreadsheet))->save('php://output'), 'not-dokumu-sablonu.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function destroyCourse(TranscriptCourse $transcriptCourse): RedirectResponse
    {
        $student = $this->editableStudent();
        abort_unless($transcriptCourse->student_id === $student->id, 403);
        $transcriptCourse->delete();

        return back()->with('status', 'Ders silindi.');
    }

    /**
     * Şablonla toplu aktarım: listede olmayan bölüm/dersler kataloğa "öğrenci beyanı" olarak eklenir.
     */
    public function importCourses(Request $request, TranscriptTemplate $template): RedirectResponse
    {
        $student = $this->editableStudent();
        $request->validate(['file' => ['required', 'file', 'extensions:xlsx,xls', 'max:10240']]);
        try {
            $result = $template->importForStudent($student, $request->file('file')->getRealPath(), $request->user()->id);
        } catch (Throwable $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }
        $message = $result['count'] > 0 ? "{$result['count']} ders şablondan eklendi." : 'Şablonda eklenecek ders bulunamadı.';

        return back()->with('status', trim($message."\n".implode("\n", $result['warnings'])));
    }

    /**
     * Öğrencinin kendi derslerini AÜ dersleriyle eşleştirdiği ekran (Md. 5/2: hangi derse karşılık hangi dersten muafiyet).
     */
    /** Eski "Eşleştirmelerim" sayfası muafiyet ekranında birleşti. */
    public function matches(): RedirectResponse
    {
        return redirect()->route('portal.courses');
    }

    /**
     * Sistemin önerdiği eşleştirmeleri, öğrencinin henüz eşleştirmediği derslere uygular.
     */
    public function autoMatch(ExemptionEngine $engine): RedirectResponse
    {
        $student = $this->editableStudent()->load('transcript');
        abort_unless($student->curriculum, 422, 'Önce AÜ programınızı seçin.');
        $applied = 0;
        foreach ($engine->suggestRequests($student, $student->curriculum) as $suggestion) {
            foreach ($suggestion['source_ids'] as $sourceId) {
                $course = $student->transcript->firstWhere('id', $sourceId);
                if ($course && $course->requested_course_id === null) {
                    $course->update(['requested_course_id' => $suggestion['target_id']]);
                    $applied++;
                }
            }
        }

        return back()->with('status', $applied > 0
            ? "{$applied} dersiniz için eşleştirme önerildi. Lütfen her satırı kontrol edin; yanlış olanları değiştirin."
            : 'Yeni öneri bulunamadı. Eşleştirmeleri kendiniz seçebilirsiniz.');
    }

    /**
     * @return Collection<int, CurriculumCourse>
     */
    private function targetsFor(Student $student): Collection
    {
        return $student->curriculum
            ? $student->curriculum->courses()->with('info')->whereIn('category', ['plan', 'elective_pool', 'faculty_elective'])
                ->orderByRaw('semester is null')->orderBy('semester')->orderBy('name')->get()
            : collect();
    }

    /** Belgeler muafiyet ekranının 3. bölümündedir. */
    public function documents(): RedirectResponse
    {
        return redirect()->to(route('portal.courses').'#mx-docs');
    }

    public function submit(): RedirectResponse
    {
        $student = $this->editableStudent()->load(['transcript', 'documents']);
        $missing = collect($this->checklist($student))->where('required', true)->where('done', false);
        if ($missing->isNotEmpty()) {
            return back()->with('status', 'Kaydedildi.')->withErrors(['submit' => 'Göndermeden önce tamamlayın: '.$missing->pluck('label')->implode(', ')]);
        }
        $student->update(['application_status' => 'gonderildi', 'submitted_at' => now()]);

        return redirect()->route('portal.home')->with('status', 'Başvurunuz komisyona gönderildi. Sonuç bu sayfada görünecektir.');
    }

    /**
     * @return list<array{label: string, done: bool, required: bool}>
     */
    private function checklist(Student $student): array
    {
        $types = $student->documents->pluck('type');

        return [
            ['label' => 'Kişisel ve önceki öğrenim bilgileri', 'done' => filled($student->previous_institution) && filled($student->program_id), 'required' => true],
            ['label' => 'Aldığınız dersler (en az bir ders)', 'done' => $student->transcript->isNotEmpty(), 'required' => true],
            ['label' => StudentDocument::TYPES['transkript'], 'done' => $types->contains('transkript'), 'required' => true],
            ['label' => StudentDocument::TYPES['dilekce'], 'done' => $types->contains('dilekce'), 'required' => true],
            ['label' => 'Ders eşleştirmeleri (hangi dersinize karşılık hangi AÜ dersi)', 'done' => $student->transcript->whereNotNull('requested_course_id')->isNotEmpty() || $student->prep_completed || $student->yds_score, 'required' => true],
            ['label' => StudentDocument::TYPES['icerik'], 'done' => $types->contains('icerik'), 'required' => false],
        ];
    }

    private function student(): Student
    {
        $student = Auth::user()?->student;
        abort_unless($student instanceof Student, 403, 'Hesabınıza bağlı öğrenci kaydı yok.');

        return $student;
    }

    private function editableStudent(): Student
    {
        $student = $this->student();
        abort_unless($student->isEditableByStudent(), 403, 'Başvurunuz gönderildiği için değişiklik yapılamaz. Komisyonla iletişime geçin.');

        return $student;
    }
}
