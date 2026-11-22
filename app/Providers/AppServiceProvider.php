<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // https sonlandıran ama X-Forwarded-Proto göndermeyen geçici tünellerde bağlantılar https üretilsin.
        if (env('FORCE_HTTPS')) {
            URL::forceScheme('https');
        }

        // Sınav Cevap Anahtarı modülünün yönetim paneline komisyon (admin) üyeleri girer.
        Gate::define('sinav-yonetimi', fn (User $user): bool => $user->isAdmin());

        // Üst çubuktaki dönem rozeti: Eylül–Ocak Güz, Şubat–Haziran Bahar, Temmuz–Ağustos Yaz.
        View::composer(['layouts.app', 'dashboard', 'portal.*'], function ($view): void {
            $now = now();
            $start = $now->month >= 9 ? $now->year : $now->year - 1;
            $term = match (true) {
                $now->month >= 9 || $now->month === 1 => 'Güz',
                $now->month <= 6 => 'Bahar',
                default => 'Yaz',
            };
            $view->with('currentTerm', $start.'-'.($start + 1).' '.$term);
        });

        // PHP cURL'ün CA paketi tanımlı değilse Windows kök sertifikalarından üretilen paketi kullan.
        $bundle = config('intibak.ca_bundle');
        if (! ini_get('curl.cainfo') && $bundle && is_file($bundle)) {
            Http::globalOptions(['verify' => $bundle]);
        }
    }
}
