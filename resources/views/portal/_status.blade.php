@php($st = $student->application_status)
<div class="alert {{ $st === 'taslak' ? 'info' : 'ok' }}" style="white-space:normal">
    <b>Başvuru durumu: {{ \App\Models\Student::APPLICATION_STATUSES[$st] ?? $st }}</b>
    @if ($st === 'taslak')
        — derslerinizi girin, eşleştirin, belgelerinizi yükleyin ve “Kaydet ve gönder” ile gönderin.
    @elseif ($st === 'gonderildi')
        — {{ $student->submitted_at?->format('d.m.Y H:i') }} tarihinde gönderildi. Komisyon inceleyene kadar değişiklik yapılamaz.
    @elseif ($st === 'inceleniyor')
        — Başvurunuz muafiyet ve intibak komisyonunda inceleniyor.
    @else
        — Sonuç <a href="{{ route('portal.applications.index') }}">Muafiyet Başvurularım</a> sayfasındadır; itirazınızı da oradan yapabilirsiniz.
    @endif
</div>
