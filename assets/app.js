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
  async function api(action, data, file) {
    var body = new FormData();
    body.append('action', action);
    body.append('data', JSON.stringify(data || {}));
    if (file) body.append('flyer', file);

    var res;
    try {
      res = await fetch('api.php', {
        method: 'POST',
        headers: { 'X-CSRF': window.APP.csrf },
        body: body,
        credentials: 'same-origin'
      });
    } catch (e) {
      throw new Error('Cannot reach the server. Please check your connection and try again.');
    }

    if (res.status === 401) {
      location.reload();
      throw new Error('Please sign in again.');
    }

    var json;
    try {
      json = await res.json();
    } catch (e) {
      throw new Error('Something went wrong. Please try again.');
    }
    if (!res.ok) throw new Error(json.error || 'Something went wrong. Please try again.');
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
    location.reload();
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

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    saveBtn.disabled = true;
    try {
      var res = await api('save_ad', readForm());
      saved = res.ad;
      fillForm(saved);
      redraw();
      errorEl.hidden = true;
      toast('Saved. The ad is up to date.');
    } catch (err) {
      errorEl.textContent = err.message; // stays until she edits a field or saves again
      errorEl.hidden = false;
    }
    refresh();
  });

  undoBtn.addEventListener('click', function () {
    fillForm(saved);
    errorEl.hidden = true;
    refresh();
    redraw();
    toast('Changes undone.');
  });

  fillForm(saved);
  refresh();
  redraw();

  window.api = api;
  window.toast = toast;
})();
