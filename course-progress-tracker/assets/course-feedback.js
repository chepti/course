/**
 * Course feedback form - opens from the unit footer button and e-mails the
 * course owner together with where in the course it was sent from
 * (unit, chapter, sub-chapter, page URL). See includes/feedback.php.
 */
(function () {
    'use strict';

    var cfg = window.cptFeedback;
    if (!cfg) { return; }

    var TYPES = [
        { id: 'question', label: 'שאלה' },
        { id: 'comment', label: 'הערה' },
        { id: 'fix', label: 'תיקון' }
    ];

    var lastItem = null; // last clicked chapter / sub-chapter in the unit nav

    // Capture phase: sub-item handlers stop propagation.
    document.addEventListener('click', function (e) {
        var it = e.target.closest && e.target.closest('#interactive-unit-container .main-item, #interactive-unit-container .sub-item');
        if (it && it.getAttribute('data-section')) { lastItem = it; }
    }, true);

    function labelOf(item) {
        if (!item) { return ''; }
        var s = item.querySelector('span');
        return ((s ? s.textContent : item.textContent) || '').replace(/\s+/g, ' ').trim();
    }

    function currentItem() {
        var container = document.getElementById('interactive-unit-container');
        if (!container) { return null; }
        // Prefer the highlighted item (kept up to date even when the engine
        // opens a sub-chapter by itself); fall back to the last click, then
        // to the unit's default chapter.
        var active = container.querySelector('.sub-item.active') || container.querySelector('.main-item.active');
        if (active) { return active; }
        if (lastItem && container.contains(lastItem)) { return lastItem; }
        var def = (window.cptUnitConfig && window.cptUnitConfig.defaultSection) || 'overview';
        return container.querySelector('.main-item[data-section="' + def + '"]');
    }

    function collectContext() {
        var item = currentItem();
        var main = null, sub = null;
        if (item && item.classList.contains('sub-item')) {
            sub = item;
            var wrap = item.closest('.sub-items');
            var nav = wrap ? wrap.closest('.nav-item') : null;
            main = nav ? nav.querySelector('.main-item') : null;
        } else {
            main = item;
        }
        var kicker = document.querySelector('.course-hero-kicker');
        var title = document.querySelector('.course-hero-title');
        var unit = [kicker ? kicker.textContent.trim() : '', title ? title.textContent.trim() : ''].filter(Boolean).join(' - ');
        if (!unit) { unit = document.title; }
        var area = document.getElementById('content-area');
        var h = area ? area.querySelector('h1, h2, h3') : null;
        return {
            unit: unit,
            main_label: labelOf(main),
            main_id: main ? main.getAttribute('data-section') : '',
            sub_label: labelOf(sub),
            sub_id: sub ? sub.getAttribute('data-section') : '',
            heading: h ? h.textContent.replace(/\s+/g, ' ').trim() : ''
        };
    }

    function whereText(ctx) {
        return [ctx.unit, ctx.main_label, ctx.sub_label].filter(Boolean).join(' › ');
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text) { n.textContent = text; }
        return n;
    }

    var overlay = null;

    function close() {
        if (overlay) { overlay.remove(); overlay = null; }
        document.removeEventListener('keydown', onKey);
        var opener = document.getElementById('course-feedback-open');
        if (opener) { opener.focus(); }
    }

    function onKey(e) { if (e.key === 'Escape') { close(); } }

    function open() {
        if (overlay) { return; }
        var ctx = collectContext();
        var type = 'comment';

        overlay = el('div', 'cpt-fb');
        var dlg = el('div', 'cpt-fb-dialog');
        dlg.setAttribute('role', 'dialog');
        dlg.setAttribute('aria-modal', 'true');
        dlg.setAttribute('aria-labelledby', 'cpt-fb-title');

        var title = el('h2', 'cpt-fb-title', 'שלחו לי משוב');
        title.id = 'cpt-fb-title';
        var where = el('p', 'cpt-fb-where');
        where.appendChild(el('span', 'cpt-fb-where-label', 'נשלח מתוך: '));
        where.appendChild(el('strong', '', whereText(ctx)));

        var chips = el('div', 'cpt-fb-types');
        chips.setAttribute('role', 'radiogroup');
        TYPES.forEach(function (t) {
            var b = el('button', 'cpt-fb-chip' + (t.id === type ? ' is-on' : ''), t.label);
            b.type = 'button';
            b.setAttribute('role', 'radio');
            b.setAttribute('aria-checked', t.id === type ? 'true' : 'false');
            b.addEventListener('click', function () {
                type = t.id;
                chips.querySelectorAll('.cpt-fb-chip').forEach(function (c) {
                    var on = c === b;
                    c.classList.toggle('is-on', on);
                    c.setAttribute('aria-checked', on ? 'true' : 'false');
                });
            });
            chips.appendChild(b);
        });

        var ta = el('textarea', 'cpt-fb-text');
        ta.rows = 5;
        ta.maxLength = 3000;
        ta.placeholder = 'כתבו כאן שאלה, הערה או תיקון (למשל: קישור שבור, טעות כתיב, משהו שלא ברור)';
        ta.setAttribute('aria-label', 'תוכן המשוב');

        // Honeypot: hidden from people, bots fill it.
        var hp = el('input', 'cpt-fb-hp');
        hp.type = 'text'; hp.name = 'website'; hp.tabIndex = -1; hp.autocomplete = 'off';
        hp.setAttribute('aria-hidden', 'true');

        var status = el('p', 'cpt-fb-status');
        status.setAttribute('aria-live', 'polite');

        var row = el('div', 'cpt-fb-actions');
        var send = el('button', 'cpt-fb-send', 'שליחה');
        send.type = 'button';
        var cancel = el('button', 'cpt-fb-cancel', 'ביטול');
        cancel.type = 'button';
        row.appendChild(send);
        row.appendChild(cancel);

        dlg.appendChild(title);
        dlg.appendChild(where);
        dlg.appendChild(chips);
        dlg.appendChild(ta);
        dlg.appendChild(hp);
        dlg.appendChild(status);
        dlg.appendChild(row);
        overlay.appendChild(dlg);
        document.body.appendChild(overlay);
        ta.focus();

        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) { close(); } });
        cancel.addEventListener('click', close);
        document.addEventListener('keydown', onKey);

        send.addEventListener('click', function () {
            var msg = ta.value.trim();
            if (msg.length < 3) {
                status.textContent = 'כתבו לפחות כמה מילים';
                status.className = 'cpt-fb-status is-error';
                ta.focus();
                return;
            }
            send.disabled = true;
            status.textContent = 'שולחת…';
            status.className = 'cpt-fb-status';

            var fd = new FormData();
            fd.append('action', 'cpt_feedback');
            fd.append('nonce', cfg.nonce);
            fd.append('post_id', cfg.postId);
            fd.append('type', type);
            fd.append('message', msg);
            fd.append('website', hp.value);
            fd.append('url', window.location.href);
            fd.append('viewport', window.innerWidth + 'x' + window.innerHeight);
            Object.keys(ctx).forEach(function (k) { fd.append(k, ctx[k]); });

            fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.success) {
                        dlg.innerHTML = '';
                        var ok = el('div', 'cpt-fb-done');
                        ok.appendChild(el('h2', 'cpt-fb-title', 'תודה, ההודעה נשלחה'));
                        ok.appendChild(el('p', '', 'אענה בהקדם אם צריך. אפשר להמשיך ללמוד.'));
                        var done = el('button', 'cpt-fb-send', 'סגירה');
                        done.type = 'button';
                        done.addEventListener('click', close);
                        ok.appendChild(done);
                        dlg.appendChild(ok);
                        done.focus();
                    } else {
                        throw new Error((res && res.data && res.data.message) || 'השליחה נכשלה');
                    }
                })
                .catch(function (err) {
                    status.textContent = err && err.message ? err.message : 'השליחה נכשלה, נסו שוב';
                    status.className = 'cpt-fb-status is-error';
                    send.disabled = false;
                });
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('#course-feedback-open');
        if (btn) { e.preventDefault(); open(); }
    });
})();
