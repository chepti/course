/**
 * Course Progress Tracker v3 - Frontend
 *
 * Flow:
 * 1. Bootstrap session via admin-ajax (never page-cached) to get a fresh REST nonce.
 * 2. Load full unit state in one REST call, render nav indicators + resume button.
 * 3. Track activities (video/click/scroll/comment/manual) via REST with retry.
 *
 * DOM contract (unchanged from v2):
 *   [data-track-section] content sections, [data-track-video] iframes,
 *   [data-track-click] buttons, [data-track-manual] checkboxes,
 *   nav: .main-item/.sub-item[data-section] inside .nav-item with .completion-circle,
 *   #content-area, .progress-bar
 */
(function () {
    'use strict';

    if (typeof cpt_tracker_data === 'undefined') {
        console.error('Course Tracker: cpt_tracker_data missing - script not localized.');
        return;
    }

    var POST_ID = parseInt(cpt_tracker_data.post_id, 10);
    var AJAX_URL = cpt_tracker_data.ajax_url;
    var VIDEO_WATCH_SECONDS = 30;
    var SCROLL_SECONDS = 30;

    var session = null; // { rest_url, rest_nonce, user_id }
    var trackedKeys = {}; // dedup within page life
    var positionTimer = null;
    var commentTimer = null;

    // ---------- HTTP ----------

    function bootstrapSession() {
        return fetch(AJAX_URL + '?action=cpt_session', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.success && json.data && json.data.logged_in) {
                    session = json.data;
                    return session;
                }
                throw new Error('not logged in');
            });
    }

    function rest(path, options, retry) {
        options = options || {};
        var headers = { 'X-WP-Nonce': session.rest_nonce };
        if (options.body) {
            headers['Content-Type'] = 'application/json';
        }
        return fetch(session.rest_url + path, {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: headers,
            body: options.body ? JSON.stringify(options.body) : undefined
        }).then(function (r) {
            if (r.status === 403 && !retry) {
                // Stale nonce (long session) - refresh once and retry
                return bootstrapSession().then(function () {
                    return rest(path, options, true);
                });
            }
            if (!r.ok) {
                return r.text().then(function (body) {
                    console.warn('Course Tracker REST error body:', body.substring(0, 400)); // eslint-disable-line no-console
                    throw new Error('REST ' + path + ' failed: ' + r.status);
                });
            }
            return r.json();
        });
    }

    function postActivity(activityType, sectionId, data, attempt) {
        attempt = attempt || 0;
        // Use admin-ajax instead of REST POST - avoids WAF blocks on /wp-json/ endpoints
        var formData = new FormData();
        formData.append('action', 'cpt_activity_v3');
        formData.append('post_id', POST_ID);
        formData.append('section_id', sectionId);
        formData.append('activity_type', activityType);
        formData.append('activity_data', JSON.stringify(data || {}));
        return fetch(AJAX_URL, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (r) {
            if (!r.ok) {
                return r.text().then(function (t) {
                    console.warn('Course Tracker: activity HTTP error', r.status, t.substring(0, 200)); // eslint-disable-line no-console
                    throw new Error('activity HTTP ' + r.status);
                });
            }
            return r.json();
        }).then(function (json) {
            if (!json || !json.success) {
                console.warn('Course Tracker: activity error:', json && json.data && json.data.message); // eslint-disable-line no-console
                return json;
            }
            var resp = json.data;
            applyState({
                section_progress: resp.section_progress,
                completed_sections: resp.completed_sections || []
            });
            updateShellProgress(resp.unit_percent);
            if (resp.completed_sections) {
                lastState = Object.assign({}, lastState, {
                    section_progress: resp.section_progress,
                    completed_sections: resp.completed_sections
                });
            }
            return resp;
        }).catch(function (err) {
            if (attempt < 2) {
                setTimeout(function () {
                    postActivity(activityType, sectionId, data, attempt + 1);
                }, 3000 * (attempt + 1));
            } else {
                console.error('Course Tracker: activity failed', activityType, sectionId, err); // eslint-disable-line no-console
            }
        });
    }

    // ---------- Section resolution ----------

    function getSectionId(element) {
        if (!element || !element.getAttribute) return null;
        if (element.tagName === 'IFRAME' && element.hasAttribute('data-track-video')) {
            return element.getAttribute('data-track-video');
        }
        var byClosest = element.closest && element.closest('[data-track-section]');
        if (byClosest) return byClosest.getAttribute('data-track-section');
        var activeNavItem = document.querySelector('.main-item.active, .sub-item.active');
        if (activeNavItem && activeNavItem.hasAttribute('data-section')) {
            return activeNavItem.getAttribute('data-section');
        }
        return null;
    }

    // ---------- State rendering ----------

    function applyState(state) {
        if (!state) return;
        var progressMap = state.section_progress || {};

        // Mark/unmark based on calculated per-section progress
        Object.keys(progressMap).forEach(function (sectionId) {
            updateNavIndicator(sectionId, progressMap[sectionId]);
            if (progressMap[sectionId] >= 100) {
                restoreManualCheckbox(sectionId);
            }
        });

        // completed_sections (from DB) can include sections whose section_progress
        // key doesn't reach 100 (legacy data / manifest mismatch) - always honour them
        if (Array.isArray(state.completed_sections)) {
            state.completed_sections.forEach(function (sectionId) {
                updateNavIndicator(sectionId, 100);
            });
        }

        if (state.resume) {
            showResumeButton(state.resume);
        }

        updateProgressDetail(progressMap, state.completed_sections);
        propagateParentNavCompletion(progressMap, state.completed_sections);

        // Debug: always log to console so we can diagnose circle issues
        /* eslint-disable no-console */
        var navSections = Array.from(document.querySelectorAll('[data-section]')).map(function (el) { return el.getAttribute('data-section'); });
        var spKeys = Object.keys(progressMap);
        var completedNow = Array.from(document.querySelectorAll('.nav-item.completed')).length;
        console.groupCollapsed('Course Tracker v3.2.2 - state (' + completedNow + ' circles marked)');
        console.log('nav data-section values:', navSections);
        console.log('section_progress from API:', progressMap);
        console.log('completed_sections from API:', state.completed_sections);
        var missing = spKeys.filter(function (id) { return navSections.indexOf(id) === -1; });
        if (missing.length) { console.warn('section_progress IDs not in nav DOM:', missing); }
        var navNotInSP = navSections.filter(function (id) { return spKeys.indexOf(id) === -1 && (!state.completed_sections || state.completed_sections.indexOf(id) === -1); });
        if (navNotInSP.length) { console.info('nav IDs with no progress data:', navNotInSP); }
        console.groupEnd();
        /* eslint-enable no-console */
    }

    function findNavItem(sectionId) {
        var navItem = document.querySelector('[data-section="' + sectionId + '"]');
        if (navItem) return navItem;
        var all = document.querySelectorAll('[data-section]');
        for (var i = 0; i < all.length; i++) {
            var s = all[i].getAttribute('data-section');
            if (s.indexOf(sectionId) === 0 || sectionId.indexOf(s) === 0) {
                return all[i];
            }
        }
        return null;
    }

    function updateNavIndicator(sectionId, progress) {
        var navItem = findNavItem(sectionId);
        if (!navItem) return;
        var container = navItem.closest('.nav-item');
        if (!container) return;
        var isComplete = progress >= 100;
        var wasComplete = container.classList.contains('completed');
        if (wasComplete === isComplete) return;
        if (isComplete) {
            container.classList.add('completed');
        } else {
            container.classList.remove('completed');
        }
    }

    /** פרק-אב מקבל וי כשכל תתי-הפרקים הושלמו (יחידה 2 ודומות). */
    function propagateParentNavCompletion(progressMap, completed) {
        progressMap = progressMap || {};
        completed = Array.isArray(completed) ? completed : [];
        function isDone(id) {
            return (progressMap[id] >= 100) || completed.indexOf(id) !== -1;
        }
        document.querySelectorAll('#nav > .nav-item').forEach(function (navItem) {
            var mainItem = navItem.querySelector(':scope > .main-item[data-section]');
            if (!mainItem) return;
            var mainId = mainItem.getAttribute('data-section');
            var subs = navItem.querySelectorAll('.sub-item[data-section]');
            if (!subs.length) return;
            var subIds = [];
            subs.forEach(function (sub) { subIds.push(sub.getAttribute('data-section')); });
            if (subIds.length && subIds.every(isDone)) {
                updateNavIndicator(mainId, 100);
            } else if (!isDone(mainId)) {
                var sum = 0;
                var count = 0;
                subIds.forEach(function (id) {
                    if (progressMap[id] !== undefined && progressMap[id] !== null) {
                        sum += progressMap[id];
                        count++;
                    }
                });
                if (count) {
                    updateNavIndicator(mainId, Math.round(sum / count));
                }
            }
        });
    }

    function restoreManualCheckbox(sectionId) {
        var attempts = 0;
        var tryCheck = function () {
            attempts++;
            var boxes = document.querySelectorAll('input[type="checkbox"][data-track-manual]');
            var found = false;
            boxes.forEach(function (box) {
                if (getSectionId(box) === sectionId) {
                    box.checked = true;
                    found = true;
                }
            });
            if (!found && attempts < 5) {
                setTimeout(tryCheck, 600);
            }
        };
        tryCheck();
    }

    // ---------- Resume ----------

    function showResumeButton(resume) {
        if (document.querySelector('.cpt-resume-button')) return;
        var progressBar = document.querySelector('.progress-bar');
        if (!progressBar) return;

        var isCurrentUnit = parseInt(resume.post_id, 10) === POST_ID;
        var label, onClick;

        if (isCurrentUnit) {
            var navItem = findNavItem(resume.section_id);
            if (!navItem) return;
            var span = navItem.querySelector('span');
            label = '📍 המשך מאיפה שעצרת: ' + (span ? span.textContent : resume.section_id);
            onClick = function () { navigateToSection(resume.section_id); };
        } else {
            label = '📍 המשך מאיפה שעצרת: ' + (resume.post_title || 'יחידה אחרת');
            onClick = function () {
                if (resume.post_url) {
                    var url = resume.post_url + (resume.post_url.indexOf('?') === -1 ? '?' : '&') + 'cpt_resume=' + encodeURIComponent(resume.section_id);
                    window.location.href = url;
                }
            };
        }

        var btn = document.createElement('div');
        btn.className = 'cpt-resume-button';
        btn.style.cssText = 'margin-right:20px;padding:8px 16px;background:linear-gradient(45deg,#3a757f,#27ae60);color:#fff;border-radius:20px;cursor:pointer;font-weight:bold;font-size:14px;white-space:nowrap;box-shadow:0 2px 6px rgba(0,0,0,0.15);display:flex;align-items:center;gap:8px;transition:transform .2s ease;';
        btn.textContent = label;
        btn.addEventListener('mouseenter', function () { btn.style.transform = 'translateY(-2px)'; });
        btn.addEventListener('mouseleave', function () { btn.style.transform = 'translateY(0)'; });
        btn.addEventListener('click', onClick);

        var indicator = progressBar.querySelector('.progress-indicator');
        if (indicator) {
            progressBar.insertBefore(btn, indicator);
        } else {
            progressBar.appendChild(btn);
        }
    }

    function navigateToSection(sectionId) {
        var navItem = findNavItem(sectionId);
        if (navItem) {
            navItem.click();
            var contentArea = document.querySelector('#content-area');
            if (contentArea) {
                contentArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    function handleResumeQueryParam() {
        var match = window.location.search.match(/[?&]cpt_resume=([^&]+)/);
        if (match) {
            setTimeout(function () {
                navigateToSection(decodeURIComponent(match[1]));
            }, 400);
        }
    }

    // ---------- Position saving ----------

    function savePosition(sectionId) {
        if (!sectionId) return;
        clearTimeout(positionTimer);
        positionTimer = setTimeout(function () {
            var formData = new FormData();
            formData.append('action', 'cpt_save_last_position');
            formData.append('post_id', POST_ID);
            formData.append('section_id', sectionId);
            fetch(AJAX_URL, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            }).catch(function (err) {
                console.error('Course Tracker: save position failed', err); // eslint-disable-line no-console
            });
        }, 800); // debounce rapid navigation
    }

    function watchSectionChanges() {
        document.addEventListener('click', function (e) {
            var navItem = e.target.closest && e.target.closest('.main-item, .sub-item');
            if (navItem && navItem.hasAttribute('data-section')) {
                savePosition(navItem.getAttribute('data-section'));
            }
        });
    }

    // ---------- Trackers ----------

    function trackVideos() {
        var seen = {};
        var attach = function () {
            document.querySelectorAll('iframe[src*="youtube.com"], iframe[src*="youtu.be"]').forEach(function (iframe) {
                var sectionId = getSectionId(iframe);
                if (!sectionId) return;
                var m = iframe.src.match(/(?:embed\/|v=|youtu\.be\/)([A-Za-z0-9_-]{11})/);
                var videoId = m ? m[1] : iframe.src;
                var key = sectionId + '_' + videoId;
                if (seen[key]) return;
                seen[key] = true;
                watchVideoVisibility(iframe, key, sectionId, videoId);
            });
        };
        attach();
        observeContent(function () { setTimeout(attach, 400); });
    }

    function watchVideoVisibility(iframe, key, sectionId, videoId) {
        if (trackedKeys[key]) return;
        var container = iframe.closest('.video-container') || iframe.parentElement;
        var visibleSince = null;

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (trackedKeys[key]) { observer.disconnect(); return; }
                if (entry.isIntersecting) {
                    if (!visibleSince) visibleSince = Date.now();
                } else {
                    visibleSince = null;
                }
            });
        }, { threshold: 0.4 });
        observer.observe(container);

        var interval = setInterval(function () {
            if (trackedKeys[key]) { clearInterval(interval); return; }
            if (visibleSince && (Date.now() - visibleSince) / 1000 >= VIDEO_WATCH_SECONDS) {
                trackedKeys[key] = true;
                clearInterval(interval);
                observer.disconnect();
                postActivity('video_watch', sectionId, {
                    video_id: videoId,
                    progress_percent: 50,
                    method: 'visibility_time'
                });
            }
        }, 3000);
    }

    function trackClicks() {
        document.addEventListener('click', function (e) {
            var target = e.target.closest && e.target.closest('[data-track-click]');
            if (!target) return;
            var sectionId = getSectionId(target);
            if (!sectionId) return;
            var key = 'click_' + sectionId + '_' + (target.href || target.textContent.trim());
            if (trackedKeys[key]) return;
            trackedKeys[key] = true;
            postActivity('button_click', sectionId, {
                element: target.tagName,
                text: target.textContent.trim().substring(0, 200),
                href: target.href || null
            });
        });
    }

    function trackScroll() {
        var visibility = {};
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var sectionId = entry.target.getAttribute('data-track-section');
                if (!sectionId) return;
                var key = 'scroll_' + sectionId;
                if (trackedKeys[key]) return;
                if (entry.isIntersecting) {
                    visibility[sectionId] = visibility[sectionId] || Date.now();
                } else if (visibility[sectionId]) {
                    var seconds = (Date.now() - visibility[sectionId]) / 1000;
                    delete visibility[sectionId];
                    if (seconds >= SCROLL_SECONDS) {
                        trackedKeys[key] = true;
                        postActivity('scroll', sectionId, { time_spent: Math.round(seconds) });
                    }
                }
            });
        }, { threshold: 0.5 });

        var attach = function () {
            document.querySelectorAll('[data-track-section]').forEach(function (el) {
                observer.observe(el);
            });
        };
        attach();
        observeContent(attach);
    }

    function trackManualChecks() {
        var handler = function (rawTarget) {
            if (!rawTarget || !rawTarget.closest) return;
            var box = rawTarget.closest('input[type="checkbox"][data-track-manual]');
            if (!box || !box.checked) return;
            var sectionId = getSectionId(box);
            if (!sectionId) {
                console.warn('Course Tracker: no section for manual checkbox', box);
                return;
            }
            var key = 'manual_' + sectionId;
            if (trackedKeys[key]) return;
            trackedKeys[key] = true;
            postActivity('manual_check', sectionId, { checked: true });
        };
        document.addEventListener('change', function (e) { handler(e.target); });
        document.addEventListener('click', function (e) { handler(e.target); });
    }

    function checkCommentStatus() {
        var discussion = document.querySelector('[data-track-section*="discussion"], [data-section*="discussion"]');
        if (!discussion) return;
        var sectionId = discussion.getAttribute('data-track-section') || discussion.getAttribute('data-section') || 'discussion';
        var key = 'comment_' + sectionId;
        if (trackedKeys[key]) return;

        // Use admin-ajax (same-origin cookie auth, no nonce needed)
        fetch(AJAX_URL + '?action=cpt_check_comment_status&post_id=' + POST_ID, {
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (json) {
            if (json && json.success && json.data && json.data.has_comment) {
                trackedKeys[key] = true;
                if (commentTimer) { clearInterval(commentTimer); commentTimer = null; }
                postActivity('comment', sectionId, { post_id: POST_ID });
            }
        }).catch(function () { /* will retry on next interval */ });
    }

    function trackComments() {
        checkCommentStatus();
        commentTimer = setInterval(checkCommentStatus, 30000);
        document.addEventListener('submit', function (e) {
            var form = e.target.closest && e.target.closest('#commentform, form.comment-form, .comment-form');
            if (form) {
                setTimeout(checkCommentStatus, 5000);
            }
        });
        if (window.location.hash && window.location.hash.indexOf('comment') !== -1) {
            setTimeout(checkCommentStatus, 2000);
        }
    }

    // ---------- Content observation (dynamic section loading) ----------

    var contentObservers = [];
    function observeContent(callback) {
        var contentArea = document.querySelector('#content-area');
        if (!contentArea) return;
        var observer = new MutationObserver(function () {
            callback();
        });
        observer.observe(contentArea, { childList: true, subtree: true });
        contentObservers.push(observer);
    }

    // ---------- Sticky nav ----------
    // The curriculum rail and top bar now use native CSS `position: sticky`
    // (see unit.css / course-shell.css). No JS positioning needed - that older
    // approach caused the jump on scroll.

    // ---------- Shell progress bar ----------

    function updateShellProgress(percent) {
        if (percent === null || percent === undefined || isNaN(percent)) return;
        percent = Math.max(0, Math.min(100, Math.round(percent)));
        var fill = document.getElementById('course-progress-fill');
        var pct  = document.getElementById('course-progress-pct');
        if (fill) { fill.style.width = percent + '%'; }
        if (pct)  { pct.textContent = percent + '%'; }
    }

    // Build the per-chapter breakdown FROM the curriculum sidebar, so it uses
    // the same Hebrew chapter labels and main-chapter grouping (not raw ids).
    function buildProgressDetail() {
        var detail = document.getElementById('course-progress-detail');
        if (!detail || detail.getAttribute('data-built')) return;
        var mains = document.querySelectorAll('#nav .main-item[data-section]');
        if (!mains.length) return;
        var html = '';
        mains.forEach(function (mi) {
            var id = mi.getAttribute('data-section');
            var labelEl = mi.querySelector('span'); // the circle is a div; label is a span
            var label = labelEl ? labelEl.textContent.trim() : id;
            html += '<span class="cpr-chip" data-section="' + id + '">'
                 +  '<span class="cpr-mark" aria-hidden="true"></span>'
                 +  '<span class="cpr-label">' + label + '</span>'
                 +  '<span class="cpr-pct">0%</span></span>';
        });
        detail.innerHTML = html;
        detail.setAttribute('data-built', '1');
    }

    // Update each chapter chip's percent + done state.
    function updateProgressDetail(progressMap, completed) {
        var detail = document.getElementById('course-progress-detail');
        if (!detail) return;
        buildProgressDetail();
        progressMap = progressMap || {};
        completed = Array.isArray(completed) ? completed : [];
        detail.querySelectorAll('.cpr-chip').forEach(function (chip) {
            var id = chip.getAttribute('data-section');
            var p = null;
            if (progressMap[id] !== undefined && progressMap[id] !== null) {
                p = progressMap[id];
            } else {
                // aggregate sub-chapters (id_*) when the main has no direct value
                var subs = Object.keys(progressMap).filter(function (k) { return k.indexOf(id + '_') === 0; });
                if (subs.length) {
                    var sum = 0;
                    subs.forEach(function (k) { sum += progressMap[k]; });
                    p = Math.round(sum / subs.length);
                }
            }
            // sidebar completion is authoritative for "done"
            var done = completed.indexOf(id) !== -1;
            var navItem = document.querySelector('#nav .main-item[data-section="' + id + '"]');
            if (navItem && navItem.closest('.nav-item') && navItem.closest('.nav-item').classList.contains('completed')) {
                done = true;
            }
            if (p === null) { p = done ? 100 : 0; }
            if (done) { p = 100; }
            p = Math.max(0, Math.min(100, Math.round(p)));
            chip.classList.toggle('is-done', p >= 100);
            var pctEl = chip.querySelector('.cpr-pct');
            if (pctEl) { pctEl.textContent = p + '%'; }
        });
    }

    // Wire the expandable progress bar + the floating "back to top" chevron.
    function initShellChrome() {
        var toggle = document.getElementById('course-progress-toggle');
        var detail = document.getElementById('course-progress-detail');
        if (toggle && detail) {
            buildProgressDetail();
            if (lastState) { updateProgressDetail(lastState.section_progress, lastState.completed_sections); }
            toggle.addEventListener('click', function () {
                var open = detail.hasAttribute('hidden');
                if (open) { detail.removeAttribute('hidden'); } else { detail.setAttribute('hidden', ''); }
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        var shell = document.getElementById('course-shell');
        if (!shell) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'course-backtotop';
        btn.setAttribute('aria-label', 'חזרה לראש היחידה');
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 15 12 9 18 15"></polyline></svg>';
        document.body.appendChild(btn);
        btn.addEventListener('click', function () {
            // scrollIntoView works even when an ancestor (not window) is the scroller
            shell.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        // Show once the hero leaves the viewport. IntersectionObserver works even
        // where the theme scrolls an inner element (so window scroll never fires).
        var sentinel = shell.querySelector('.course-hero') || shell;
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries) {
                btn.classList.toggle('is-visible', !entries[0].isIntersecting);
            }, { threshold: 0 });
            io.observe(sentinel);
        } else {
            window.addEventListener('scroll', function () {
                btn.classList.toggle('is-visible', window.pageYOffset > 400);
            }, { passive: true });
        }
    }

    // ---------- Guest banner (view-only; progress needs a logged-in user) ----------

    function showGuestBanner() {
        if (document.getElementById('course-guest-banner')) return;
        var shell = document.getElementById('course-shell');
        if (!shell) return;
        var loginUrl = cpt_tracker_data.login_url || '/wp-login.php';
        var el = document.createElement('div');
        el.id = 'course-guest-banner';
        el.className = 'course-guest-banner';
        el.setAttribute('role', 'note');
        el.innerHTML = '<p><strong>צפייה חופשית</strong> — אפשר ללמוד מהתוכן גם בלי התחברות. '
            + 'כדי לשמור התקדמות, לסמן פרקים כהושלמו ולקבל תעודה — '
            + '<a href="' + loginUrl + '">התחברו או הירשמו</a>.</p>';
        var anchor = shell.querySelector('.course-progress') || shell.querySelector('.course-intro') || shell.querySelector('.course-hero');
        if (anchor && anchor.nextSibling) {
            anchor.parentNode.insertBefore(el, anchor.nextSibling);
        } else if (anchor) {
            anchor.parentNode.appendChild(el);
        } else {
            var body = shell.querySelector('.course-unit-body');
            if (body) shell.insertBefore(el, body);
        }
    }

    function startTracking() {
        trackVideos();
        trackClicks();
        trackScroll();
        trackManualChecks();
        trackComments();
        watchSectionChanges();
        handleResumeQueryParam();
        bootstrapSession().then(function () {
            rest('state?post_id=' + POST_ID).then(function (state) {
                applyState(state);
                updateShellProgress(state.unit_percent);
                lastState = state;
            }).catch(function (err) {
                console.warn('Course Tracker: state load failed -', err.message); // eslint-disable-line no-console
            });
        }).catch(function (err) {
            console.warn('Course Tracker: session failed (resume unavailable) -', err.message); // eslint-disable-line no-console
        });
    }

    // ---------- Shell loader: reveal once the unit content has rendered ----------

    function initShellLoader() {
        var shell = document.getElementById('course-shell');
        if (!shell) return;
        var area = document.getElementById('content-area') || shell.querySelector('#content');

        function ready() { shell.classList.add('is-ready'); }

        // Reveal as soon as the content area has meaningful children…
        if (area) {
            if (area.children.length > 0 && area.offsetHeight > 40) {
                ready();
            } else {
                var obs = new MutationObserver(function () {
                    if (area.children.length > 0) { ready(); obs.disconnect(); }
                });
                obs.observe(area, { childList: true, subtree: true });
            }
        }
        // …with a hard fallback so the loader never sticks.
        setTimeout(ready, 2500);
    }

    // ---------- Init ----------

    function init() {
        if (!POST_ID || !AJAX_URL) return;
        console.log('Course Tracker v3.10.12 init - post_id:', POST_ID); // eslint-disable-line no-console

        initShellLoader();
        initShellChrome();

        // Re-apply indicators when section HTML swaps in (debounced — avoids flicker)
        observeContent(scheduleApplyState);

        if (cpt_tracker_data.logged_in) {
            if (cpt_tracker_data.initial_state) {
                applyState(cpt_tracker_data.initial_state);
                lastState = cpt_tracker_data.initial_state;
            }
            startTracking();
            return;
        }

        // דף אורח (לעיתים ממטמון): בדיקת סשן חיה — בלי reload אינסופי.
        bootstrapSession().then(function () {
            startTracking();
        }).catch(function () {
            showGuestBanner();
        });
    }

    var lastState = {};
    var applyStateTimer = null;

    function scheduleApplyState() {
        if (!lastState || !lastState.section_progress) return;
        clearTimeout(applyStateTimer);
        applyStateTimer = setTimeout(function () {
            applyState(lastState);
        }, 350);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
