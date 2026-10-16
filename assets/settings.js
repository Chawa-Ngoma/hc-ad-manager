// Settings tab: fill the form from window.APP.settings, save it, and send a test email.
(function () {
  'use strict';

  var form = document.getElementById('settings-form');
  var saveBtn = document.getElementById('settings-save');
  var testBtn = document.getElementById('test-send');
  var errorEl = document.getElementById('settings-error');
  var resultEl = document.getElementById('test-result');
  var passSaved = document.getElementById('pass-saved');
  var brand = document.querySelector('.brand');
  var keys = ['senderName', 'senderEmail', 'replyTo', 'ccEmail', 'smtpHost', 'smtpPort', 'smtpSecurity', 'smtpUser', 'batchLimit'];
  var testTouched = false; // once she types in the test box, stop filling it in for her

  // The password fields are never filled: only the hint says whether one is saved.
  function fill(s) {
    keys.forEach(function (k) { form.elements[k].value = s[k]; });
    form.elements.trustCert.checked = s.trustCert === true;
    form.elements.smtpPass.value = '';
    form.elements.newPassword.value = '';
    passSaved.hidden = !s.hasPass;
    brand.textContent = s.senderName || 'Ad Manager';
    if (!testTouched) form.elements.testTo.value = s.replyTo || s.senderEmail;
  }

  function showResult(text, kind) {
    resultEl.textContent = text;
    resultEl.className = 'result' + (kind ? ' ' + kind : '');
    resultEl.hidden = false;
  }

  form.addEventListener('input', function (e) {
    errorEl.hidden = true;
    if (e.target.name === 'testTo') testTouched = true;
  });

  // Saves the form. Resolves true when saved; false when it was refused (the reason is shown above the button).
  async function save() {
    var data = {};
    keys.forEach(function (k) { data[k] = form.elements[k].value; });
    data.trustCert = form.elements.trustCert.checked === true;
    data.smtpPass = form.elements.smtpPass.value;
    data.newPassword = form.elements.newPassword.value;
    saveBtn.disabled = true;
    testBtn.disabled = true;
    try {
      var res = await window.api('save_settings', data);
      window.APP.csrf = res.csrf; // a new app password starts a new session token
      document.querySelector('meta[name=csrf]').setAttribute('content', res.csrf);
      window.APP.settings = res.settings;
      fill(res.settings);
      errorEl.hidden = true;
      return true;
    } catch (err) {
      errorEl.textContent = err.message; // stays until she edits a field or saves again
      errorEl.hidden = false;
      return false;
    } finally {
      saveBtn.disabled = false;
      testBtn.disabled = false;
    }
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (await save()) window.toast('Settings saved.');
  });

  testBtn.addEventListener('click', async function () {
    resultEl.hidden = true;
    if (!(await save())) return; // do not send with settings that could not be saved
    var to = form.elements.testTo.value.trim();
    showResult('Sending a test email…', '');
    saveBtn.disabled = true;
    testBtn.disabled = true;
    try {
      await window.api('test', { to: to });
      showResult('Test email sent to ' + to + '. Please check that inbox.', 'ok');
    } catch (err) {
      showResult(err.message, 'err');
    }
    saveBtn.disabled = false;
    testBtn.disabled = false;
  });

  fill(window.APP.settings);
})();
