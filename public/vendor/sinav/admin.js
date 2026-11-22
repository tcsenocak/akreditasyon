// Yönetim paneli yardımcıları. Çoğu işlem normal form gönderimidir; JavaScript yalnızca
// kolaylık ve dosya yükleme için kullanılır.
(function () {
  'use strict';
  var S = window.SINAV;

  // ---------- Parça parça dosya yükleme ----------
  // Sunucunun upload_max_filesize sınırı (çoğunlukla 2 MB) aşılmasın diye dosya küçük
  // parçalar halinde gönderilir; sunucu parçaları birleştirir.
  function randomId() {
    var a = new Uint8Array(16);
    crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  async function post(url, body) {
    var res = await fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': S.csrf, Accept: 'application/json' } });
    var data = null;
    try { data = await res.json(); } catch (e) { /* JSON değil */ }
    if (!res.ok || !data || !data.ok) {
      if (res.status === 419) throw new Error('Oturum süresi doldu. Sayfayı yenileyip tekrar deneyin.');
      if (res.status === 413) throw new Error('Sunucu dosya parçasını kabul etmedi (çok büyük).');
      throw new Error((data && data.message) || 'Sunucu hatası (' + res.status + ').');
    }
    return data;
  }

  /** Dosyayı (veya Blob'u) parçalar halinde gönderir; yükleme kimliğini döner. */
  async function uploadChunks(blob, onProgress) {
    var id = randomId();
    var size = S.chunkBytes;
    var total = Math.max(1, Math.ceil(blob.size / size));
    for (var i = 0; i < total; i++) {
      var fd = new FormData();
      fd.append('upload_id', id);
      fd.append('index', String(i));
      fd.append('total', String(total));
      fd.append('chunk', blob.slice(i * size, (i + 1) * size), 'parca');
      await post(S.chunkUrl, fd);
      if (onProgress) onProgress(i + 1, total);
    }
    return id;
  }

  /** Yüklemeyi tamamlatır: parçalar gönderilir, ardından işlem adresine kimlik + alanlar yollanır. */
  async function sendFile(actionUrl, blob, fields, onProgress) {
    var id = await uploadChunks(blob, onProgress);
    var fd = new FormData();
    fd.append('upload_id', id);
    Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
    return post(actionUrl, fd);
  }

  window.SinavUpload = { sendFile: sendFile, uploadChunks: uploadChunks, post: post };

  // Sayfa yenilendikten sonra gösterilecek yükleme bilgisi (ör. "25 MB → 4 MB sıkıştırıldı").
  try {
    var pending = sessionStorage.getItem('sinav-upload-msg');
    if (pending) {
      sessionStorage.removeItem('sinav-upload-msg');
      var main = document.getElementById('icerik');
      var note = document.createElement('div');
      note.className = 'alert success';
      note.setAttribute('role', 'status');
      note.textContent = pending;
      if (main) main.insertBefore(note, main.firstChild);
    }
  } catch (e) { /* tarayıcı depolaması kapalı olabilir */ }

  // ---------- Cevap anahtarı dosyası yükleme ----------
  document.querySelectorAll('[data-exam-upload]').forEach(function (box) {
    var input = box.querySelector('[data-file]');
    var kind = box.querySelector('[data-kind]');
    var prog = box.querySelector('[data-progress]');
    var err = box.querySelector('[data-error]');
    input.addEventListener('change', async function () {
      var files = Array.prototype.slice.call(input.files || []);
      if (!files.length) return;
      input.disabled = true;
      err.classList.add('hidden');
      prog.classList.remove('hidden');
      try {
        var notes = [];
        for (var i = 0; i < files.length; i++) {
          var f = files[i];
          // Büyük PDF/görsel önce bu bilgisayarda sıkıştırılır.
          var p = await window.SinavCompress.prepare(f, S.maxExamFile, function (t) { prog.textContent = t; });
          if (p.compressed) notes.push(f.name + ': ' + window.SinavCompress.fmt(p.before) + ' → ' + window.SinavCompress.fmt(p.after));
          prog.textContent = p.name + ' yükleniyor…';
          await sendFile(box.getAttribute('data-exam-upload'), p.blob, { name: p.name, kind: kind.value }, function (d, t) {
            prog.textContent = p.name + ': ' + d + '/' + t + ' parça';
          });
        }
        if (notes.length) {
          try { sessionStorage.setItem('sinav-upload-msg', 'Sıkıştırılarak yüklendi — ' + notes.join('; ') + '.'); } catch (e) { /* depolama kapalı */ }
        }
        window.location.reload();
      } catch (e) {
        err.textContent = e.message;
        err.classList.remove('hidden');
        prog.classList.add('hidden');
        input.disabled = false;
        input.value = '';
      }
    });
  });

  // ---------- Önizleme (yönetici: asıl kopya) ----------
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-preview]');
    if (!btn) return;
    var wrap = btn.closest('li, .box') || btn.parentElement;
    var host = wrap.querySelector('[data-preview-host]');
    if (!host) return;
    if (host.firstChild) { host.replaceChildren(); btn.textContent = 'Önizle'; return; }
    var url = btn.getAttribute('data-preview');
    var el;
    if (btn.getAttribute('data-mime') === 'application/pdf') {
      el = document.createElement('iframe');
      el.src = url;
      el.title = 'Önizleme';
    } else {
      el = document.createElement('img');
      el.src = url;
      el.alt = 'Önizleme';
    }
    host.appendChild(el);
    host.style.marginTop = '8px';
    btn.textContent = 'Kapat';
  });

  // ---------- Yazarak onay ("SİL", dönem adı) ----------
  function trUpper(s) { return (s || '').trim().replace(/i/g, 'İ').replace(/ı/g, 'I').toUpperCase(); }
  document.querySelectorAll('[data-must]').forEach(function (input) {
    var form = input.closest('form');
    var btn = form && form.querySelector('[data-confirm-btn]');
    if (!btn) return;
    var check = function () { btn.disabled = trUpper(input.value) !== trUpper(input.getAttribute('data-must')); };
    input.addEventListener('input', check);
    check();
  });

  // ---------- Öğrenci listesi: tümünü seç, seçili sayısı ----------
  var all = document.querySelector('[data-select-all]');
  var counter = document.getElementById('secili-sayi');
  function boxes() { return document.querySelectorAll('input[name="ids[]"]'); }
  function count() {
    var n = Array.prototype.filter.call(boxes(), function (b) { return b.checked; }).length;
    if (counter) counter.textContent = String(n);
    if (all) all.checked = n > 0 && n === boxes().length;
  }
  if (all) all.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = all.checked; }); count(); });
  boxes().forEach(function (b) { b.addEventListener('change', count); });
  var ogrForm = document.getElementById('ogr-form');
  if (ogrForm) {
    ogrForm.addEventListener('submit', function (e) {
      if (!Array.prototype.some.call(boxes(), function (b) { return b.checked; })) { e.preventDefault(); alert('Önce öğrenci seçin.'); }
    });
  }

  // ---------- Ders seçilince yalnızca o dersin grupları ----------
  document.querySelectorAll('[data-group-filter]').forEach(function (sel) {
    var target = document.getElementById(sel.getAttribute('data-group-filter'));
    function apply() {
      var first = null;
      Array.prototype.forEach.call(target.options, function (o) {
        var show = o.getAttribute('data-course') === sel.value;
        o.hidden = !show;
        o.disabled = !show;
        if (show && !first) first = o;
      });
      if (!target.selectedOptions[0] || target.selectedOptions[0].disabled) target.value = first ? first.value : '';
    }
    sel.addEventListener('change', apply);
    apply();
  });

  // ---------- Sınav formu ----------
  // Yayın saatine elle dokunulmadıysa, bitiş saati değişince ona eşitlenir.
  document.querySelectorAll('[data-times]').forEach(function (box) {
    var end = box.querySelectorAll('[data-time="end"] select');
    var pub = box.querySelectorAll('[data-time="publish"] select');
    var touched = box.querySelector('[data-touched]');
    var hint = box.querySelector('[data-pub-hint]');
    if (end.length < 2 || pub.length < 2) return;
    function sync() {
      if (touched.value) return;
      pub[0].value = end[0].value;
      pub[1].value = end[1].value;
    }
    end.forEach(function (s) { s.addEventListener('change', sync); });
    pub.forEach(function (s) {
      s.addEventListener('change', function () {
        touched.value = '1';
        if (hint) hint.textContent = 'Elle değiştirildi (aynı gün).';
      });
    });
    // Saat seçilince dakika listesine geç (seçim kendiliğinden kapanır).
    box.querySelectorAll('.time').forEach(function (t) {
      var s = t.querySelectorAll('select');
      s[0].addEventListener('change', function () { if (s[0].value && !s[1].value) s[1].value = '00'; s[1].focus(); });
    });
    if (!pub[0].value) sync();
  });
  document.querySelectorAll('[data-custom-toggle]').forEach(function (cb) {
    var row = cb.closest('[data-group-row]');
    var times = row.querySelector('[data-custom-times]');
    cb.addEventListener('change', function () {
      times.classList.toggle('hidden', !cb.checked);
      if (cb.checked) {
        // Ortak saatleri başlangıç değeri olarak kopyala.
        var common = document.querySelector('[data-times]');
        var src = common.querySelectorAll('input[type="date"], select');
        var dst = times.querySelectorAll('input[type="date"], select');
        if (src.length === dst.length) dst.forEach(function (d, i) { if (!d.value) d.value = src[i].value; });
      }
    });
  });

  // Sınıf listesi PDF ise yazısı tarayıcıda okunup metin olarak gönderilir.
  document.querySelectorAll('[data-class-list]').forEach(function (input) {
    var form = input.form;
    var status = form.querySelector('[data-list-status]');
    input.addEventListener('change', async function () {
      var f = input.files[0];
      if (!f || !/\.pdf$/i.test(f.name)) return;
      var btn = form.querySelector('button');
      btn.disabled = true;
      status.textContent = 'PDF okunuyor…';
      try {
        form.querySelector('[name="list_text"]').value = await window.SinavCompress.pdfText(f);
        form.querySelector('[name="list_name"]').value = f.name;
        input.value = '';
        status.textContent = f.name + ' okundu. "Listeyi oku ve devam et" ile ilerleyin.';
      } catch (e) {
        status.textContent = 'PDF okunamadı. Listeyi Excel olarak yükleyin ya da yapıştırın.';
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
