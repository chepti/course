(function () {
  'use strict';

  var cfg = window.ltwConfig || {};
  var i18n = cfg.i18n || {};

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function qsa(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function api(path, options) {
    options = options || {};
    var headers = { 'Content-Type': 'application/json' };
    if (cfg.nonce) {
      headers['X-WP-Nonce'] = cfg.nonce;
    }
    return fetch(cfg.restUrl + path, {
      method: options.method || 'GET',
      headers: headers,
      body: options.body ? JSON.stringify(options.body) : undefined,
      credentials: 'same-origin',
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) {
          var err = new Error((data && data.message) || 'error');
          err.code = data && data.code;
          err.status = res.status;
          throw err;
        }
        return data;
      });
    });
  }

  function renderStars(n) {
    var out = '';
    for (var i = 0; i < 5; i++) {
      out += i < n ? '★' : '☆';
    }
    return out;
  }

  function initRoot(root) {
    var campaign = root.getAttribute('data-campaign') || cfg.campaign || 'summer-2026';
    var form = qs('.ltw-form', root);
    var wall = qs('.ltw-wall', root);
    var emptyEl = qs('.ltw-wall-empty', root);
    var msgEl = qs('.ltw-msg', root);
    var tipField = qs('textarea[name="tip"]', form);
    var charCurrent = qs('.ltw-char-current', form);
    var knownIds = {};
    var pollTimer = null;

    // i18n labels
    var map = {
      '.ltw-form-title': 'formTitle',
      '.ltw-form-hint': 'formHint',
      '.ltw-label-tip': 'tipLabel',
      '.ltw-label-name': 'nameLabel',
      '.ltw-label-initials': 'initialsLabel',
      '.ltw-label-stars': 'starsLabel',
      '.ltw-submit': 'submit',
      '.ltw-wall-title': 'wallTitle',
    };
    Object.keys(map).forEach(function (sel) {
      var el = qs(sel, root);
      if (el && i18n[map[sel]]) {
        el.textContent = i18n[map[sel]];
      }
    });
    if (tipField && i18n.tipPlaceholder) {
      tipField.placeholder = i18n.tipPlaceholder;
    }
    var nameField = qs('input[name="name"]', form);
    if (nameField && i18n.namePlaceholder) {
      nameField.placeholder = i18n.namePlaceholder;
    }
    if (emptyEl && i18n.wallEmpty) {
      emptyEl.textContent = i18n.wallEmpty;
    }

    function noteTilt() {
      return ((Math.random() * 3.6) - 1.8).toFixed(2) + 'deg';
    }

    function showMsg(text, type) {
      if (!msgEl) return;
      msgEl.textContent = text;
      msgEl.className = 'ltw-msg' + (type ? ' ltw-msg-' + type : '');
    }

    function noteEl(tip) {
      var el = document.createElement('article');
      el.className = 'ltw-note';
      el.setAttribute('role', 'listitem');
      el.dataset.id = String(tip.id);
      el.style.background = tip.color || '#fef3c7';
      el.style.transform = 'rotate(' + noteTilt() + ')';

      var text = document.createElement('p');
      text.className = 'ltw-note-text';
      text.textContent = tip.tip;

      var meta = document.createElement('div');
      meta.className = 'ltw-note-meta';

      var name = document.createElement('span');
      name.className = 'ltw-note-name';
      name.textContent = tip.name || i18n.anonymous || 'מורה/ה';

      var stars = document.createElement('span');
      stars.className = 'ltw-note-stars';
      stars.setAttribute('aria-label', tip.stars + ' כוכבים');
      stars.textContent = renderStars(tip.stars);

      meta.appendChild(name);
      meta.appendChild(stars);
      el.appendChild(text);
      el.appendChild(meta);
      return el;
    }

    function prependNote(tip, animate) {
      if (knownIds[tip.id]) return;
      knownIds[tip.id] = true;
      var el = noteEl(tip);
      if (!animate) {
        el.style.animation = 'none';
      }
      wall.insertBefore(el, wall.firstChild);
      if (emptyEl) {
        emptyEl.hidden = true;
      }
    }

    function loadTips(since) {
      var q = 'tips?campaign=' + encodeURIComponent(campaign);
      if (since) {
        q += '&since=' + since;
      }
      return api(q).then(function (data) {
        var tips = (data && data.tips) || [];
        if (!since) {
          tips.slice().reverse().forEach(function (t) {
            prependNote(t, false);
          });
        } else {
          tips.forEach(function (t) {
            prependNote(t, true);
          });
        }
        if (emptyEl) {
          emptyEl.hidden = wall.children.length > 0;
        }
      });
    }

    function startPoll() {
      if (pollTimer) clearInterval(pollTimer);
      pollTimer = setInterval(function () {
        var maxId = 0;
        qsa('.ltw-note', wall).forEach(function (n) {
          var id = parseInt(n.dataset.id, 10);
          if (id > maxId) maxId = id;
        });
        loadTips(maxId).catch(function () {});
      }, cfg.pollMs || 15000);
    }

    if (tipField && charCurrent) {
      tipField.addEventListener('input', function () {
        charCurrent.textContent = String(tipField.value.length);
      });
    }

    function refreshStars() {
      var checked = qs('input[name="stars"]:checked', form);
      var val = checked ? parseInt(checked.value, 10) : 0;
      qsa('.ltw-star-btn', form).forEach(function (btn) {
        var input = qs('input', btn);
        var starVal = parseInt(input.value, 10);
        var span = qs('span', btn);
        span.style.color = starVal <= val ? '#f59e0b' : '#ddd';
      });
    }

    qsa('.ltw-star-btn input', form).forEach(function (input) {
      input.addEventListener('change', refreshStars);
    });
    qsa('.ltw-star-btn', form).forEach(function (btn) {
      btn.addEventListener('mouseenter', function () {
        var val = parseInt(qs('input', btn).value, 10);
        qsa('.ltw-star-btn', form).forEach(function (b) {
          var v = parseInt(qs('input', b).value, 10);
          qs('span', b).style.color = v <= val ? '#f59e0b' : '#ddd';
        });
      });
      btn.addEventListener('mouseleave', refreshStars);
    });
    refreshStars();

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      showMsg('');

      var tip = (tipField.value || '').trim();
      if (!tip) {
        showMsg(i18n.tipRequired || 'כתבו טיפ', 'err');
        tipField.focus();
        return;
      }

      var nameVal = (qs('input[name="name"]', form).value || '').trim();
      if (!nameVal) {
        showMsg(i18n.nameRequired || 'כתבו שם', 'err');
        qs('input[name="name"]', form).focus();
        return;
      }

      var starsInput = qs('input[name="stars"]:checked', form);
      var stars = starsInput ? parseInt(starsInput.value, 10) : 0;
      if (stars < 1 || stars > 5) {
        showMsg(i18n.starsRequired || 'בחרו כוכבים', 'err');
        return;
      }

      var btn = qs('.ltw-submit', form);
      btn.disabled = true;
      btn.textContent = i18n.submitting || 'שולח…';

      api('tips', {
        method: 'POST',
        body: {
          campaign: campaign,
          tip: tip,
          name: nameVal,
          initials_only: qs('input[name="initials_only"]', form).checked,
          stars: stars,
          website_url: (qs('input[name="website_url"]', form).value || ''),
        },
      })
        .then(function (data) {
          if (data && data.tip) {
            prependNote(data.tip, true);
          }
          form.reset();
          qs('input[name="initials_only"]', form).checked = true;
          qs('input[name="stars"][value="5"]', form).checked = true;
          if (charCurrent) charCurrent.textContent = '0';
          refreshStars();
          showMsg(i18n.thanks || 'תודה!', 'ok');
        })
        .catch(function (err) {
          if (err.code === 'ltw_rate_limit' || err.status === 429) {
            showMsg(i18n.rateLimit || 'המתינו', 'err');
          } else if (err.code === 'ltw_empty_name') {
            showMsg(i18n.nameRequired || 'כתבו שם', 'err');
          } else {
            showMsg(i18n.error || 'שגיאה', 'err');
          }
        })
        .finally(function () {
          btn.disabled = false;
          btn.textContent = i18n.submit || 'שלח';
        });
    });

    loadTips(0).then(startPoll).catch(function () {
      if (emptyEl) emptyEl.hidden = false;
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    qsa('.ltw-root').forEach(initRoot);
  });
})();
