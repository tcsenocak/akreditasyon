<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use App\Models\ExemptionApplication;
use App\Models\ExemptionFile;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\TranscriptCourse;
use App\Models\User;
use App\Services\ApplicationRules;
use App\Services\ApplicationWindow;
use App\Services\CatalogService;
use App\Services\CurriculumTable;
use App\Services\Notifier;
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

        return redirect()->route($user->isStudent() ? 'portal.home' : ($user->isInstructor() ? 'sinav.admin.dashboard' : 'dashboard'))->with('status', 'Parolanız değiştirildi.');
    }

    public function home(): View
    {
        $student = $this->student()->load(['program', 'curriculum', 'transcript.requestedCourse', 'documents']);
        $application = $this->application($student);
        $file = ExemptionFile::query()->where('application_id', $application->id)->latest()->first();
        $status = $student->application_status;
        $order = ['taslak' => 0, 'gonderildi' => 1, 'inceleniyor' => 2, 'sonuclandi' => 3];
        $now = $order[$status] ?? 0;
        $stages = [
            ['label' => 'Başvuru hazırlanıyor', 'date' => $application->created_at, 'text' => $application->isPrep() ? 'Dil sınavı bilgilerinizi girin, dilekçe ve sonuç belgenizi yükleyin.' : 'Derslerinizi girip eşleştirin, belgelerinizi yükleyin.'],
            ['label' => 'Gönderildi', 'date' => $student->submitted_at, 'text' => 'Başvurunuz komisyona ulaştı; artık değişiklik yapılamaz.'],
            ['label' => 'Komisyonda inceleniyor', 'date' => $file?->created_at, 'text' => 'Muafiyet ve intibak komisyonu başvurunuzu ve belgelerinizi inceliyor.'],
            ['label' => 'Sonuçlandı', 'date' => $application->decided_at, 'text' => 'Karar verildi; sonucu Muafiyet Başvurularım sayfasında görebilirsiniz.'],
        ];
        foreach ($stages as $i => &$stage) {
            $stage['state'] = $i < $now ? 'done' : ($i === $now ? 'current' : 'todo');
        }
        unset($stage);

        return view('portal.home', [
            'student' => $student,
            'application' => $application,
            'mode' => $student->portalMode(),
            'programs' => Program::sortedTurkish(Program::query()->with('curricula')->get()),
            'checklist' => $this->checklist($student, $application),
            'stages' => $stages,
            'reopened' => $status === 'taslak' && $student->submitted_at !== null,
            'windows' => collect(ExemptionApplication::TYPES)->map(fn ($label, $type) => ['open' => ApplicationWindow::isOpen($type), 'message' => ApplicationWindow::message($type)]),
        ]);
    }

    /**
     * "Muafiyet Başvurularım": gönderilmiş ve karara bağlanmış tüm başvuruların özet listesi.
     */
    public function applications(): View
    {
        $student = $this->student();
        $applications = $student->applications()->with(['exemptionFile' => fn ($q) => $q->withCount(['acceptedItems', 'items'])])
            ->where('status', '!=', 'taslak')->reorder()->orderByDesc('id')->get();

        return view('portal.applications', ['student' => $student, 'applications' => $applications]);
    }

    /**
     * Tek başvurunun ayrıntısı: talepler, karar, itiraz ve (yaz okulunda) not girişi.
     */
    public function showApplication(ExemptionApplication $application): View
    {
        $student = $this->ownApplication($application);
        $application->load(['transcript.requestedCourse', 'documents']);
        $decided = ExemptionFile::query()->with(['acceptedItems.course', 'acceptedItems.sources', 'items.course'])
            ->where('application_id', $application->id)->where('status', 'karar')->latest()->first();

        return view('portal.application', [
            'student' => $student,
            'application' => $application,
            'decided' => $decided,
            'requests' => $application->transcript->whereNotNull('requested_course_id'),
            'summerRows' => $application->isSummer() && $application->status === 'sonuclandi'
                ? $application->transcript->whereNotNull('requested_course_id')
                    ->filter(fn ($row) => $decided?->acceptedItems->contains(fn ($item) => $item->sources->contains('id', $row->id)))
                : collect(),
        ]);
    }

    /**
     * Başvuru oturumdaki öğrenciye ait olmalıdır.
     */
    private function ownApplication(ExemptionApplication $application): Student
    {
        $student = $this->student();
        abort_unless($application->student_id === $student->id && $application->status !== 'taslak', 404);

        return $student;
    }

    /**
     * Önceki başvuru sonuçlandıysa yeni başvuru açar; güncel başvuru henüz hazırlanıyorsa yalnız türünü değiştirir.
     */
    public function startApplication(Request $request): RedirectResponse
    {
        $student = $this->student();
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(ExemptionApplication::TYPES))]]);
        $application = $this->application($student);

        if ($student->application_status === 'taslak') {
            if ($data['type'] === 'hazirlik' && $student->transcript()->exists()) {
                return back()->withErrors(['type' => 'Hazırlık muafiyeti başvurusunda ders girilmez. Önce girdiğiniz dersleri silin.']);
            }
            $application->update(['type' => $data['type']]);

            return redirect()->route('portal.courses')->with('status', 'Başvuru türü: '.ExemptionApplication::TYPES[$data['type']].'.');
        }
        if ($student->application_status !== 'sonuclandi') {
            return back()->withErrors(['type' => 'Önceki başvurunuz sonuçlanmadan yeni başvuru açılamaz.']);
        }
        if (! ApplicationWindow::isOpen($data['type'])) {
            return back()->withErrors(['type' => ApplicationWindow::message($data['type'])]);
        }
        $student->startApplication($data['type']);
        AuditLog::record('yeni_basvuru', 'Öğrenci yeni başvuru açtı: '.ExemptionApplication::TYPES[$data['type']], $student);

        return redirect()->route('portal.courses')->with('status', 'Yeni başvurunuz açıldı ('.ExemptionApplication::TYPES[$data['type']].'). Önceki başvurularda talep ettiğiniz dersler yeniden istenemez.');
    }

    /**
     * Hazırlık sınıfı muafiyeti: geçerliliği kabul edilen dil sınavı ve puanı.
     */
    public function savePrep(Request $request): RedirectResponse
    {
        $student = $this->editableStudent();
        $application = $this->application($student);
        abort_unless($application->isPrep(), 404);
        $data = $request->validate([
            'language_exam' => ['required', 'string', 'max:60'],
            'language_score' => ['required', 'numeric', 'between:0,120'],
            'language_exam_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $application->update($data);

        if ($request->boolean('gonder')) {
            return $this->submit();
        }

        return redirect()->route('portal.courses')->with('status', 'Dil sınavı bilgileriniz kaydedildi.');
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
    public function courses(CatalogService $catalog): View|RedirectResponse
    {
        $student = $this->student();
        if (! $student->isEditableByStudent()) {
            return redirect()->route($student->application_status === 'sonuclandi' ? 'portal.applications.index' : 'portal.home');
        }
        $student->load(['transcript.requestedCourse', 'curriculum.program']);
        $application = $this->application($student);
        $programKey = $this->programKey($student, $catalog);
        $targets = $this->targetsFor($student);
        $blocked = ApplicationRules::blockedTargets($student, $targets);
        $targetIds = $targets->pluck('id')->map(fn ($id) => (int) $id)->reject(fn ($id) => isset($blocked[$id]))->values()->all();
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
            'checklist' => $this->checklist($student->loadMissing('documents'), $application),
            'application' => $application,
            'blocked' => $blocked,
            'summerMax' => ApplicationRules::summerMaxEcts(),
            'window' => ['open' => ApplicationWindow::isOpen($application->type), 'message' => ApplicationWindow::message($application->type)],
            'institutions' => $student->transcript->pluck('institution')->filter()->unique()->values(),
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
        $application = $this->application($student);
        abort_if($application->isPrep(), 422, 'Hazırlık muafiyeti başvurusunda ders girilmez.');
        $targets = $this->targetsFor($student);
        $blocked = ApplicationRules::blockedTargets($student, $targets);
        $targetIds = $targets->pluck('id')->map(fn ($id) => (int) $id)->all();
        $data = $request->validate([
            'items' => ['array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.target_id' => ['nullable', 'integer', Rule::in($targetIds)],
            'items.*.institution' => ['required', Rule::in(array_keys(config('universiteler.list')))],
            'items.*.code' => ['nullable', 'string', 'max:32'],
            'items.*.name' => ['required', 'string', 'max:200'],
            'items.*.credit' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'items.*.ects' => ['required', 'numeric', 'min:0', 'max:60'],
            'items.*.grade_raw' => [$application->isSummer() ? 'nullable' : 'required', 'string', 'max:16'],
            'deleted' => ['array'],
            'deleted.*' => ['integer'],
        ], [
            'items.*.name.required' => 'Her satırda karşılık gelen dersin adını yazın.',
            'items.*.ects.required' => 'Her satırda AKTS yazın.',
            'items.*.grade_raw.required' => 'Her satırda notunuzu yazın.',
        ]);

        $requested = collect($data['items'] ?? [])->pluck('target_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $again = array_values(array_intersect_key($blocked, array_flip($requested)));
        if ($again !== []) {
            return back()->withInput()->withErrors(['items' => 'Bu dersler için daha önce başvurdunuz, yeniden talep edilemez: '.collect($again)->pluck('label')->implode(', ')]);
        }
        if ($application->isSummer() && ($total = ApplicationRules::totalEcts($targets, $requested)) > ApplicationRules::summerMaxEcts()) {
            return back()->withInput()->withErrors(['items' => 'Yaz okulunda en fazla '.ApplicationRules::summerMaxEcts().' AKTS ders alınabilir; seçtiğiniz AÜ derslerinin toplamı '.$total.' AKTS.']);
        }

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
        $application = $this->application($student);
        if (! ApplicationWindow::isOpen($application->type)) {
            return back()->with('status', 'Kaydedildi.')->withErrors(['submit' => ApplicationWindow::message($application->type).' Başvurunuz kaydedildi; alım açılınca gönderebilirsiniz.']);
        }
        $missing = collect($this->checklist($student, $application))->where('required', true)->where('done', false);
        if ($missing->isNotEmpty()) {
            return back()->with('status', 'Kaydedildi.')->withErrors(['submit' => 'Göndermeden önce tamamlayın: '.$missing->pluck('label')->implode(', ')]);
        }
        $student->update(['application_status' => 'gonderildi', 'submitted_at' => now()]);
        $application->update(['return_note' => null]);
        AuditLog::record('gonderim', $application->type_label.' gönderildi', $student);
        Notifier::commission($student, 'Yeni başvuru', [
            $student->full_name.' '.mb_strtolower($application->type_label, 'UTF-8').' gönderdi.',
            'Değerlendirmek için öğrenci sayfasını açın.',
        ], $application->exemptionFile?->assignee);

        return redirect()->route('portal.home')->with('status', 'Başvurunuz komisyona gönderildi. Sonuç Muafiyet Başvurularım sayfasında görünecektir.');
    }

    /**
     * Başvuru türüne göre gönderim öncesi yapılacaklar. Birden çok kurumdan ders saydıran öğrenci
     * her kurum için ayrı (o kurumun transkript ve içeriklerini birleştiren) belge yükler.
     *
     * @return list<array{label: string, done: bool, required: bool}>
     */
    private function checklist(Student $student, ?ExemptionApplication $application = null): array
    {
        $application ??= $this->application($student);
        $docs = $student->documents;
        $types = $docs->pluck('type');
        $petition = ['label' => StudentDocument::TYPES['dilekce'], 'done' => $types->contains('dilekce'), 'required' => true];

        if ($application->isPrep()) {
            return [
                ['label' => 'Dil sınavı adı ve puanı', 'done' => filled($application->language_exam) && $application->language_score !== null, 'required' => true],
                $petition,
                ['label' => StudentDocument::TYPES['dil'], 'done' => $types->contains('dil'), 'required' => true],
            ];
        }

        $list = [
            ['label' => 'Kişisel ve önceki öğrenim bilgileri', 'done' => filled($student->previous_institution) && filled($student->program_id), 'required' => true],
            ['label' => $application->isSummer() ? 'Yaz okulunda alacağınız dersler (en az bir ders)' : 'Aldığınız dersler (en az bir ders)', 'done' => $student->transcript->isNotEmpty(), 'required' => true],
        ];
        if (! $application->isSummer()) {
            $institutions = $student->transcript->pluck('institution')->filter()->unique()->values();
            if ($institutions->count() > 1) {
                foreach ($institutions as $institution) {
                    $list[] = ['label' => StudentDocument::TYPES['transkript'].' — '.$institution, 'done' => $docs->contains(fn ($d) => $d->type === 'transkript' && $d->institution === $institution), 'required' => true];
                }
            } else {
                $list[] = ['label' => StudentDocument::TYPES['transkript'], 'done' => $types->contains('transkript'), 'required' => true];
            }
        }
        $list[] = $petition;
        $list[] = ['label' => 'Ders eşleştirmeleri (hangi dersinize karşılık hangi AÜ dersi)', 'done' => $student->transcript->whereNotNull('requested_course_id')->isNotEmpty(), 'required' => true];
        $list[] = ['label' => StudentDocument::TYPES['icerik'], 'done' => $types->contains('icerik'), 'required' => $application->isSummer()];

        return $list;
    }

    /**
     * Kişisel verilerin işlenmesine ilişkin aydınlatma metni (6698 sayılı KVKK Md. 10).
     */
    public function kvkk(): View
    {
        return view('portal.kvkk', ['accepted' => Auth::user()->kvkk_accepted_at]);
    }

    public function acceptKvkk(Request $request): RedirectResponse
    {
        $request->validate(['okudum' => ['accepted']], ['okudum.accepted' => 'Devam etmek için metni okuduğunuzu onaylayın.']);
        $request->user()->forceFill(['kvkk_accepted_at' => now()])->save();

        return redirect()->route('portal.home');
    }

    /**
     * Öğrencinin girdiği bilgilerden doldurulmuş dilekçe; yazdırılıp imzalanır ve belge olarak yüklenir.
     */
    public function petition(): View
    {
        $student = $this->student()->load(['program', 'curriculum', 'transcript.requestedCourse']);

        return view('portal.petition', [
            'student' => $student,
            'application' => $this->application($student),
            'email' => Auth::user()->email,
        ]);
    }

    /**
     * Karara itiraz (Uygulama Esasları Md. 5/9): karardan sonraki beş iş günü içinde, bir kez.
     */
    public function objection(Request $request, ExemptionApplication $application): RedirectResponse
    {
        $student = $this->ownApplication($application);
        abort_unless($application->canObject(), 422, 'İtiraz süresi geçti ya da daha önce itiraz ettiniz.');
        $data = $request->validate(['objection_text' => ['required', 'string', 'min:20', 'max:3000']], [
            'objection_text.min' => 'İtirazınızı gerekçesiyle birlikte en az birkaç cümleyle yazın.',
        ]);
        $application->update(['objection_text' => $data['objection_text'], 'objected_at' => now()]);
        AuditLog::record('itiraz', 'Öğrenci karara itiraz etti ('.$application->type_label.', başvuru #'.$application->id.')', $student);
        Notifier::commission($student, 'Karara itiraz', [$student->full_name.' muafiyet kararına itiraz etti.', 'İtiraz, Başvurular sayfasında cevap bekliyor.'], $application->exemptionFile?->assignee);

        return back()->with('status', 'İtirazınız komisyona iletildi.');
    }

    /**
     * Yaz okulu ön onayı sonuçlandıktan sonra öğrenci aldığı notları ve transkriptini girer.
     */
    public function summerGrades(Request $request, ExemptionApplication $application): RedirectResponse
    {
        $student = $this->ownApplication($application);
        abort_unless($application->isSummer() && $application->status === 'sonuclandi', 422, 'Yaz okulu notları yalnız onaylanmış yaz okulu başvurusuna girilir.');
        $rows = $application->transcript()->whereNotNull('requested_course_id')->get()->keyBy('id');
        $data = $request->validate([
            'grades' => ['required', 'array'],
            'grades.*' => ['nullable', 'string', 'max:16'],
            'transkript' => [$application->summer_grades_at ? 'nullable' : 'required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:15360'],
        ], ['transkript.required' => 'Yaz okulu transkriptinizi yükleyin.']);

        DB::transaction(function () use ($data, $rows, $student, $application, $request): void {
            foreach ($data['grades'] as $id => $grade) {
                if (($row = $rows->get((int) $id)) && filled($grade)) {
                    $this->transcripts->save($row, ['grade_raw' => trim((string) $grade), 'name' => $row->name, 'code' => $row->code, 'credit' => $row->credit, 'ects' => $row->ects, 'semester' => $row->semester]);
                }
            }
            if ($upload = $request->file('transkript')) {
                StudentDocument::query()->create([
                    'student_id' => $student->id,
                    'application_id' => $application->id,
                    'type' => 'transkript',
                    'original_name' => mb_substr($upload->getClientOriginalName(), 0, 250),
                    'path' => $upload->store('belgeler/'.$student->id, 'local'),
                    'size' => $upload->getSize(),
                    'mime' => $upload->getMimeType(),
                    'uploaded_by' => $request->user()->id,
                ]);
            }
            $application->update(['summer_grades_at' => now()]);
        });
        AuditLog::record('yaz_notu', 'Öğrenci yaz okulu notlarını girdi', $student);
        Notifier::commission($student, 'Yaz okulu notları', [$student->full_name.' yaz okulunda aldığı derslerin notlarını girdi; harf notlarını kesinleştirin.'], $application->exemptionFile?->assignee);

        return back()->with('status', 'Yaz okulu notlarınız komisyona iletildi.');
    }

    private function application(Student $student): ExemptionApplication
    {
        return $student->currentApplication ?? $student->startApplication('normal');
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
