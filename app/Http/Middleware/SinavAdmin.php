<?php

namespace App\Http\Middleware;

use App\Services\Sinav\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sınav yönetim paneli: sistemin kendi girişiyle oturum açmış komisyon üyeleri ile
 * öğretim elemanları (yalnızca kendi dersleri) girebilir.
 * Kullanım: SinavAdmin::class (ikisi de) veya SinavAdmin::class.':commission' (yalnız komisyon).
 */
class SinavAdmin
{
    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        $user = auth(config('sinav.guard'))->user();
        if (! $user) {
            $login = config('sinav.login_route');
            if ($login && Route::has($login)) {
                session()->put('url.intended', $request->fullUrl());

                return redirect()->route($login);
            }

            return response()->view('sinav.admin.denied', ['reason' => 'login'], 401);
        }

        $isCommission = Access::isCommission($user);
        $isInstructor = Access::isInstructor($user);
        if (! $isCommission && ! $isInstructor) {
            return response()->view('sinav.admin.denied', ['reason' => 'notAdmin', 'email' => (string) ($user->email ?? '')], 403);
        }
        if ($level === 'commission' && ! $isCommission) {
            return response()->view('sinav.admin.denied', ['reason' => 'commissionOnly', 'email' => (string) ($user->email ?? '')], 403);
        }
        // Komisyonun verdiği geçici parola ilk girişte değiştirilmeli.
        if (! $isCommission && ($user->must_change_password ?? false) && ! $request->routeIs('sinav.admin.password', 'sinav.admin.password.update')) {
            return redirect()->route('sinav.admin.password');
        }

        $response = $next($request);
        // Panel sayfaları tarayıcı önbelleğinde kalmasın (öğrenci listesi içerir).
        if (! $response->headers->has('Cache-Control') || ! str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store, max-age=0');
        }

        return $response;
    }
}
