<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Başvuru belgeleri: öğrenci kendi kaydına, komisyon her öğrenciye yükleyebilir; dosyalar yerel (özel) diskte tutulur.
 */
class StudentDocumentController extends Controller
{
    public function store(Request $request, ?Student $student = null): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        if ($user->isStudent()) {
            $student = $user->student;
            abort_unless($student && $student->isEditableByStudent(), 403, 'Başvurunuz gönderildiği için belge eklenemez.');
        }
        abort_unless($student instanceof Student, 404);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(StudentDocument::TYPES))],
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:15360'],
            'institution' => ['nullable', 'string', 'max:200'],
        ]);
        foreach ($request->file('files') as $upload) {
            $path = $upload->store('belgeler/'.$student->id, 'local');
            $student->documents()->create([
                'type' => $data['type'],
                'original_name' => mb_substr($upload->getClientOriginalName(), 0, 250),
                'path' => $path,
                'size' => $upload->getSize(),
                'mime' => $upload->getMimeType(),
                'uploaded_by' => $user->id,
                'institution' => $data['institution'] ?? null,
            ]);
        }

        $message = count($request->file('files')).' belge yüklendi.';

        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('status', $message);
    }

    public function download(Request $request, StudentDocument $document): StreamedResponse
    {
        $this->authorizeAccess($request, $document);
        abort_unless(Storage::disk('local')->exists($document->path), 404, 'Dosya bulunamadı.');

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    public function destroy(Request $request, StudentDocument $document): RedirectResponse|JsonResponse
    {
        $this->authorizeAccess($request, $document);
        if ($request->user()->isStudent()) {
            abort_unless($document->student->isEditableByStudent(), 403, 'Başvurunuz gönderildiği için belge silinemez.');
        }
        Storage::disk('local')->delete($document->path);
        $document->delete();

        return $request->expectsJson() ? response()->json(['message' => 'Belge silindi.']) : back()->with('status', 'Belge silindi.');
    }

    private function authorizeAccess(Request $request, StudentDocument $document): void
    {
        $user = $request->user();
        abort_unless($user->isCommission() || ($user->isStudent() && $user->student_id === $document->student_id), 403);
    }
}
