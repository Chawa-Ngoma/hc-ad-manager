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

  window.api = api;
  window.toast = toast;
})();
