<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotayı kullanıcı rolüne göre sınırlar: role:admin (komisyon başkanı), role:admin,commission (komisyon) veya role:student.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, $roles, true)) {
            if ($user?->isStudent()) {
                return redirect()->route('portal.home');
            }
            if ($user?->isInstructor()) {
                return redirect()->route('sinav.admin.dashboard');
            }
            abort(403, 'Bu sayfaya erişim yetkiniz yok.');
        }

        // Komisyonun verdiği geçici parola ilk girişte değiştirilmeli.
        if ($user->must_change_password && ! $request->routeIs('portal.password', 'portal.password.update')) {
            return redirect()->route('portal.password');
        }

        // Öğrenci, kişisel verilerinin işlenmesine ilişkin aydınlatma metnini okuduğunu bir kez onaylar.
        if ($user->isStudent() && $user->kvkk_accepted_at === null && ! $request->routeIs('portal.kvkk', 'portal.kvkk.accept')) {
            return redirect()->route('portal.kvkk');
        }

        return $next($request);
    }
}
