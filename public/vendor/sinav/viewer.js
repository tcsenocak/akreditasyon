// Öğrenci görünümünde dosya gösterimi: indirme bağlantısı yok, sayfalar tuvale (canvas)
// resim olarak çizilir ve her sayfanın üst kenarına küçük bir filigran (öğrenci no + tarih)
// yazılır. PDF içindeki betikler çalıştırılmaz.
// Ayarlar sayfadaki veri özniteliklerinden okunur (satır içi betik yok; CSP ile uyumlu).
const cfg = { ...document.getElementById('sinav-viewer-config').dataset };

function drawWatermark(canvas, text) {
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  const size = Math.max(10, Math.round(canvas.width / 70));
  ctx.save();
  ctx.font = `${size}px sans-serif`;
  ctx.fillStyle = 'rgba(100, 116, 139, 0.55)';
  ctx.textAlign = 'right';
  ctx.textBaseline = 'top';
  ctx.fillText(text, canvas.width - size, Math.round(size * 0.6));
  ctx.restore();
}

let pdfjsPromise = null;
function loadPdfjs() {
  if (!pdfjsPromise) {
    pdfjsPromise = import(cfg.pdfjs).then((m) => {
      m.GlobalWorkerOptions.workerSrc = cfg.worker;
      return m;
    });
  }
  return pdfjsPromise;
}

async function render(fig) {
  const state = fig.querySelector('[data-state]');
  const host = fig.querySelector('[data-pages]');
  host.replaceChildren();
  state.textContent = 'Dosya yükleniyor…';
  state.className = 'small';
  state.hidden = false;
  try {
    const res = await fetch(fig.dataset.protectedFile, { credentials: 'same-origin', cache: 'no-store' });
    if (res.status === 403) {
      state.textContent = 'Bu dosya şu anda yayında değil.';
      state.className = 'alert warn';
      return;
    }
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const bytes = new Uint8Array(await res.arrayBuffer());
    const width = Math.max(300, host.clientWidth || 600);
    // Telefonlarda çok sayfalı PDF'lerde bellek sınırına takılmamak için çözünürlük sınırlı tutulur.
    const ratio = Math.min(window.devicePixelRatio || 1, 1.5);
    const addCanvas = (w, h, aria) => {
      const c = document.createElement('canvas');
      c.width = Math.floor(w);
      c.height = Math.floor(h);
      c.setAttribute('role', 'img');
      c.setAttribute('aria-label', aria);
      host.appendChild(c);
      return c;
    };
    if (fig.dataset.mime === 'application/pdf') {
      const pdfjs = await loadPdfjs();
      const pdf = await pdfjs.getDocument({ data: bytes, wasmUrl: cfg.wasm, standardFontDataUrl: cfg.fonts, isEvalSupported: false }).promise;
      for (let p = 1; p <= pdf.numPages; p++) {
        const page = await pdf.getPage(p);
        const scale = (width / page.getViewport({ scale: 1 }).width) * ratio;
        const viewport = page.getViewport({ scale });
        const canvas = addCanvas(viewport.width, viewport.height, `${fig.dataset.name}, sayfa ${p} / ${pdf.numPages}`);
        await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport }).promise;
        drawWatermark(canvas, cfg.watermark);
      }
    } else {
      // Görsel <img> olarak değil tuval olarak gösterilir (uzun basınca "resmi kaydet" çıkmaz).
      const bitmap = await createImageBitmap(new Blob([bytes], { type: fig.dataset.mime }));
      const scale = Math.min(1, (width * ratio) / bitmap.width);
      const canvas = addCanvas(bitmap.width * scale, bitmap.height * scale, fig.dataset.name);
      canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      drawWatermark(canvas, cfg.watermark);
    }
    state.hidden = true;
  } catch (e) {
    console.error('Dosya görüntülenemedi:', e && e.name, e && e.message);
    state.className = 'alert error';
    state.textContent = 'Dosya görüntülenemedi. Bağlantınızı kontrol edin; sorun sürerse telefonunuzun tarayıcısını güncelleyin. ';
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.textContent = 'Tekrar dene';
    retry.style.cssText = 'font-weight:600;text-decoration:underline;background:none;border:0;color:inherit;padding:0';
    retry.onclick = () => render(fig);
    state.appendChild(retry);
  }
}

for (const fig of document.querySelectorAll('[data-protected-file]')) {
  fig.addEventListener('contextmenu', (e) => e.preventDefault());
  render(fig);
}
