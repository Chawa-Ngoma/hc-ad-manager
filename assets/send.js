// Send tab: compose the email, check the addresses, preview what customers will see, and review before sending.
// Addresses stay in this page: they are never sent to the server before a send, never saved on the device and never
// put in a URL. Only the message text is remembered locally.
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

  // ---- review
  function closeSummary() { summary.hidden = true; review.hidden = false; }

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
    summary.hidden = false;
    review.hidden = true;
  });
  $('send-back').addEventListener('click', closeSummary);
  $('go-settings').addEventListener('click', function () { $('t-settings').click(); });

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
