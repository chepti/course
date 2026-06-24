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
              '<p class="cpt-fe-hint">סרטון במקום מסוים: <code>[video: קישור | כותרת]</code> · תיבת פרומפט להעתקה: <code>[prompt]הטקסט[/prompt]</code> · קו מפריד: כפתור הקו האופקי.</p>' +
              '<textarea id="' + EDITOR_ID + '" style="width:100%;min-height:320px"></textarea>' +
            '</div>' +
            '<div class="cpt-fe-panel-foot">' +
              '<button type="button" class="cpt-fe-btn cpt-fe-save">💾 שמירה</button>' +
              '<button type="button" class="cpt-fe-btn cpt-fe-cancel">ביטול</button>' +
            '</div>';

        document.body.appendChild(overlay);
        document.body.appendChild(panel);

        var ta = document.getElementById(EDITOR_ID);
        ta.value = rawHtml || ''; // Text/Code tab + plain-textarea fallback

        // rich editor (falls back to a plain textarea if wp.editor is absent)
        if (window.wp && wp.editor && wp.editor.initialize) {
            var edHeight = Math.max(360, window.innerHeight - 250);
            wp.editor.initialize(EDITOR_ID, {
                tinymce: {
                    wpautop: true,
                    height: edHeight,
                    toolbar1: 'formatselect bold italic bullist numlist link hr removeformat undo redo',
                    directionality: 'rtl'
                },
                quicktags: true,
                mediaButtons: true
            });
            // TinyMCE reads the textarea at init; the value we just set can be
            // missed, so push it into the visual editor once it's ready.
            setTimeout(function () {
                var ed = window.tinymce && tinymce.get(EDITOR_ID);
                if (ed) { ed.setContent(rawHtml || ''); }
            }, 300);
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
                    // keep the engine's in-memory copy in sync so navigating away
                    // and back shows the new content (not the page-load version)
                    if (window.cptUnitContent) { window.cptUnitContent[sid] = res.data.rendered; }
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
                if (st) { st.textContent = val; }
            } else {
                alert('שמירה נכשלה');
            }
        });
    }

    // A fixed bottom toolbar (theme-proof) instead of per-area buttons that the
    // theme's button styles hijack and the sidebar covers.
    function buildToolbar() {
        if (document.getElementById('cpt-fe-bar')) { return; }
        var bar = document.createElement('div');
        bar.id = 'cpt-fe-bar';
        bar.className = 'cpt-fe-bar';

        var edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'cpt-fe-bar-btn';
        edit.innerHTML = '✏️ ערוך פרק';
        edit.addEventListener('click', function (e) { e.preventDefault(); editContent(); });

        var title = document.createElement('button');
        title.type = 'button';
        title.className = 'cpt-fe-bar-btn cpt-fe-bar-btn-sec';
        title.innerHTML = 'כותרת צד';
        title.addEventListener('click', function (e) { e.preventDefault(); editSidebarTitle(); });

        bar.appendChild(edit);
        bar.appendChild(title);
        document.body.appendChild(bar);
    }

    function removeToolbar() {
        var b = document.getElementById('cpt-fe-bar');
        if (b) { b.remove(); }
    }

    // ---------- Toggle ----------

    function init() {
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.id = 'cpt-fe-toggle';
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
            if (on) { buildToolbar(); } else { removeToolbar(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
