<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sınav sayfalarına güvenlik başlıkları ekler.
 *
 * - Hepsi: çerçeveye gömme yasağı, MIME koklama yasağı, önbelleğe almama.
 * - Öğrenci sayfaları: sıkı İçerik Güvenlik Politikası (CSP). Yalnızca sitenin kendi
 *   betikleri ve bu isteğe özel "nonce" taşıyan satır içi betikler çalışır (Firebase
 *   sürümündeki CSP'nin karşılığı). Yönetim sayfalarına konmaz, çünkü başka bir uygulamanın
 *   düzenine gömüldüklerinde o düzenin kendi satır içi betiklerini bozabilir.
 * - Öğrenciye verilen dosya yanıtları: hiçbir şey çalıştıramayan "sandbox" politikası.
 */
class SinavSecurityHeaders
{
    /** Yönetim sayfasındaki önizleme çerçevesinde açılan dosya yanıtları. */
    private const ADMIN_PREVIEW_ROUTES = ['sinav.admin.files.show', 'sinav.admin.acc.file', 'sinav.admin.acc.output'];

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        app()->instance('sinav.csp_nonce', $nonce);

        $response = $next($request);
        $headers = $response->headers;
        $route = (string) optional($request->route())->getName();
        if (in_array($route, self::ADMIN_PREVIEW_ROUTES, true)) {
            // Yönetici dosya önizlemesi aynı sitedeki sayfaya çerçeve içinde gömülür.
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        }
        if (! $headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'DENY');
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
        if (! $headers->has('Cache-Control') || ! str_contains((string) $headers->get('Cache-Control'), 'no-store')) {
            $headers->set('Cache-Control', 'no-store, max-age=0');
        }

        if ($route === 'sinav.file') {
            $headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
            $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        } elseif ($route !== '' && ! str_starts_with($route, 'sinav.admin.')) {
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}' 'wasm-unsafe-eval'",
                "worker-src 'self' blob:",
                "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
                "font-src 'self' data: https://fonts.bunny.net",
                "img-src 'self' data: blob:",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'none'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]));
        }

        return $response;
    }
}
