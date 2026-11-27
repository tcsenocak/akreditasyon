<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExemptionApplication;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ders eşleştirmesi olmayan başvuruların (hazırlık sınıfı muafiyeti) komisyon kararı.
 */
class ApplicationController extends Controller
{
    /**
     * İşlem bekleyen başvurular: yeni gönderilenler, değerlendirmesi süren dosyalar ve cevaplanmamış itirazlar.
     * Karara bağlanan başvuru listeden düşer; öğrenci itiraz ederse yeniden girer.
     */
    public function index(Request $request): View
    {
        $filter = (string) $request->query('suz');
        $applications = ExemptionApplication::query()->open()
            ->with(['student.program', 'exemptionFile.assignee'])
            ->when($filter === 'yeni', fn ($q) => $q->where('status', 'gonderildi'))
            ->when($filter === 'surmekte', fn ($q) => $q->where('status', 'inceleniyor'))
            ->when($filter === 'itiraz', fn ($q) => $q->whereNotNull('objected_at')->whereNull('objection_responded_at'))
            ->orderByRaw('coalesce(objected_at, submitted_at)')->paginate(30)->withQueryString();

        return view('applications.index', [
            'applications' => $applications,
            'filter' => $filter,
            'counts' => [
                'yeni' => ExemptionApplication::query()->where('status', 'gonderildi')->count(),
                'surmekte' => ExemptionApplication::query()->where('status', 'inceleniyor')->count(),
                'itiraz' => ExemptionApplication::query()->whereNotNull('objected_at')->whereNull('objection_responded_at')->count(),
            ],
        ]);
    }

    /**
     * Tek başvurunun komisyon görünümü: belgeler, dersler, değerlendirme dosyası, hazırlık kararı, geri açma ve itiraz.
     */
    public function show(ExemptionApplication $application): View
    {
        $application->load(['student.program', 'student.curriculum', 'student.user', 'transcript.requestedCourse', 'documents', 'exemptionFile.assignee', 'decider']);

        return view('applications.show', [
            'application' => $application,
            'student' => $application->student,
            'file' => $application->exemptionFile,
            'isCurrent' => $application->student->current_application_id === $application->id,
            'number' => $application->student->applications()->where('id', '<=', $application->id)->count(),
        ]);
    }

    public function decide(Request $request, ExemptionApplication $application): RedirectResponse
    {
        abort_unless($application->isPrep(), 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in(array_keys(ExemptionApplication::DECISIONS))],
            'decision_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $application->update($data + ['decided_by' => $request->user()->id, 'decided_at' => now(), 'status' => 'sonuclandi']);
        if ($application->student->current_application_id === $application->id) {
            $application->student->update(['application_status' => 'sonuclandi']);
        }
        AuditLog::record('hazirlik', 'Hazırlık muafiyeti kararı: '.ExemptionApplication::DECISIONS[$data['decision']], $application->student);
        Notifier::student($application->student, 'Hazırlık muafiyeti kararınız', [
            'Hazırlık sınıfı muafiyeti başvurunuz sonuçlandı: '.ExemptionApplication::DECISIONS[$data['decision']].'.',
            'Ayrıntılar Muafiyet Başvurularım sayfasındadır.',
        ]);

        return back()->with('status', 'Hazırlık muafiyeti kararı kaydedildi: '.ExemptionApplication::DECISIONS[$data['decision']].'.');
    }

    /**
     * Öğrencinin itirazına komisyonun cevabı; öğrenci Başvurum sayfasında görür.
     */
    public function respondObjection(Request $request, ExemptionApplication $application): RedirectResponse
    {
        abort_unless($application->objected_at !== null, 404);
        $data = $request->validate(['objection_response' => ['required', 'string', 'max:3000']]);
        $application->update($data + ['objection_responded_at' => now()]);
        AuditLog::record('itiraz', 'İtiraza cevap verildi ('.$application->type_label.', başvuru #'.$application->id.')', $application->student);
        Notifier::student($application->student, 'İtirazınız değerlendirildi', ['Komisyonun cevabı: '.$data['objection_response']]);

        return back()->with('status', 'İtiraz cevabı kaydedildi.');
    }
}
