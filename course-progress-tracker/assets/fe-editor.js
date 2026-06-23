/**
 * Front-end inline editor (admins only). Adds an edit-mode toggle; in edit
 * mode the current chapter's content and the sidebar title get ✏️ buttons that
 * open an in-place rich editor saving over AJAX into the structured content.
 */
(function () {
    'use strict';
    if (typeof cptFE === 'undefined') { return; }

    var EDITOR_ID = 'cpt-fe-textarea';

    function post(action, fields) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', cptFE.nonce);
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
        return fetch(cptFE.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); });
    }

    function currentSection() {
        var el = document.querySelector('#content-area .content-section[data-track-section]');
        return el ? el.getAttribute('data-track-section') : null;
    }

    // ---------- Editing panel ----------

    function openPanel(title, rawHtml, onSave) {
        closePanel();
        var overlay = document.createElement('div');
        overlay.className = 'cpt-fe-overlay';
        overlay.id = 'cpt-fe-overlay';
        overlay.addEventListener('click', closePanel);

        var panel = document.createElement('div');
        panel.className = 'cpt-fe-panel';
        panel.id = 'cpt-fe-panel';
        panel.innerHTML =
            '<div class="cpt-fe-panel-head"><span>' + title + '</span></div>' +
            '<div class="cpt-fe-panel-body">' +
              '<p class="cpt-fe-hint">לשיבוץ סרטון במקום מסוים: שורה <code>[video: קישור | כותרת]</code>. קו מפריד: כפתור הקו האופקי.</p>' +
              '<textarea id="' + EDITOR_ID + '" style="width:100%;min-height:320px"></textarea>' +
            '</div>' +
            '<div class="cpt-fe-panel-foot">' +
              '<button type="button" class="cpt-fe-btn cpt-fe-save">💾 שמירה</button>' +
              '<button type="button" class="cpt-fe-btn cpt-fe-cancel">ביטול</button>' +
            '</div>';

        document.body.appendChild(overlay);
        document.body.appendChild(panel);

        var ta = document.getElementById(EDITOR_ID);
        ta.value = rawHtml || '';

        // rich editor (falls back to a plain textarea if wp.editor is absent)
        if (window.wp && wp.editor && wp.editor.initialize) {
            wp.editor.initialize(EDITOR_ID, {
                tinymce: {
                    wpautop: true,
                    toolbar1: 'formatselect bold italic bullist numlist link hr removeformat undo redo',
                    directionality: 'rtl'
                },
                quicktags: true,
                mediaButtons: true
            });
        }

        panel.querySelector('.cpt-fe-cancel').addEventListener('click', closePanel);
        panel.querySelector('.cpt-fe-save').addEventListener('click', function () {
            var html = getEditorContent();
            panel.classList.add('cpt-fe-spinner');
            onSave(html, function (ok) {
                panel.classList.remove('cpt-fe-spinner');
                if (ok) { closePanel(); }
            });
        });
    }

    function getEditorContent() {
        if (window.tinymce) {
            var ed = tinymce.get(EDITOR_ID);
            if (ed) { return ed.getContent(); }
        }
        var ta = document.getElementById(EDITOR_ID);
        return ta ? ta.value : '';
    }

    function closePanel() {
        if (window.wp && wp.editor && wp.editor.remove) {
            try { wp.editor.remove(EDITOR_ID); } catch (e) {}
        }
        ['cpt-fe-panel', 'cpt-fe-overlay'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.remove(); }
        });
    }

    // ---------- Edit affordances ----------

    function editContent() {
        var sid = currentSection();
        if (!sid) { alert('פתחי קודם פרק (לחיצה על פרק בתפריט הצד), ואז ערכי.'); return; }
        var raw = cptFE.sections[sid] || '';
        openPanel('עריכת פרק: ' + sid, raw, function (html, done) {
            post('cpt_fe_save_section', { slug: cptFE.slug, section_id: sid, html: html }).then(function (res) {
                if (res && res.success) {
                    cptFE.sections[sid] = html;
                    var area = document.getElementById('content-area');
                    if (area) { area.innerHTML = res.data.rendered; }
                    done(true);
                } else {
                    alert('שמירה נכשלה: ' + (res && res.data ? res.data.message : 'שגיאה'));
                    done(false);
                }
            }).catch(function () { alert('שגיאת רשת בשמירה'); done(false); });
        });
    }

    function editSidebarTitle() {
        var current = cptFE.sidebarTitle || '';
        var val = window.prompt('כותרת הסיידבר:', current);
        if (val === null) { return; }
        post('cpt_fe_save_title', { slug: cptFE.slug, title: val }).then(function (res) {
            if (res && res.success) {
                cptFE.sidebarTitle = val;
                var st = document.querySelector('.sidebar-title');
                if (st) {
                    var btn = st.querySelector('.cpt-fe-edit-btn');
                    st.textContent = val;
                    if (btn) { st.appendChild(btn); }
                }
            } else {
                alert('שמירה נכשלה');
            }
        });
    }

    function ensureEditButtons() {
        var area = document.getElementById('content-area');
        if (area && !area.querySelector(':scope > .cpt-fe-edit-btn')) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'cpt-fe-edit-btn';
            b.title = 'ערוך את תוכן הפרק';
            b.textContent = '✏️';
            b.addEventListener('click', function (e) { e.preventDefault(); editContent(); });
            area.appendChild(b);
        }
        var st = document.querySelector('.sidebar-title');
        if (st && !st.querySelector('.cpt-fe-edit-btn')) {
            var b2 = document.createElement('button');
            b2.type = 'button';
            b2.className = 'cpt-fe-edit-btn';
            b2.title = 'ערוך כותרת';
            b2.textContent = '✏️';
            b2.addEventListener('click', function (e) { e.preventDefault(); editSidebarTitle(); });
            st.appendChild(b2);
        }
    }

    // ---------- Toggle ----------

    function init() {
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'cpt-fe-toggle';
        toggle.innerHTML = '<span>✏️</span> מצב עריכה';
        document.body.appendChild(toggle);

        toggle.addEventListener('click', function () {
            if (!cptFE.hasData) {
                if (confirm('יחידה זו עדיין לא הומרה לתצורה הניתנת לעריכה. לפתוח את מסך ההמרה?')) {
                    window.location.href = cptFE.editUrl;
                }
                return;
            }
            var on = document.body.classList.toggle('cpt-edit-mode');
            toggle.innerHTML = on ? '<span>✓</span> סיום עריכה' : '<span>✏️</span> מצב עריכה';
            if (on) { ensureEditButtons(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
