<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Her gece veritabanı ve öğrenci belgeleri yedeklenir (zamanlayıcı: php artisan schedule:run her dakika).
Schedule::command('yedek:al')->dailyAt('02:30');
