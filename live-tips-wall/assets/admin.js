(function () {
  'use strict';

  var cfg = window.ltwAdmin || {};
  var i18n = cfg.i18n || {};

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.ltw-toggle-btn');
    if (!btn) return;

    var row = btn.closest('tr');
    if (!row) return;

    var id = row.getAttribute('data-id');
    var current = btn.getAttribute('data-status');
    var next = current === 'hidden' ? 'visible' : 'hidden';

    btn.disabled = true;

    fetch(cfg.restUrl + 'tips/' + id + '/status', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce,
      },
      body: JSON.stringify({ status: next }),
      credentials: 'same-origin',
    })
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok) throw new Error();
          return data;
        });
      })
      .then(function () {
        btn.setAttribute('data-status', next);
        btn.textContent = next === 'hidden' ? (i18n.show || 'הצג שוב') : (i18n.hide || 'הסתר');
        var statusCell = row.querySelector('.ltw-status-cell');
        if (statusCell) {
          statusCell.textContent = next === 'hidden' ? (i18n.hidden || 'מוסתר') : (i18n.visible || 'גלוי');
        }
        row.classList.toggle('ltw-row-hidden', next === 'hidden');
      })
      .catch(function () {
        alert(i18n.error || 'שגיאה');
      })
      .finally(function () {
        btn.disabled = false;
      });
  });
})();
