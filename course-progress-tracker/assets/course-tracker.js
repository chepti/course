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
        if (progress >= 100) {
            container.classList.add('completed');
        } else {
            container.classList.remove('completed');
        }
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

    // ---------- Init ----------

    function init() {
        if (!POST_ID || !AJAX_URL) return;
        console.log('Course Tracker v3.2.6 init - post_id:', POST_ID); // eslint-disable-line no-console

        // Apply server-embedded initial state immediately so circles are coloured on load
        if (cpt_tracker_data.initial_state) {
            applyState(cpt_tracker_data.initial_state);
            lastState = cpt_tracker_data.initial_state;
        }

        // Re-apply indicators when section HTML swaps in
        observeContent(function () {
            setTimeout(function () { if (lastState) { applyState(lastState); } }, 300);
        });

        // Start all trackers immediately - they use admin-ajax (no nonce needed)
        trackVideos();
        trackClicks();
        trackScroll();
        trackManualChecks();
        trackComments();
        watchSectionChanges();
        handleResumeQueryParam();

        // Optional: bootstrap REST session for the resume button (GET /state).
        // Failure is non-fatal - activity tracking already works via admin-ajax above.
        bootstrapSession().then(function () {
            rest('state?post_id=' + POST_ID).then(function (state) {
                applyState(state);
                lastState = state;
            }).catch(function (err) {
                console.warn('Course Tracker: state load failed -', err.message); // eslint-disable-line no-console
            });
        }).catch(function (err) {
            console.warn('Course Tracker: session failed (resume unavailable) -', err.message); // eslint-disable-line no-console
        });
    }

    var lastState = {};

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
