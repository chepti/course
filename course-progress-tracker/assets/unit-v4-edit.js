/**
 * Front-end editing for the v4 item structure (admins only).
 *
 * "מצב עריכה" (in the admin strip) shows an edit bar on every page:
 *   item    - edit (title + rich content), new item, move, delete
 *   group   - edit (title, completion rule, preface), new item, move, delete
 *   chapter - edit (title, preface), new sub-chapter, move, delete, new chapter
 * Every action saves over AJAX (cpt_v4_edit) and the page re-renders from the
 * server's answer, so what you see is what was saved.
 */
(function () {
    'use strict';
    var App = window.cptV4App;
    if (!App || !App.config.isAdmin) { return; }
    var C = App.config, icon = App.icon, esc = App.esc;
    var EDITOR_ID = 'cu4-editor';

    var EXTRA = {
        pencil: '<path d="M12 20h9"/><path d="M16.4 3.6a2 2 0 0 1 2.8 2.8L7.5 18.2 4 19l.8-3.5Z"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        up: '<path d="m18 15-6-6-6 6"/>',
        down: '<path d="m6 9 6 6 6-6"/>',
        trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/>',
        settings: '<path d="M4 6h10M18 6h2M4 12h2M10 12h10M4 18h10M18 18h2"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="16" cy="18" r="2"/>'
    };
    function ic(name) {
        return '<svg class="cu4-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + EXTRA[name] + '</svg>';
    }
    function btn(op, id, label, icn, cls, tip) {
        return '<button type="button" class="cu4-pill ' + (cls || 'cu4-pill-ghost') + '" data-edit="' + op + '" data-id="' + esc(id) + '"' +
               (tip ? ' data-tip="' + esc(tip) + '"' : '') + '>' + ic(icn) + (label ? '<span>' + label + '</span>' : '') + '</button>';
    }

    function toolbar(route) {
        if (!route || route.type === 'notes') { return ''; }
        var X = App.lookup(), h = '<div class="cu4-editbar">';
        if (route.type === 'i') {
            var it = X.items[route.id];
            h += '<span class="cu4-editbar-label">פריט</span>' +
                 btn('item_edit', it.id, 'עריכת הפריט', 'pencil', 'cu4-pill-solid') +
                 btn('group_additem', it.group.id, 'פריט חדש', 'plus') +
                 btn('item_up', it.id, '', 'up', '', 'הזזה למעלה ברשימה') +
                 btn('item_down', it.id, '', 'down', '', 'הזזה למטה ברשימה') +
                 btn('item_delete', it.id, 'מחיקה', 'trash', 'cu4-pill-danger') +
                 btn('group_edit', it.group.id, 'הגדרות תת-הפרק', 'settings', '', 'שם תת-הפרק, כלל ההשלמה והפתיח');
        } else if (route.type === 'g') {
            var g = X.groups[route.id];
            h += '<span class="cu4-editbar-label">תת-פרק</span>' +
                 btn('group_edit', g.id, 'עריכת תת-הפרק', 'pencil', 'cu4-pill-solid', 'שם, כלל ההשלמה ופתיח') +
                 btn('group_additem', g.id, 'פריט חדש', 'plus') +
                 btn('group_up', g.id, '', 'up', '', 'הזזה למעלה') +
                 btn('group_down', g.id, '', 'down', '', 'הזזה למטה') +
                 btn('group_delete', g.id, 'מחיקה', 'trash', 'cu4-pill-danger', 'אפשר למחוק רק תת-פרק ריק');
        } else {
            var ch = X.chapters[route.id];
            h += '<span class="cu4-editbar-label">פרק</span>' +
                 btn('chapter_edit', ch.id, 'עריכת הפרק', 'pencil', 'cu4-pill-solid', 'שם הפרק והפתיח') +
                 btn('chapter_addgroup', ch.id, 'תת-פרק חדש', 'plus') +
                 btn('chapter_up', ch.id, '', 'up', '', 'הזזה למעלה') +
                 btn('chapter_down', ch.id, '', 'down', '', 'הזזה למטה') +
                 btn('chapter_delete', ch.id, 'מחיקה', 'trash', 'cu4-pill-danger', 'אפשר למחוק רק פרק ריק') +
                 btn('chapter_add', '', 'פרק חדש', 'plus') +
                 btn('unit_edit', '', 'הגדרות היחידה', 'settings', '', 'כותרת התפריט וקישור לסרטונים לחסומים');
        }
        return h + '</div>';
    }

    function send(fields) {
        fields.nonce = C.nonce;
        fields.slug = C.slug;
        return App.ajax('cpt_v4_edit', fields).then(function (res) {
            if (res && res.success) {
                App.replaceUnit(res.data.unit, res.data.focus);
                return true;
            }
            window.alert('הפעולה נכשלה: ' + (res && res.data && res.data.message ? res.data.message : 'שגיאה'));
            return false;
        }).catch(function () { window.alert('שגיאת רשת. השינוי לא נשמר.'); return false; });
    }

    // ---------------------------------------------------------------- panel

    function closePanel() {
        if (window.tinymce) { try { var ed = window.tinymce.get(EDITOR_ID); if (ed) { ed.remove(); } } catch (e) {} }
        if (window.wp && window.wp.editor && window.wp.editor.remove) { try { window.wp.editor.remove(EDITOR_ID); } catch (e) {} }
        ['cu4-panel', 'cu4-panel-scrim'].forEach(function (id) { var el = document.getElementById(id); if (el) { el.remove(); } });
    }

    function editorContent() {
        if (window.tinymce) { var ed = window.tinymce.get(EDITOR_ID); if (ed && !ed.isHidden()) { return ed.getContent(); } }
        var ta = document.getElementById(EDITOR_ID);
        return ta ? ta.value : '';
    }

    /** opts: {heading, title, html (null = no editor), htmlLabel, extra (html), onSave(values, panel)} */
    function openPanel(opts) {
        closePanel();
        var scrim = document.createElement('div');
        scrim.id = 'cu4-panel-scrim';
        scrim.className = 'cu4-panel-scrim';
        var panel = document.createElement('div');
        panel.id = 'cu4-panel';
        panel.className = 'cu4-panel';
        panel.innerHTML =
            '<div class="cu4-panel-head"><span>' + esc(opts.heading) + '</span><button type="button" class="cu4-px" data-p="close" aria-label="סגירה">' + icon('close') + '</button></div>' +
            '<div class="cu4-panel-body">' +
              '<label class="cu4-f"><span>כותרת</span><input type="text" id="cu4-f-title" value="' + esc(opts.title || '') + '"></label>' +
              (opts.extra || '') +
              (opts.html !== null ?
                '<label class="cu4-f"><span>' + esc(opts.htmlLabel || 'תוכן') + '</span></label>' +
                '<p class="cu4-f-help">סרטון: <code>[video: קישור | כותרת]</code> · פרומפט להעתקה: <code>[prompt]הטקסט[/prompt]</code> · מצגת קנבה: <code>[canva: קישור]</code> · תיבת סיום למשימה: <code>[done-button: העליתי לחומר פתוח]</code></p>' +
                '<textarea id="' + EDITOR_ID + '" style="width:100%;min-height:300px"></textarea>' : '') +
            '</div>' +
            '<div class="cu4-panel-foot">' +
              '<button type="button" class="cu4-pbtn cu4-pbtn-primary" data-p="save">שמירה</button>' +
              '<button type="button" class="cu4-pbtn" data-p="close">ביטול</button>' +
            '</div>';
        document.body.appendChild(scrim);
        document.body.appendChild(panel);

        if (opts.html !== null) {
            var raw = opts.html || '';
            document.getElementById(EDITOR_ID).value = raw;
            if (window.wp && window.wp.editor && window.wp.editor.initialize) {
                window.wp.editor.initialize(EDITOR_ID, {
                    tinymce: {
                        wpautop: false,
                        height: Math.max(300, window.innerHeight - 430),
                        toolbar1: 'formatselect bold italic bullist numlist link hr removeformat undo redo',
                        directionality: 'rtl',
                        setup: function (ed) { ed.on('init', function () { ed.setContent(raw); }); }
                    },
                    quicktags: true,
                    mediaButtons: true
                });
            }
        }
        panel.addEventListener('click', function (e) {
            var b = e.target.closest('[data-p]');
            if (!b) { return; }
            if (b.getAttribute('data-p') === 'close') { closePanel(); return; }
            var values = { title: document.getElementById('cu4-f-title').value.trim() };
            if (opts.html !== null) { values.html = editorContent(); }
            b.disabled = true;
            b.textContent = 'שומר…';
            opts.onSave(values, panel).then(function (ok) {
                if (ok) { closePanel(); } else { b.disabled = false; b.textContent = 'שמירה'; }
            });
        });
        scrim.addEventListener('click', function () {
            if (window.confirm('לסגור בלי לשמור?')) { closePanel(); }
        });
        var t = document.getElementById('cu4-f-title');
        if (t) { t.focus(); }
    }

    function editItem(it) {
        var kind = it.kind === 'discussion' || it.kind === 'task' ? it.kind : 'auto';
        openPanel({
            heading: 'עריכת פריט',
            title: it.title,
            html: it.raw || '',
            extra:
                '<label class="cu4-f"><span>איך הפריט מקבל וי?</span><select id="cu4-f-kind">' +
                  '<option value="auto"' + (kind === 'auto' ? ' selected' : '') + '>רגיל: צפייה בסרטון, או שהייה בעמוד אם אין סרטון</option>' +
                  '<option value="discussion"' + (kind === 'discussion' ? ' selected' : '') + '>דיון: אחרי פרסום תגובה</option>' +
                  '<option value="task"' + (kind === 'task' ? ' selected' : '') + '>משימה: בסימון תיבת הסיום (אם יש), אחרת שהייה בעמוד</option>' +
                '</select></label>' +
                '<label class="cu4-f-check"><input type="checkbox" id="cu4-f-comments"' + (it.comments ? ' checked' : '') + '> להציג בפריט הזה את אזור התגובות של היחידה</label>',
            onSave: function (v) {
                return send({ op: 'item_save', id: it.id, title: v.title, html: v.html,
                    kind: document.getElementById('cu4-f-kind').value,
                    comments: document.getElementById('cu4-f-comments').checked ? '1' : '0' });
            }
        });
    }

    function editGroup(g) {
        var n = g.items.length;
        var isNum = typeof g.rule === 'number' || /^\d+$/.test(String(g.rule));
        openPanel({
            heading: 'הגדרות תת-פרק',
            title: g.title,
            html: g.rawIntro || '',
            htmlLabel: 'פתיח (מוצג בעמוד תת-הפרק, לפני רשימת הפריטים)',
            extra:
                '<div class="cu4-f-row">' +
                '<label class="cu4-f"><span>מה נדרש כדי להשלים את תת-הפרק?</span><select id="cu4-f-rule">' +
                  '<option value="one"' + (g.rule === 'one' ? ' selected' : '') + '>מספיק פריט אחד</option>' +
                  '<option value="num"' + (isNum ? ' selected' : '') + '>מספר פריטים שאקבע</option>' +
                  '<option value="all"' + (g.rule === 'all' ? ' selected' : '') + '>כל הפריטים</option>' +
                  '<option value="optional"' + (g.rule === 'optional' ? ' selected' : '') + '>רשות (העשרה, לא נספר)</option>' +
                '</select></label>' +
                '<label class="cu4-f"><span>כמה פריטים? (מתוך ' + n + ')</span><input type="number" id="cu4-f-num" min="1" max="' + Math.max(1, n) + '" value="' + (isNum ? g.rule : 2) + '"></label>' +
                '</div>' +
                '<p class="cu4-f-help">המשתלמות רואות את הכלל הזה בעמוד תת-הפרק, וסרגל ההתקדמות מתמלא לפיו.</p>',
            onSave: function (v) {
                var rule = document.getElementById('cu4-f-rule').value;
                if (rule === 'num') { rule = String(Math.max(1, parseInt(document.getElementById('cu4-f-num').value, 10) || 1)); }
                return send({ op: 'group_save', id: g.id, title: v.title, html: v.html, rule: rule });
            }
        });
    }

    function editChapter(ch) {
        openPanel({
            heading: 'עריכת פרק',
            title: ch.title,
            html: ch.rawIntro || '',
            htmlLabel: 'פתיח (מוצג בעמוד הפרק, לפני רשימת תתי-הפרקים)',
            onSave: function (v) { return send({ op: 'chapter_save', id: ch.id, title: v.title, html: v.html }); }
        });
    }

    function editUnit() {
        openPanel({
            heading: 'הגדרות היחידה',
            title: C.unit.sidebarTitle || '',
            html: null,
            extra: '<label class="cu4-f"><span>קישור לתיקיית "סרטונים לחסומים" (ריק = בלי כפתור)</span><input type="url" id="cu4-f-blocked" dir="ltr" value="' + esc(C.unit.blockedUrl || '') + '"></label>',
            onSave: function (v) {
                return send({ op: 'unit_save', title: v.title, blocked_url: document.getElementById('cu4-f-blocked').value.trim() });
            }
        });
    }

    function askNew(label, op, id) {
        var name = window.prompt(label, '');
        if (name === null) { return; }
        send({ op: op, id: id, title: name.trim() });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-edit]');
        if (!b) { return; }
        e.preventDefault();
        var op = b.getAttribute('data-edit'), id = b.getAttribute('data-id'), X = App.lookup();
        if (op === 'item_edit') { editItem(X.items[id]); }
        else if (op === 'group_edit') { editGroup(X.groups[id]); }
        else if (op === 'chapter_edit') { editChapter(X.chapters[id]); }
        else if (op === 'unit_edit') { editUnit(); }
        else if (op === 'group_additem') { askNew('שם הפריט החדש:', 'group_additem', id); }
        else if (op === 'chapter_addgroup') { askNew('שם תת-הפרק החדש:', 'chapter_addgroup', id); }
        else if (op === 'chapter_add') { askNew('שם הפרק החדש:', 'chapter_add', ''); }
        else if (/_(up|down)$/.test(op)) {
            var parts = op.split('_');
            send({ op: parts[0] + '_move', id: id, dir: parts[1] });
        }
        else if (/_delete$/.test(op)) {
            var what = op === 'item_delete' ? 'הפריט "' + X.items[id].title + '"' : (op === 'group_delete' ? 'תת-הפרק "' + X.groups[id].title + '"' : 'הפרק "' + X.chapters[id].title + '"');
            if (window.confirm('למחוק את ' + what + '? אי אפשר לבטל את המחיקה.')) { send({ op: op, id: id }); }
        }
    });

    window.cptV4Edit = { toolbar: toolbar };
})();
