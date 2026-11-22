<?php

namespace App\Http\Controllers;

use App\Models\CatalogProgram;
use Illuminate\Http\RedirectResponse;

/**
 * Öğrencilerin girdiği diğer üniversite bölüm ders listeleri: öğrenci girer, komisyon (hoca)
 * öğrencinin sayfasından onaylar. Ayrı bir katalog sayfası yoktur.
 */
class CatalogController extends Controller
{
    public function verify(CatalogProgram $program): RedirectResponse
    {
        $program->update(['verified' => ! $program->verified]);

        return back()->with('status', $program->institution.' — '.$program->name.($program->verified ? ' ders listesi onaylandı.' : ' onayı kaldırıldı.'));
    }
}
