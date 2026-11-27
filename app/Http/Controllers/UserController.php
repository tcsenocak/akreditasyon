<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/**
 * Komisyon üyeleri: her üye kendi hesabıyla girer, başkan parolasını paylaşmak zorunda kalmaz.
 */
class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'users' => User::query()->whereIn('role', array_keys(User::COMMISSION_ROLES))->orderBy('role')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $password = Str::password(12, symbols: false);
        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($password)]);
        $user->forceFill(['role' => $data['role'], 'title' => $data['title'], 'must_change_password' => true])->save();
        AuditLog::record('kullanici', $user->display_name.' eklendi ('.User::COMMISSION_ROLES[$user->role].')');

        return back()->with('account_password', ['email' => $user->email, 'password' => $password])
            ->with('status', $user->display_name.' eklendi.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isCommission(), 404);
        $data = $this->validated($request, $user);
        if ($user->is($request->user()) && $data['role'] !== 'admin') {
            return back()->withErrors(['role' => 'Kendi başkanlık yetkinizi kaldıramazsınız; önce başka bir başkan tanımlayın.']);
        }
        $user->forceFill(['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'], 'title' => $data['title']])->save();
        AuditLog::record('kullanici', $user->display_name.' güncellendi ('.User::COMMISSION_ROLES[$user->role].')');

        return back()->with('status', $user->display_name.' güncellendi.');
    }

    public function resetPassword(User $user): RedirectResponse
    {
        abort_unless($user->isCommission(), 404);
        $password = Str::password(12, symbols: false);
        $user->forceFill(['password' => Hash::make($password), 'must_change_password' => true, 'remember_token' => null])->save();

        return back()->with('account_password', ['email' => $user->email, 'password' => $password])
            ->with('status', $user->display_name.' için geçici parola üretildi.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isCommission(), 404);
        abort_if($user->is($request->user()), 422, 'Kendi hesabınızı silemezsiniz.');
        AuditLog::record('kullanici', $user->display_name.' silindi');
        $user->delete();

        return back()->with('status', 'Kullanıcı silindi.');
    }

    /**
     * @return array{name: string, email: string, role: string, title: ?string}
     */
    private function validated(Request $request, ?User $user): array
    {
        $validator = validator($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', Rule::in(array_keys(User::COMMISSION_ROLES))],
            'title' => ['required', Rule::in(array_keys(User::TITLES))],
        ], [], ['title' => 'unvan', 'name' => 'ad soyad', 'role' => 'rol']);
        $validator->after(function (Validator $v) use ($request): void {
            if ($request->input('role') === 'admin' && ! (User::TITLES[$request->input('title')] ?? false)) {
                $v->errors()->add('title', 'Komisyon başkanı öğretim üyesi olmalıdır (Prof. Dr., Doç. Dr. veya Dr. Öğr. Üyesi).');
            }
        });

        return $validator->validate();
    }
}
