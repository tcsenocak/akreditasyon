@php($st = $student->application_status)
<div class="alert {{ $st === 'taslak' ? 'info' : 'ok' }}" style="white-space:normal">
    <b>Başvuru durumu: {{ \App\Models\Student::APPLICATION_STATUSES[$st] ?? $st }}</b>
    @if ($st === 'taslak')
        — <a href="{{ route('portal.courses') }}">Muafiyet Başvurum</a> sayfasında derslerinizi girin, eşleştirin, belgelerinizi yükleyin ve “Kaydet ve gönder” ile gönderin.
    @elseif ($st === 'gonderildi')
        — {{ $student->submitted_at?->format('d.m.Y H:i') }} tarihinde gönderildi. Komisyon inceleyene kadar değişiklik yapılamaz.
    @elseif ($st === 'inceleniyor')
        — Başvurunuz muafiyet ve intibak komisyonunda inceleniyor.
    @else
        — Sonuç aşağıda. İtiraz için bildirimden itibaren beş iş günü içinde Dekanlığa başvurabilirsiniz (Uygulama Esasları Md. 5/9).
    @endif
</div>
