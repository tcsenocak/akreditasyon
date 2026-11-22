<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\TranscriptService;
use Illuminate\Http\RedirectResponse;

/**
 * Döküm satırlarını öğrenci girer; komisyon yalnız not dönüşümünü güncel kurallarla yeniler.
 */
class TranscriptController extends Controller
{
    public function reconvert(Student $student, TranscriptService $transcripts): RedirectResponse
    {
        $transcripts->reconvert($student);

        return back()->with('status', 'Notlar güncel dönüşüm kurallarıyla yeniden çevrildi.');
    }
}
