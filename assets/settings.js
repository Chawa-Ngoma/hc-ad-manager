// Settings tab: fill the form from window.APP.settings, save it, and show whether a mail password is saved.
(function () {
  'use strict';

  var form = document.getElementById('settings-form');
  var saveBtn = document.getElementById('settings-save');
  var errorEl = document.getElementById('settings-error');
  var passSaved = document.getElementById('pass-saved');
  var brand = document.querySelector('.brand');
  var keys = ['senderName', 'senderEmail', 'replyTo', 'ccEmail', 'smtpHost', 'smtpPort', 'smtpSecurity', 'smtpUser', 'batchLimit'];

  // The password fields are never filled: only the hint says whether one is saved.
  function fill(s) {
    keys.forEach(function (k) { form.elements[k].value = s[k]; });
    form.elements.trustCert.checked = s.trustCert === true;
    form.elements.smtpPass.value = '';
    form.elements.newPassword.value = '';
    passSaved.hidden = !s.hasPass;
    brand.textContent = s.senderName || 'Ad Manager';
  }

  form.addEventListener('input', function () { errorEl.hidden = true; });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var data = {};
    keys.forEach(function (k) { data[k] = form.elements[k].value; });
    data.trustCert = form.elements.trustCert.checked === true;
    data.smtpPass = form.elements.smtpPass.value;
    data.newPassword = form.elements.newPassword.value;
    saveBtn.disabled = true;
    try {
      var res = await window.api('save_settings', data);
      window.APP.csrf = res.csrf; // a new app password starts a new session token
      document.querySelector('meta[name=csrf]').setAttribute('content', res.csrf);
      window.APP.settings = res.settings;
      fill(res.settings);
      errorEl.hidden = true;
      window.toast('Settings saved.');
    } catch (err) {
      errorEl.textContent = err.message; // stays until she edits a field or saves again
      errorEl.hidden = false;
    }
    saveBtn.disabled = false;
  });

  fill(window.APP.settings);
})();
