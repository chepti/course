# -*- coding: utf-8 -*-
"""
Convert the live unit content (option cpt_unit_content_N: chapters holding one
long HTML blob) into the v4 item structure (option cpt_unit_v4_N):

  chapter (מבט על / כלים / דיון / משימה)
    -> group (a sub-chapter, e.g. "משחקים ולומדות") with a completion rule
       -> item (one tool / one video / one text), each with its own page + tick

Usage:  python -I tools/convert_v4.py            (reads live-content-snapshot/unit-N.json)
Output: live-content-snapshot/v4/unit-N.json  + a printed outline for review.

The old option is never touched - v4 lives in a separate option, so switching
back is always possible.
"""
import io
import json
import os
import re
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SNAP = os.path.join(ROOT, 'live-content-snapshot')
OUT = os.path.join(SNAP, 'v4')

# Groups whose h4 headings are steps of ONE process (not alternative tools):
# they stay a single item.
NO_SPLIT = {
    ('6', 'tools'),
    ('7', 'tools_form'), ('7', 'tools_one_way'), ('7', 'tools_two_way'),
    ('10', 'tools_connect'), ('10', 'tools_recipes'),
}
OPTIONAL_ID = re.compile(r'(help|helpers|resources|inspiration)', re.I)
OPTIONAL_TITLES = ('כלי עזר', 'השראה', 'עוד אפיקי למידה', 'פרומפטים מיוחדים')
TASK_ID = re.compile(r'^(task|assignment)', re.I)
DISC_ID = re.compile(r'^discussion', re.I)
OVERVIEW_ID = re.compile(r'^(overview|intro)$', re.I)

VIDEO_RE = re.compile(r'\[video:|<iframe[^>]+(youtube\.com|youtu\.be)', re.I)
H4_RE = re.compile(r'<h4\b[^>]*>(.*?)</h4>', re.S | re.I)
H2_LEAD_RE = re.compile(r'^\s*(?:<hr\s*/?>\s*)*<h[123]\b[^>]*>.*?</h[123]>', re.S | re.I)
HR_EDGE_RE = re.compile(r'^(?:\s|<hr\s*/?>|<p>\s*(?:&nbsp;)?\s*</p>)+|(?:\s|<hr\s*/?>|<p>\s*(?:&nbsp;)?\s*</p>)+$', re.I)


def text_of(html):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', html)).replace('&nbsp;', ' ').strip()


def clean(html):
    prev = None
    while prev != html:
        prev = html
        html = HR_EDGE_RE.sub('', html)
    return html.strip()


def kind_of(html, gid):
    if DISC_ID.match(gid):
        return 'discussion'
    if TASK_ID.match(gid):
        return 'task'
    if VIDEO_RE.search(html):
        return 'video'
    if '<iframe' in html or '[canva:' in html:
        return 'embed'
    return 'read'


def split_items(unit, sec):
    """Return (intro_html, [ {title, html} ])."""
    gid, title, html = sec['id'], sec['title'], sec.get('html', '') or ''
    # videos from the old structured list are appended as tokens
    for v in sec.get('videos') or []:
        if v.get('url'):
            html += '\n[video: %s%s]' % (v['url'], (' | ' + v['title']) if v.get('title') else '')
    body = html
    m = H2_LEAD_RE.match(body)
    if m and len(text_of(m.group(0))) <= 60:
        body = body[m.end():]
    body = clean(body)

    no_split = (unit, gid) in NO_SPLIT or DISC_ID.match(gid) or TASK_ID.match(gid)
    heads = [h for h in H4_RE.finditer(body) if 0 < len(text_of(h.group(1))) <= 60]
    if OVERVIEW_ID.match(gid) and len(VIDEO_RE.findall(body)) < 2:
        no_split = True
    if no_split or len(heads) < 2:
        return '', [{'title': title, 'html': body}]

    intro = clean(body[:heads[0].start()])
    items = []
    for i, h in enumerate(heads):
        end = heads[i + 1].start() if i + 1 < len(heads) else len(body)
        part = clean(body[h.end():end])
        if re.search(r'<a[ >]', h.group(1), re.I):
            # the heading itself was a link - keep it reachable inside the item
            part = '<p>' + h.group(1).strip() + '</p>' + chr(10) + part
        items.append({'title': text_of(h.group(1)), 'html': part})
    # an intro that carries a video is real content, not a preface
    if intro and VIDEO_RE.search(intro):
        items.insert(0, {'title': title, 'html': intro})
        intro = ''
    # an overview's preface belongs with its first item (nothing to "choose" there)
    if intro and OVERVIEW_ID.match(gid):
        items[0]['html'] = intro + chr(10) + items[0]['html']
        intro = ''
    return intro, items


