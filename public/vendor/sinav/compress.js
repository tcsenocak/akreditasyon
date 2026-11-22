// Büyük PDF ve görselleri yüklemeden ÖNCE tarayıcıda sıkıştırır.
//
// PDF: her sayfa pdf.js ile resme çevrilir (JPEG) ve pdf-lib ile yeni bir PDF'te birleştirilir.
// Sayfa başına boyut bütçesi aşılırsa o sayfa daha düşük çözünürlük/kaliteyle yeniden çizilir.
// Sonuç: taranmış ve görsel ağırlıklı PDF'ler çok küçülür. Sayfalardaki yazı resme dönüştüğü
// için seçilemez/aranamaz (cevap anahtarı görüntülemesi için sorun değildir).
// Sıkıştırılmış dosya özgünden büyük çıkarsa özgün dosya kullanılır.
// Dosya hiçbir yere gönderilmeden önce bu bilgisayarda işlenir.
(function () {
  'use strict';
  var S = window.SINAV || {};
  var MB = 1024 * 1024;

  // [dpi, JPEG kalitesi] — sırayla denenir
  var LEVELS = [[150, 0.72], [120, 0.65], [100, 0.55], [80, 0.5], [65, 0.45]];

  function fmt(n) { return n >= MB ? (n / MB).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

  function loadScript(src) {
    return new Promise(function (ok, fail) {
      var s = document.createElement('script');
      s.src = src;
      s.onload = ok;
      s.onerror = function () { fail(new Error('Sıkıştırma kütüphanesi yüklenemedi.')); };
      document.head.appendChild(s);
    });
  }
  var pdfLibP = null;
  function pdfLib() {
    if (window.PDFLib) return Promise.resolve(window.PDFLib);
    if (!pdfLibP) pdfLibP = loadScript(S.assets + '/vendor/pdf-lib.min.js').then(function () { return window.PDFLib; });
    return pdfLibP;
  }
  var pdfjsP = null;
  function pdfjs() {
    if (!pdfjsP) {
      pdfjsP = import(S.assets + '/vendor/pdfjs/pdf.min.js').then(function (m) {
        m.GlobalWorkerOptions.workerSrc = S.assets + '/vendor/pdfjs/pdf.worker.min.js';
        return m;
      });
    }
    return pdfjsP;
  }

  function toJpeg(canvas, q) {
    return new Promise(function (ok, fail) {
      canvas.toBlob(function (b) { b ? ok(b) : fail(new Error('Sayfa resme çevrilemedi.')); }, 'image/jpeg', q);
    });
  }

  async function headIs(file, sig) {
    var b = new Uint8Array(await file.slice(0, 4).arrayBuffer());
    return sig.every(function (x, i) { return b[i] === x; });
  }

  async function compressPdf(file, limit, onProgress) {
    var lib = await pdfLib();
    var js = await pdfjs();
    var src;
    var task;
    try {
      task = js.getDocument({
        data: new Uint8Array(await file.arrayBuffer()),
        wasmUrl: S.assets + '/vendor/pdfjs/wasm/',
        standardFontDataUrl: S.assets + '/vendor/pdfjs/standard_fonts/',
        isEvalSupported: false,
      });
      src = await task.promise;
    } catch (e) {
      if (e && e.name === 'PasswordException') throw new Error(file.name + ': PDF parola korumalı; sıkıştırılamadı. Parolasız kaydedip tekrar deneyin.');
      throw new Error(file.name + ': PDF okunamadı (bozuk olabilir).');
    }
    var n = src.numPages;
    // Hedef: sınırın %90'ı; sayfa başına eşit bütçe.
    var budget = Math.max(60 * 1024, (limit * 0.9 - 30 * 1024) / n);
    var out = await lib.PDFDocument.create();
    var canvas = document.createElement('canvas');
    var ctx = canvas.getContext('2d');
    for (var p = 1; p <= n; p++) {
      var page = await src.getPage(p);
      var base = page.getViewport({ scale: 1 });
      var blob = null;
      for (var L = 0; L < LEVELS.length; L++) {
        var vp = page.getViewport({ scale: LEVELS[L][0] / 72 });
        canvas.width = Math.floor(vp.width);
        canvas.height = Math.floor(vp.height);
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        // intent 'print': pdf.js bu kipte requestAnimationFrame kullanmaz; böylece yönetici
        // sıkıştırma sürerken başka sekmeye geçse de işlem durmaz.
        await page.render({ canvas: canvas, canvasContext: ctx, viewport: vp, intent: 'print' }).promise;
        blob = await toJpeg(canvas, LEVELS[L][1]);
        if (blob.size <= budget) break;
      }
      var img = await out.embedJpg(new Uint8Array(await blob.arrayBuffer()));
      out.addPage([base.width, base.height]).drawImage(img, { x: 0, y: 0, width: base.width, height: base.height });
      page.cleanup();
      if (onProgress) onProgress('Sıkıştırılıyor: ' + file.name + ' — sayfa ' + p + '/' + n);
    }
    canvas.width = canvas.height = 0;
    await task.destroy();
    var bytes = await out.save();
    return new Blob([bytes], { type: 'application/pdf' });
  }

  async function compressImage(file, onProgress) {
    if (onProgress) onProgress('Sıkıştırılıyor: ' + file.name);
    var bmp = await createImageBitmap(file);
    var s = Math.min(1, 2400 / Math.max(bmp.width, bmp.height));
    var c = document.createElement('canvas');
    c.width = Math.round(bmp.width * s);
    c.height = Math.round(bmp.height * s);
    var g = c.getContext('2d');
    g.fillStyle = '#fff';
    g.fillRect(0, 0, c.width, c.height);
    g.drawImage(bmp, 0, 0, c.width, c.height);
    var b = await toJpeg(c, 0.8);
    c.width = c.height = 0;
    return b;
  }

  /**
   * Gerekirse sıkıştırır. Dönüş: { blob, name, before, after, compressed }.
   * limit: sunucunun kabul ettiği en büyük boyut (bayt).
   */
  async function prepare(file, limit, onProgress) {
    var above = S.compressAbove || 5 * MB;
    var result = { blob: file, name: file.name, before: file.size, after: file.size, compressed: false };
    if (file.size > (S.maxOriginal || 300 * MB)) {
      throw new Error(file.name + ': dosya çok büyük (' + fmt(file.size) + '). En fazla ' + fmt(S.maxOriginal || 300 * MB) + ' seçilebilir.');
    }
    if (file.size <= above) return result;
    var isPdf = await headIs(file, [0x25, 0x50, 0x44, 0x46]);
    var isPng = await headIs(file, [0x89, 0x50, 0x4e, 0x47]);
    var isJpg = await headIs(file, [0xff, 0xd8, 0xff]);
    var blob = null;
    if (isPdf) blob = await compressPdf(file, limit, onProgress);
    else if (isPng || isJpg) blob = await compressImage(file, onProgress);
    if (blob && blob.size < file.size) {
      result.blob = blob;
      result.after = blob.size;
      result.compressed = true;
      if (!isPdf) result.name = file.name.replace(/\.(png|jpe?g)$/i, '') + '.jpg';
    }
    if (result.after > limit) {
      throw new Error(file.name + ': ' + (result.compressed ? 'sıkıştırıldıktan sonra bile ' : '') + fmt(result.after) +
        '; en fazla ' + fmt(limit) + ' olabilir. Dosyayı birkaç parçaya bölüp ayrı ayrı yükleyin.');
    }
    return result;
  }

  /** PDF'in yazısını satır satır çıkarır; aynı satırdaki parçalar sekmeyle ayrılır (sınıf listesi için). */
  async function pdfText(file) {
    var js = await pdfjs();
    var task = js.getDocument({
      data: new Uint8Array(await file.arrayBuffer()),
      wasmUrl: S.assets + '/vendor/pdfjs/wasm/',
      standardFontDataUrl: S.assets + '/vendor/pdfjs/standard_fonts/',
      isEvalSupported: false,
    });
    try {
      var doc = await task.promise;
      var out = [];
      for (var p = 1; p <= doc.numPages; p++) {
        var content = await (await doc.getPage(p)).getTextContent();
        var rows = {};
        content.items.forEach(function (it) {
          if (!it.str || !it.str.trim()) return;
          var y = Math.round(it.transform[5] / 3);
          (rows[y] = rows[y] || []).push({ x: it.transform[4], s: it.str.trim() });
        });
        Object.keys(rows).map(Number).sort(function (a, b) { return b - a; }).forEach(function (y) {
          out.push(rows[y].sort(function (a, b) { return a.x - b.x; }).map(function (i) { return i.s; }).join('\t'));
        });
      }
      return out.join('\n');
    } finally {
      task.destroy();
    }
  }

  window.SinavCompress = { prepare: prepare, compressPdf: compressPdf, pdfText: pdfText, fmt: fmt };
})();
