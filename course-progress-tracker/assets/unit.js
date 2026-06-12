/**
 * Course unit shared engine (v2) - course-progress-tracker.
 *
 * Skeleton = unit-9 (family F, the cleanest: no updateProgress / timers dead
 * code), features = unit-6 (family D: tools auto-open) + delegated copy
 * handling, all behind per-unit flags. See דוח_איחוד_מנוע.md section ב.
 *
 * Slim-format units define, inline in the page content (which is rendered
 * before the footer, where this script is enqueued with $in_footer = true):
 *   window.cptUnitContent - { sectionKey: htmlString, ... }
 *   window.cptUnitConfig  - optional flags:
 *     defaultSection    : section auto-opened on desktop (default 'overview')
 *     activeMode        : 'none' | 'self' | 'parent' (.active highlighting)
 *     hasActiveStrip    : add .has-active to #content on selection (units 2-3)
 *     autoOpenFirstSub  : "tools" main item opens its first sub-item (units 6-7)
 *     copyIcons         : 'inline' (icons already inside the content's
 *                         .copy-button markup - engine must NOT inject) |
 *                         'inject' (engine fills empty .copy-button, units 8-9)
 *     freshWrapperCheck : mobile re-click toggle also compares wrapper.innerHTML
 *                         (units 1-7 behavior; false = units 8-9 behavior)
 *     copyIcon/checkIcon: SVG strings for the copy-to-clipboard feedback
 *
 * Old-format units (their own embedded engine) never define
 * window.cptUnitContent, so this script no-ops for them.
 *
 * No updateProgress here on purpose: it is dead code in every unit (see the
 * report). Completion display is handled by assets/course-tracker.js, whose
 * DOM contract is preserved untouched: .main-item/.sub-item[data-section],
 * .nav-item, .completion-circle, #content-area, .progress-bar.
 */
(function () {
    'use strict';

    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    onReady(function () {
        var content = window.cptUnitContent;
        if (!content) { return; } // old-format unit: its embedded engine takes over

        var container = document.querySelector('#interactive-unit-container');
        if (!container) { return; }

        var defaults = {
            defaultSection: 'overview',
            activeMode: 'none',
            hasActiveStrip: false,
            autoOpenFirstSub: false,
            copyIcons: 'inline',
            freshWrapperCheck: true,
            copyIcon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M16 1H6C4.9 1 4 1.9 4 3v12h2V3h10V1zm3 4H10c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h9c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H10V7h9v14z"/></svg>',
            checkIcon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>'
        };
        var cfg = window.cptUnitConfig || {};
        Object.keys(defaults).forEach(function (key) {
            if (!(key in cfg)) { cfg[key] = defaults[key]; }
        });

        var isMobileView = window.innerWidth <= 800;
        var contentArea = container.querySelector('#content-area');
        var contentEl = container.querySelector('#content');

        function injectCopyButtons() {
            if (cfg.copyIcons !== 'inject') { return; } // 'inline': icons ship in the content, never double-inject
            container.querySelectorAll('.copy-button').forEach(function (button) {
                if (button.innerHTML.indexOf('svg') === -1) {
                    button.innerHTML = cfg.copyIcon;
                }
            });
        }

        // Copy-to-clipboard: delegated, works for inline and injected icons
        container.addEventListener('click', function (e) {
            var copyButton = e.target.closest('.copy-button');
            if (!copyButton) { return; }
            var promptContainer = copyButton.closest('.prompt-container');
            if (!promptContainer) { return; }
            var pre = promptContainer.querySelector('pre');
            if (!pre) { return; }
            navigator.clipboard.writeText(pre.innerText).then(function () {
                copyButton.innerHTML = cfg.checkIcon;
                setTimeout(function () {
                    copyButton.innerHTML = cfg.copyIcon;
                }, 2000);
            }).catch(function (err) {
                console.error('Failed to copy text: ', err);
            });
        });

        function setActive(item, parentMainItem) {
            if (cfg.activeMode === 'none') { return; }
            container.querySelectorAll('.main-item, .sub-item').forEach(function (el) {
                el.classList.remove('active');
            });
            item.classList.add('active');
            if (cfg.activeMode === 'parent' && parentMainItem) {
                parentMainItem.classList.add('active');
            }
            if (cfg.hasActiveStrip && contentEl) {
                contentEl.classList.add('has-active');
            }
        }

        function render(navItem, contentHTML) {
            if (isMobileView) {
                var wrapper = navItem.querySelector(':scope > .section-wrapper');
                if (!wrapper) {
                    wrapper = document.createElement('div');
                    wrapper.className = 'section-wrapper';
                    navItem.appendChild(wrapper);
                }

                var shouldShow = !wrapper.classList.contains('active') ||
                    (cfg.freshWrapperCheck && wrapper.innerHTML !== contentHTML);

                container.querySelectorAll('.section-wrapper.active').forEach(function (w) {
                    if (w !== wrapper) { w.classList.remove('active'); }
                });

                wrapper.innerHTML = contentHTML;
                injectCopyButtons();

                if (shouldShow) {
                    setTimeout(function () { wrapper.classList.add('active'); }, 10);
                } else {
                    wrapper.classList.remove('active');
                }
            } else {
                if (contentArea) { contentArea.innerHTML = contentHTML; }
                injectCopyButtons();
            }
        }

        function handleMainItemClick(e) {
            var mainItem = e.currentTarget;
            var navItem = mainItem.closest('.nav-item');
            var section = mainItem.dataset.section;
            var subItemsContainer = navItem.querySelector('.sub-items');

            setActive(mainItem, null);

            if (subItemsContainer) {
                subItemsContainer.classList.toggle('active');

                // units 6-7: "tools" opens its first sub-item instead of own content
                if (cfg.autoOpenFirstSub && section === 'tools') {
                    var firstSubItem = subItemsContainer.querySelector('.sub-item');
                    if (firstSubItem && subItemsContainer.classList.contains('active')) {
                        setTimeout(function () {
                            handleSubItemClick({ currentTarget: firstSubItem, stopPropagation: function () {} });
                        }, 50);
                        return;
                    }
                }
            }

            if (content[section]) {
                render(navItem, content[section]);
            }
            // Completion is handled by course-tracker.js
        }

        function handleSubItemClick(e) {
            e.stopPropagation();
            var subItem = e.currentTarget;
            var section = subItem.dataset.section;
            var navItem = subItem.closest('.nav-item');
            var mainNavItem = navItem.closest('.sub-items').closest('.nav-item');

            setActive(subItem, mainNavItem ? mainNavItem.querySelector('.main-item') : null);

            if (content[section]) {
                render(mainNavItem, content[section]);
            }
            // Completion is handled by course-tracker.js
        }

        function initializeView() {
            container.querySelectorAll('.main-item').forEach(function (header) {
                header.addEventListener('click', handleMainItemClick);
            });
            container.querySelectorAll('.sub-item').forEach(function (header) {
                header.addEventListener('click', handleSubItemClick);
            });

            if (!isMobileView) {
                var defaultItem = container.querySelector('.main-item[data-section="' + cfg.defaultSection + '"]');
                if (defaultItem) { defaultItem.click(); }
            }
        }

        function handleResize() {
            var newIsMobile = window.innerWidth <= 800;
            if (newIsMobile !== isMobileView) {
                isMobileView = newIsMobile;
                container.querySelectorAll('.section-wrapper').forEach(function (w) { w.remove(); });
                if (contentArea) { contentArea.innerHTML = ''; }
                initializeView();
            }
        }

        initializeView();
        injectCopyButtons();
        window.addEventListener('resize', handleResize);
    });
})();
