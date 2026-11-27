<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\AccountActivation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Öğrenci, komisyonun gönderdiği bağlantıyla hesabını etkinleştirir ve parolasını kendisi belirler.
 */
class AccountActivationController extends Controller
{
    public function show(string $token): View
    {
        $user = AccountActivation::find($token);

        return view('auth.activate', ['token' => $token, 'email' => $user?->email, 'valid' => $user !== null]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $user = AccountActivation::find($token);
        if (! $user) {
            return redirect()->route('activation.show', $token);
        }
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
            'activation_token' => null,
            'activation_expires_at' => null,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();
        AuditLog::record('hesap', 'Öğrenci hesabını etkinleştirdi ve parolasını belirledi', $user->student);

        return redirect()->route($user->isStudent() ? 'portal.home' : 'dashboard')->with('status', 'Hesabınız etkinleştirildi.');
    }
}
