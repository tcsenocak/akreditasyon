@extends('layouts.app')
@section('title', 'Kişisel verilerin korunması')
@section('content')
{{-- Taslak metin: üniversitenin KVKK birimince gözden geçirilip onaylanmalıdır. --}}
<div class="card" style="max-width:820px;margin:0 auto">
    <h1>Kişisel Verilerin İşlenmesine İlişkin Aydınlatma Metni</h1>
    <p class="sub">6698 sayılı Kişisel Verilerin Korunması Kanunu (KVKK) Madde 10 uyarınca</p>

    <h3>Veri sorumlusu</h3>
    <p>Atatürk Üniversitesi (Mühendislik Fakültesi Dekanlığı — Muafiyet ve İntibak Komisyonu).</p>

    <h3>İşlenen kişisel veriler</h3>
    <p>Kimlik (ad, soyad, öğrenci numarası), iletişim (e-posta, telefon), eğitim bilgileri (daha önce öğrenim görülen kurum ve program, alınan dersler, notlar, transkript, ders içerikleri, yabancı dil sınavı sonucu), başvuru dilekçesi ve yüklenen belgeler ile sistemdeki işlem kayıtları.</p>

    <h3>İşleme amacı</h3>
    <p>Ders muafiyeti, intibak, yaz okulu ön onayı ve hazırlık sınıfı muafiyeti başvurularının alınması, komisyonca değerlendirilmesi, yönetim kurulu kararına sunulması, sonucun ve itiraz sürecinin size bildirilmesi.</p>

    <h3>Hukuki sebep</h3>
    <p>2547 sayılı Yükseköğretim Kanunu, Atatürk Üniversitesi Ön Lisans ve Lisans Eğitim-Öğretim ve Sınav Yönetmeliği ile Ders Muafiyeti ve İntibak İşlemleri Uygulama Esasları; KVKK Md. 5/2-(a) kanunlarda açıkça öngörülmesi, (ç) veri sorumlusunun hukuki yükümlülüğünü yerine getirmesi ve (e) bir hakkın tesisi.</p>

    <h3>Aktarım</h3>
    <p>Verileriniz yalnız başvurunuzun sonuçlandırılması için gerekli olduğu ölçüde Fakülte Yönetim Kurulu ve Öğrenci İşleri Daire Başkanlığı ile, mevzuatın gerektirdiği hâllerde yetkili kamu kurum ve kuruluşlarıyla paylaşılır. Üçüncü kişilere ticari amaçla aktarılmaz.</p>

    <h3>Toplama yöntemi</h3>
    <p>Bu başvuru sistemine sizin girdiğiniz bilgiler ve yüklediğiniz belgeler aracılığıyla, elektronik ortamda toplanır.</p>

    <h3>Haklarınız</h3>
    <p>KVKK Md. 11 uyarınca verilerinizin işlenip işlenmediğini öğrenme, bilgi talep etme, amacına uygun kullanılıp kullanılmadığını öğrenme, aktarıldığı kişileri bilme, eksik veya yanlış işlenmişse düzeltilmesini, şartları oluştuğunda silinmesini isteme ve kanuna aykırı işleme nedeniyle zarara uğramanız hâlinde zararın giderilmesini talep etme haklarına sahipsiniz. Taleplerinizi Atatürk Üniversitesine yazılı olarak iletebilirsiniz.</p>

    @if ($accepted)
        <p class="small muted">Bu metni {{ $accepted->format('d.m.Y H:i') }} tarihinde okuduğunuzu onayladınız.</p>
    @else
        <form method="POST" action="{{ route('portal.kvkk.accept') }}" style="margin-top:16px">
            @csrf
            <label class="row" style="gap:8px;align-items:flex-start;margin:0 0 12px"><input type="checkbox" name="okudum" value="1" required style="margin-top:3px"> <span>Aydınlatma metnini okudum ve anladım.</span></label>
            <button>Devam et</button>
        </form>
    @endif
</div>
@endsection
