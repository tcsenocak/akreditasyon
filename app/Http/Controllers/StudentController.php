<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CatalogCourse;
use App\Models\CatalogProgram;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Services\AccountActivation;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StudentController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $status = $request->query('durum');
        $students = Student::query()->with(['program', 'user'])->withCount(['applications as submitted_applications_count' => fn ($q) => $q->whereNotNull('submitted_at')])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")->orWhere('student_no', 'like', "%{$search}%")))
            ->when(array_key_exists((string) $status, Student::APPLICATION_STATUSES), fn ($q) => $q->where('application_status', $status))
            ->orderBy('first_name')->orderBy('last_name')->paginate(25)->withQueryString();

        return view('students.index', compact('students', 'search', 'status'));
    }

    public function create(): View
    {
        return view('students.form', ['student' => new Student(['admission_type' => 'yatay']), 'programs' => $this->programs()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['account_email' => ['required', 'email', 'max:190', 'unique:users,email']]);
        $student = Student::query()->create($this->validated($request));
        $request->merge(['email' => $request->input('account_email')]);
        $this->createAccount($request, $student);

        return redirect()->route('students.show', $student)
            ->with('account_link', session('account_link'))
            ->with('status', 'Öğrenci hesabı açıldı. Bilgi, ders, eşleştirme ve belgeleri öğrenci tamamlayacak.');
    }

    public function show(Student $student): View
    {
        $logs = AuditLog::query()->with('user')->where('student_id', $student->id)->latest('id')->take(30)->get();
        $student->load(['program', 'curriculum', 'transcript', 'user', 'currentApplication',
            'applications' => fn ($q) => $q->with(['decider', 'exemptionFile' => fn ($f) => $f->withCount(['acceptedItems', 'items'])])->withCount(['transcript', 'documents'])]);

        $catalogPrograms = CatalogProgram::query()->withCount('courses')
            ->whereIn('id', CatalogCourse::query()->whereIn('id', $student->transcript->pluck('catalog_course_id')->filter())->select('catalog_program_id'))
            ->orderBy('verified')->orderBy('institution')->get();

        return view('students.show', ['student' => $student, 'programs' => $this->programs(), 'catalogPrograms' => $catalogPrograms, 'logs' => $logs]);
    }

    public function edit(Student $student): View
    {
        return view('students.form', ['student' => $student, 'programs' => $this->programs()]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $student->update($this->validated($request));

        return redirect()->route('students.show', $student)->with('status', 'Öğrenci bilgileri güncellendi.');
    }

    /**
     * Öğrenci başvurusunun durumunu değiştirir (ör. düzeltme için öğrenciye geri açma).
     */
    public function applicationStatus(Request $request, Student $student): RedirectResponse
    {
        $data = $request->validate([
            'application_status' => ['required', Rule::in(array_keys(Student::APPLICATION_STATUSES))],
            'note' => ['nullable', 'required_if:application_status,taslak', 'string', 'max:2000'],
        ], ['note.required_if' => 'Öğrenciye neyin eksik ya da hatalı olduğunu yazın.']);
        $old = $student->application_status;
        $student->update(['application_status' => $data['application_status']]);
        $label = Student::APPLICATION_STATUSES[$data['application_status']];

        if ($data['application_status'] === 'taslak' && $old !== 'taslak') {
            $student->currentApplication?->update(['return_note' => $data['note'], 'returned_at' => now()]);
            AuditLog::record('geri_acma', 'Düzeltme için geri açıldı: '.$data['note'], $student);
            Notifier::student($student, 'Başvurunuz düzeltme için geri açıldı', [
                'Komisyon başvurunuzu düzeltmeniz için size geri açtı.',
                'Komisyonun notu: '.$data['note'],
                'Gerekli değişiklikleri yapıp başvurunuzu yeniden gönderin.',
            ]);
        } else {
            AuditLog::record('durum', 'Başvuru durumu: '.Student::APPLICATION_STATUSES[$old].' → '.$label, $student);
        }

        return back()->with('status', 'Başvuru durumu: '.$label);
    }

    /**
     * Öğrenciye panel hesabı açar. Parolayı öğrenci belirler: etkinleştirme bağlantısı e-postayla gider;
     * e-posta sunucusu tanımlı değilse bağlantı komisyona bir kez gösterilir.
     */
    public function createAccount(Request $request, Student $student): RedirectResponse
    {
        abort_if($student->user()->exists(), 422, 'Bu öğrencinin hesabı zaten var.');
        $data = $request->validate(['email' => ['required', 'email', 'max:190', 'unique:users,email']]);
        $user = new User(['name' => $student->full_name, 'email' => $data['email'], 'password' => Str::random(40)]);
        $user->forceFill(['role' => 'student', 'student_id' => $student->id, 'must_change_password' => true])->save();
        $link = AccountActivation::issue($user);
        AuditLog::record('hesap', 'Öğrenci hesabı açıldı; etkinleştirme bağlantısı '.($link['sent'] ? 'e-postayla gönderildi' : 'üretildi'), $student);

        return back()->with('account_link', $link)->with('status', 'Öğrenci hesabı açıldı.');
    }

    /**
     * Yeni etkinleştirme / parola belirleme bağlantısı gönderir (hesap etkinleşmemişse ya da öğrenci parolasını unuttuysa).
     */
    public function resetPassword(Student $student): RedirectResponse
    {
        $user = $student->user()->firstOrFail();
        $link = AccountActivation::issue($user);
        AuditLog::record('hesap', 'Parola belirleme bağlantısı '.($link['sent'] ? 'e-postayla gönderildi' : 'üretildi'), $student);

        return back()->with('account_link', $link)
            ->with('status', $link['sent'] ? 'Bağlantı öğrencinin e-postasına gönderildi.' : 'Yeni bağlantı üretildi.');
    }

    public function destroyAccount(Student $student): RedirectResponse
    {
        AuditLog::record('hesap', 'Öğrenci hesabı kapatıldı', $student);
        $student->user()->delete();

        return back()->with('status', 'Öğrenci hesabı kapatıldı (başvuru kayıtları korunur).');
    }

    public function destroy(Student $student): RedirectResponse
    {
        AuditLog::record('silme', 'Öğrenci kaydı silindi: '.$student->full_name.($student->student_no ? ' ('.$student->student_no.')' : ''));
        $student->delete();

        return redirect()->route('students.index')->with('status', 'Öğrenci silindi.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        // Komisyon yalnız hesap için gereken kimlik ve program bilgisini girer; öğrenim bilgilerini öğrenci tamamlar.
        $data = $request->validate([
            'student_no' => ['nullable', 'string', 'max:32'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'program_id' => ['required', 'exists:programs,id'],
            'curriculum_id' => ['nullable', 'exists:curricula,id'],
            'admission_type' => ['nullable', Rule::in(array_keys(Student::ADMISSION_TYPES))],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['admission_type'] ??= 'yatay';
        if (empty($data['curriculum_id'])) {
            $data['curriculum_id'] = Curriculum::query()->where('program_id', $data['program_id'])->where('is_active', true)->value('id');
        }

        return $data;
    }

    private function programs(): Collection
    {
        return Program::sortedTurkish(Program::query()->with('curricula')->get());
    }
}
