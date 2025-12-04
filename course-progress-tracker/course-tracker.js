/**
 * Course Progress Tracker - Frontend Tracking Script
 * Tracks user activities: video watches, clicks, scrolls, comments, manual checks
 */

(function() {
    'use strict';

    // Check if tracker data is available
    if (typeof progress_tracker_data === 'undefined') {
        console.error('Course Progress Tracker: No tracker data found. Make sure to enqueue the script with progress_tracker_data.');
        console.error('Make sure you added the code from FUNCTIONS_PHP_CODE.txt to your theme\'s functions.php');
        
        // Show visible error message on page
        const errorDiv = document.createElement('div');
        errorDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #ff4444; color: white; padding: 15px 20px; border-radius: 5px; z-index: 99999; max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.3);';
        errorDiv.innerHTML = '<strong>⚠️ Course Progress Tracker לא נטען!</strong><br><br>הקוד מ-FUNCTIONS_PHP_CODE.txt לא נוסף ל-functions.php או שהתנאי לא מתאים לעמוד זה.';
        document.body.appendChild(errorDiv);
        
        return;
    }
    
    console.log('Course Progress Tracker: Initializing...', progress_tracker_data);
    
    // Success message removed per user request

    const tracker = {
        postId: progress_tracker_data.post_id,
        ajaxUrl: progress_tracker_data.ajax_url,
        nonce: progress_tracker_data.nonce,
        trackedVideos: new Set(),
        trackedClicks: new Set(),
        videoPlayers: {},
        commentCheckInterval: null,
        
        /**
         * Initialize tracking
         */
        init: function() {
            console.log('Course Progress Tracker: Starting initialization...', {
                postId: this.postId,
                ajaxUrl: this.ajaxUrl
            });
            
            if (!this.postId || !this.ajaxUrl) {
                console.error('Course Progress Tracker: Missing required data!', {
                    postId: this.postId,
                    ajaxUrl: this.ajaxUrl
                });
                return;
            }
            
            this.trackVideos();
            this.trackClicks();
            this.trackScroll();
            this.checkComments();
            this.trackManualChecks();
            this.loadProgressIndicators();
            
            // Also restore checkboxes when navigating between sections
            this.restoreCheckboxes();
            
            // Load and restore last position
            this.loadLastPosition();
            
            // Track section changes to save last position
            this.trackSectionChanges();
            
            console.log('Course Progress Tracker: Initialization complete.');
        },

        /**
         * Track YouTube video watches using postMessage
         */
        trackVideos: function() {
            const videoContainers = document.querySelectorAll('iframe[src*="youtube.com"], iframe[src*="youtu.be"]');
            
            console.log('Course Progress Tracker: Found', videoContainers.length, 'video iframes');
            
            videoContainers.forEach((iframe) => {
                const videoId = this.extractVideoId(iframe.src);
                if (!videoId) {
                    console.log('Course Progress Tracker: Could not extract video ID from:', iframe.src);
                    return;
                }

                const sectionId = this.getSectionId(iframe);
                if (!sectionId) {
                    console.log('Course Progress Tracker: Could not determine section ID for video:', videoId);
                    return;
                }

                // Create unique video identifier
                const videoKey = `${sectionId}_${videoId}`;
                
                if (this.trackedVideos.has(videoKey)) {
                    console.log('Course Progress Tracker: Video already tracked:', videoKey);
                    return;
                }
                
                console.log('Course Progress Tracker: Starting to track video:', videoKey, 'in section:', sectionId);
                // Track video using postMessage
                this.trackVideoWithPostMessage(iframe, videoKey, sectionId, videoId);
            });
            
            // Also watch for dynamically loaded videos
            const contentArea = document.querySelector('#content-area');
            if (contentArea) {
                const videoObserver = new MutationObserver(() => {
                    // Re-check for videos when content changes
                    setTimeout(() => {
                        const newVideos = document.querySelectorAll('iframe[src*="youtube.com"], iframe[src*="youtu.be"]');
                        newVideos.forEach((iframe) => {
                            const videoId = this.extractVideoId(iframe.src);
                            if (!videoId) return;
                            
                            const sectionId = this.getSectionId(iframe);
                            if (!sectionId) return;
                            
                            const videoKey = `${sectionId}_${videoId}`;
                            if (!this.trackedVideos.has(videoKey)) {
                                console.log('Course Progress Tracker: Found new video after content change:', videoKey);
                                this.trackVideoWithPostMessage(iframe, videoKey, sectionId, videoId);
                            }
                        });
                    }, 500);
                });
                
                videoObserver.observe(contentArea, {
                    childList: true,
                    subtree: true
                });
            }
        },

        /**
         * Extract YouTube video ID from URL
         */
        extractVideoId: function(url) {
            const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|&v=)([^#&?]*).*/;
            const match = url.match(regExp);
            return (match && match[2].length === 11) ? match[2] : null;
        },

        /**
         * Track video using time-based method (simpler and more reliable)
         */
        trackVideoWithPostMessage: function(iframe, videoKey, sectionId, videoId) {
            let startTime = null;
            let hasTracked = false;
            let visibilityCheck = null;

            // Track when video container becomes visible
            const videoContainer = iframe.closest('.video-container') || iframe.parentElement;
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && !hasTracked) {
                        // Video is visible - start tracking time
                        if (!startTime) {
                            startTime = Date.now();
                            
                            // Check visibility periodically
                            visibilityCheck = setInterval(() => {
                                if (entry.isIntersecting && startTime) {
                                    const timeSpent = (Date.now() - startTime) / 1000;
                                    // Assume 50% watched after 60 seconds of visibility
                                    // (conservative estimate - average video is ~5 minutes)
                                    if (timeSpent >= 60 && !hasTracked) {
                                        console.log('Course Progress Tracker: Video watched for 60 seconds, tracking:', videoKey);
                                        this.trackActivity('video_watch', sectionId, {
                                            video_id: videoId,
                                            progress_percent: 50,
                                            watched_at: new Date().toISOString(),
                                            time_spent: timeSpent,
                                            method: 'visibility_time'
                                        });
                                        this.trackedVideos.add(videoKey);
                                        hasTracked = true;
                                        
                                        // Update progress indicator
                                        this.updateProgressIndicator(sectionId, Math.min(100, (timeSpent / 60) * 50));
                                        
                                        if (visibilityCheck) {
                                            clearInterval(visibilityCheck);
                                        }
                                        observer.disconnect();
                                    }
                                } else if (!entry.isIntersecting && startTime) {
                                    // Video not visible - reset timer
                                    startTime = null;
                                }
                            }, 5000); // Check every 5 seconds
                        }
                    }
                });
            }, {
                threshold: 0.5 // Track when 50% of video is visible
            });

            observer.observe(videoContainer);

            // Also track click as engagement indicator
            iframe.addEventListener('click', () => {
                if (!hasTracked && !startTime) {
                    startTime = Date.now();
                    // Track after 45 seconds if still visible
                    setTimeout(() => {
                        if (!hasTracked && startTime) {
                            console.log('Course Progress Tracker: Video clicked and watched for 45 seconds, tracking:', videoKey);
                            this.trackActivity('video_watch', sectionId, {
                                video_id: videoId,
                                progress_percent: 50,
                                watched_at: new Date().toISOString(),
                                method: 'click_time'
                            });
                            this.trackedVideos.add(videoKey);
                            hasTracked = true;
                            
                            // Update progress indicator
                            this.updateProgressIndicator(sectionId, 50);
                            
                            if (visibilityCheck) {
                                clearInterval(visibilityCheck);
                            }
                            observer.disconnect();
                        }
                    }, 45000);
                }
            });
        },

        /**
         * Track button/link clicks
         */
        trackClicks: function() {
            document.addEventListener('click', (e) => {
                const target = e.target.closest('[data-track-click]');
                if (!target) return;

                const sectionId = this.getSectionId(target);
                if (!sectionId) return;

                const clickKey = `${sectionId}_${target.href || target.textContent}`;
                if (this.trackedClicks.has(clickKey)) return;

                this.trackActivity('button_click', sectionId, {
                    element: target.tagName,
                    text: target.textContent.trim(),
                    href: target.href || null,
                    clicked_at: new Date().toISOString()
                });
                this.trackedClicks.add(clickKey);
            });
        },

        /**
         * Track scroll and time spent
         */
        trackScroll: function() {
            const sections = document.querySelectorAll('[data-track-section]');
            const sectionVisibility = new Map();

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    const sectionId = entry.target.getAttribute('data-track-section');
                    if (!sectionId) return;

                    if (entry.isIntersecting) {
                        if (!sectionVisibility.has(sectionId)) {
                            sectionVisibility.set(sectionId, {
                                startTime: Date.now(),
                                tracked: false
                            });
                        }
                    } else {
                        const visibility = sectionVisibility.get(sectionId);
                        if (visibility && !visibility.tracked) {
                            const timeSpent = (Date.now() - visibility.startTime) / 1000; // seconds
                            if (timeSpent >= 30) { // Track if spent at least 30 seconds
                                this.trackActivity('scroll', sectionId, {
                                    time_spent: timeSpent,
                                    tracked_at: new Date().toISOString()
                                });
                                visibility.tracked = true;
                            }
                        }
                    }
                });
            }, {
                threshold: 0.5 // Track when 50% of section is visible
            });

            sections.forEach(section => {
                observer.observe(section);
            });
        },

        /**
         * Check for WordPress comments periodically
         */
        checkComments: function() {
            // Check immediately
            this.checkCommentStatus();

            // Check every 30 seconds
            this.commentCheckInterval = setInterval(() => {
                this.checkCommentStatus();
            }, 30000);
            
            // Also check when comment form is submitted - use event delegation for dynamic forms
            jQuery(document).on('submit', '#commentform, form.comment-form, .comment-form', (e) => {
                console.log('Course Progress Tracker: Comment form submitted, will check in 5 seconds...');
                // Don't prevent default - let the form submit normally
                setTimeout(() => {
                    this.checkCommentStatus();
                }, 5000);
            });
            
            // Also listen for AJAX comment submission (if using AJAX comments)
            jQuery(document).on('comment-post', () => {
                console.log('Course Progress Tracker: Comment posted via AJAX, checking status...');
                setTimeout(() => {
                    this.checkCommentStatus();
                }, 3000);
            });
            
            // Also check when page loads after comment submission (WordPress redirects)
            if (window.location.hash && window.location.hash.includes('comment')) {
                console.log('Course Progress Tracker: Page loaded with comment hash, checking status...');
                setTimeout(() => {
                    this.checkCommentStatus();
                }, 2000);
            }
            
            // Also check periodically when on discussion section
            const checkDiscussionSection = () => {
                const discussionSection = document.querySelector('[data-track-section*="discussion"], [data-section="discussion"]');
                if (discussionSection) {
                    console.log('Course Progress Tracker: Discussion section detected, checking comment status...');
                    this.checkCommentStatus();
                }
            };
            
            // Check immediately if discussion section is visible
            setTimeout(checkDiscussionSection, 1000);
            
            // Also watch for content changes to detect discussion section
            const contentArea = document.querySelector('#content-area');
            if (contentArea) {
                const discussionObserver = new MutationObserver(() => {
                    setTimeout(checkDiscussionSection, 500);
                });
                discussionObserver.observe(contentArea, {
                    childList: true,
                    subtree: true
                });
            }
        },

        /**
         * Check comment status via AJAX
         */
        checkCommentStatus: function() {
            // Try to find discussion section - check multiple ways
            let discussionSection = document.querySelector('[data-track-section*="discussion"]');
            if (!discussionSection) {
                // Try to find by section ID in content
                discussionSection = document.querySelector('[data-track-section="discussion"]');
            }
            if (!discussionSection) {
                // Try to find in navigation
                const navItem = document.querySelector('[data-section="discussion"]');
                if (navItem) {
                    // Use 'discussion' as section ID
                    this.checkCommentForSection('discussion');
                    return;
                }
                console.log('Course Progress Tracker: No discussion section found');
                return;
            }

            const sectionId = discussionSection.getAttribute('data-track-section') || 'discussion';
            this.checkCommentForSection(sectionId);
        },
        
        /**
         * Check comment for specific section
         */
        checkCommentForSection: function(sectionId) {
            console.log('Course Progress Tracker: Checking comment status for section:', sectionId);
            
            jQuery.ajax({
                url: this.ajaxUrl,
                type: 'GET',
                data: {
                    action: progress_tracker_data.check_comment_action,
                    post_id: this.postId,
                    nonce: this.nonce
                },
                success: (response) => {
                    console.log('Course Progress Tracker: Comment check response', response);
                    if (response.success && response.data.has_comment) {
                        console.log('Course Progress Tracker: Comment found! Tracking activity...');
                        // Check if already tracked in this session
                        const commentKey = `${sectionId}_comment`;
                        if (!this.trackedClicks || !this.trackedClicks.has(commentKey)) {
                            this.trackActivity('comment', sectionId, {
                                checked_at: new Date().toISOString(),
                                post_id: this.postId
                            });
                            // Mark as tracked
                            if (this.trackedClicks) {
                                this.trackedClicks.add(commentKey);
                            }
                            // Update progress indicator
                            this.updateProgressIndicator(sectionId, 100);
                            // Stop checking once comment is found
                            if (this.commentCheckInterval) {
                                clearInterval(this.commentCheckInterval);
                                this.commentCheckInterval = null;
                            }
                        } else {
                            console.log('Course Progress Tracker: Comment already tracked in this session');
                            // Stop checking anyway
                            if (this.commentCheckInterval) {
                                clearInterval(this.commentCheckInterval);
                                this.commentCheckInterval = null;
                            }
                        }
                    } else {
                        console.log('Course Progress Tracker: No comment found yet (has_comment:', response.data?.has_comment, ')');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Course Progress Tracker: Comment check AJAX error', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                }
            });
        },

        /**
         * Track manual checkboxes
         */
        trackManualChecks: function() {
            // Use event delegation to catch dynamically loaded checkboxes
            document.addEventListener('change', (e) => {
                const target = e.target;
                if (target.type !== 'checkbox' || !target.hasAttribute('data-track-manual')) {
                    return;
                }

                if (!target.checked) return;

                const sectionId = this.getSectionId(target);
                if (!sectionId) return;
                
                // Check if already tracked in this session
                const checkKey = `${sectionId}_manual_check`;
                if (this.trackedClicks && this.trackedClicks.has(checkKey)) {
                    console.log('Course Progress Tracker: Manual check already tracked in this session');
                    return;
                }

                console.log('Course Progress Tracker: Manual check triggered', {
                    sectionId: sectionId,
                    postId: this.postId,
                    ajaxUrl: this.ajaxUrl
                });
                
                jQuery.ajax({
                    url: this.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: progress_tracker_data.manual_check_action,
                        post_id: this.postId,
                        section_id: sectionId,
                        nonce: this.nonce
                    },
                    success: (response) => {
                        console.log('Course Progress Tracker: Manual check response', response);
                        if (response.success) {
                            console.log('Course Progress Tracker: Manual check saved successfully!');
                            this.updateProgressIndicator(sectionId, 100);
                            // Mark as tracked
                            if (this.trackedClicks) {
                                this.trackedClicks.add(checkKey);
                            }
                        } else {
                            console.error('Course Progress Tracker: Manual check failed', response);
                            console.error('Course Progress Tracker: Error details:', response.data);
                            console.error('Course Progress Tracker: Full response:', JSON.stringify(response, null, 2));
                        }
                    },
                    error: (xhr, status, error) => {
                        console.error('Course Progress Tracker: Manual check AJAX error', {
                            status: status,
                            error: error,
                            response: xhr.responseText,
                            responseJSON: xhr.responseJSON
                        });
                    }
                });
            });
            
            // Also restore checkboxes when content changes
            this.restoreCheckboxes();
        },
        
        /**
         * Restore checkboxes from saved progress
         */
        restoreCheckboxes: function() {
            // Watch for content area changes
            const contentArea = document.querySelector('#content-area');
            if (contentArea) {
                const observer = new MutationObserver(() => {
                    // When content changes, restore checkboxes after a delay
                    setTimeout(() => {
                        this.loadProgressIndicators();
                    }, 500);
                });
                
                observer.observe(contentArea, {
                    childList: true,
                    subtree: true
                });
            }
        },

        /**
         * Track activity via AJAX
         */
        trackActivity: function(activityType, sectionId, activityData) {
            console.log('Course Progress Tracker: Tracking activity', {
                type: activityType,
                section: sectionId,
                data: activityData,
                post_id: this.postId,
                ajax_url: this.ajaxUrl
            });
            
            // Prepare data for AJAX
            const ajaxData = {
                action: progress_tracker_data.track_action,
                post_id: this.postId,
                section_id: sectionId,
                activity_type: activityType,
                nonce: this.nonce
            };
            
            // Add activity_data if it's an object
            if (activityData && typeof activityData === 'object') {
                ajaxData.activity_data = activityData;
            }
            
            console.log('Course Progress Tracker: Sending AJAX request', ajaxData);
            
            jQuery.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: ajaxData,
                success: (response) => {
                    console.log('Course Progress Tracker: Activity tracked successfully', response);
                    if (response.success) {
                        // Reload progress indicators to get updated progress
                        setTimeout(() => {
                            this.loadProgressIndicators();
                        }, 300);
                    } else {
                        console.error('Course Progress Tracker: Activity tracking failed', response);
                        console.error('Course Progress Tracker: Error details:', response.data);
                        console.error('Course Progress Tracker: Full response:', JSON.stringify(response, null, 2));
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Course Progress Tracker: Activity tracking AJAX error', {
                        status: status,
                        error: error,
                        response: xhr.responseText,
                        responseJSON: xhr.responseJSON,
                        url: this.ajaxUrl,
                        action: progress_tracker_data.track_action,
                        sentData: ajaxData
                    });
                }
            });
        },

        /**
         * Get section ID from element or parent
         */
        getSectionId: function(element) {
            // For iframes, check data-track-video first
            if (element.tagName === 'IFRAME' && element.hasAttribute('data-track-video')) {
                return element.getAttribute('data-track-video');
            }
            
            // Check element itself
            if (element.hasAttribute('data-track-section')) {
                return element.getAttribute('data-track-section');
            }

            // Check parent elements
            let parent = element.parentElement;
            let depth = 0;
            while (parent && depth < 5) {
                if (parent.hasAttribute('data-track-section')) {
                    return parent.getAttribute('data-track-section');
                }
                // Check if parent is in a content-section
                const contentSection = parent.closest('.content-section');
                if (contentSection && contentSection.hasAttribute('data-track-section')) {
                    return contentSection.getAttribute('data-track-section');
                }
                parent = parent.parentElement;
                depth++;
            }
            
            // Last resort: try to find section from navigation
            const activeNavItem = document.querySelector('.main-item.active, .sub-item.active');
            if (activeNavItem) {
                return activeNavItem.getAttribute('data-section');
            }

            return null;
        },

        /**
         * Load and display progress indicators
         */
        loadProgressIndicators: function() {
            console.log('Course Progress Tracker: Loading progress indicators...');
            jQuery.ajax({
                url: this.ajaxUrl,
                type: 'GET',
                data: {
                    action: progress_tracker_data.get_progress_action,
                    post_id: this.postId,
                    nonce: this.nonce
                },
                success: (response) => {
                    console.log('Course Progress Tracker: Progress indicators loaded', response);
                    if (response.success && response.data.progress) {
                        Object.keys(response.data.progress).forEach(sectionId => {
                            const progress = response.data.progress[sectionId].progress;
                            const activities = response.data.progress[sectionId].activities || [];
                            
                            // Update progress indicator
                            this.updateProgressIndicator(sectionId, progress);
                            
                            // Check if this is a task/assignment section with manual check
                            if ((sectionId.includes('task') || sectionId.includes('assignment') || sectionId === 'task' || sectionId === 'assignment') && progress >= 100) {
                                // Use multiple attempts to find checkbox (content might be loaded dynamically)
                                let attempts = 0;
                                const maxAttempts = 5;
                                const checkCheckbox = () => {
                                    attempts++;
                                    // Find and check the checkbox - check all manual checkboxes
                                    const checkboxes = document.querySelectorAll('[data-track-manual]');
                                    console.log(`Course Progress Tracker: Attempt ${attempts} - Looking for checkboxes in section ${sectionId}, Found ${checkboxes.length} checkboxes`);
                                    
                                    let found = false;
                                    checkboxes.forEach(checkbox => {
                                        const checkboxSectionId = this.getSectionId(checkbox);
                                        console.log('Course Progress Tracker: Checkbox section ID:', checkboxSectionId, 'Target:', sectionId);
                                        
                                        if (checkboxSectionId === sectionId) {
                                            checkbox.checked = true;
                                            console.log('Course Progress Tracker: Checkbox restored for section', sectionId);
                                            found = true;
                                        }
                                    });
                                    
                                    // If not found and haven't exceeded max attempts, try again
                                    if (!found && attempts < maxAttempts) {
                                        setTimeout(checkCheckbox, 500);
                                    }
                                };
                                
                                // Start checking
                                setTimeout(checkCheckbox, 100);
                            }
                        });
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Course Progress Tracker: Failed to load progress indicators', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                }
            });
        },

        /**
         * Update progress indicator for a section
         */
        updateProgressIndicator: function(sectionId, progress) {
            // Find nav item for this section - check both main and sub items
            let navItem = document.querySelector(`[data-section="${sectionId}"]`);
            if (!navItem) {
                // Try to find by matching section ID pattern
                const allNavItems = document.querySelectorAll('[data-section]');
                for (let item of allNavItems) {
                    const itemSection = item.getAttribute('data-section');
                    if (itemSection === sectionId || itemSection.indexOf(sectionId) === 0 || sectionId.indexOf(itemSection) === 0) {
                        navItem = item;
                        break;
                    }
                }
            }
            
            if (!navItem) {
                console.log('Course Progress Tracker: Nav item not found for section:', sectionId);
                return;
            }

            // Update completion circle - only show checkmark if 100% complete
            const circle = navItem.querySelector('.completion-circle');
            const navItemContainer = navItem.closest('.nav-item');
            
            if (circle && navItemContainer) {
                // Remove all progress classes first
                navItemContainer.classList.remove('completed', 'in-progress');
                
                // Only add completed class if 100% done
                if (progress >= 100) {
                    navItemContainer.classList.add('completed');
                    // Add checkmark to circle with colored background
                    // Use CSS class instead of inline styles for better control
                    circle.style.background = '';
                    circle.style.borderColor = '';
                    circle.style.color = '';
                    circle.innerHTML = '';
                } else {
                    // Remove completed class and checkmark if not complete
                    navItemContainer.classList.remove('completed');
                    circle.innerHTML = '';
                    circle.style.background = '';
                    circle.style.borderColor = '';
                    circle.style.color = '';
                }
            }
        },
        
        /**
         * Update overall progress bar (if exists in shortcode)
         */
        updateOverallProgressBar: function() {
            // Check if there's a progress bar from shortcode
            const progressBars = document.querySelectorAll('.cpt-progress-item .cpt-progress-bar, .cpt-progress-item [style*="width"]');
            if (progressBars.length > 0) {
                // Reload progress indicators which will update the bars
                this.loadProgressIndicators();
            }
        },

        /**
         * Save last position when user navigates to a section
         */
        saveLastPosition: function(sectionId) {
            if (!sectionId) {
                console.log('Course Progress Tracker: No section ID provided for saving last position');
                return;
            }

            console.log('Course Progress Tracker: Saving last position', {
                postId: this.postId,
                sectionId: sectionId
            });

            const saveAction = progress_tracker_data.save_last_position_action || 'cpt_save_last_position';
            jQuery.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: saveAction,
                    post_id: this.postId,
                    section_id: sectionId,
                    nonce: this.nonce
                },
                success: (response) => {
                    if (response.success) {
                        console.log('Course Progress Tracker: Last position saved successfully');
                    } else {
                        console.error('Course Progress Tracker: Failed to save last position', response);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Course Progress Tracker: Error saving last position', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                }
            });
        },

        /**
         * Load last position and show resume button in progress bar
         */
        loadLastPosition: function() {
            console.log('Course Progress Tracker: Loading last position...');

            const getAction = progress_tracker_data.get_last_position_action || 'cpt_get_last_position';
            
            // First check for last position in all units (to support cross-unit navigation)
            jQuery.ajax({
                url: this.ajaxUrl,
                type: 'GET',
                data: {
                    action: getAction,
                    all_units: 'true',
                    nonce: this.nonce
                },
                success: (response) => {
                    console.log('Course Progress Tracker: Last position response (all units)', response);
                    if (response.success && response.data.section_id) {
                        const data = response.data;
                        console.log('Course Progress Tracker: Found last position:', data);
                        this.showResumeButtonInProgressBar(data);
                    } else {
                        // Fallback: check current unit only
                        this.loadLastPositionForCurrentUnit();
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Course Progress Tracker: Error loading last position (all units)', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    // Fallback: check current unit only
                    this.loadLastPositionForCurrentUnit();
                }
            });
        },

        /**
         * Load last position for current unit only (fallback)
         */
        loadLastPositionForCurrentUnit: function() {
            const getAction = progress_tracker_data.get_last_position_action || 'cpt_get_last_position';
            jQuery.ajax({
                url: this.ajaxUrl,
                type: 'GET',
                data: {
                    action: getAction,
                    post_id: this.postId,
                    nonce: this.nonce
                },
                success: (response) => {
                    console.log('Course Progress Tracker: Last position response (current unit)', response);
                    if (response.success && response.data.section_id) {
                        const sectionId = response.data.section_id;
                        console.log('Course Progress Tracker: Found last position:', sectionId);
                        this.showResumeButtonInProgressBar({
                            post_id: this.postId,
                            section_id: sectionId,
                            post_url: window.location.href
                        });
                    } else {
                        console.log('Course Progress Tracker: No last position found');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Course Progress Tracker: Error loading last position', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                }
            });
        },

        /**
         * Show resume button in progress bar
         */
        showResumeButtonInProgressBar: function(data) {
            // Remove existing resume button if any
            const existingButton = document.querySelector('.cpt-resume-button');
            if (existingButton) {
                existingButton.remove();
            }

            // Find progress bar
            const progressBar = document.querySelector('.progress-bar');
            if (!progressBar) {
                console.log('Course Progress Tracker: Progress bar not found');
                return;
            }

            // Check if we're on the same unit or different unit
            const isCurrentUnit = data.post_id == this.postId;
            let buttonText = '';
            let clickHandler = null;

            if (isCurrentUnit) {
                // Same unit - find section and navigate to it
                const navItem = document.querySelector(`[data-section="${data.section_id}"]`);
                if (!navItem) {
                    console.log('Course Progress Tracker: Could not find nav item for section:', data.section_id);
                    return;
                }
                const sectionName = navItem.querySelector('span')?.textContent || data.section_id;
                buttonText = `📍 המשך מאיפה שהפסקת: ${sectionName}`;
                
                clickHandler = () => {
                    console.log('Course Progress Tracker: Resuming to section:', data.section_id);
                    navItem.click();
                    const contentArea = document.querySelector('#content-area');
                    if (contentArea) {
                        contentArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                };
            } else {
                // Different unit - navigate to that unit
                const unitTitle = data.post_title || `יחידה ${data.post_id}`;
                buttonText = `📍 המשך מאיפה שהפסקת: ${unitTitle}`;
                
                clickHandler = () => {
                    console.log('Course Progress Tracker: Navigating to unit:', data.post_id);
                    if (data.post_url) {
                        window.location.href = data.post_url;
                    }
                };
            }

            // Create resume button
            const resumeButton = document.createElement('div');
            resumeButton.className = 'cpt-resume-button';
            resumeButton.style.cssText = `
                margin-right: 20px;
                padding: 8px 16px;
                background: linear-gradient(45deg, #E74C3C, #27ae60);
                color: white;
                border-radius: 20px;
                cursor: pointer;
                font-weight: bold;
                font-size: 14px;
                white-space: nowrap;
                transition: all 0.3s ease;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
                border: 2px solid #E74C3C;
                display: flex;
                align-items: center;
                gap: 8px;
            `;
            resumeButton.innerHTML = buttonText;
            
            // Add hover effect
            resumeButton.addEventListener('mouseenter', () => {
                resumeButton.style.transform = 'translateY(-2px)';
                resumeButton.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.25)';
            });
            resumeButton.addEventListener('mouseleave', () => {
                resumeButton.style.transform = 'translateY(0)';
                resumeButton.style.boxShadow = '0 2px 6px rgba(0, 0, 0, 0.15)';
            });

            // Add click handler
            resumeButton.addEventListener('click', clickHandler);

            // Insert button in progress bar (before progress indicator)
            const progressIndicator = progressBar.querySelector('.progress-indicator');
            if (progressIndicator) {
                progressBar.insertBefore(resumeButton, progressIndicator);
            } else {
                progressBar.appendChild(resumeButton);
            }
        },

        /**
         * Track section changes to save last position
         */
        trackSectionChanges: function() {
            // Watch for clicks on nav items
            document.addEventListener('click', (e) => {
                const navItem = e.target.closest('.main-item, .sub-item');
                if (navItem && navItem.hasAttribute('data-section')) {
                    const sectionId = navItem.getAttribute('data-section');
                    // Save position after a short delay to ensure section is loaded
                    setTimeout(() => {
                        this.saveLastPosition(sectionId);
                    }, 500);
                }
            });

            // Also watch for content area changes (for dynamic content loading)
            const contentArea = document.querySelector('#content-area');
            if (contentArea) {
                const observer = new MutationObserver(() => {
                    // When content changes, check what section is active
                    const activeNavItem = document.querySelector('.main-item.active, .sub-item.active');
                    if (activeNavItem && activeNavItem.hasAttribute('data-section')) {
                        const sectionId = activeNavItem.getAttribute('data-section');
                        this.saveLastPosition(sectionId);
                    }
                });
                
                observer.observe(contentArea, {
                    childList: true,
                    subtree: true
                });
            }
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => tracker.init());
    } else {
        tracker.init();
    }

})();

