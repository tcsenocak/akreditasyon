<?php

namespace App\Http\Controllers\Sinav;

use App\Models\Sinav\Course;
use App\Models\User;
use App\Services\Sinav\Util;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Öğretim elemanı (hoca) hesapları: komisyon açar, derse atar, parolasını sıfırlar.
 * Geçici parola komisyona bir kez gösterilir; hoca ilk girişte kendi parolasını belirler.
 */
class InstructorController extends BaseController
{
    public function index(): View
    {
        $instructors = User::query()->where('role', 'instructor')->orderBy('name')->get();
        $courses = Course::query()->whereNotNull('instructor_user_id')->orderBy('code')->get()->groupBy('instructor_user_id');

        return view('sinav.admin.instructors', compact('instructors', 'courses'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
        ]);
        $password = $this->temporaryPassword();
        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($password)]);
        $user->forceFill(['role' => 'instructor', 'must_change_password' => true])->save();
        Util::log('instructor.create', 'user', $user->id);

        return back()->with('account_password', ['email' => $user->email, 'password' => $password])
            ->with('success', 'Öğretim elemanı hesabı açıldı. Şimdi "Dersler" sayfasından derslerine atayın.');
    }

    public function resetPassword(User $instructor): RedirectResponse
    {
        abort_unless($instructor->role === 'instructor', 404);
        $password = $this->temporaryPassword();
        $instructor->forceFill(['password' => Hash::make($password), 'must_change_password' => true, 'remember_token' => null])->save();
        Util::log('instructor.reset', 'user', $instructor->id);

        return back()->with('account_password', ['email' => $instructor->email, 'password' => $password])
            ->with('success', 'Parola sıfırlandı.');
    }

    public function destroy(User $instructor): RedirectResponse
    {
        abort_unless($instructor->role === 'instructor', 404);
        $id = $instructor->id;
        // Dersler silinmez; yalnızca sorumlu boşalır (veritabanı kuralı: nullOnDelete).
        $instructor->delete();
        Util::log('instructor.delete', 'user', $id);

        return back()->with('success', 'Öğretim elemanı hesabı kapatıldı. Dersleri ve sınavları korunuyor.');
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
}
