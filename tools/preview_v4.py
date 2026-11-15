# -*- coding: utf-8 -*-
"""
Local preview of the v4 item engine, without WordPress.

Builds course-preview-v4.html from live-content-snapshot/v4/unit-N.json: the
same payload includes/v4-render.php prints, plus an in-memory stand-in for the
admin-ajax endpoints (state / event / note / edit are simulated in the page).

    python -I tools/preview_v4.py
    python -m http.server 8099      ->  http://localhost:8099/course-preview-v4.html?unit=5
    add &guest=1 for the logged-out view, &admin=1 for the editing tools
"""
import html
import io
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
V4 = os.path.join(ROOT, 'live-content-snapshot', 'v4')
YT = re.compile(r'(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/|live/|shorts/))([A-Za-z0-9_-]{11})')


def facade(vid, title=''):
    t = '<div class="cu4-video-title">%s</div>' % html.escape(title.strip()) if title.strip() else ''
    return ('<div class="cu4-video" data-yt="%s">%s<button type="button" class="cu4-video-play" aria-label="הפעלת הסרטון">'
            '<img src="https://i.ytimg.com/vi/%s/hqdefault.jpg" alt="" loading="lazy">'
            '<span class="cu4-video-btn"><svg viewBox="0 0 24 24"><polygon points="6 3 20 12 6 21 6 3"/></svg></span></button></div>') % (vid, t, vid)


def render(h):
    def prompt(m):
        txt = re.sub(r'<br\s*/?>', '\n', m.group(1))
        txt = re.sub(r'</p>\s*<p[^>]*>', '\n\n', txt)
        txt = html.unescape(re.sub(r'<[^>]+>', '', txt)).strip()
        return '<div class="prompt-container"><button class="copy-button"></button><pre>%s</pre></div>' % html.escape(txt)
    h = re.sub(r'\[prompt\]([\s\S]*?)\[/prompt\]', prompt, h)

    def video(m):
        ids = YT.findall(m.group(1))
        return facade(ids[0], m.group(2) or '') if ids else m.group(0)
    h = re.sub(r'(?:<p>\s*)?\[video:\s*([^\]|]+?)\s*(?:\|\s*([^\]]*?))?\s*\](?:\s*</p>)?', video, h)
    h = re.sub(r'<iframe\b[^>]*\bsrc=["\'][^"\']*(?:youtube(?:-nocookie)?\.com/embed/|youtu\.be/)([A-Za-z0-9_-]{11})[^"\']*["\'][^>]*>\s*</iframe>',
               lambda m: facade(m.group(1)), h, flags=re.I)
    h = re.sub(r'(?:<p>\s*)?\[done-button:\s*([^\]]+?)\s*\](?:\s*</p>)?',
               lambda m: '<div class="task-done-box" style="margin:20px 0;padding:15px;background:#f0f7ff;border-right:4px solid var(--primary-color);border-radius:4px;">'
                         '<label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:bold;">'
                         '<input type="checkbox" data-track-manual style="width:20px;height:20px;cursor:pointer;"><span>%s</span></label></div>' % html.escape(m.group(1).strip()), h)
    return h


def need(rule, n):
    if rule == 'optional':
        return 0
    if rule == 'all':
        return n
    if str(rule).isdigit():
        return max(0, min(int(rule), n))
    return min(1, n)


def payload(d):
    chapters = []
    for ch in d['chapters']:
        c = {'id': ch['id'], 'title': ch['title'], 'intro': render(ch.get('intro', '')), 'rawIntro': ch.get('intro', ''), 'groups': []}
        for g in ch['groups']:
            gg = {'id': g['id'], 'title': g['title'], 'rule': g['rule'], 'need': need(g['rule'], len(g['items'])),
                  'intro': render(g.get('intro', '')), 'rawIntro': g.get('intro', ''), 'items': []}
            for it in g['items']:
                raw = it.get('html', '')
                vids = list(dict.fromkeys(YT.findall(raw)))
                has_done = '[done-button:' in raw
                mode = 'comment' if it['kind'] == 'discussion' else 'check' if has_done else 'video' if vids else 'view'
                gg['items'].append({'id': it['id'], 'title': it['title'], 'kind': it['kind'], 'mode': mode, 'videos': vids,
                                    'comments': bool(it.get('comments')), 'html': render(raw), 'raw': raw})
            c['groups'].append(gg)
        chapters.append(c)
    return {'sidebarTitle': d.get('sidebar_title', ''), 'blockedUrl': d.get('blocked_url', ''), 'chapters': chapters}


