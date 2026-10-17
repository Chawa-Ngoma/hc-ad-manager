// App shell: api() helper, toast, tab switching and sign out.
(function () {
  'use strict';

  var toastTimer;

  function toast(message) {
    var el = document.getElementById('toast');
    el.textContent = message;
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 3000);
  }

  // Posts one action to api.php and resolves with the parsed JSON.
  // Rejects with an Error whose message is safe to show to the user.
  async function api(action, data, file, stay, pdf) {
    var body = new FormData();
    body.append('action', action);
    body.append('data', JSON.stringify(data || {}));
    if (file) body.append('flyer', file);
    if (pdf) body.append('pdf', pdf);

    var res;
    try {
      res = await fetch('api.php', {
        method: 'POST',
        headers: { 'X-CSRF': window.APP.csrf },
        body: body,
        credentials: 'same-origin'
      });
    } catch (e) {
      throw new Error('Cannot reach the server. Please check your connection and try again.'); // no .status: the network failed
    }

    if (res.status === 401) {
      if (!stay) location.replace('./'); // a send in progress stays on screen instead, so its numbers are not lost
      var signedOut = new Error('Please sign in again.');
      signedOut.status = 401;
      throw signedOut;
    }

    var json;
    try {
      json = await res.json();
    } catch (e) {
      var odd = new Error('Something went wrong. Please try again.');
      odd.status = res.status;
      throw odd;
    }
    if (!res.ok) {
      var failed = new Error(json.error || 'Something went wrong. Please try again.');
      failed.status = res.status;
      failed.body = json;
      throw failed;
    }
    return json;
  }

  function showTab(name) {
    document.querySelectorAll('.tab').forEach(function (s) { s.hidden = s.id !== 'tab-' + name; });
    document.querySelectorAll('.tabbar button').forEach(function (b) {
      b.setAttribute('aria-selected', String(b.dataset.tab === name));
    });
  }

  document.querySelectorAll('.tabbar button').forEach(function (b) {
    b.addEventListener('click', function () { showTab(b.dataset.tab); });
  });

  document.getElementById('signout').addEventListener('click', async function () {
    try {
      await api('logout');
    } catch (e) {
      toast(e.message);
      return;
    }
    location.replace('./');
  });

  // Ad editor: fill the form, track unsaved changes, save and undo.
  var form = document.getElementById('ad-form');
  var saveBtn = document.getElementById('ad-save');
  var undoBtn = document.getElementById('ad-undo');
  var stateEl = document.getElementById('ad-state');
  var errorEl = document.getElementById('ad-error');
  var itemBoxes = Array.prototype.slice.call(form.querySelectorAll('input[name="items[]"]'));
  var itemOrder = itemBoxes.map(function (b) { return b.value; });
  var keys = Object.keys(window.APP.ad); // the server's fixed key order
  var saved = window.APP.ad;

  // Same key order and item order every time, so two equal ads give equal JSON.
  function normalise(ad) {
    var out = {};
    keys.forEach(function (k) {
      out[k] = k === 'items'
        ? itemOrder.filter(function (i) { return ad.items.indexOf(i) !== -1; })
        : String(ad[k]);
    });
    return JSON.stringify(out);
  }

  function readForm() {
    var ad = {};
    keys.forEach(function (k) { if (k !== 'items') ad[k] = form.elements[k].value; });
    // Always an array, even when nothing is ticked.
    ad.items = itemBoxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
    return ad;
  }

  function fillForm(ad) {
    keys.forEach(function (k) { if (k !== 'items') form.elements[k].value = ad[k]; });
    itemBoxes.forEach(function (b) { b.checked = ad.items.indexOf(b.value) !== -1; });
  }

  function isDirty() { return normalise(readForm()) !== normalise(saved); }

  function refresh() {
    var dirty = isDirty();
    saveBtn.disabled = !dirty;
    undoBtn.disabled = !dirty;
    stateEl.textContent = dirty ? 'You have changes that are not saved yet.' : 'Everything is saved.';
  }

  // Live preview: redraw from the form (unsaved edits included) on every change, with no server call.
  var canvas = document.getElementById('flyer');
  var drawQueued = false;
  function redraw() {
    if (drawQueued) return;
    drawQueued = true;
    requestAnimationFrame(function () {
      drawQueued = false;
      var ad = readForm();
      window.drawFlyer(canvas, ad);
      canvas.setAttribute('aria-label', 'Preview of the flyer: ' + ad.title + ', ' + ad.venue);
      document.dispatchEvent(new CustomEvent('flyer-changed', { detail: ad })); // the Send tab follows the ad
    });
  }

  form.addEventListener('input', function () { errorEl.hidden = true; refresh(); redraw(); });
  form.addEventListener('change', redraw);

  // Phones: switch between the form and the flyer (from 900 px wide both show).
  var cols = document.getElementById('ad-cols');
  function showView(view) {
    cols.dataset.view = view;
    document.getElementById('seg-edit').setAttribute('aria-pressed', String(view === 'edit'));
    document.getElementById('seg-see').setAttribute('aria-pressed', String(view === 'see'));
    window.scrollTo(0, 0);
  }
  document.getElementById('seg-edit').addEventListener('click', function () { showView('edit'); });
  document.getElementById('seg-see').addEventListener('click', function () { showView('see'); });

  // Saves the ad. Resolves to '' when saved, or the reason it was refused. The Send tab uses it before every send.
  async function saveAd(quiet) {
    saveBtn.disabled = true;
    var reason = '';
    try {
      var res = await api('save_ad', readForm());
      saved = res.ad;
      fillForm(saved);
      redraw();
      errorEl.hidden = true;
      if (!quiet) toast('Saved. The ad is up to date.');
    } catch (err) {
      errorEl.textContent = err.message; // stays until she edits a field or saves again
      errorEl.hidden = false;
      reason = err.message;
    }
    refresh();
    return reason;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    saveAd();
  });

  undoBtn.addEventListener('click', function () {
    fillForm(saved);
    errorEl.hidden = true;
    refresh();
    redraw();
    toast('Changes undone.');
  });

  // Hands a finished file to the share sheet where the phone allows it, otherwise downloads it under the same name.
  async function shareOrSave(blob, name, type) {
    var file = new File([blob], name, { type: type });
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
      try { await navigator.share({ files: [file], title: 'Flyer' }); return; }
      catch (err) { if (err && err.name === 'AbortError') return; }
    }
    var url = URL.createObjectURL(blob), a = document.createElement('a');
    a.href = url; a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  // Save flyer picture. Draws now, from the form, so unsaved edits are in it.
  document.getElementById('save-flyer').addEventListener('click', function () {
    window.drawFlyer(canvas, readForm());
    canvas.toBlob(function (blob) {
      if (!blob) { toast('Could not make the picture. Please try again.'); return; }
      shareOrSave(blob, 'Flyer.png', 'image/png');
    }, 'image/png');
  });

  document.getElementById('save-flyer-pdf').addEventListener('click', async function () {
    var pdf = await window.flyerPdf();
    if (!pdf) { toast('Could not make the PDF. Please try again.'); return; }
    shareOrSave(pdf, 'Flyer.pdf', 'application/pdf');
  });

  fillForm(saved);
  refresh();
  redraw();

  window.api = api;
  window.saveAd = saveAd;
  // The flyer as a PNG blob, drawn from the form (after a save that is the saved ad).
  window.flyerPng = function () {
    return new Promise(function (resolve) {
      window.drawFlyer(canvas, readForm());
      canvas.toBlob(resolve, 'image/png');
    });
  };
  // The flyer as a one-page PDF, no library: the canvas as a JPEG (quality 0.95, on white, because a JPEG has no
  // transparency) wrapped in a hand-written PDF whose page is the flyer's own 2:3 shape (400 x 600), no margin. It is a picture: no
  // selectable text and no tappable numbers. Resolves to a Blob, or null.
  window.flyerPdf = function () {
    return new Promise(function (resolve) {
      window.drawFlyer(canvas, readForm());
      var flat = document.createElement('canvas');
      flat.width = canvas.width; flat.height = canvas.height;
      var g = flat.getContext('2d');
      g.fillStyle = '#fff'; g.fillRect(0, 0, flat.width, flat.height); g.drawImage(canvas, 0, 0);
      flat.toBlob(function (jpg) { resolve(jpg ? pdfPage(jpg, flat.width, flat.height) : null); }, 'image/jpeg', 0.95);
    });
  };

  function pdfPage(jpg, w, h) {
    var PW = 400, PH = 600; // the flyer's own 2:3 shape, so the picture fills the page edge to edge
    var draw = 'q ' + PW + ' 0 0 ' + PH + ' 0 0 cm /Im0 Do Q';
    var parts = ['%PDF-1.4\n'], pos = parts[0].length, at = [];
    function put(n, text) { at[n] = pos; var t = n + ' 0 obj\n' + text + '\nendobj\n'; parts.push(t); pos += t.length; }
    put(1, '<< /Type /Catalog /Pages 2 0 R >>');
    put(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
    put(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + PW + ' ' + PH + '] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>');
    put(4, '<< /Length ' + draw.length + ' >>\nstream\n' + draw + '\nendstream');
    at[5] = pos; // the picture itself goes in as the Blob, so its bytes are never copied into a string
    var open = '5 0 obj\n<< /Type /XObject /Subtype /Image /Width ' + w + ' /Height ' + h + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + jpg.size + ' >>\nstream\n';
    var shut = '\nendstream\nendobj\n';
    parts.push(open, jpg, shut); pos += open.length + jpg.size + shut.length;
    var xref = 'xref\n0 6\n0000000000 65535 f \n';
    for (var n = 1; n <= 5; n++) xref += ('0000000000' + at[n]).slice(-10) + ' 00000 n \n';
    parts.push(xref + 'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' + pos + '\n%%EOF\n');
    return new Blob(parts, { type: 'application/pdf' });
  }
  window.toast = toast;
})();
