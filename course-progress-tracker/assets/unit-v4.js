/**
 * Course unit engine v4 - item structure (Coursera / Udemy style).
 *
 * window.cptV4 (printed by includes/v4-render.php) holds the unit:
 *   chapters -> groups (with a completion rule) -> items (own page, own tick).
 *
 * Pages (hash routes, so every item has its own link):
 *   #/i/{item}   one item          #/g/{group}  a sub-chapter overview
 *   #/c/{chapter} a chapter overview            #/notes  the learner's notes
 *
 * Ticks are automatic only:
 *   video   - real playback time (half the video or 3 minutes), via the YouTube API
 *   view    - a few seconds on a text item
 *   comment - a comment posted in the unit discussion (verified by the server)
 *   check   - the task's own "done" checkbox
 * A group is complete when its rule is met (usually ONE item), and the unit
 * reaches 100% when every non-optional group is complete.
 */
(function () {
    'use strict';

    var C = window.cptV4;
    var root = document.getElementById('cu4');
    if (!C || !root) { return; }

    var U = C.unit;
    var S = null;            // progress state from the server (null = not loaded / guest)
    var notes = {};
    var logged = false;
    var route = null;
    var editing = false;
    var timers = [];         // per-page timers, cleared on navigation
    var players = [];
    var X = {};              // lookup tables, rebuilt by index()

    var VIEW_SECONDS = 6;
    var BLOCKED_SECONDS = 20;
    var VIDEO_SHARE = 0.5;
    var VIDEO_MAX_SECONDS = 180;

    // ---------------------------------------------------------------- helpers

    var ICONS = {
        check: '<path d="M20 6 9 17l-5-5"/>',
        play: '<circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/>',
        read: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v5h6M16 13H8M16 17H8M10 9H8"/>',
        embed: '<path d="M2 3h20M21 3v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V3M7 21l5-5 5 5"/>',
        discussion: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        task: '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 14l2 2 4-4"/>',
        chevron: '<path d="m6 9 6 6 6-6"/>',
        next: '<path d="m15 18-6-6 6-6"/>',
        prev: '<path d="m9 18 6-6-6-6"/>',
        menu: '<path d="M3 12h18M3 6h18M3 18h18"/>',
        note: '<path d="M12 20h9"/><path d="M16.4 3.6a2 2 0 0 1 2.8 2.8L7.5 18.2 4 19l.8-3.5Z"/>',
        close: '<path d="M18 6 6 18M6 6l12 12"/>',
        copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9Z"/>'
    };

    function icon(name, cls) {
        return '<svg class="cu4-ic' + (cls ? ' ' + cls : '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function ajax(action, fields, method) {
        var url = C.ajaxUrl, opt = { credentials: 'same-origin' };
        if (method === 'GET') {
            url += '?action=' + action + '&' + Object.keys(fields).map(function (k) {
                return encodeURIComponent(k) + '=' + encodeURIComponent(fields[k]);
            }).join('&');
        } else {
            var body = new FormData();
            body.append('action', action);
            Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
            opt.method = 'POST';
            opt.body = body;
        }
        return fetch(url, opt).then(function (r) { return r.json(); });
    }

    function later(fn, ms) { var t = setTimeout(fn, ms); timers.push({ t: t }); return t; }
    function every(fn, ms) { var t = setInterval(fn, ms); timers.push({ i: t }); return t; }
    function clearPage() {
        timers.forEach(function (x) { if (x.t) { clearTimeout(x.t); } if (x.i) { clearInterval(x.i); } });
        timers = [];
        players.forEach(function (p) { try { p.destroy(); } catch (e) {} });
        players = [];
    }

    // ---------------------------------------------------------------- structure

    function index() {
        X = { items: {}, groups: {}, chapters: {}, seq: [] };
        U.chapters.forEach(function (ch) {
            X.chapters[ch.id] = ch;
            // "flat" chapter = one group holding one item (מבט על / דיון / משימה)
            ch.flat = ch.groups.length === 1 && ch.groups[0].items.length === 1 && !ch.intro;
            if (!ch.flat || editing) { X.seq.push({ type: 'c', id: ch.id }); }
            ch.groups.forEach(function (g) {
                X.groups[g.id] = g;
                g.chapter = ch;
                g.hasPage = editing || (!ch.flat && (g.items.length > 1 || !!g.intro));
                if (g.hasPage) { X.seq.push({ type: 'g', id: g.id }); }
                g.items.forEach(function (it, n) {
                    X.items[it.id] = it;
                    it.group = g;
                    it.pos = n + 1;
                    X.seq.push({ type: 'i', id: it.id });
                });
            });
        });
    }

    function href(type, id) { return '#/' + type + (id ? '/' + encodeURIComponent(id) : ''); }

    function titleOf(r) {
        if (r.type === 'i') { return X.items[r.id].title; }
        if (r.type === 'g') { return X.groups[r.id].title; }
        if (r.type === 'c') { return X.chapters[r.id].title; }
        return '';
    }

    function isDone(itemId) { return !!(S && S.done.indexOf(itemId) !== -1); }
    function gState(gid) { return (S && S.groups[gid]) || { done: 0, need: X.groups[gid].need, total: X.groups[gid].items.length, complete: false }; }
    function cState(cid) { return (S && S.chapters[cid]) || { pct: 0, complete: false }; }

    function ruleText(g) {
        var n = g.items.length;
        if (g.rule === 'optional') { return 'פרק רשות להעשרה. הוא לא נדרש כדי להשלים את היחידה.'; }
        if (n <= 1) { return ''; }
        if (g.rule === 'all') { return 'כדי להשלים את הפרק יש לעבור על כל ' + n + ' הפריטים.'; }
        if (g.need === 1) { return 'כדי להשלים את הפרק מספיק פריט אחד מתוך ' + n + '. בחרו את מה שמעניין אתכם, ואפשר כמובן גם יותר.'; }
        return 'כדי להשלים את הפרק מספיק ' + g.need + ' פריטים מתוך ' + n + '.';
    }

    function modeTip(it) {
        if (it.mode === 'video') { return 'הסימון מתקבל אוטומטית אחרי צפייה בחצי מהסרטון (או 3 דקות)'; }
        if (it.mode === 'comment') { return 'הסימון מתקבל אוטומטית אחרי שמפרסמים תגובה בדיון'; }
        if (it.mode === 'check') { return 'הסימון מתקבל כשמסמנים את תיבת הסיום שבסוף המשימה'; }
        return 'הסימון מתקבל אוטומטית אחרי כמה שניות בעמוד';
    }

    // ---------------------------------------------------------------- shell

    function buildShell() {
        var admin = '';
        if (C.isAdmin) {
            admin = '<div class="cu4-admin" id="cu4-admin"></div>';
        }
        root.innerHTML = admin +
            '<div class="cu4-top" id="cu4-top"></div>' +
            '<div class="cu4-body">' +
              '<aside class="cu4-nav" id="cu4-nav" aria-label="תוכן היחידה"></aside>' +
              '<div class="cu4-scrim" id="cu4-scrim"></div>' +
              '<main class="cu4-main" id="cu4-main"><div class="cu4-loading"><span class="cu4-spinner"></span><span>טוען את היחידה…</span></div></main>' +
            '</div>' +
            '<div class="cu4-toast" id="cu4-toast" role="status" aria-live="polite"></div>' +
            '<div id="cu4-comments-home" hidden></div>';
        renderAdmin();
    }

    function renderAdmin() {
        var el = document.getElementById('cu4-admin');
        if (!el) { return; }
        var live = C.live;
        el.innerHTML =
            '<span class="cu4-admin-txt">' + icon('eye') +
              (live ? 'המבנה החדש פעיל לכל המשתלמות.' : 'תצוגה מקדימה של המבנה החדש. רק מנהלים רואים אותה כרגע.') + '</span>' +
            '<span class="cu4-admin-actions">' +
              '<button type="button" class="cu4-pill cu4-pill-ghost" data-act="edit-toggle">' + icon('note') + '<span>' + (editing ? 'סיום עריכה' : 'מצב עריכה') + '</span></button>' +
              '<button type="button" class="cu4-pill ' + (live ? 'cu4-pill-ghost' : 'cu4-pill-solid') + '" data-act="switch" data-on="' + (live ? '0' : '1') + '">' +
                (live ? 'חזרה למבנה הישן' : 'הפעלה לכל המשתלמות') + '</button>' +
            '</span>';
    }

    // ---------------------------------------------------------------- progress header

    function renderTop() {
        var el = document.getElementById('cu4-top');
        var pct = S ? S.percent : 0;
        var h = '<button type="button" class="cu4-pill cu4-pill-ghost cu4-menu-btn" data-act="menu">' + icon('list') + '<span>תוכן היחידה</span></button>';
        if (logged && S) {
            h += '<div class="cu4-prog" data-tip="כדי להשלים יחידה מספיק לעמוד בדרישה של כל פרק. לא צריך לפתוח כל פריט. עד עכשיו נפתחו ' + S.items_done + ' מתוך ' + S.items_total + ' פריטים.">' +
                   '<span class="cu4-prog-label">ההתקדמות שלי ביחידה</span>' +
                   '<span class="cu4-prog-track"><span class="cu4-prog-fill" style="width:' + pct + '%"></span></span>' +
                   '<strong class="cu4-prog-pct">' + pct + '%</strong>' +
                 '</div>';
            h += '<div class="cu4-chips">';
            U.chapters.forEach(function (ch) {
                var cs = cState(ch.id);
                if (cs.pct === null) { return; }
                var req = ch.groups.filter(function (g) { return g.need > 0; });
                var ok = req.filter(function (g) { return gState(g.id).complete; }).length;
                h += '<a class="cu4-chip' + (cs.complete ? ' is-done' : '') + '" href="' + firstHref(ch) + '"' +
                     (req.length > 1 ? ' data-tip="' + ok + ' מתוך ' + req.length + ' תתי-פרקים הושלמו"' : '') + '>' +
                     '<span class="cu4-mark">' + (cs.complete ? icon('check') : '') + '</span>' + esc(ch.title) +
                     (req.length > 1 && !cs.complete ? '<span class="cu4-chip-n">' + ok + '/' + req.length + '</span>' : '') + '</a>';
            });
            h += '</div>';
            h += '<a class="cu4-pill cu4-pill-ghost cu4-notes-btn" href="#/notes" data-tip="כל ההערות שכתבת לעצמך ביחידה">' + icon('note') + '<span>ההערות שלי</span></a>';
        } else if (!logged && S === false) {
            h += '<div class="cu4-guest">' + icon('info') + '<span>צפייה חופשית בלי שמירת התקדמות. כדי לסמן פריטים ולכתוב הערות, <a href="' + esc(C.loginUrl) + '">התחברו</a>.</span></div>';
        }
        el.innerHTML = h;
    }

    function firstHref(ch) {
        if (ch.flat && !editing) { return href('i', ch.groups[0].items[0].id); }
        return href('c', ch.id);
    }

    // ---------------------------------------------------------------- sidebar

    function renderNav() {
        var el = document.getElementById('cu4-nav');
        var open = {};
        el.querySelectorAll('.cu4-g.is-open').forEach(function (n) { open[n.getAttribute('data-g')] = 1; });
        var curGroup = route && route.type === 'i' && X.items[route.id] ? X.items[route.id].group.id : (route && route.type === 'g' ? route.id : '');

        var h = '<div class="cu4-nav-head"><span class="cu4-nav-title">' + esc(U.sidebarTitle || C.unitTitle || 'תוכן היחידה') + '</span>' +
                '<button type="button" class="cu4-x" data-act="menu-close" aria-label="סגירה">' + icon('close') + '</button></div>';
        U.chapters.forEach(function (ch) {
            var cs = cState(ch.id);
            if (ch.flat && !editing) {
                var it = ch.groups[0].items[0];
                h += '<div class="cu4-ch cu4-ch-flat">' + itemRow(it, ch.title, true) + '</div>';
                return;
            }
            h += '<div class="cu4-ch">';
            h += '<a class="cu4-ch-head' + (route && route.type === 'c' && route.id === ch.id ? ' is-current' : '') + '" href="' + href('c', ch.id) + '">' +
                   '<span class="cu4-mark' + (cs.complete ? ' is-done' : '') + '">' + (cs.complete ? icon('check') : '') + '</span>' +
                   '<span class="cu4-ch-title">' + esc(ch.title) + '</span></a>';
            ch.groups.forEach(function (g) {
                var gs = gState(g.id);
                var isOpen = open[g.id] || g.id === curGroup;
                var single = g.items.length === 1 && !editing;
                if (single) {
                    h += '<div class="cu4-g cu4-g-single">' + itemRow(g.items[0], g.title, false, g) + '</div>';
                    return;
                }
                var tip = g.rule === 'optional' ? 'פרק רשות להעשרה' :
                          (g.rule === 'all' ? 'יש לעבור על כל הפריטים' : 'מספיק ' + (g.need === 1 ? 'פריט אחד' : g.need + ' פריטים') + ' מתוך ' + g.items.length);
                h += '<div class="cu4-g' + (isOpen ? ' is-open' : '') + (gs.complete ? ' is-done' : '') + '" data-g="' + esc(g.id) + '">' +
                       '<div class="cu4-g-head' + (route && route.type === 'g' && route.id === g.id ? ' is-current' : '') + '">' +
                         '<a class="cu4-g-link" href="' + href('g', g.id) + '">' +
                           '<span class="cu4-mark' + (gs.complete ? ' is-done' : '') + (g.rule === 'optional' ? ' is-optional' : '') + '">' + (gs.complete ? icon('check') : '') + '</span>' +
                           '<span class="cu4-g-title">' + esc(g.title) + '</span>' +
                         '</a>' +
                         '<span class="cu4-g-count" data-tip="' + tip + '">' + (g.rule === 'optional' ? 'רשות' : gs.done + '/' + g.items.length) + '</span>' +
                         '<button type="button" class="cu4-g-toggle" data-act="toggle-g" aria-label="פתיחה וסגירה של רשימת הפריטים">' + icon('chevron') + '</button>' +
                       '</div>' +
                       '<div class="cu4-items">' + g.items.map(function (it) { return itemRow(it); }).join('') + '</div>' +
                     '</div>';
            });
            h += '</div>';
        });
        el.innerHTML = h;
    }

    function itemRow(it, label, top, g) {
        var done = isDone(it.id);
        // a one-item group is done when its item is (or when credited from the old tracker)
        if (!done && g && gState(g.id).complete) { done = true; }
        if (!done && top && cState(it.group.chapter.id).complete) { done = true; }
        var optional = it.group.rule === 'optional';
        return '<a class="cu4-row' + (top ? ' cu4-row-top' : '') + (done ? ' is-done' : '') +
               (route && route.type === 'i' && route.id === it.id ? ' is-current' : '') + '" href="' + href('i', it.id) + '" data-item="' + esc(it.id) + '">' +
               '<span class="cu4-mark' + (done ? ' is-done' : '') + (optional && !done ? ' is-optional' : '') + '">' + (done ? icon('check') : '') + '</span>' +
               '<span class="cu4-row-title">' + esc(label || it.title) + '</span>' +
               (notes[it.id] ? '<span class="cu4-row-note" data-tip="יש לך הערה כאן">' + icon('note') + '</span>' : '') +
               '<span class="cu4-row-kind">' + icon(it.kind === 'video' ? 'play' : it.kind) + '</span>' +
               '</a>';
    }

    // ---------------------------------------------------------------- pages

    function pager() {
        var i = -1;
        X.seq.forEach(function (r, n) { if (route && r.type === route.type && r.id === route.id) { i = n; } });
        if (i === -1) { return ''; }
        var prev = X.seq[i - 1], next = X.seq[i + 1];
        var h = '<nav class="cu4-pager">';
        h += prev ? '<a class="cu4-pill cu4-pill-ghost" href="' + href(prev.type, prev.id) + '" data-tip="' + esc(titleOf(prev)) + '">' + icon('prev') + '<span>הקודם</span></a>' : '<span></span>';
        h += next ? '<a class="cu4-pill cu4-pill-solid" href="' + href(next.type, next.id) + '"><span>הבא: ' + esc(titleOf(next)) + '</span>' + icon('next') + '</a>' : '<span></span>';
        return h + '</nav>';
    }

    function statusPill(it) {
        var done = isDone(it.id);
        if (!logged) { return ''; }
        return '<span class="cu4-status' + (done ? ' is-done' : '') + '" id="cu4-status" data-tip="' + (done ? 'הפריט סומן כהושלם' : modeTip(it)) + '">' +
               '<span class="cu4-mark' + (done ? ' is-done' : '') + '">' + (done ? icon('check') : '') + '</span>' +
               (done ? 'הושלם' : 'טרם הושלם') + '</span>';
    }

    function editBar() {
        return (editing && window.cptV4Edit) ? window.cptV4Edit.toolbar(route) : '';
    }

    function pageItem(it) {
        var g = it.group, ch = g.chapter;
        var crumbs = '<a href="' + firstHref(ch) + '">' + esc(ch.title) + '</a>';
        if (g.hasPage) { crumbs += '<span class="cu4-sep">›</span><a href="' + href('g', g.id) + '">' + esc(g.title) + '</a>'; }
        if (g.items.length > 1) { crumbs += '<span class="cu4-crumb-pos">פריט ' + it.pos + ' מתוך ' + g.items.length + '</span>'; }
        var h = editBar() +
            '<div class="cu4-crumbs">' + crumbs + '</div>' +
            '<header class="cu4-head"><h2 class="cu4-title">' + esc(it.title) + '</h2>' + statusPill(it) + '</header>' +
            '<div class="cu4-content" id="cu4-content">' + (it.html || '<p class="cu4-empty">עדיין אין כאן תוכן.</p>') + '</div>' +
            '<div class="cu4-comments" id="cu4-comments"></div>';
        if (logged) {
            h += '<section class="cu4-note">' +
                   '<label class="cu4-note-label" for="cu4-note-text">' + icon('note') + '<span>ההערות שלי לפריט הזה</span>' +
                   '<span class="cu4-note-hint" data-tip="ההערות פרטיות: רק את רואה אותן. הן נשמרות לבד ומחכות לך גם בפעם הבאה.">' + icon('info') + '</span></label>' +
                   '<textarea id="cu4-note-text" rows="3" placeholder="מה כדאי לי לזכור? רעיון לשיעור, פרומפט שעבד, שאלה לברר...">' + esc(notes[it.id] || '') + '</textarea>' +
                   '<span class="cu4-note-state" id="cu4-note-state"></span>' +
                 '</section>';
        }
        return h + pager();
    }

    function cards(g) {
        return '<div class="cu4-cards">' + g.items.map(function (it) {
            var done = isDone(it.id);
            return '<a class="cu4-card' + (done ? ' is-done' : '') + '" href="' + href('i', it.id) + '">' +
                   '<span class="cu4-card-kind">' + icon(it.kind === 'video' ? 'play' : it.kind) + '</span>' +
                   '<span class="cu4-card-title">' + esc(it.title) + '</span>' +
                   '<span class="cu4-mark' + (done ? ' is-done' : '') + '">' + (done ? icon('check') : '') + '</span></a>';
        }).join('') + '</div>';
    }

    function ruleBox(g) {
        var t = ruleText(g);
        if (!t) { return ''; }
        var gs = gState(g.id);
        return '<p class="cu4-rule' + (gs.complete ? ' is-done' : '') + '">' + icon(gs.complete ? 'check' : (g.rule === 'optional' ? 'star' : 'info')) +
               '<span>' + (gs.complete ? 'הפרק הושלם. ' : '') + t + '</span></p>';
    }

    function pageGroup(g) {
        var ch = g.chapter;
        return editBar() +
            '<div class="cu4-crumbs"><a href="' + firstHref(ch) + '">' + esc(ch.title) + '</a></div>' +
            '<header class="cu4-head"><h2 class="cu4-title">' + esc(g.title) + '</h2></header>' +
            ruleBox(g) +
            (g.intro ? '<div class="cu4-content">' + g.intro + '</div>' : '') +
            (g.items.length ? cards(g) : '<p class="cu4-empty">עדיין אין פריטים בפרק הזה.</p>') +
            pager();
    }

    function pageChapter(ch) {
        var h = editBar() +
            '<header class="cu4-head"><h2 class="cu4-title">' + esc(ch.title) + '</h2></header>' +
            (ch.intro ? '<div class="cu4-content">' + ch.intro + '</div>' : '');
        var req = ch.groups.filter(function (g) { return g.need > 0; });
        if (req.length > 1) {
            h += '<p class="cu4-rule">' + icon('info') + '<span>בפרק הזה ' + req.length + ' תתי-פרקים. בכל אחד מספיק לעמוד בדרישה שמצוינת לידו, ולא חייבים לפתוח כל פריט.</span></p>';
        }
        h += '<div class="cu4-glist">';
        ch.groups.forEach(function (g) {
            var gs = gState(g.id);
            var need = g.rule === 'optional' ? 'רשות' : (g.rule === 'all' || g.items.length <= 1 ? (g.items.length <= 1 ? 'פריט אחד' : 'כל ' + g.items.length + ' הפריטים') : g.need + ' מתוך ' + g.items.length);
            h += '<a class="cu4-gcard' + (gs.complete ? ' is-done' : '') + '" href="' + (g.hasPage ? href('g', g.id) : (g.items[0] ? href('i', g.items[0].id) : href('g', g.id))) + '">' +
                   '<span class="cu4-mark' + (gs.complete ? ' is-done' : '') + (g.rule === 'optional' ? ' is-optional' : '') + '">' + (gs.complete ? icon('check') : '') + '</span>' +
                   '<span class="cu4-gcard-main"><span class="cu4-gcard-title">' + esc(g.title) + '</span>' +
                   '<span class="cu4-gcard-sub">' + g.items.map(function (it) { return esc(it.title); }).join(' · ') + '</span></span>' +
                   '<span class="cu4-gcard-need" data-tip="' + (g.rule === 'optional' ? 'להעשרה, לא נדרש להשלמת היחידה' : 'מה נדרש כדי להשלים את תת-הפרק') + '">' + need + '</span></a>';
        });
        return h + '</div>' + pager();
    }

    function pageNotes() {
        var h = '<header class="cu4-head"><h2 class="cu4-title">ההערות שלי ביחידה</h2></header>';
        var list = [];
        X.seq.forEach(function (r) { if (r.type === 'i' && notes[r.id]) { list.push(X.items[r.id]); } });
        if (!list.length) {
            return h + '<p class="cu4-empty">עדיין לא כתבת הערות. בתחתית כל פריט יש מקום להערות אישיות, והן יתרכזו כאן.</p>';
        }
        h += '<p><button type="button" class="cu4-pill cu4-pill-ghost" data-act="copy-notes">' + icon('copy') + '<span>העתקת כל ההערות</span></button></p><div class="cu4-notes-list">';
        list.forEach(function (it) {
            h += '<article class="cu4-notes-item"><a href="' + href('i', it.id) + '">' + esc(it.group.chapter.title) + ' › ' + esc(it.title) + '</a>' +
                 '<p>' + esc(notes[it.id]).replace(/\n/g, '<br>') + '</p></article>';
        });
        return h + '</div>';
    }

    // ---------------------------------------------------------------- navigation

    function parseHash() {
        var m = (location.hash || '').match(/^#\/(i|g|c|notes)(?:\/(.+))?$/);
        if (!m) { return null; }
        var r = { type: m[1], id: m[2] ? decodeURIComponent(m[2]) : '' };
        if (r.type === 'i' && !X.items[r.id]) { return null; }
        if (r.type === 'g' && !X.groups[r.id]) { return null; }
        if (r.type === 'c' && !X.chapters[r.id]) { return null; }
        return r;
    }

    function defaultRoute(resume) {
        if (resume && X.items[resume]) { return { type: 'i', id: resume }; }
        return X.seq[0] || null;
    }

    function show(r, opts) {
        opts = opts || {};
        clearPage();
        homeComments();
        route = r;
        var main = document.getElementById('cu4-main');
        if (!r) { main.innerHTML = '<p class="cu4-empty">אין עדיין תוכן ביחידה.</p>'; renderNav(); return; }
        var h = '';
        if (r.type === 'i') { h = pageItem(X.items[r.id]); }
        else if (r.type === 'g') { h = pageGroup(X.groups[r.id]); }
        else if (r.type === 'c') { h = pageChapter(X.chapters[r.id]); }
        else { h = pageNotes(); }
        main.innerHTML = h;
        main.setAttribute('data-page', r.type);
        renderNav();
        root.classList.remove('is-menu-open');
        fillCopyButtons(main);
        setContext();
        if (r.type === 'i') { activateItem(X.items[r.id]); }
        if (!opts.keepScroll) {
            var top = root.getBoundingClientRect().top;
            if (top < -20) { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        }
        var cur = document.querySelector('#cu4-nav .is-current');
        if (cur && cur.scrollIntoView && window.innerWidth > 900) {
            var nav = document.getElementById('cu4-nav');
            var a = cur.getBoundingClientRect(), b = nav.getBoundingClientRect();
            if (a.top < b.top || a.bottom > b.bottom) { nav.scrollTop += a.top - b.top - 80; }
        }
    }

    function go(type, id) {
        var h = href(type, id);
        if (location.hash === h) { show({ type: type, id: id || '' }); } else { location.hash = h; }
    }

    // where the learner is - used by the feedback form (assets/course-feedback.js)
    function setContext() {
        var ctx = { main_label: '', main_id: '', sub_label: '', sub_id: '', heading: '' };
        if (route && route.type === 'i') {
            var it = X.items[route.id];
            ctx = { main_label: it.group.chapter.title, main_id: it.group.chapter.id, sub_label: it.group.title === it.title ? it.title : it.group.title + ' › ' + it.title, sub_id: it.id, heading: it.title };
        } else if (route && route.type === 'g') {
            var g = X.groups[route.id];
            ctx = { main_label: g.chapter.title, main_id: g.chapter.id, sub_label: g.title, sub_id: g.id, heading: g.title };
        } else if (route && route.type === 'c') {
            var ch = X.chapters[route.id];
            ctx = { main_label: ch.title, main_id: ch.id, sub_label: '', sub_id: '', heading: ch.title };
        }
        window.cptV4Current = ctx;
    }

    // ---------------------------------------------------------------- comments (only inside discussion items)

    // The comment area is either the theme's (.comments-area) or Gutenberg
    // blocks placed in the page (comments list and form can be separate blocks).
    var commentParts = null;
    function commentNodes() {
        if (commentParts) { return commentParts; }
        var sel = '.wp-block-comments, .wp-block-post-comments-form, .comments-area';
        commentParts = Array.prototype.filter.call(document.querySelectorAll(sel), function (n) {
            return !(n.parentElement && n.parentElement.closest(sel));
        });
        return commentParts;
    }
    function commentsNode() { return commentNodes()[0] || null; }

    function homeComments() {
        var home = document.getElementById('cu4-comments-home');
        if (!home) { return; }
        commentNodes().forEach(function (node) { if (node.parentNode !== home) { home.appendChild(node); } });
    }

    function placeComments() {
        var slot = document.getElementById('cu4-comments');
        if (!slot || !commentNodes().length) { return; }
        commentNodes().forEach(function (node) { slot.appendChild(node); });
        slot.classList.add('is-on');
    }

    // Old blocks that still sit in the page after the unit (a second "blocked
    // videos" button, the old intro paragraphs...) - their content is inside
    // the unit now. Hide them, walking up while the unit is wrapped in a group.
    function hideLeftovers() {
        document.documentElement.classList.add('cpt-v4-root');
        var el = document.getElementById('course-shell');
        var stop = '.entry-content, .ast-container, .site-content, #content, #primary, #page, main, article, body';
        for (var level = 0; el && level < 4; level++) {
            for (var sib = el.nextElementSibling; sib; sib = sib.nextElementSibling) {
                if (!/^(SCRIPT|STYLE)$/.test(sib.tagName)) { sib.classList.add('cu4-leftover'); }
            }
            var parent = el.parentElement;
            if (!parent || parent.matches(stop)) { break; }
            el = parent;
        }
    }

    // ---------------------------------------------------------------- completion tracking

    function sendEvent(it, type, data) {
        if (!logged) { return Promise.resolve(); }
        return ajax('cpt_v4_event', { post_id: C.postId, item: it.id, type: type, data: JSON.stringify(data || {}) })
            .then(function (res) { if (res && res.success) { applyState(res.data); } })
            .catch(function () { /* the next event or page load will catch up */ });
    }

    function applyState(next) {
        var prev = S;
        S = next;
        renderTop();
        renderNav();
        // refresh the status pill / cards in place - never re-render the page (a video may be playing)
        if (route && route.type === 'i') {
            var it = X.items[route.id], pill = document.getElementById('cu4-status');
            if (pill && it) { pill.outerHTML = statusPill(it); }
        } else if (route && (route.type === 'g' || route.type === 'c')) {
            show(route, { keepScroll: true });
        }
        if (prev && prev.groups) {
            if (next.percent >= 100 && prev.percent < 100) {
                toast('כל הכבוד! השלמת את היחידה.');
            } else {
                Object.keys(next.groups).some(function (gid) {
                    if (next.groups[gid].complete && prev.groups[gid] && !prev.groups[gid].complete && X.groups[gid]) {
                        toast('הפרק "' + X.groups[gid].title + '" הושלם');
                        return true;
                    }
                    return false;
                });
            }
        }
    }

    function toast(text) {
        var el = document.getElementById('cu4-toast');
        el.innerHTML = '<span class="cu4-mark is-done">' + icon('check') + '</span><span>' + esc(text) + '</span>';
        el.classList.add('is-on');
        setTimeout(function () { el.classList.remove('is-on'); }, 4200);
    }

    function dwell(it, seconds, data) {
        var spent = 0;
        every(function () {
            if (document.visibilityState !== 'visible' || isDone(it.id)) { return; }
            spent++;
            if (spent === seconds) { data.seconds = spent; sendEvent(it, 'view', data); }
        }, 1000);
    }

    function activateItem(it) {
        var box = document.getElementById('cu4-content');
        if (it.comments) { placeComments(); }
        if (C.isAdmin && it.comments && !commentsNode()) {
            document.getElementById('cu4-comments').innerHTML = '<p class="cu4-empty">התגובות של העמוד יוצגו כאן (בתצוגה הזו לא נמצא אזור תגובות).</p>';
        }
        setupVideos(it, box);
        if (!logged) { return; }

        // remember where the learner is (resume point)
        later(function () {
            ajax('cpt_save_last_position', { post_id: C.postId, section_id: it.id }).catch(function () {});
        }, 1200);

        if (it.mode === 'view' && !isDone(it.id)) { dwell(it, VIEW_SECONDS, {}); }
        if (it.mode === 'comment' && !isDone(it.id)) { sendEvent(it, 'comment', {}); }
        if (it.mode === 'check') {
            box.querySelectorAll('input[data-track-manual]').forEach(function (cb) {
                cb.checked = isDone(it.id);
                cb.addEventListener('change', function () { sendEvent(it, cb.checked ? 'check' : 'uncheck', {}); });
            });
        }

        var ta = document.getElementById('cu4-note-text'), st = document.getElementById('cu4-note-state'), t = null;
        if (ta) {
            ta.addEventListener('input', function () {
                st.textContent = '';
                clearTimeout(t);
                t = setTimeout(function () { saveNote(it, ta.value, st); }, 900);
            });
            ta.addEventListener('blur', function () { if (t) { clearTimeout(t); t = null; saveNote(it, ta.value, st); } });
        }
    }

    function saveNote(it, text, st) {
        var had = !!notes[it.id];
        ajax('cpt_v4_note', { post_id: C.postId, item: it.id, note: text }).then(function (res) {
            if (res && res.success) {
                if (text.trim()) { notes[it.id] = text; } else { delete notes[it.id]; }
                if (st) { st.textContent = 'נשמר'; }
                if (had !== !!notes[it.id]) { renderNav(); }
            } else if (st) { st.textContent = 'השמירה נכשלה'; }
        }).catch(function () { if (st) { st.textContent = 'השמירה נכשלה (בעיית רשת)'; } });
    }

    // ---------------------------------------------------------------- video (YouTube API, real playback time)

    var ytQueue = [], ytLoading = false, ytFailed = false;

    function loadYT(cb, fail) {
        if (window.YT && window.YT.Player) { cb(); return; }
        if (ytFailed) { fail(); return; }
        ytQueue.push({ cb: cb, fail: fail });
        if (ytLoading) { return; }
        ytLoading = true;
        var prev = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = function () {
            if (typeof prev === 'function') { prev(); }
            ytQueue.splice(0).forEach(function (q) { q.cb(); });
        };
        var s = document.createElement('script');
        s.src = 'https://www.youtube.com/iframe_api';
        s.onerror = function () { ytFailed = true; ytQueue.splice(0).forEach(function (q) { q.fail(); }); };
        document.head.appendChild(s);
        setTimeout(function () {
            if (!(window.YT && window.YT.Player)) { ytFailed = true; ytQueue.splice(0).forEach(function (q) { q.fail(); }); }
        }, 9000);
    }

    function setupVideos(it, box) {
        var blockedShown = false;
        function blocked(wrap) {
            wrap.classList.add('is-blocked');
            if (!wrap.querySelector('.cu4-video-blocked')) {
                var msg = document.createElement('div');
                msg.className = 'cu4-video-blocked';
                msg.innerHTML = icon('info') + '<span>נראה שיוטיוב חסום אצלך. ' +
                    (U.blockedUrl ? '<a href="' + esc(U.blockedUrl) + '" target="_blank" rel="noopener">לצפייה בתיקיית הסרטונים של היחידה</a>' : 'אפשר לצפות בסרטון מרשת אחרת.') + '</span>';
                wrap.appendChild(msg);
            }
            if (!blockedShown && logged && it.mode === 'video' && !isDone(it.id)) {
                blockedShown = true;
                dwell(it, BLOCKED_SECONDS, { blocked: 1 });
            }
        }
        box.querySelectorAll('.cu4-video[data-yt]').forEach(function (wrap) {
            var id = wrap.getAttribute('data-yt');
            var btn = wrap.querySelector('.cu4-video-play');
            var img = wrap.querySelector('img');
            if (img) {
                img.addEventListener('error', function () { blocked(wrap); });
                // YouTube answers a missing poster with a 120px placeholder, a filter answers with an error
                if (img.complete && img.naturalWidth === 0) { blocked(wrap); }
            }
            if (!btn) { return; }
            btn.addEventListener('click', function () {
                wrap.classList.add('is-loading');
                loadYT(function () {
                    var holder = document.createElement('div');
                    btn.replaceWith(holder);
                    wrap.classList.remove('is-loading');
                    wrap.classList.add('is-playing');
                    var watched = 0, sent = false;
                    var player = new window.YT.Player(holder, {
                        videoId: id,
                        playerVars: { autoplay: 1, rel: 0, playsinline: 1, modestbranding: 1 },
                        events: {
                            onStateChange: function (e) { if (e.data === 0) { finish(true); } },
                            onError: function () { /* private / removed video: nothing to track */ }
                        }
                    });
                    players.push(player);
                    function finish(ended) {
                        if (sent || !logged || it.mode !== 'video' || isDone(it.id)) { return; }
                        var dur = 0;
                        try { dur = player.getDuration() || 0; } catch (e) {}
                        var need = dur > 0 ? Math.min(dur * VIDEO_SHARE, VIDEO_MAX_SECONDS) : VIDEO_MAX_SECONDS;
                        if (watched >= need || (ended && watched >= Math.min(need, 20))) {
                            sent = true;
                            sendEvent(it, 'video', { video_id: id, seconds: Math.round(watched), duration: Math.round(dur) });
                        }
                    }
                    every(function () {
                        var st = -1;
                        try { st = player.getPlayerState(); } catch (e) {}
                        if (st === 1 && document.visibilityState === 'visible') { watched++; finish(false); }
                    }, 1000);
                }, function () {
                    wrap.classList.remove('is-loading');
                    blocked(wrap);
                });
            });
        });
    }

    // ---------------------------------------------------------------- content helpers

    function fillCopyButtons(scope) {
        scope.querySelectorAll('.prompt-container .copy-button').forEach(function (b) {
            if (b.innerHTML.indexOf('svg') === -1) { b.innerHTML = icon('copy'); }
            b.setAttribute('type', 'button');
            b.setAttribute('aria-label', 'העתקת הפרומפט');
        });
    }

    function copyText(text, done) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {});
        }
    }

    // ---------------------------------------------------------------- events

    root.addEventListener('click', function (e) {
        var copyBtn = e.target.closest('.copy-button');
        if (copyBtn) {
            var pre = copyBtn.closest('.prompt-container') && copyBtn.closest('.prompt-container').querySelector('pre');
            if (pre) {
                copyText(pre.innerText, function () {
                    copyBtn.innerHTML = icon('check');
                    setTimeout(function () { copyBtn.innerHTML = icon('copy'); }, 1800);
                });
            }
            return;
        }
        var act = e.target.closest('[data-act]');
        if (!act) {
            if (e.target.id === 'cu4-scrim') { root.classList.remove('is-menu-open'); }
            return;
        }
        var a = act.getAttribute('data-act');
        if (a === 'menu') { root.classList.add('is-menu-open'); }
        else if (a === 'menu-close') { root.classList.remove('is-menu-open'); }
        else if (a === 'toggle-g') { act.closest('.cu4-g').classList.toggle('is-open'); }
        else if (a === 'copy-notes') {
            var out = [];
            X.seq.forEach(function (r) {
                if (r.type === 'i' && notes[r.id]) { out.push(X.items[r.id].group.chapter.title + ' › ' + X.items[r.id].title + '\n' + notes[r.id]); }
            });
            copyText((C.unitTitle ? C.unitTitle + '\n\n' : '') + out.join('\n\n'), function () { toast('ההערות הועתקו'); });
        }
        else if (a === 'edit-toggle') {
            editing = !editing;
            document.body.classList.toggle('cu4-editing', editing);
            index();
            renderAdmin();
            show(parseHash() || route || defaultRoute(), { keepScroll: true });
        }
        else if (a === 'switch') {
            var on = act.getAttribute('data-on') === '1';
            var msg = on ? 'להפעיל את המבנה החדש לכל המשתלמות, בכל היחידות? ההתקדמות הקיימת שלהן נשמרת.' : 'לחזור למבנה הישן לכל המשתלמות?';
            if (!window.confirm(msg)) { return; }
            ajax('cpt_v4_switch', { nonce: C.nonce, on: on ? '1' : '0' }).then(function (res) {
                if (res && res.success) { C.live = res.data.live; renderAdmin(); toast(C.live ? 'המבנה החדש פעיל לכולן' : 'חזרנו למבנה הישן'); }
                else { window.alert('הפעולה נכשלה'); }
            });
        }
    });

    window.addEventListener('hashchange', function () {
        var r = parseHash();
        if (r) { show(r); }
    });

    // ---------------------------------------------------------------- API for the editor (unit-v4-edit.js)

    window.cptV4App = {
        config: C,
        icon: icon,
        esc: esc,
        ajax: ajax,
        lookup: function () { return X; },
        route: function () { return route; },
        go: go,
        replaceUnit: function (unit, focus) {
            U = C.unit = unit;
            index();
            var r = null;
            if (focus && X.items[focus]) { r = { type: 'i', id: focus }; }
            else if (focus && X.groups[focus]) { r = { type: 'g', id: focus }; }
            else if (focus && X.chapters[focus]) { r = { type: 'c', id: focus }; }
            r = r || defaultRoute();
            if (r) {
                var h = href(r.type, r.id);
                if (location.hash !== h) { history.replaceState(null, '', h); }
            }
            renderTop();
            show(r, { keepScroll: true });
        }
    };

    // ---------------------------------------------------------------- init

    function start(resume) {
        renderTop();
        var hash = location.hash || '';
        var r = parseHash();
        var toComment = /^#(comment-\d+|respond|comments)/.test(hash);
        // deep link from a feedback e-mail / the old resume links: ?cpt_resume={id}
        var q = (location.search.match(/[?&]cpt_resume=([^&]+)/) || [])[1];
        if (!r && q) {
            q = decodeURIComponent(q);
            if (X.items[q]) { r = { type: 'i', id: q }; }
            else if (X.groups[q]) { r = X.groups[q].hasPage ? { type: 'g', id: q } : { type: 'i', id: X.groups[q].items[0].id }; }
            else if (X.chapters[q]) { r = X.chapters[q].flat ? { type: 'i', id: X.chapters[q].groups[0].items[0].id } : { type: 'c', id: q }; }
        }
        if (toComment) {
            Object.keys(X.items).some(function (id) {
                if (X.items[id].comments) { r = { type: 'i', id: id }; return true; }
                return false;
            });
        }
        show(r || defaultRoute(resume), { keepScroll: !toComment });
        if (toComment) {
            setTimeout(function () {
                var target = document.getElementById(hash.slice(1)) || commentsNode();
                if (target) { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            }, 400);
        }
    }

    index();
    commentNodes();   // find them before anything is hidden or moved
    buildShell();
    homeComments();   // comments leave the page flow first...
    hideLeftovers();  // ...so only real leftovers get hidden
    renderTop();
    ajax('cpt_v4_state', { post_id: C.postId }, 'GET').then(function (res) {
        if (res && res.success) {
            logged = true;
            S = res.data;
            notes = res.data.notes || {};
            start(res.data.resume);
        } else {
            S = false;
            start(null);
        }
    }).catch(function () {
        S = false;
        start(null);
    });
})();