PAGE = r'''<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>תצוגה מקומית - מבנה פריטים v4</title>
<link href="https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;700&family=Varela+Round&display=swap" rel="stylesheet">
<link rel="stylesheet" href="course-progress-tracker/assets/course-shell.css">
<link rel="stylesheet" href="course-progress-tracker/assets/unit-v4.css">
<style>body{margin:0;background:#eef1f4;font-family:Rubik,sans-serif} .wrap{max-width:1180px;margin:0 auto;background:#fff}</style>
</head>
<body class="cpt-v4-page">
<div class="wrap">
<div class="course-shell cu4-shell is-ready" id="course-shell">
  <header class="course-hero"><div class="course-hero-overlay"><span class="course-hero-kicker" id="kicker"></span><h1 class="course-hero-title" id="htitle"></h1></div></header>
  <div class="cu4" id="cu4"></div>
</div>
<div id="comments" class="comments-area"><h3>תגובות (דמה)</h3><p>כאן יופיע אזור התגובות של וורדפרס.</p>
  <button type="button" id="fake-comment">פרסום תגובה (דמה)</button></div>
</div>
<script>
var UNITS = __UNITS__;
var q = new URLSearchParams(location.search);
var n = q.get('unit') || '5';
var unit = UNITS[n];
document.getElementById('kicker').textContent = 'יחידה ' + n;
document.getElementById('htitle').textContent = unit.sidebarTitle;
document.getElementById('cu4').style.setProperty('--cu4-accent', unit.color);
window.cptV4 = { postId: 1, slug: n, unitNo: +n, unitTitle: unit.sidebarTitle, ajaxUrl: '/mock-ajax', loginUrl: '#login',
  isAdmin: q.get('admin') === '1', live: false, nonce: 'x', unit: unit };

// ---- in-memory stand-in for admin-ajax (mirrors includes/v4-model.php rules) ----
(function () {
  var key = 'cu4-preview-' + n;
  var db = JSON.parse(localStorage.getItem(key) || '{"done":[],"notes":{},"comment":false}');
  function save() { localStorage.setItem(key, JSON.stringify(db)); }
  function state() {
    var U = window.cptV4.unit, groups = {}, chapters = {}, itemsDone = 0, total = 0, acc = 0, cn = 0, all = true;
    U.chapters.forEach(function (ch) {
      var sum = 0, req = 0, call = true;
      ch.groups.forEach(function (g) {
        var d = 0;
        g.items.forEach(function (it) {
          total++;
          if (it.mode === 'comment' && db.comment && db.done.indexOf(it.id) < 0) { db.done.push(it.id); }
          if (db.done.indexOf(it.id) >= 0) { d++; }
        });
        itemsDone += d;
        var complete = g.need > 0 && d >= g.need;
        groups[g.id] = { done: d, need: g.need, total: g.items.length, complete: complete };
        if (g.need > 0) { req++; sum += complete ? 1 : d / g.need; if (!complete) { call = false; } }
      });
      chapters[ch.id] = { pct: req ? Math.round(100 * sum / req) : null, complete: req ? call : false };
      if (req) { cn++; acc += chapters[ch.id].pct; if (!call) { all = false; } }
    });
    return { done: db.done.slice(), groups: groups, chapters: chapters, percent: cn && all ? 100 : (cn ? Math.round(acc / cn) : 0),
             items_done: itemsDone, items_total: total };
  }
  document.getElementById('fake-comment').addEventListener('click', function () { db.comment = true; save(); alert('תגובה נרשמה (דמה). עברו לפריט אחר וחזרו.'); });
  var real = window.fetch;
  window.fetch = function (url, opt) {
    if (String(url).indexOf('/mock-ajax') !== 0) { return real.apply(this, arguments); }
    var p = {};
    if (opt && opt.body) { opt.body.forEach(function (v, k) { p[k] = v; }); }
    else { new URLSearchParams(String(url).split('?')[1] || '').forEach(function (v, k) { p[k] = v; }); }
    var out = { success: true, data: {} };
    if (q.get('guest') === '1') { out = { success: false, data: { logged_in: false } }; }
    else if (p.action === 'cpt_v4_state') { out.data = Object.assign(state(), { notes: db.notes, resume: db.pos || null }); }
    else if (p.action === 'cpt_v4_event') {
      if (p.type === 'uncheck') { db.done = db.done.filter(function (x) { return x !== p.item; }); }
      else if (p.type !== 'comment' && db.done.indexOf(p.item) < 0) { db.done.push(p.item); }
      save(); out.data = state(); console.log('[mock] event', p.type, p.item, p.data);
    }
    else if (p.action === 'cpt_v4_note') { if (p.note.trim()) { db.notes[p.item] = p.note; } else { delete db.notes[p.item]; } save(); }
    else if (p.action === 'cpt_save_last_position') { db.pos = p.section_id; save(); }
    else if (p.action === 'cpt_v4_switch') { out.data = { live: p.on === '1' }; }
    else if (p.action === 'cpt_v4_edit') { out = { success: false, data: { message: 'עריכה נשמרת רק באתר (בתצוגה המקומית אין שרת)' } }; }
    return Promise.resolve({ json: function () { return Promise.resolve(out); } });
  };
})();
</script>
<script src="course-progress-tracker/assets/unit-v4.js"></script>
<script>if (window.cptV4.isAdmin) { var s = document.createElement('script'); s.src = 'course-progress-tracker/assets/unit-v4-edit.js'; document.body.appendChild(s); }</script>
</body>
</html>
'''


def main():
    units = {}
    for n in range(1, 11):
        d = json.load(io.open(os.path.join(V4, 'unit-%d.json' % n), encoding='utf-8'))
        p = payload(d)
        p['color'] = d.get('primary_color', '#2a8c8c')
        units[str(n)] = p
    blob = json.dumps(units, ensure_ascii=False).replace('</', '<\\/')
    io.open(os.path.join(ROOT, 'course-preview-v4.html'), 'w', encoding='utf-8', newline='\n').write(PAGE.replace('__UNITS__', blob))
    print('wrote course-preview-v4.html')


if __name__ == '__main__':
    main()
