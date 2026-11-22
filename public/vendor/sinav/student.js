// Öğrenci ekranı: telefon denetimi ve içerik koruma (caydırıcı önlemler).
// ÖNEMLİ: Bunlar güvenlik önlemi DEĞİLDİR; kimin neyi görebileceğine sunucu karar verir.
(function () {
  'use strict';

  function isPhone() {
    var uaData = navigator.userAgentData;
    if (uaData && typeof uaData.mobile === 'boolean' && uaData.mobile) return true;
    var ua = navigator.userAgent || '';
    if (/Android.+Mobile|iPhone|iPod|Windows Phone|Mobile Safari|Opera Mini|IEMobile/i.test(ua)) return true;
    // Telefonda "Masaüstü sitesi" açıkken de telefon kabul edilir: dokunmatik + kısa kenarı küçük ekran.
    var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    var shortSide = Math.min((screen && screen.width) || 0, (screen && screen.height) || 0);
    return !!coarse && shortSide > 0 && shortSide <= 500;
  }

  if (!isPhone()) {
    document.getElementById('st-app').style.display = 'none';
    var box = document.getElementById('phone-only');
    box.classList.remove('hidden');
    box.style.display = 'flex';
    if (window.SinavQR) {
      window.SinavQR.toCanvas(document.getElementById('phone-qr'), window.location.href, { width: 200, margin: 2 }, function () {});
    }
    return;
  }

  // Uygulama arka plana alınınca (görev değiştirici önizlemesi vb.) içerik bulanıklaştırılır.
  var main = document.getElementById('st-main');
  function hide(on) { if (main) main.classList.toggle('blurred', on); }
  document.addEventListener('visibilitychange', function () { hide(document.visibilityState !== 'visible'); });
  window.addEventListener('beforeprint', function () { hide(true); });
  window.addEventListener('afterprint', function () { hide(document.visibilityState !== 'visible'); });

  // Kaydet, yazdır ve kopyala kısayolları kapalı.
  window.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && ['s', 'p', 'c'].indexOf((e.key || '').toLowerCase()) >= 0) e.preventDefault();
    if (e.key === 'PrintScreen') hide(true);
  });
  document.addEventListener('copy', function (e) { e.preventDefault(); });
  var examBox = document.getElementById('exam-box');
  if (examBox) examBox.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  document.addEventListener('dragstart', function (e) { if (e.target && e.target.tagName === 'CANVAS') e.preventDefault(); });

  // Giriş düğmesi: çift gönderimi önle.
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('[data-busy-text]');
      if (b) { b.disabled = true; b.textContent = b.getAttribute('data-busy-text'); }
    });
  });
})();
