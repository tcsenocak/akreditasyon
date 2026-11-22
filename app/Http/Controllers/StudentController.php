<?php

namespace App\Http\Controllers;

use App\Models\CatalogCourse;
use App\Models\CatalogProgram;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StudentController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $status = $request->query('durum');
        $students = Student::query()->with('program')->withCount(['transcript', 'exemptionFiles', 'documents'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")->orWhere('student_no', 'like', "%{$search}%")))
            ->when(array_key_exists((string) $status, Student::APPLICATION_STATUSES), fn ($q) => $q->where('application_status', $status))
            ->orderByRaw("application_status = 'gonderildi' desc")->latest('submitted_at')->latest()->paginate(25)->withQueryString();

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
            ->with('account_password', session('account_password'))
            ->with('status', 'Öğrenci hesabı açıldı. Bilgi, ders, eşleştirme ve belgeleri öğrenci tamamlayacak.');
    }

    public function show(Student $student): View
    {
        $student->load(['program', 'curriculum', 'transcript.requestedCourse', 'exemptionFiles.curriculum.program', 'documents', 'user']);

        $catalogPrograms = CatalogProgram::query()->withCount('courses')
            ->whereIn('id', CatalogCourse::query()->whereIn('id', $student->transcript->pluck('catalog_course_id')->filter())->select('catalog_program_id'))
            ->orderBy('verified')->orderBy('institution')->get();

        return view('students.show', ['student' => $student, 'programs' => $this->programs(), 'catalogPrograms' => $catalogPrograms]);
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
        $data = $request->validate(['application_status' => ['required', Rule::in(array_keys(Student::APPLICATION_STATUSES))]]);
        $student->update($data);

        return back()->with('status', 'Başvuru durumu: '.Student::APPLICATION_STATUSES[$data['application_status']]);
    }

    /**
     * Öğrenciye panel hesabı açar; geçici parola komisyona bir kez gösterilir.
     */
    public function createAccount(Request $request, Student $student): RedirectResponse
    {
        abort_if($student->user()->exists(), 422, 'Bu öğrencinin hesabı zaten var.');
        $data = $request->validate(['email' => ['required', 'email', 'max:190', 'unique:users,email']]);
        $password = $this->temporaryPassword();
        $user = new User(['name' => $student->full_name, 'email' => $data['email'], 'password' => Hash::make($password)]);
        $user->forceFill(['role' => 'student', 'student_id' => $student->id, 'must_change_password' => true])->save();

        return back()->with('account_password', ['email' => $user->email, 'password' => $password])
            ->with('status', 'Öğrenci hesabı açıldı.');
    }

    /**
     * Geçici parola üretir; öğrenci ilk girişte değiştirmek zorundadır.
     */
    public function resetPassword(Student $student): RedirectResponse
    {
        $user = $student->user()->firstOrFail();
        $password = $this->temporaryPassword();
        $user->forceFill(['password' => Hash::make($password), 'must_change_password' => true, 'remember_token' => null])->save();

        return back()->with('account_password', ['email' => $user->email, 'password' => $password])
            ->with('status', 'Öğrencinin parolası sıfırlandı.');
    }

    public function destroyAccount(Student $student): RedirectResponse
    {
        $student->user()->delete();

        return back()->with('status', 'Öğrenci hesabı kapatıldı (başvuru kayıtları korunur).');
    }

    private function temporaryPassword(): string
    {
        // Karışabilecek karakterler (0/O, 1/l/I) çıkarıldı.
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < 10; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    public function destroy(Student $student): RedirectResponse
    {
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
