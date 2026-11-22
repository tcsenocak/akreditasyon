<?php

namespace App\Services\Sinav;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Sınav sisteminin menüsü. Bağımsız düzenin kenar menüsü bunu çizer; sistem başka bir
 * uygulamaya (ör. Ders Programı) gömülürse o uygulamanın kenar menüsü de aynı listeyi
 * kullanabilir:
 *
 *   @foreach (\App\Services\Sinav\Menu::groups() as $group)
 *       <h4>{{ $group['title'] }}</h4>
 *       @foreach ($group['items'] as $item)
 *           <a href="{{ $item['url'] }}" @class(['active' => $item['active']])>{{ $item['label'] }}</a>
 *
 *       @endforeach
 *   @endforeach
 */
class Menu
{
    const GROUPS = [
        ['title' => 'Sınav Sistemi', 'items' => [
            ['sinav.admin.dashboard', 'Genel Bakış', 'home'],
        ]],
        ['title' => 'Dersler', 'items' => [
            ['sinav.admin.courses', 'Dersler', 'book'],
            ['sinav.admin.groups', 'Gruplar', 'layers'],
            ['sinav.admin.students', 'Öğrenciler', 'users'],
        ]],
        ['title' => 'Sınav', 'items' => [
            ['sinav.admin.exams', 'Sınavlar', 'clipboard'],
            ['sinav.admin.keys', 'Cevap Anahtarları', 'key'],
            ['sinav.admin.publish', 'Yayın Takvimi', 'send'],
            ['sinav.admin.qr', 'QR Kod', 'qr'],
        ]],
        ['title' => 'Arşiv', 'items' => [
            ['sinav.admin.acc', 'Ders Dosyası', 'folder'],
        ]],
        ['title' => 'Sistem', 'items' => [
            ['sinav.admin.audit', 'İşlem Geçmişi', 'history'],
            ['sinav.admin.settings', 'Ayarlar', 'settings'],
        ]],
    ];

    /** @return array<int, array{title: string, items: array<int, array{route: string, url: string, label: string, icon: string, active: bool}>}> */
    public static function groups()
    {
        $current = (string) Route::currentRouteName();
        $out = [];
        foreach (self::GROUPS as $g) {
            $items = [];
            foreach ($g['items'] as [$route, $label, $icon]) {
                $items[] = [
                    'route' => $route,
                    'url' => route($route),
                    'label' => $label,
                    'icon' => $icon,
                    'active' => $current === $route || Str::startsWith($current, $route.'.'),
                ];
            }
            $out[] = ['title' => $g['title'], 'items' => $items];
        }

        return $out;
    }
}
