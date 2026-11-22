@extends('layouts.app')
@section('title', 'Mevzuat')
@section('content')
<div class="page-head"><div><h1>Uygulanan kurallar</h1><div class="sub">Sistemin her otomatik işleminin dayanağı</div></div></div>

<div class="grid grid-2">
<div class="card">
    <h2>Ders Muafiyeti ve İntibak İşlemleri Uygulama Esasları <span class="badge brand">Senato 15.03.2024</span></h2>
    <div class="law"><b>Md. 4/ç — Muafiyet:</b> Daha önce alınıp başarılan dersin yerine, AKTS değeri ve içerik uyumuna göre plandaki dersin denkliği. <span class="muted">→ Sistem ad benzerliği ile yük kontrolü yapar: önce AKTS (kaynak ≥ hedef); AKTS tutmazsa ulusal kredi (kaynak ≥ hedef). İkisi de yetmezse aynı adlı dersler (ör. Matematik I + Matematik II) toplanır ve toplam AKTS ya da kredi yetiyorsa tek dersin yerine sayılır (komisyon uygulaması). Kaynak ders içeriği girilmişse OBS ders içeriğiyle karşılaştırılır; nihai içerik kararı komisyonundur.</span></div>
    <div class="law"><b>Md. 5/3:</b> Birden fazla derse karşılık muadil ders(ler)den muafiyet istenebilir. <span class="muted">→ Elle eklemede birden çok kaynak ders seçilebilir; not AKTS ağırlıklı ortalamadır.</span></div>
    <div class="law"><b>Md. 5/6:</b> Başarısızlık nedeniyle tekrar alınan dersler ve daha önce karara bağlanmış dersler için muafiyet istenemez.</div>
    <div class="law"><b>Md. 5/8:</b> YDS ≥ 50 (veya eşdeğeri) → ortak zorunlu yabancı dil ve mesleki yabancı dil; yarıyıllık programda mesleki yabancı dilden en fazla 2 ders.</div>
    <div class="law"><b>Md. 6/2:</b> Diğer kurum notları AÜ harf notlarına çevrilir. <b>Md. 6/3:</b> G/M ile başarılı dersler CC işlenir.</div>
    <div class="law"><b>Md. 6/4:</b> Dikey geçişte muaf derslerin harf notları not döküm belgesine işlenir; mezuniyette lisans ortalamasına katılır.</div>
    <div class="law"><b>Md. 6/5:</b> Atatürk İlkeleri ve İnkılap Tarihi, Türk Dili, Yabancı Dil ve İş Sağlığı ve Güvenliği derslerinde AKTS ve içerik aranmaz; açıköğretimde yıllık okutulan ortak zorunlu ders iki yarıyıl için muaf sayılır.</div>
    <div class="law"><b>Md. 6/6:</b> Temel bilgisayar dersleri için muafiyet sınavı; 100 üzerinden ≥ 50 muaf. 88–100 AA, 81–87,99 BA, 74–80,99 BB, 67–73,99 CB, 60–66,99 CC, 53–59,99 DC, 50–52,99 DD.</div>
    <div class="law"><b>Md. 6/7:</b> Hazırlık sınıfı muafiyet sınavında başarılı ya da hazırlığı tamamlayan öğrenci ortak zorunlu yabancı dil derslerinden muaftır.</div>
    <div class="law"><b>Md. 7/1:</b> Muaf derslerin AKTS'leri toplanır, her yarıyıl 30 AKTS sayılarak yarıyıl sayısı bulunur; intibak izleyen yarıyıla yapılır. <span class="muted">→ İntibak yarıyılı = ⌊muaf AKTS ÷ 30⌋ + 1</span></div>
    <div class="law"><b>Md. 7/2:</b> İntibakı yapılan öğrenci önce alt yarıyıllardaki muaf olmadığı derslere kayıt yaptırır. <span class="muted">→ Raporda “alt yarıyıllardan alınması gereken dersler” listelenir.</span></div>
    <div class="law"><b>Md. 5/9:</b> İtirazlar, işlemin bildirildiği tarihten itibaren 5 iş günü içinde Dekanlığa yapılır.</div>
</div>
<div class="card">
    <h2>Öğretim Planları İntibak Usul ve Esasları <span class="badge brand">19.08.2022</span></h2>
    <div class="law"><b>Md. 5/4:</b> İntibak sonrası toplam AKTS ve AGNO'nun aynı kalmasına dikkat edilir; öğrenci lehine işlem yapılır.</div>
    <div class="law"><b>Md. 6/1-a:</b> Kök kodu veya adı aynı dersler sistemce eşleştirilir ve değiştirilemez. <span class="muted">→ “Kilitli” eşleştirmeler silinemez.</span></div>
    <div class="law"><b>Md. 6/1-b, c:</b> Diğer dersler komisyon çalışmasıyla eşleştirilir; eski ders yeni planda Üniversite Seçmeli dışındaki seçmelilerle eşleştirilebilir.</div>
    <div class="law"><b>Md. 6/1-ç:</b> Eski planda eşleşmeyen ders kalmamalıdır. <span class="muted">→ Eşleşmeyen dersler listelenir.</span></div>
    <div class="law"><b>Md. 7/1-c-ii:</b> AKTS/AGNO farkı 1–6 AKTS'lik Topluma Katkı, Araştırmaya Katkı ve Eğitime Katkı dersleriyle (1.–3. yarıyıl, yaz okulu) eşitlenir; bu dersler plan derslerinin yerine sayılmaz.</div>
    <div class="law"><b>Md. 7/1-c-iii:</b> Eski planı tamamlayanlara, yeni planda alt yarıyıllarda bulunan yeni dersler CC ile muaf.</div>
    <div class="law"><b>Md. 7/1-c-iv:</b> M veya G ile başarılan dersler CC ile aktarılır.</div>
    <div class="law"><b>Md. 7/2 Kaldırma:</b> DD–AA notlu kaldırılan dersler içerik aranmaksızın yeni derslere; FF/Z olanlar yeni dersi aynı notla alır; hiç almayan sorumlu değildir.</div>
    <div class="law"><b>Md. 7/3 Birleştirme:</b> Başarılı derslerden harf notu yüksek olan esas alınır; başarısızlar dikkate alınmaz; hepsinden başarısızsa birleşik dersten sorumludur.</div>
    <div class="law"><b>Md. 7/4 Bölünme:</b> Başarılı dersin notu eşdeğer yeni derslere; başarısızlar bölünmüş dersleri alır.</div>
    <div class="law"><b>Md. 7/5–7/8:</b> Yarıyıl, zorunlu/seçmeli türü veya adı değişen derslerde intibak yeni plandaki haliyle yapılır.</div>
    <h2 style="margin-top:20px">Harf notları <span class="badge brand">Yönetmelik Md. 27</span></h2>
    <table>
        <tr><th>Harf</th>@foreach (config('intibak.letters') as $l => $c)<td>{{ $l }}</td>@endforeach</tr>
        <tr><th>Katsayı</th>@foreach (config('intibak.letters') as $l => $c)<td>{{ number_format($c, 2, ',', '') }}</td>@endforeach</tr>
    </table>
    <p class="small muted">AA–CC başarılı; DC ve DD koşullu (mezuniyette AGNO ≥ 2,00). Ayar: <code>config/intibak.php</code> → <code>accept_conditional_pass</code>.</p>
</div>
</div>
@endsection