def rule_for(gid, title, parent_chapter_kind):
    if OPTIONAL_ID.search(gid) or title.strip() in OPTIONAL_TITLES:
        return 'optional'
    if DISC_ID.match(gid) or TASK_ID.match(gid):
        return 'all'
    return 'one'


def convert(unit, data):
    secs = [s for s in data['sections'] if s.get('id')]
    by_id = {s['id']: s for s in secs}

    def root_of(s):
        seen = set()
        while s.get('parent') and s['parent'] in by_id and s['id'] not in seen:
            seen.add(s['id'])
            s = by_id[s['parent']]
        return s['id']

    chapters = []
    for main in [s for s in secs if not s.get('parent')]:
        ch = {'id': main['id'], 'title': main['title'], 'intro': '', 'groups': []}
        kids = [s for s in secs if s.get('parent') and root_of(s) == main['id']]
        own = main.get('html', '') or ''
        own_is_content = (not kids) or bool(VIDEO_RE.search(own)) or bool(main.get('videos'))
        sources = ([main] if own_is_content else []) + kids
        if not own_is_content:
            ch['intro'] = clean(own)
        for s in sources:
            if s is not main and not (s.get('html') or '').strip() and not s.get('videos'):
                continue  # empty container (e.g. unit 1 help_tools)
            intro, items = split_items(unit, s)
            g = {
                'id': s['id'], 'title': s['title'],
                'rule': rule_for(s['id'], s['title'], main['id']),
                'intro': intro, 'items': [],
            }
            for k, it in enumerate(items, 1):
                g['items'].append({
                    'id': '%s-%d' % (s['id'], k),
                    'title': it['title'],
                    'kind': kind_of(it['html'], s['id']),
                    'html': it['html'],
                    'comments': bool(DISC_ID.match(s['id'])),
                })
            ch['groups'].append(g)
        chapters.append(ch)

    return {
        'v': 4,
        'sidebar_title': data.get('sidebar_title', ''),
        'primary_color': data.get('primary_color', '#2a8c8c'),
        'blocked_url': data.get('blocked_url', ''),
        'chapters': chapters,
    }


def main():
    os.makedirs(OUT, exist_ok=True)
    for n in range(1, 11):
        src = os.path.join(SNAP, 'unit-%d.json' % n)
        raw = open(src, encoding='utf-8').read().lstrip('﻿')
        data, _ = json.JSONDecoder().raw_decode(raw)
        v4 = convert(str(n), data)
        with open(os.path.join(OUT, 'unit-%d.json' % n), 'w', encoding='utf-8') as f:
            json.dump(v4, f, ensure_ascii=False, indent=1)
        total = sum(len(g['items']) for c in v4['chapters'] for g in c['groups'])
        print('=== יחידה %d: %s  (%d פריטים)' % (n, v4['sidebar_title'], total))
        for c in v4['chapters']:
            print('  [%s] %s%s' % (c['id'], c['title'], '  (+פתיח %d)' % len(text_of(c['intro'])) if c['intro'] else ''))
            for g in c['groups']:
                print('     - %s | %s | rule=%s%s' % (g['id'], g['title'], g['rule'], ' | intro %d' % len(text_of(g['intro'])) if g['intro'] else ''))
                for it in g['items']:
                    print('           %-10s %s  (%d)' % (it['kind'], it['title'], len(text_of(it['html']))))


if __name__ == '__main__':
    main()
