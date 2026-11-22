// Ders dosyası (akreditasyon): Excel → PDF tablo çevirme ve bölümleri tek PDF'te birleştirme.
// İşlem tarayıcıda yapılır (pdf-lib); sunucu yalnızca dosyaları saklar.
(function () {
  'use strict';
  var dataEl = document.getElementById('acc-data');
  if (!dataEl) return;
  var D = JSON.parse(dataEl.textContent);
  var S = window.SINAV;
  var U = window.SinavUpload;
  var PDFLib = window.PDFLib;
  var progress = document.getElementById('acc-progress');
  var errorBox = document.getElementById('acc-error');

  function busy(text) {
    progress.textContent = text || '';
    progress.classList.toggle('hidden', !text);
  }
  function fail(text) {
    errorBox.textContent = text;
    errorBox.classList.toggle('hidden', !text);
  }

  var A4 = [595.28, 841.89];
  var A4L = [841.89, 595.28];
  var fontsPromise = null;
  function loadFonts() {
    if (!fontsPromise) {
      var base = S.assets + '/vendor/pdfjs/standard_fonts/';
      fontsPromise = Promise.all(['LiberationSans-Regular.ttf', 'LiberationSans-Bold.ttf'].map(function (n) {
        return fetch(base + n).then(function (r) { if (!r.ok) throw new Error('Yazı tipi yüklenemedi.'); return r.arrayBuffer(); });
      }));
    }
    return fontsPromise;
  }
  async function newDoc() {
    var f = await loadFonts();
    var pdf = await PDFLib.PDFDocument.create();
    pdf.registerFontkit(window.fontkit);
    // Türkçe karakterler için gömülü Liberation Sans kullanılır.
    return { pdf: pdf, regular: await pdf.embedFont(f[0], { subset: true }), bold: await pdf.embedFont(f[1], { subset: true }) };
  }
  function fit(text, font, size, width) {
    if (font.widthOfTextAtSize(text, size) <= width) return text;
    var t = text;
    while (t.length > 1 && font.widthOfTextAtSize(t + '…', size) > width) t = t.slice(0, -1);
    return t + '…';
  }
  var ink = function () { return PDFLib.rgb(0.08, 0.1, 0.14); };
  var grid = function () { return PDFLib.rgb(0.6, 0.6, 0.65); };

  /** Excel satırlarını (ilk satır başlık) yatay A4 sayfalara tablo olarak yazar. */
  async function tableToPdf(rows, title) {
    var d = await newDoc();
    var data = rows.map(function (r) { return r.map(function (c) { return String(c == null ? '' : c).replace(/\s+/g, ' ').trim(); }); })
      .filter(function (r) { return r.some(function (c) { return c; }); });
    if (!data.length) throw new Error('Tablo boş.');
    var cols = Math.max.apply(null, data.map(function (r) { return r.length; }));
    data.forEach(function (r) { while (r.length < cols) r.push(''); });
    var W = A4L[0], H = A4L[1], margin = 28, usable = W - margin * 2, pad = 3, size = 8;
    function measure(s) {
      var out = [];
      for (var c = 0; c < cols; c++) {
        var m = 0;
        data.forEach(function (r, i) { m = Math.max(m, (i === 0 ? d.bold : d.regular).widthOfTextAtSize(r[c], s)); });
        out.push(Math.min(220, m + pad * 2));
      }
      return out;
    }
    var sum = function (a) { return a.reduce(function (x, y) { return x + y; }, 0); };
    var widths = measure(size);
    while (sum(widths) > usable && size > 5) { size -= 0.5; widths = measure(size); }
    var total = sum(widths);
    if (total > usable) widths = widths.map(function (w) { return (w * usable) / total; });
    var rowH = size + pad * 2 + 2, page = null, y = 0, pageNo = 0;
    function drawRow(r, isHeader) {
      var x = margin;
      if (isHeader) page.drawRectangle({ x: margin, y: y - rowH, width: sum(widths), height: rowH, color: PDFLib.rgb(0.9, 0.92, 0.95) });
      r.forEach(function (t, c) {
        var font = isHeader ? d.bold : d.regular;
        page.drawText(fit(t, font, size, widths[c] - pad * 2), { x: x + pad, y: y - rowH + pad + 1, size: size, font: font, color: ink() });
        page.drawLine({ start: { x: x, y: y }, end: { x: x, y: y - rowH }, thickness: 0.4, color: grid() });
        x += widths[c];
      });
      page.drawLine({ start: { x: x, y: y }, end: { x: x, y: y - rowH }, thickness: 0.4, color: grid() });
      page.drawLine({ start: { x: margin, y: y }, end: { x: x, y: y }, thickness: 0.4, color: grid() });
      page.drawLine({ start: { x: margin, y: y - rowH }, end: { x: x, y: y - rowH }, thickness: 0.4, color: grid() });
      y -= rowH;
    }
    function footer() { page.drawText('Sayfa ' + pageNo, { x: W - margin - 40, y: margin - 10, size: 7, font: d.regular, color: PDFLib.rgb(0.4, 0.4, 0.45) }); }
    function startPage() {
      page = d.pdf.addPage([W, H]);
      pageNo++;
      y = H - margin;
      page.drawText(fit(title, d.bold, 11, usable), { x: margin, y: y - 11, size: 11, font: d.bold });
      y -= 24;
      drawRow(data[0], true);
    }
    startPage();
    data.slice(1).forEach(function (r) {
      if (y - rowH < margin + 14) { footer(); startPage(); }
      drawRow(r, false);
    });
    footer();
    return d.pdf.save();
  }

  function centered(page, text, font, size, y) {
    var w = page.getWidth();
    var t = fit(text, font, size, w - 80);
    page.drawText(t, { x: (w - font.widthOfTextAtSize(t, size)) / 2, y: y, size: size, font: font, color: ink() });
  }

  /** Bölümleri sırayla tek PDF'te birleştirir. */
  async function buildPdf(parts, opts) {
    var d = await newDoc();
    var pdf = d.pdf;
    var problems = [];
    if (opts.cover && opts.cover.length) {
      var cp = pdf.addPage(A4);
      var y = A4[1] / 2 + opts.cover.length * 14;
      opts.cover.forEach(function (line, i) {
        centered(cp, line, i === 0 ? d.bold : d.regular, i === 0 ? 20 : 13, y);
        y -= i === 0 ? 36 : 22;
      });
    }
    for (var p = 0; p < parts.length; p++) {
      var part = parts[p];
      if (!part.files.length) continue;
      if (opts.sectionTitlePages) centered(pdf.addPage(A4), part.title, d.bold, 22, A4[1] / 2);
      for (var k = 0; k < part.files.length; k++) {
        var f = part.files[k];
        try {
          if (f.mime === 'application/pdf') {
            var src = await PDFLib.PDFDocument.load(f.bytes, { ignoreEncryption: true });
            if (src.isEncrypted) throw new Error('şifreli');
            var pages = await pdf.copyPages(src, src.getPageIndices());
            pages.forEach(function (pg) { pdf.addPage(pg); });
          } else {
            var img = f.mime === 'image/png' ? await pdf.embedPng(f.bytes) : await pdf.embedJpg(f.bytes);
            var size = img.width > img.height ? A4L : A4;
            var m = 20;
            var s = Math.min((size[0] - m * 2) / img.width, (size[1] - m * 2) / img.height);
            var page = pdf.addPage(size);
            page.drawImage(img, { x: (size[0] - img.width * s) / 2, y: (size[1] - img.height * s) / 2, width: img.width * s, height: img.height * s });
          }
        } catch (e) {
          problems.push(part.title + ' → ' + f.name + ': ' + (e.message === 'şifreli' ? 'PDF şifreli olduğu için eklenemedi' : 'dosya okunamadı'));
        }
      }
    }
    if (pdf.getPageCount() === 0) throw new Error('Birleştirilecek dosya yok.');
    return { bytes: await pdf.save(), pageCount: pdf.getPageCount(), problems: problems };
  }

  function formatSize(n) {
    return n >= 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
  }
  function download(bytes, name) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 60000);
  }

  // ---------- Yükleme (Excel ise önce tabloya çevrilir) ----------
  document.querySelectorAll('[data-acc-upload]').forEach(function (input) {
    input.addEventListener('change', async function () {
      var files = Array.prototype.slice.call(input.files || []);
      if (!files.length) return;
      fail('');
      document.querySelectorAll('[data-acc-upload]').forEach(function (i) { i.disabled = true; });
      try {
        var notes = [];
        for (var i = 0; i < files.length; i++) {
          var f = files[i];
          var blob = f;
          var name = f.name;
          var source = 'upload';
          if (/\.xlsx$/i.test(f.name)) {
            if (f.size > 5 * 1048576) throw new Error(f.name + ': Excel dosyası çok büyük (en fazla 5 MB).');
            busy(f.name + ': Excel okunuyor…');
            var id = await U.uploadChunks(f);
            var fd = new FormData();
            fd.append('upload_id', id);
            var res = await U.post(D.xlsxUrl, fd);
            busy(f.name + ': tabloya çevriliyor…');
            blob = new Blob([await tableToPdf(res.data.rows, f.name.replace(/\.xlsx$/i, ''))], { type: 'application/pdf' });
            source = 'xlsx';
          } else {
            // Büyük PDF/görsel önce bu bilgisayarda sıkıştırılır.
            var p = await window.SinavCompress.prepare(f, S.maxAccFile, busy);
            blob = p.blob;
            name = p.name;
            if (p.compressed) notes.push(f.name + ': ' + formatSize(p.before) + ' → ' + formatSize(p.after));
          }
          busy(name + ' yükleniyor…');
          await U.sendFile(input.getAttribute('data-acc-upload'), blob, { name: name, source: source }, function (d, t) {
            busy(name + ': ' + d + '/' + t);
          });
        }
        if (notes.length) {
          try { sessionStorage.setItem('sinav-acc-msg', 'Sıkıştırılarak yüklendi — ' + notes.join('; ') + '.'); } catch (e) { /* depolama kapalı */ }
        }
        window.location.reload();
      } catch (e) {
        busy('');
        fail(e.message || 'Yükleme başarısız.');
        document.querySelectorAll('[data-acc-upload]').forEach(function (i) { i.disabled = false; i.value = ''; });
      }
    });
  });

  // ---------- Tek PDF oluştur, indir ve sakla ----------
  var buildBtn = document.getElementById('acc-build');
  if (buildBtn) {
    buildBtn.addEventListener('click', async function () {
      buildBtn.disabled = true;
      fail('');
      try {
        var parts = [];
        for (var i = 0; i < D.sections.length; i++) {
          var s = D.sections[i];
          var loaded = [];
          for (var k = 0; k < s.files.length; k++) {
            var f = s.files[k];
            busy('Hazırlanıyor: ' + s.title + ' → ' + f.name);
            var r = await fetch(f.url, { credentials: 'same-origin' });
            if (!r.ok) throw new Error(f.name + ' okunamadı (' + r.status + ').');
            loaded.push({ name: f.name, mime: f.mime, bytes: new Uint8Array(await r.arrayBuffer()) });
          }
          parts.push({ title: s.title, files: loaded });
        }
        busy('Birleştiriliyor…');
        var result = await buildPdf(parts, {
          cover: document.getElementById('acc-cover').checked ? D.cover : null,
          sectionTitlePages: document.getElementById('acc-titles').checked,
        });
        download(result.bytes, D.fileName);
        // İndirmeye ek olarak sistemde de saklanır (sığmazsa uyarı verilir, indirme yine yapılmıştır).
        busy('Sistemde saklanıyor…');
        var saved = 'Sistemde de saklandı.';
        try {
          var toSave = new Blob([result.bytes], { type: 'application/pdf' });
          // Saklama sınırını aşıyorsa sistemdeki kopya sıkıştırılır (indirilen kopya özgün kalır).
          if (toSave.size > S.maxAccOutput) {
            toSave = await window.SinavCompress.compressPdf(new File([toSave], D.fileName), S.maxAccOutput, busy);
            if (toSave.size > S.maxAccOutput) throw new Error('PDF indirildi ama sıkıştırıldıktan sonra bile ' + formatSize(toSave.size) + ' olduğu için sistemde saklanamadı. Bilgisayarınızdaki kopyayı Drive\'a yükleyin.');
            saved = 'Sistemde sıkıştırılmış kopyası saklandı (' + formatSize(toSave.size) + ').';
          }
          busy('Sistemde saklanıyor…');
          await U.sendFile(D.saveUrl, toSave, { name: D.fileName, page_count: String(result.pageCount) });
        } catch (e) {
          saved = e.message;
        }
        var missing = D.sections.filter(function (x) { return !x.files.length; }).map(function (x) { return x.title; });
        var msg = ['PDF oluşturuldu ve indirildi: ' + result.pageCount + ' sayfa, ' + formatSize(result.bytes.length) + '. ' + saved,
          missing.length ? 'Boş bölümler (atlandı): ' + missing.join(', ') + '.' : '',
          result.problems.length ? 'Eklenemeyenler: ' + result.problems.join('; ') + '.' : ''].filter(Boolean).join(' ');
        busy('');
        sessionStorage.setItem('sinav-acc-msg', msg);
        window.location.reload();
      } catch (e) {
        busy('');
        fail(e.message || 'PDF oluşturulamadı.');
        buildBtn.disabled = false;
      }
    });
  }
  try {
    var m = sessionStorage.getItem('sinav-acc-msg');
    if (m) {
      sessionStorage.removeItem('sinav-acc-msg');
      var ok = document.createElement('div');
      ok.className = 'alert success';
      ok.setAttribute('role', 'status');
      ok.textContent = m;
      progress.parentNode.insertBefore(ok, progress);
    }
  } catch (e) { /* tarayıcı depolaması kapalı olabilir */ }
})();
