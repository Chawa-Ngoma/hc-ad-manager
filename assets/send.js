// Send tab: compose the email, check the addresses, preview what customers will see, review, and send.
// Addresses stay in this page until "Send now": they are never sent to the server before that, never saved on the
// device and never put in a URL. Only the message text is remembered locally.
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var MESSAGE_KEY = 'send-message';
  var MESSAGE_MAX = 5000;
  var DEFAULT_MESSAGE = 'Hi there,\n\nWe would love to see you at our next showing. Come and see what we have on display.';
  // Guidance only: the server's own check decides when it matters.
  var ADDRESS = /^[^\s@,;<>]+@[^\s@,;<>]+\.[^\s@,;<>]{2,}$/;

  var tab = $('tab-send');
  var to = $('send-to'), cc = $('send-cc'), subject = $('send-subject'), message = $('send-message');
  var details = $('send-details'), image = $('send-image'), frame = $('send-preview');
  var review = $('send-review'), hint = $('send-hint'), summary = $('send-summary');
  var ad = window.APP.ad;
  var ccTouched = false, subjectEdited = false, previewStale = true, previewSeq = 0, previewTimer;

  function settings() { return window.APP.settings; }
  function one(s) { return String(s).replace(/\s+/g, ' ').trim(); }
  function businessName() { return settings().senderName || 'Ad Manager'; } // same fallback as the server
  function fromText() {
    var s = settings();
    if (!s.senderEmail) return 'Not set up yet';
    return s.senderName ? s.senderName + ' <' + s.senderEmail + '>' : s.senderEmail;
  }

  // ---- settings: the note, the From line and the CC default
  function showSettings() {
    var s = settings(), missing = [];
    if (!s.senderEmail) missing.push('sender email');
    if (!s.smtpHost) missing.push('mail server name');
    $('send-notice').hidden = missing.length === 0;
    $('send-notice-text').textContent = 'Before you can send, add your ' + missing.join(' and ') + ' in Settings.';
    $('send-from').textContent = fromText();
    if (!ccTouched) cc.value = s.ccEmail;
  }

  // ---- subject: follows the ad until it is edited
  function autoSubject() {
    if (subjectEdited) return;
    var venue = one(ad.venue), date = window.flyerDate(ad.date);
    subject.value = businessName() + (venue ? ' at ' + venue : '') + (date ? ', ' + date : '');
  }

  // ---- addresses: chips and the count against the limit
  function checkAddresses() {
    var seen = {}, valid = 0, bad = 0, repeated = 0, chips = $('send-chips');
    chips.textContent = '';
    to.value.split(/[\s,;]+/).filter(Boolean).forEach(function (text) {
      var good = ADDRESS.test(text), key = text.toLowerCase(), kind = 'ok', label = text;
      if (!good) { bad++; kind = 'invalid'; label = text + ' (check this)'; }
      else if (seen[key]) { repeated++; kind = 'dup'; label = text + ' (repeated)'; }
      else { seen[key] = true; valid++; }
      var chip = document.createElement('span');
      chip.className = kind === 'ok' ? 'chip' : 'chip ' + kind;
      chip.textContent = label;
      chips.appendChild(chip);
    });
    var limit = settings().batchLimit, out = $('send-count'), text;
    if (!valid && !bad && !repeated) {
      text = 'No addresses yet. You can send to ' + limit + ' customers at a time.';
    } else {
      text = valid + ' of ' + limit + ' customers';
      if (bad) text += ', ' + bad + ' to check';
      if (repeated) text += ', ' + repeated + ' repeated';
      if (valid > limit) text += '. Too many: please remove ' + (valid - limit) + '.';
    }
    out.textContent = text;
    out.className = valid > limit ? 'count over' : 'count';
    return { valid: valid, bad: bad, limit: limit };
  }

  // ---- the preview: the server renders the real email; we show it in a sandboxed frame without scripts
  function fit() {
    var doc = frame.contentDocument;
    if (!doc || !doc.body || tab.hidden) return;
    frame.style.height = '0px';
    frame.style.height = (doc.documentElement.scrollHeight + frame.offsetHeight - frame.clientHeight) + 'px'; // plus the frame's border
  }

  async function loadPreview() {
    var seq = ++previewSeq, note = $('send-preview-note');
    previewStale = false;
    try {
      var res = await window.api('preview', { ad: ad, msg: message.value, details: details.checked, image: image.checked });
      if (seq !== previewSeq) return; // a newer request is on its way
      frame.srcdoc = res.html;
      note.hidden = true;
    } catch (err) {
      if (seq !== previewSeq) return;
      previewStale = true;
      note.textContent = err.message;
      note.hidden = false;
    }
  }

  // 400 ms after the last change; while the tab is hidden just remember that the preview is out of date.
  function schedulePreview() {
    previewStale = true;
    clearTimeout(previewTimer);
    previewTimer = setTimeout(function () { if (!tab.hidden) loadPreview(); }, 400);
  }

  // ---- which card shows under the preview: '' (just Review and send), summary, progress, problem or result
  var panels = { summary: summary, progress: $('send-progress'), problem: $('send-problem'), result: $('send-result') };
  var sending = false, job = null;
  function showPanel(name) {
    Object.keys(panels).forEach(function (k) { panels[k].hidden = k !== name; });
    review.hidden = name !== '';
  }
  function closeSummary() { if (!summary.hidden) showPanel(''); }

  // ---- review

  function problem(c) {
    var s = settings();
    if (!s.senderEmail || !s.smtpHost) return 'Please fill in your email settings first.';
    if (!c.valid) return 'Please add at least one valid email address.';
    if (c.valid > c.limit) return 'You can send to ' + c.limit + ' customers at a time. Please remove ' + (c.valid - c.limit) + '.';
    if (cc.value.trim() && !ADDRESS.test(cc.value.trim())) return 'The CC address does not look right.';
    if (!one(subject.value)) return 'Please write a subject.';
    return '';
  }

  review.addEventListener('click', function () {
    var c = checkAddresses(), why = problem(c);
    hint.hidden = !why;
    hint.textContent = why;
    if (why) return;
    $('sum-from').textContent = fromText();
    $('sum-count').textContent = c.valid + (c.valid === 1 ? ' customer' : ' customers') + (c.bad ? ' (' + c.bad + ' to check will be skipped)' : '');
    $('sum-cc').textContent = cc.value.trim() || 'None';
    $('sum-subject').textContent = one(subject.value);
    $('sum-image').textContent = image.checked ? 'Attached' : 'Not attached';
    showPanel('summary');
  });
  $('send-back').addEventListener('click', closeSummary);
  $('go-settings').addEventListener('click', function () { $('t-settings').click(); });

  // ---- the send
  function sleep(ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); }
  function tokens(box) { return box.value.split(/[\s,;]+/).filter(Boolean); }

  // Every control in the Send tab, and Sign out, is switched off while a send runs.
  function lock(on) {
    Array.prototype.forEach.call(tab.querySelectorAll('input, textarea, select, button'), function (el) {
      if (!el.closest('#send-progress, #send-problem, #send-result')) el.disabled = on;
    });
    $('signout').disabled = on;
  }

  window.addEventListener('beforeunload', function (e) {
    if (sending) { e.preventDefault(); e.returnValue = ''; }
  });

  function setProgress(done, total, label) {
    var pct = total ? Math.round(done / total * 100) : 0;
    $('send-bar-fill').style.width = pct + '%';
    $('send-bar').setAttribute('aria-valuenow', String(pct));
    $('send-progress-text').textContent = label || done + ' of ' + total + ' done';
  }

  // how: 'resume' (Try again carries on), 'signin' (sign in again) or 'back' (nothing to carry on with)
  function showProblem(text, how) {
    sending = how === 'resume'; // only a send that can carry on keeps the "leave this page?" warning
    $('send-problem-text').textContent = text;
    $('send-retry').hidden = how !== 'resume';
    $('send-signin').hidden = how !== 'signin';
    $('send-stop').hidden = how === 'signin';
    $('send-stop').textContent = how === 'resume' ? 'Stop sending' : 'Back';
    showPanel('problem');
  }

  function take(r) {
    job.total = r.total;
    job.next = r.next;
    job.sent = r.sent;
    job.failed = r.failed || [];
    job.unconfirmed = r.unconfirmed || [];
    setProgress(job.next, job.total);
  }

  function fillList(id, items) {
    var list = $(id + '-list');
    list.textContent = '';
    items.forEach(function (text) {
      var li = document.createElement('li');
      li.textContent = text;
      list.appendChild(li);
    });
    $(id).hidden = items.length === 0;
  }

  function finish(r) {
    sending = false;
    lock(false);
    var bad = job.failed.length, unsure = job.unconfirmed.length, clean = !bad && !unsure, copy = r.cc;
    $('send-result').className = 'panel ' + (clean ? 'good' : 'problems');
    $('send-result-title').textContent = clean ? 'Sent' : 'Sent with problems';
    $('send-result-counts').textContent = job.sent + ' of ' + job.total + ' sent.' +
      (bad ? ' ' + bad + ' did not go out.' : '') + (unsure ? ' ' + unsure + ' may or may not have received it.' : '');
    fillList('send-failed', job.failed.map(function (f) { return f.to + ': ' + f.err; }));
    fillList('send-unconfirmed', job.unconfirmed.map(function (u) { return u.to; }));
    $('send-cc-note').hidden = !copy;
    if (copy) {
      $('send-cc-note').textContent = copy.skipped ? 'No copy was sent to you because no customer email was accepted.'
        : copy.ok ? 'Your copy was sent.' : 'Your copy could not be sent: ' + copy.err + ' This did not affect your customers.';
    }
    showPanel('result');
  }

  // Calls send_chunk until everyone is done. Only this call is ever repeated automatically, and that is safe:
  // the server never sends to the same person twice.
  async function runChunks() {
    var lost = 0, r;
    sending = true;
    showPanel('progress');
    setProgress(job.next, job.total);
    while (true) {
      try {
        r = await window.api('send_chunk', { id: job.id }, null, true);
        lost = 0;
      } catch (err) {
        if (err.status === 401) {
          showProblem('You were signed out. Some emails may already have gone out. ' + job.sent + ' of ' + job.total + ' had gone out when you were signed out.', 'signin');
          return;
        }
        if (!err.status && lost < 3) { lost++; await sleep(2000); continue; } // the network dropped: ask again
        if (err.body && typeof err.body.next === 'number') take(err.body);
        showProblem(err.message + ' ' + job.sent + ' of ' + job.total + ' had gone out when it stopped. Some emails may already have gone out, so do not send everything again.', err.status === 404 ? 'back' : 'resume');
        return;
      }
      if (r.busy) { await sleep(1500); continue; } // another request is still working on this send
      take(r);
      if (r.done) { finish(r); return; }
    }
  }

  async function startSend() {
    var c = checkAddresses(), why = problem(c);
    if (why) { showPanel(''); hint.hidden = false; hint.textContent = why; return; }
    lock(true);
    showPanel('progress');
    setProgress(0, 0, 'Saving your ad…');
    var reason = await window.saveAd(true); // what is sent is what is saved
    if (reason) { showProblem(reason + ' Nothing was sent.', 'back'); return; }
    var png = null;
    if (image.checked) {
      setProgress(0, 0, 'Getting the flyer picture ready…');
      png = await window.flyerPng();
      if (!png) { showProblem('The flyer picture could not be made. Nothing was sent. Please try again.', 'back'); return; }
    }
    setProgress(0, 0, 'Getting ready…');
    var prep;
    try { // not repeated automatically: a person decides whether to try again
      prep = await window.api('send_prepare', { to: tokens(to), cc: tokens(cc), subject: one(subject.value), msg: message.value, details: details.checked, image: image.checked }, png, true);
    } catch (err) {
      showProblem(err.status === 401 ? 'You were signed out. Nothing was sent.' : err.message + ' Nothing was sent.', err.status === 401 ? 'signin' : 'back');
      return;
    }
    job = { id: prep.id, total: prep.total, next: 0, sent: 0, failed: [], unconfirmed: [] };
    runChunks();
  }

  $('send-now').addEventListener('click', startSend);
  $('send-retry').addEventListener('click', function () { runChunks(); });
  $('send-signin').addEventListener('click', function () { location.reload(); });
  $('send-stop').addEventListener('click', function () { sending = false; job = null; lock(false); showPanel(''); });
  $('send-again').addEventListener('click', function () { job = null; to.value = ''; checkAddresses(); hint.hidden = true; showPanel(''); });

  // ---- wiring
  to.addEventListener('input', function () { checkAddresses(); hint.hidden = true; closeSummary(); });
  cc.addEventListener('input', function () { ccTouched = true; hint.hidden = true; closeSummary(); });
  subject.addEventListener('input', function () { subjectEdited = true; hint.hidden = true; closeSummary(); });
  message.addEventListener('input', function () {
    try { localStorage.setItem(MESSAGE_KEY, message.value); } catch (e) { /* storage is optional */ }
    closeSummary();
    schedulePreview();
  });
  [details, image].forEach(function (box) { box.addEventListener('change', function () { closeSummary(); schedulePreview(); }); });

  document.addEventListener('flyer-changed', function (e) {
    ad = e.detail;
    autoSubject();
    schedulePreview();
  });
  document.addEventListener('settings-saved', function () {
    showSettings();
    autoSubject();
    checkAddresses();
    closeSummary();
    schedulePreview();
  });

  frame.addEventListener('load', fit);
  window.addEventListener('resize', fit);
  $('t-send').addEventListener('click', function () {
    fit();
    if (previewStale) loadPreview();
  });

  // ---- start
  var remembered = null;
  try { remembered = localStorage.getItem(MESSAGE_KEY); } catch (e) { /* storage is optional */ }
  message.value = (remembered === null ? DEFAULT_MESSAGE : remembered).slice(0, MESSAGE_MAX);
  showSettings();
  autoSubject();
  checkAddresses();
})();
