<?php
if (!defined('ABSPATH')) { exit; }

// Add admin bar menu item
function cpt_add_admin_bar_menu($wp_admin_bar) {
    if (!is_user_logged_in()) {
        return;
    }
    
    // Add main menu item
    $wp_admin_bar->add_menu([
        'id' => 'cpt-my-progress',
        'title' => '📊 ההתקדמות שלי',
        'href' => '#',
        'meta' => [
            'class' => 'cpt-progress-menu'
        ]
    ]);
    
    // Get user's progress summary
    $user_id = get_current_user_id();
    global $wpdb;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;
    
    $all_units = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT post_id FROM $activity_table WHERE user_id = %d ORDER BY post_id ASC",
        $user_id
    ));
    
    // Filter out "פרק בדיקה" and sort by unit number
    $filtered_units = [];
    foreach ($all_units as $unit) {
        $post_title = get_the_title($unit->post_id);
        // Skip "פרק בדיקה" - but be more specific to avoid filtering units with "בדיקה" in title
        if (strpos($post_title, 'פרק בדיקה') !== false || 
            (strpos($post_title, 'בדיקה') !== false && strpos($post_title, 'יחידה') === false)) {
            continue;
        }
        // Extract unit number from title
        $unit_number = 999;
        if (preg_match('/יחידה\s*(\d+)/', $post_title, $matches)) {
            $unit_number = intval($matches[1]);
        }
        $filtered_units[] = [
            'post_id' => $unit->post_id,
            'unit_number' => $unit_number,
            'post_title' => $post_title
        ];
    }
    
    // Sort by unit number
    usort($filtered_units, function($a, $b) {
        return $a['unit_number'] <=> $b['unit_number'];
    });
    
    if (!empty($filtered_units)) {
        foreach ($filtered_units as $unit_data) {
            $post_id = $unit_data['post_id'];
            $post_title = $unit_data['post_title'];
            // Remove "פרטי:" prefix if exists
            $post_title = preg_replace('/^פרטי:\s*/', '', $post_title);
            $post_url = get_permalink($post_id);
            
            // Calculate progress for this unit
            $all_sections = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT section_id FROM $activity_table WHERE user_id = %d AND post_id = %d",
                $user_id, $post_id
            ));
            
            // Initialize progress calculation
            $section_progress = [];
            if (!empty($all_sections)) {
                foreach ($all_sections as $section_id) {
                    $section_activities = $wpdb->get_results($wpdb->prepare(
                        "SELECT activity_type, activity_data FROM $activity_table 
                         WHERE user_id = %d AND post_id = %d AND section_id = %s",
                        $user_id, $post_id, $section_id
                    ));
                    $section_progress[$section_id] = cpt_calculate_section_progress($post_id, $section_id, $section_activities);
                }
            }
            
            // Calculate overall percentage (each main section = 25%)
            // Note: task and assignment are the same - count only once
            $main_sections = ['overview', 'tools', 'discussion'];
            $total_progress = 0;
            
            foreach ($main_sections as $main_section) {
                $section_progress_value = 0;
                foreach ($section_progress as $section_id => $progress) {
                    // Match exact or starts with
                    if ($section_id === $main_section || strpos($section_id, $main_section) === 0) {
                        if ($progress > $section_progress_value) {
                            $section_progress_value = $progress;
                        }
                    }
                }
                $total_progress += ($section_progress_value / 100) * 25;
            }
            
            // Handle task/assignment - count only once
            $task_progress_value = 0;
            foreach ($section_progress as $section_id => $progress) {
                if ($section_id === 'task' || $section_id === 'assignment' || 
                    strpos($section_id, 'task') === 0 || strpos($section_id, 'assignment') === 0) {
                    if ($progress > $task_progress_value) {
                        $task_progress_value = $progress;
                    }
                }
            }
            $total_progress += ($task_progress_value / 100) * 25;
            
            // Round to avoid floating point issues
            $total_progress = round($total_progress);
            
            // Determine color based on progress
            $progress_color = $total_progress >= 80 ? '#27ae60' : ($total_progress >= 50 ? '#f39c12' : '#e74c3c');
            
            // Create title with colored progress line
            $title_html = esc_html($post_title) . ' (' . $total_progress . '%)';
            
            // Add progress and color to class name for easier extraction
            $class_name = 'cpt-unit-item cpt-progress-' . $total_progress;
            
            $wp_admin_bar->add_menu([
                'parent' => 'cpt-my-progress',
                'id' => 'cpt-unit-' . $post_id,
                'title' => $title_html,
                'href' => $post_url,
                'meta' => [
                    'html' => false,
                    'class' => $class_name,
                    'title' => 'progress:' . $total_progress
                ]
            ]);
        }
    } else {
        $wp_admin_bar->add_menu([
            'parent' => 'cpt-my-progress',
            'id' => 'cpt-no-progress',
            'title' => 'עוד לא התחלת',
            'href' => '#',
        ]);
    }
}
add_action('admin_bar_menu', 'cpt_add_admin_bar_menu', 100);


// 6. AJAX endpoint for drill-down details (enhanced with activity data)
function cpt_get_user_unit_details_callback() {
    if (!current_user_can('manage_options') || !check_ajax_referer('cpt_admin_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Authentication failed.'], 403);
        return;
    }

    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

    if (!$user_id || !$post_id) {
        wp_send_json_error(['message' => 'Invalid parameters.'], 400);
        return;
    }

    global $wpdb;
    $table_name = CPT_TABLE_NAME;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;

    // Get all distinct sections for this unit
    // Get all sections from activity table (more accurate - only sections with actual activity)
    $all_sections_raw = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT section_id FROM $activity_table WHERE post_id = %d ORDER BY section_id ASC", 
        $post_id
    ));
    
    // Filter out old/invalid sections - only keep valid main sections and their sub-sections
    // Note: intro is treated as overview (some units use 'intro' instead of 'overview')
    $valid_section_prefixes = ['overview', 'intro', 'tools', 'discussion', 'task', 'assignment', 'help_tools', 'inspiration', 'tools_demo', 'tools_intermediaries', 'tools_intro', 'tools_oral', 'tools_ask_me', 'tools_learning_mode', 'tools_document_analysis', 'tools_screen_share', 'tools_presentations', 'tools_infographics', 'tools_diagrams', 'tools_form', 'tools_one_way', 'tools_two_way', 'tools_advanced', 'image-generators', 'image-editing', 'designs', '3d', 'help-tools', 'tools_meetings', 'tools_tasks', 'tools_documents', 'tools_analysis', 'tools_dashboard', 'tools_practice', 'tools_alt_assessment', 'tools_micro_assessment', 'tools_test_build', 'tools_results_analysis', 'tools_resources', 'tools_audio', 'tools_dubbing', 'tools_animation', 'tools_explanation', 'tools_video'];
    $all_sections = [];
    foreach ($all_sections_raw as $section_id) {
        // Check if section matches any valid prefix
        $is_valid = false;
        foreach ($valid_section_prefixes as $prefix) {
            if ($section_id === $prefix || strpos($section_id, $prefix) === 0) {
                $is_valid = true;
                break;
            }
        }
        if ($is_valid) {
            $all_sections[] = $section_id;
        }
    }

    // Get sections completed by this specific user (filtered)
    $completed_sections_raw = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT section_id FROM $activity_table WHERE user_id = %d AND post_id = %d", 
        $user_id, $post_id
    ));
    $completed_sections_filtered = [];
    foreach ($completed_sections_raw as $sec_id) {
        // Only include valid sections
        foreach ($valid_section_prefixes as $prefix) {
            if ($sec_id === $prefix || strpos($sec_id, $prefix) === 0) {
                $completed_sections_filtered[] = $sec_id;
                break;
            }
        }
    }
    $completed_sections = array_flip($completed_sections_filtered);

    // Get activity details for each section (only valid sections)
    $section_activities = [];
    foreach ($all_sections as $section_id) {
        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT activity_type, activity_data, created_at FROM $activity_table 
             WHERE user_id = %d AND post_id = %d AND section_id = %s 
             ORDER BY created_at DESC",
            $user_id, $post_id, $section_id
        ));
        
        $section_activities_obj = [];
        foreach ($activities as $activity) {
            $section_activities_obj[] = (object)[
                'activity_type' => $activity->activity_type,
                'activity_data' => $activity->activity_data,
            ];
        }
        
        $progress = cpt_calculate_section_progress($post_id, $section_id, $section_activities_obj);
        
        $section_activities[$section_id] = [
            'completed' => isset($completed_sections[$section_id]),
            'progress' => $progress,
            'activities' => $activities,
        ];
    }

    $details = [
        'all_sections' => $all_sections,
        'completed_sections' => $completed_sections,
        'section_activities' => $section_activities,
    ];

    wp_send_json_success($details);
}
add_action('wp_ajax_cpt_get_user_unit_details', 'cpt_get_user_unit_details_callback');


// 7. Enqueue scripts and styles for admin page
function cpt_admin_enqueue_scripts($hook = '') {
    // Load admin page scripts and styles only on plugin's admin page
    if (!empty($hook) && 'toplevel_page_course-progress-tracker' === $hook) {
        $plugin_url = CPT_PLUGIN_URL;
        wp_enqueue_style('cpt-admin-style', $plugin_url . 'admin-style.css', [], '2.0.0');
        wp_enqueue_script('cpt-admin-script', $plugin_url . 'admin-script.js', ['jquery'], '2.0.0', true);
        
        // Pass data to script
        wp_localize_script('cpt-admin-script', 'cpt_admin_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cpt_admin_nonce'),
        ]);
    }
    
    // Always load admin bar styles and scripts for logged-in users
    if (is_user_logged_in()) {
        // Add inline CSS for admin bar progress lines
        $admin_bar_css = '
            #wpadminbar .cpt-progress-menu .ab-sub-wrapper .cpt-unit-item,
            #wpadminbar #cpt-my-progress .ab-item,
            #wpadminbar [id^="cpt-unit-"] {
                position: relative !important;
                padding-bottom: 10px !important;
                margin-bottom: 2px !important;
            }
            #wpadminbar .cpt-progress-line-admin {
                position: absolute !important;
                bottom: 0 !important;
                right: 0 !important;
                left: 0 !important;
                height: 3px !important;
                border-radius: 2px !important;
                pointer-events: none !important;
                box-shadow: 0 1px 2px rgba(0,0,0,0.1) !important;
                transition: all 0.3s ease !important;
                z-index: 10 !important;
            }
            #wpadminbar .cpt-unit-item:hover .cpt-progress-line-admin,
            #wpadminbar #cpt-my-progress .ab-item:hover .cpt-progress-line-admin {
                height: 4px !important;
                box-shadow: 0 2px 4px rgba(0,0,0,0.15) !important;
            }
        ';
        wp_add_inline_style('admin-bar', $admin_bar_css);
        
        // Add inline JavaScript to add progress lines to admin bar items
        $admin_bar_js = 'jQuery(document).ready(function($) {
            function addProgressLines() {
                var selectors = [
                    "#wpadminbar .cpt-unit-item",
                    "#wpadminbar #cpt-my-progress .ab-item",
                    "#wpadminbar [id^=\'cpt-unit-\']"
                ];
                
                for (var i = 0; i < selectors.length; i++) {
                    var selector = selectors[i];
                    $(selector).each(function() {
                        var $item = $(this);
                        if ($item.find(".cpt-progress-line-admin").length > 0 || $item.hasClass("cpt-line-added")) {
                            return;
                        }
                        
                        var progress = 0;
                        var classAttr = $item.attr("class") || "";
                        
                        var classMatch = classAttr.match(/cpt-progress-(\d+)/);
                        if (classMatch) {
                            progress = parseInt(classMatch[1]);
                        }
                        
                        if (progress === 0) {
                            var titleAttr = $item.attr("title") || "";
                            var titleMatch = titleAttr.match(/progress:(\d+)/);
                            if (titleMatch) {
                                progress = parseInt(titleMatch[1]);
                            }
                        }
                        
                        if (progress === 0) {
                            progress = parseInt($item.attr("data-progress")) || parseInt($item.data("progress")) || 0;
                        }
                        
                        if (progress === 0) {
                            var textContent = $item.text();
                            var textMatch = textContent.match(/\((\d+)%\)/);
                            if (textMatch) {
                                progress = parseInt(textMatch[1]);
                            }
                        }
                        
                        var color = "#e0e0e0";
                        if (progress >= 80) {
                            color = "#27ae60";
                        } else if (progress >= 50) {
                            color = "#f39c12";
                        } else if (progress > 0) {
                            color = "#e74c3c";
                        }
                        
                        var line = $("<div>").addClass("cpt-progress-line-admin").css({
                            "background": "linear-gradient(to right, " + color + " " + progress + "%, #e0e0e0 " + progress + "%)",
                            "background-size": "100% 100%"
                        });
                        $item.append(line);
                        $item.addClass("cpt-line-added");
                    });
                }
            }
            
            addProgressLines();
            setTimeout(addProgressLines, 300);
            setTimeout(addProgressLines, 600);
            setTimeout(addProgressLines, 1000);
            setTimeout(addProgressLines, 2000);
            
            $(document).on("click", "#wpadminbar #cpt-my-progress, #wpadminbar .cpt-progress-menu", function() {
                setTimeout(addProgressLines, 100);
                setTimeout(addProgressLines, 300);
                setTimeout(addProgressLines, 600);
            });
            
            if (typeof MutationObserver !== "undefined") {
                var observer = new MutationObserver(function(mutations) {
                    addProgressLines();
                });
                var adminBar = document.getElementById("wpadminbar");
                if (adminBar) {
                    observer.observe(adminBar, {
                        childList: true,
                        subtree: true,
                        attributes: true,
                        attributeFilter: ["class"]
                    });
                }
            }
        });';
        wp_add_inline_script('jquery', $admin_bar_js);
    }
}
add_action('admin_enqueue_scripts', 'cpt_admin_enqueue_scripts');

// Separate function for frontend admin bar styles
function cpt_frontend_admin_bar_styles() {
    if (!is_user_logged_in()) {
        return;
    }
    
    // Add inline CSS for admin bar progress lines (frontend)
    $admin_bar_css = '
        #wpadminbar .cpt-progress-menu .ab-sub-wrapper .cpt-unit-item,
        #wpadminbar #cpt-my-progress .ab-item,
        #wpadminbar [id^="cpt-unit-"] {
            position: relative !important;
            padding-bottom: 10px !important;
            margin-bottom: 2px !important;
        }
        #wpadminbar .cpt-progress-line-admin {
            position: absolute !important;
            bottom: 0 !important;
            right: 0 !important;
            left: 0 !important;
            height: 3px !important;
            border-radius: 2px !important;
            pointer-events: none !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1) !important;
            transition: all 0.3s ease !important;
            z-index: 10 !important;
        }
        #wpadminbar .cpt-unit-item:hover .cpt-progress-line-admin,
        #wpadminbar #cpt-my-progress .ab-item:hover .cpt-progress-line-admin {
            height: 4px !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15) !important;
        }
    ';
    wp_add_inline_style('admin-bar', $admin_bar_css);
    
    // Add inline JavaScript to add progress lines to admin bar items (frontend)
    $admin_bar_js = 'jQuery(document).ready(function($) {
        function addProgressLines() {
            var selectors = [
                "#wpadminbar .cpt-unit-item",
                "#wpadminbar #cpt-my-progress .ab-item",
                "#wpadminbar [id^=\'cpt-unit-\']"
            ];
            
            for (var i = 0; i < selectors.length; i++) {
                var selector = selectors[i];
                $(selector).each(function() {
                    var $item = $(this);
                    if ($item.find(".cpt-progress-line-admin").length > 0 || $item.hasClass("cpt-line-added")) {
                        return;
                    }
                    
                    var progress = 0;
                    var classAttr = $item.attr("class") || "";
                    
                    var classMatch = classAttr.match(/cpt-progress-(\d+)/);
                    if (classMatch) {
                        progress = parseInt(classMatch[1]);
                    }
                    
                    if (progress === 0) {
                        var titleAttr = $item.attr("title") || "";
                        var titleMatch = titleAttr.match(/progress:(\d+)/);
                        if (titleMatch) {
                            progress = parseInt(titleMatch[1]);
                        }
                    }
                    
                    if (progress === 0) {
                        progress = parseInt($item.attr("data-progress")) || parseInt($item.data("progress")) || 0;
                    }
                    
                    if (progress === 0) {
                        var textContent = $item.text();
                        var textMatch = textContent.match(/\((\d+)%\)/);
                        if (textMatch) {
                            progress = parseInt(textMatch[1]);
                        }
                    }
                    
                    var color = "#e0e0e0";
                    if (progress >= 80) {
                        color = "#27ae60";
                    } else if (progress >= 50) {
                        color = "#f39c12";
                    } else if (progress > 0) {
                        color = "#e74c3c";
                    }
                    
                    var line = $("<div>").addClass("cpt-progress-line-admin").css({
                        "background": "linear-gradient(to right, " + color + " " + progress + "%, #e0e0e0 " + progress + "%)",
                        "background-size": "100% 100%"
                    });
                    $item.append(line);
                    $item.addClass("cpt-line-added");
                });
            }
        }
        
        addProgressLines();
        setTimeout(addProgressLines, 300);
        setTimeout(addProgressLines, 600);
        setTimeout(addProgressLines, 1000);
        setTimeout(addProgressLines, 2000);
        
        $(document).on("click", "#wpadminbar #cpt-my-progress, #wpadminbar .cpt-progress-menu", function() {
            setTimeout(addProgressLines, 100);
            setTimeout(addProgressLines, 300);
            setTimeout(addProgressLines, 600);
        });
        
        if (typeof MutationObserver !== "undefined") {
            var observer = new MutationObserver(function(mutations) {
                addProgressLines();
            });
            var adminBar = document.getElementById("wpadminbar");
            if (adminBar) {
                observer.observe(adminBar, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ["class"]
                });
            }
        }
    });';
    wp_add_inline_script('jquery', $admin_bar_js);
}
add_action('wp_enqueue_scripts', 'cpt_frontend_admin_bar_styles');


// 7.1 Helper function to enqueue course tracker script
// Note: This function should be called from theme's functions.php, not from here!
// See FUNCTIONS_PHP_CODE.txt for the code to add to your theme's functions.php
// 
// DO NOT uncomment the add_action below - it will cause conflicts!
// Instead, copy the function to your theme's functions.php and customize the condition


// 8. Admin dashboard page
function cpt_admin_menu() {
    add_menu_page(
        'דוח התקדמות בקורס',
        'התקדמות בקורס',
        'manage_options',
        'course-progress-tracker',
        'cpt_admin_page_content',
        'dashicons-chart-line',
        20
    );

    add_submenu_page(
        'course-progress-tracker',
        'ניהול קבוצות לומדים',
        'קבוצות לומדים',
        'manage_options',
        'course-progress-groups',
        'cpt_groups_admin_page_content'
    );
}
add_action('admin_menu', 'cpt_admin_menu');

function cpt_admin_page_content() {
    global $wpdb;
    $table_name = CPT_TABLE_NAME;

    // 1. Get all progress data
    $all_progress = $wpdb->get_results("SELECT user_id, post_id, section_id FROM $table_name");

    if (empty($all_progress)) {
        echo '<div class="wrap"><h1>דוח התקדמות בקורס</h1><p>אין עדיין נתוני התקדמות.</p></div>';
        return;
    }

    // 2. Process data
    $user_progress = [];
    $all_unit_ids = [];
    $total_sections_per_unit = [];

    foreach ($all_progress as $row) {
        $user_id = $row->user_id;
        $post_id = $row->post_id;
        $section_id = $row->section_id;

        if (!isset($user_progress[$user_id])) {
            $user_progress[$user_id] = [];
        }
        if (!isset($user_progress[$user_id][$post_id])) {
            $user_progress[$user_id][$post_id] = [];
        }
        $user_progress[$user_id][$post_id][$section_id] = true;
        $all_unit_ids[$post_id] = true;
    }
    
    // Calculate total sections for each unit based on distinct completed sections across all users
    $totals_results = $wpdb->get_results("SELECT post_id, COUNT(DISTINCT section_id) as total_sections FROM $table_name GROUP BY post_id", OBJECT_K);
     foreach ($totals_results as $post_id => $data) {
        $total_sections_per_unit[$post_id] = (int) $data->total_sections;
    }

    $all_unit_ids = array_keys($all_unit_ids);
    
    // Filter out "פרק בדיקה" and sort by unit number
    $filtered_unit_ids = [];
    foreach ($all_unit_ids as $unit_id) {
        $unit_title = get_the_title($unit_id);
        // Skip "פרק בדיקה" - but be more specific to avoid filtering units with "בדיקה" in title
        if (strpos($unit_title, 'פרק בדיקה') !== false || 
            (strpos($unit_title, 'בדיקה') !== false && strpos($unit_title, 'יחידה') === false)) {
            continue;
        }
        // Extract unit number from title
        $unit_number = 999;
        if (preg_match('/יחידה\s*(\d+)/', $unit_title, $matches)) {
            $unit_number = intval($matches[1]);
        }
        $filtered_unit_ids[] = [
            'id' => $unit_id,
            'number' => $unit_number
        ];
    }
    
    // Sort by unit number
    usort($filtered_unit_ids, function($a, $b) {
        return $a['number'] <=> $b['number'];
    });
    
    $all_unit_ids = array_column($filtered_unit_ids, 'id');

    // Existing learner groups (for filters)
    $existing_groups = $wpdb->get_col(
        "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'cpt_group' AND meta_value <> '' ORDER BY meta_value ASC"
    );
    ?>
    <div class="wrap">
        <h1>דוח התקדמות בקורס - סיכום</h1>
        <style>
            .progress-table { width: 100%; border-collapse: collapse; }
            .progress-table th, .progress-table td { padding: 8px 12px; border: 1px solid #ddd; text-align: right; }
            .progress-table th { background-color: #f2f2f2; }
            .unit-header { writing-mode: vertical-rl; text-orientation: mixed; white-space: nowrap; }
            .cpt-filters { margin: 15px 0 20px; background: #f8f8f8; padding: 10px 12px; border-radius: 4px; }
            .cpt-filters label { font-weight: 600; display: inline-block; margin-bottom: 2px; }
            .cpt-filters .cpt-filters-row { display: flex; flex-wrap: wrap; gap: 12px 18px; align-items: flex-end; }
            .cpt-filters .cpt-filter-field { min-width: 190px; }
            .cpt-filters input[type="text"],
            .cpt-filters select { max-width: 230px; }
            .cpt-user-registered { display: inline-block; font-size: 11px; color: #666; }
            .cpt-group-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-left: 6px; vertical-align: middle; }
        </style>

        <div class="cpt-filters">
            <div class="cpt-filters-row">
                <div class="cpt-filter-field">
                    <label for="cpt-user-search">חיפוש לפי שם / מייל</label><br />
                    <input type="text" id="cpt-user-search" class="regular-text" placeholder="התחילי להקליד שם, מייל או משתמש/ת" />
                </div>
                <div class="cpt-filter-field">
                    <label for="cpt-suffix-filter">סיומת שם משתמש (למשל MI)</label><br />
                    <input type="text" id="cpt-suffix-filter" class="regular-text" placeholder="למשל MI, TD וכדומה" />
                </div>
                <div class="cpt-filter-field">
                    <label>טווח תאריכי הרשמה</label><br />
                    <input type="text" id="cpt-date-from" placeholder="למשל 24.1.2026" /> –
                    <input type="text" id="cpt-date-to" placeholder="למשל 31.1.2026" />
                </div>
                <div class="cpt-filter-field">
                    <label for="cpt-group-filter">קבוצת לומדים</label><br />
                    <select id="cpt-group-filter">
                        <option value="all">כל הקבוצות</option>
                        <option value="none">ללא קבוצה</option>
                        <?php if (!empty($existing_groups)) : ?>
                            <?php foreach ($existing_groups as $group_name) : ?>
                                <option value="<?php echo esc_attr($group_name); ?>"><?php echo esc_html($group_name); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="cpt-filter-field">
                    <label for="cpt-sort-users">מיון משתמשים</label><br />
                    <select id="cpt-sort-users">
                        <option value="default">ללא (לפי סדר ברירת מחדל)</option>
                        <option value="name-asc">שם א-ב</option>
                        <option value="name-desc">שם ת-א</option>
                        <option value="date-asc">תאריך הרשמה מהקודם לחדש</option>
                        <option value="date-desc">תאריך הרשמה מהחדש לקודם</option>
                    </select>
                </div>
                <div class="cpt-filter-field">
                    <button type="button" class="button" id="cpt-clear-filters">איפוס מסננים</button>
                </div>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped progress-table">
            <thead>
                <tr>
                    <th>משתמש/ת</th>
                    <?php foreach ($all_unit_ids as $unit_id): 
                        $unit_title = get_the_title($unit_id);
                        $unit_title = preg_replace('/^פרטי:\s*/', '', $unit_title);
                    ?>
                        <th class="unit-header"><?php echo esc_html($unit_title); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($user_progress as $user_id => $units): ?>
                    <?php
                    $user = get_userdata($user_id);
                    $display_name = $user ? $user->display_name : 'ID ' . $user_id;
                    $user_login = $user ? $user->user_login : '';
                    $user_email = $user ? $user->user_email : '';
                    $user_registered_raw = $user && !empty($user->user_registered) ? substr($user->user_registered, 0, 10) : '';
                    $user_registered_display = $user_registered_raw ? date_i18n('d.m.Y', strtotime($user_registered_raw)) : '';
                    $user_group = $user ? get_user_meta($user_id, 'cpt_group', true) : '';
                    ?>
                    <tr class="cpt-user-row"
                        data-user-id="<?php echo esc_attr($user_id); ?>"
                        data-user-name="<?php echo esc_attr($display_name); ?>"
                        data-user-login="<?php echo esc_attr($user_login); ?>"
                        data-user-email="<?php echo esc_attr($user_email); ?>"
                        data-user-registered="<?php echo esc_attr($user_registered_raw); ?>"
                        data-user-group="<?php echo esc_attr($user_group); ?>">
                        <td>
                            <?php if ($user_group): ?>
                                <span class="cpt-group-dot" style="background-color:<?php echo esc_attr(cpt_group_color($user_group)); ?>;" title="<?php echo esc_attr($user_group); ?>"></span>
                            <?php endif; ?>
                            <?php echo esc_html($display_name); ?>
                        </td>
                        <?php foreach ($all_unit_ids as $unit_id): ?>
                            <td class="progress-cell" data-user-id="<?php echo esc_attr($user_id); ?>" data-post-id="<?php echo esc_attr($unit_id); ?>" title="לחץ לפירוט">
                                <?php
                                // Calculate progress based on activities, not just completed sections
                                $activity_table = CPT_ACTIVITY_TABLE_NAME;
                                $user_activities = $wpdb->get_results($wpdb->prepare(
                                    "SELECT section_id, activity_type, activity_data FROM $activity_table WHERE user_id = %d AND post_id = %d",
                                    $user_id, $unit_id
                                ));
                                
                                // Group by section
                                $section_activities_map = [];
                                foreach ($user_activities as $activity) {
                                    $sec_id = $activity->section_id;
                                    if (!isset($section_activities_map[$sec_id])) {
                                        $section_activities_map[$sec_id] = [];
                                    }
                                    $section_activities_map[$sec_id][] = (object)[
                                        'activity_type' => $activity->activity_type,
                                        'activity_data' => $activity->activity_data,
                                    ];
                                }
                                
                                // Calculate progress for each section
                                // Each unit has exactly 4 main sections: overview, tools, discussion, task/assignment
                                // Each section is worth 25%
                                // Note: task and assignment are the same - count only once
                                $main_sections = ['overview', 'tools', 'discussion'];
                                $total_progress = 0;
                                
                                foreach ($main_sections as $main_section) {
                                    // Find all sections that belong to this main section
                                    $section_progress_value = 0;
                                    foreach ($section_activities_map as $sec_id => $sec_activities) {
                                        // Check if section_id matches the main section (including sub-sections)
                                        if ($sec_id === $main_section || strpos($sec_id, $main_section) === 0) {
                                            $progress = cpt_calculate_section_progress($unit_id, $sec_id, $sec_activities);
                                            // Use the highest progress for this main section
                                            if ($progress > $section_progress_value) {
                                                $section_progress_value = $progress;
                                            }
                                        }
                                    }
                                    // Each main section contributes 25% (0-100% of that 25%)
                                    $total_progress += ($section_progress_value / 100) * 25;
                                }
                                
                                // Handle task/assignment - count only once
                                $task_progress_value = 0;
                                foreach ($section_activities_map as $sec_id => $sec_activities) {
                                    if ($sec_id === 'task' || $sec_id === 'assignment' || 
                                        strpos($sec_id, 'task') === 0 || strpos($sec_id, 'assignment') === 0) {
                                        $sec_progress = cpt_calculate_section_progress($unit_id, $sec_id, $sec_activities);
                                        if ($sec_progress > $task_progress_value) {
                                            $task_progress_value = $sec_progress;
                                        }
                                    }
                                }
                                $total_progress += ($task_progress_value / 100) * 25;
                                
                                $percentage = round($total_progress); // Already calculated as percentage (0-100)
                                echo cpt_render_progress_bar($percentage);
                                ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Modal for details -->
        <div id="cpt-details-modal" style="display:none;">
            <div id="cpt-modal-content">
                <button id="cpt-modal-close">&times;</button>
                <h3 id="cpt-modal-title">פירוט התקדמות</h3>
                <div id="cpt-modal-body"></div>
            </div>
            <div id="cpt-modal-backdrop"></div>
        </div>

        <script>
        (function($) {
            $(function() {
                var $table = $('.progress-table');
                var $tbody = $table.find('tbody');

                if (!$tbody.length) {
                    return;
                }

                var originalOrder = $tbody.find('tr').toArray();

                function cptParseHebrewDate(str) {
                    if (!str) return '';
                    str = ('' + str).trim();
                    if (!str) return '';
                    str = str.replace(/[-]/g, '.').replace(/\//g, '.');
                    var parts = str.split('.');
                    if (parts.length < 3) return '';
                    var d = (parts[0] || '').trim();
                    var m = (parts[1] || '').trim();
                    var y = (parts[2] || '').trim();
                    if (!d || !m || !y) return '';
                    if (y.length === 2) {
                        y = (parseInt(y, 10) >= 70 ? '19' : '20') + y;
                    }
                    if (d.length === 1) d = '0' + d;
                    if (m.length === 1) m = '0' + m;
                    return y + '-' + m + '-' + d;
                }

                function applyFiltersAndSort() {
                    var search = ($('#cpt-user-search').val() || '').toLowerCase().trim();
                    var suffix = ($('#cpt-suffix-filter').val() || '').toLowerCase().trim();
                    var sort = $('#cpt-sort-users').val() || 'default';
                    var dateFromRaw = $('#cpt-date-from').val() || '';
                    var dateToRaw = $('#cpt-date-to').val() || '';
                    var dateFrom = cptParseHebrewDate(dateFromRaw) || '';
                    var dateTo = cptParseHebrewDate(dateToRaw) || '';
                    var groupFilter = ($('#cpt-group-filter').val() || 'all').toLowerCase();

                    var rows = originalOrder.slice();

                    rows = rows.filter(function(row) {
                        var $row = $(row);
                        var name = (($row.data('user-name') || '') + '').toLowerCase();
                        var login = (($row.data('user-login') || '') + '').toLowerCase();
                        var email = (($row.data('user-email') || '') + '').toLowerCase();
                        var registered = ($row.data('user-registered') || '') + '';
                        var group = (($row.data('user-group') || '') + '').toLowerCase();

                        if (search) {
                            if (name.indexOf(search) === -1 &&
                                login.indexOf(search) === -1 &&
                                email.indexOf(search) === -1) {
                                return false;
                            }
                        }

                        if (suffix) {
                            var s = suffix;
                            var matchesSuffix = false;

                            if (name.endsWith(s) || login.endsWith(s)) {
                                matchesSuffix = true;
                            }

                            if (!matchesSuffix) {
                                return false;
                            }
                        }

                        if (groupFilter && groupFilter !== 'all') {
                            if (groupFilter === 'none') {
                                if (group) {
                                    return false;
                                }
                            } else {
                                if (group !== groupFilter) {
                                    return false;
                                }
                            }
                        }

                        if (dateFrom || dateTo) {
                            if (!registered) {
                                return false;
                            }
                            if (dateFrom && registered < dateFrom) {
                                return false;
                            }
                            if (dateTo && registered > dateTo) {
                                return false;
                            }
                        }

                        return true;
                    });

                    if (sort && sort !== 'default') {
                        rows.sort(function(a, b) {
                            var $a = $(a);
                            var $b = $(b);
                            var nameA = (($a.data('user-name') || '') + '').toLowerCase();
                            var nameB = (($b.data('user-name') || '') + '').toLowerCase();
                            var dateA = ($a.data('user-registered') || '') + '';
                            var dateB = ($b.data('user-registered') || '') + '';

                            switch (sort) {
                                case 'name-asc':
                                    return nameA.localeCompare(nameB, 'he');
                                case 'name-desc':
                                    return nameB.localeCompare(nameA, 'he');
                                case 'date-asc':
                                    return dateA.localeCompare(dateB);
                                case 'date-desc':
                                    return dateB.localeCompare(dateA);
                                default:
                                    return 0;
                            }
                        });
                    }

                    $tbody.empty();
                    rows.forEach(function(row) {
                        $tbody.append(row);
                    });
                }

                $('#cpt-user-search, #cpt-suffix-filter').on('input', function() {
                    applyFiltersAndSort();
                });

                $('#cpt-date-from, #cpt-date-to, #cpt-sort-users, #cpt-group-filter').on('change', function() {
                    applyFiltersAndSort();
                });

                $('#cpt-clear-filters').on('click', function() {
                    $('#cpt-user-search').val('');
                    $('#cpt-suffix-filter').val('');
                    $('#cpt-date-from').val('');
                    $('#cpt-date-to').val('');
                    $('#cpt-group-filter').val('all');
                    $('#cpt-sort-users').val('default');
                    applyFiltersAndSort();
                });
            });
        })(jQuery);
        </script>

    </div>
    <?php
}

/**
 * Admin page: learner groups management
 */
function cpt_groups_admin_page_content() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;

    $message = '';
    $error = '';

    if (!empty($_POST['cpt_group_action']) && $_POST['cpt_group_action'] === 'assign') {
        check_admin_referer('cpt_assign_group', 'cpt_group_nonce');

        $group_name = isset($_POST['cpt_group_name']) ? sanitize_text_field(wp_unslash($_POST['cpt_group_name'])) : '';
        $user_ids = isset($_POST['cpt_user_ids']) ? (array) $_POST['cpt_user_ids'] : [];
        $user_ids = array_filter(array_map('intval', $user_ids));

        if (empty($user_ids)) {
            $error = 'לא נבחרו משתמשים.';
        } else {
            if ($group_name === '') {
                foreach ($user_ids as $user_id) {
                    delete_user_meta($user_id, 'cpt_group');
                }
                $message = 'הקבוצה הוסרה מהמשתמשים שנבחרו.';
            } else {
                foreach ($user_ids as $user_id) {
                    update_user_meta($user_id, 'cpt_group', $group_name);
                }
                $message = 'המשתמשים שנבחרו שובצו לקבוצה "' . esc_html($group_name) . '".';
            }
        }
    }

    // משתמשים: מי שיש להם התקדמות (course_progress / course_activity) + לומדים לפי תפקיד
    $progress_table = CPT_TABLE_NAME;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;
    $user_ids_from_progress = $wpdb->get_col("SELECT DISTINCT user_id FROM $progress_table");
    $user_ids_from_activity = $wpdb->get_col("SELECT DISTINCT user_id FROM $activity_table");
    $user_ids = array_filter(array_map('intval', array_unique(array_merge(
        (array) $user_ids_from_progress,
        (array) $user_ids_from_activity
    ))));

    $role_slugs = [];
    $role_slug = function_exists('car_resolve_target_role') ? car_resolve_target_role() : '';
    if ($role_slug) {
        $role_slugs[] = $role_slug;
    }
    if (get_role('learner') && !in_array('learner', $role_slugs)) {
        $role_slugs[] = 'learner';
    }
    if (!empty($role_slugs)) {
        $learner_ids = array_map('intval', (array) get_users([
            'role__in' => $role_slugs,
            'fields'   => 'ID',
            'number'   => 5000,
        ]));
        $user_ids = array_unique(array_merge($user_ids, $learner_ids));
    }

    $users = [];
    if (!empty($user_ids)) {
        $users = get_users([
            'include' => array_values($user_ids),
            'orderby' => 'registered',
            'order'   => 'ASC',
            'number'  => 5000,
        ]);
    }

    // Existing groups for filters and suggestions
    $existing_groups = $wpdb->get_col(
        "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'cpt_group' AND meta_value <> '' ORDER BY meta_value ASC"
    );
    ?>
    <div class="wrap">
        <h1>ניהול קבוצות לומדים</h1>

        <?php if ($message): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <p>בעמוד זה אפשר לבחור לומדים, לתת להם שם קבוצה קבוע (למשל "MI ינואר 2026") ולסמן/לשנות את השיוך שלהם.</p>

        <style>
            .cpt-filters { margin: 15px 0 20px; background: #f8f8f8; padding: 10px 12px; border-radius: 4px; }
            .cpt-filters label { font-weight: 600; display: inline-block; margin-bottom: 2px; }
            .cpt-filters .cpt-filters-row { display: flex; flex-wrap: wrap; gap: 12px 18px; align-items: flex-end; }
            .cpt-filters .cpt-filter-field { min-width: 190px; }
            .cpt-filters input[type="text"],
            .cpt-filters select { max-width: 230px; }
            .cpt-user-registered { display: inline-block; font-size: 11px; color: #666; }
            .cpt-groups-table th.check-column { width: 40px; }
            .cpt-groups-table th.cpt-sortable { cursor: pointer; }
            .cpt-groups-table th.cpt-sortable:hover { background: #e8e8e8; }
            .cpt-groups-table th.cpt-sortable:after { content: ' ⇅'; opacity: 0.4; font-size: 0.9em; }
            .cpt-groups-table th.cpt-sort-asc:after { content: ' ↑'; opacity: 1; }
            .cpt-groups-table th.cpt-sort-desc:after { content: ' ↓'; opacity: 1; }
        </style>

        <div class="cpt-filters">
            <div class="cpt-filters-row">
                <div class="cpt-filter-field">
                    <label for="cpt-group-user-search">חיפוש לפי שם / מייל</label><br />
                    <input type="text" id="cpt-group-user-search" class="regular-text" placeholder="התחילי להקליד שם, מייל או משתמש/ת" />
                </div>
                <div class="cpt-filter-field">
                    <label for="cpt-group-suffix-filter">סיומת שם משתמש (למשל MI)</label><br />
                    <input type="text" id="cpt-group-suffix-filter" class="regular-text" placeholder="למשל MI, TD וכדומה" />
                </div>
                <div class="cpt-filter-field">
                    <label>טווח תאריכי הרשמה</label><br />
                    <input type="text" id="cpt-group-date-from" placeholder="למשל 24.1.2026" /> –
                    <input type="text" id="cpt-group-date-to" placeholder="למשל 31.1.2026" />
                </div>
                <div class="cpt-filter-field">
                    <label for="cpt-group-existing-filter">סינון לפי קבוצה</label><br />
                    <select id="cpt-group-existing-filter">
                        <option value="none" selected="selected">ללא קבוצה (ברירת מחדל)</option>
                        <option value="all">כל הקבוצות</option>
                        <?php if (!empty($existing_groups)) : ?>
                            <?php foreach ($existing_groups as $group_name) : ?>
                                <option value="<?php echo esc_attr($group_name); ?>"><?php echo esc_html($group_name); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="cpt-filter-field">
                    <button type="button" class="button" id="cpt-group-clear-filters">איפוס מסננים</button>
                    <button type="button" class="button" id="cpt-group-show-all">הצג את כל המשתמשים</button>
                </div>
            </div>
        </div>

        <form method="post">
            <?php wp_nonce_field('cpt_assign_group', 'cpt_group_nonce'); ?>
            <input type="hidden" name="cpt_group_action" value="assign" />

            <h2>שיוך לקבוצה</h2>
            <p>
                <label for="cpt-group-name">שם קבוצה לשיוך</label><br />
                <input type="text" id="cpt-group-name" name="cpt_group_name" class="regular-text" list="cpt-group-name-list" placeholder="למשל MI ינואר 2026" />
                <datalist id="cpt-group-name-list">
                    <?php if (!empty($existing_groups)) : ?>
                        <?php foreach ($existing_groups as $group_name) : ?>
                            <option value="<?php echo esc_attr($group_name); ?>"></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </datalist>
            </p>
            <p class="description">
                אפשר לבחור שם קבוצה חדש, או לבחור אחד קיים מהרשימה. אם משאירים את השדה ריק ומשייכים משתמשים – השיוך שלהם יימחק (ללא קבוצה).
            </p>

            <h2>בחירת משתמשים</h2>

            <table class="wp-list-table widefat fixed striped cpt-groups-table">
                <thead>
                    <tr>
                        <td class="manage-column check-column"><input type="checkbox" id="cpt-group-select-all" /></td>
                        <th class="cpt-sortable" data-sort="user-name">משתמש/ת</th>
                        <th class="cpt-sortable" data-sort="user-login">שם משתמש</th>
                        <th>אימייל</th>
                        <th class="cpt-sortable" data-sort="user-registered">תאריך הרשמה</th>
                        <th class="cpt-sortable" data-sort="user-group">קבוצה נוכחית</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($users)) : ?>
                        <?php foreach ($users as $user) : ?>
                            <?php
                            $user_id = $user->ID;
                            $display_name = $user->display_name;
                            $user_login = $user->user_login;
                            $user_email = $user->user_email;
                            $user_registered_raw = !empty($user->user_registered) ? substr($user->user_registered, 0, 10) : '';
                            $user_registered_display = $user_registered_raw ? date_i18n('d.m.Y', strtotime($user_registered_raw)) : '';
                            $user_group = get_user_meta($user_id, 'cpt_group', true);
                            ?>
                            <tr class="cpt-groups-user-row"
                                data-user-id="<?php echo esc_attr($user_id); ?>"
                                data-user-name="<?php echo esc_attr($display_name); ?>"
                                data-user-login="<?php echo esc_attr($user_login); ?>"
                                data-user-email="<?php echo esc_attr($user_email); ?>"
                                data-user-registered="<?php echo esc_attr($user_registered_raw); ?>"
                                data-user-group="<?php echo esc_attr($user_group); ?>">
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="cpt_user_ids[]" value="<?php echo esc_attr($user_id); ?>" />
                                </th>
                                <td>
                                    <?php echo esc_html($display_name); ?>
                                </td>
                                <td><?php echo esc_html($user_login); ?></td>
                                <td><?php echo esc_html($user_email); ?></td>
                                <td>
                                    <?php if ($user_registered_display): ?>
                                        <span class="cpt-user-registered"><?php echo esc_html($user_registered_display); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $user_group ? esc_html($user_group) : '—'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="6">לא נמצאו לומדים להצגה.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <p>
                <button type="submit" class="button button-primary">שייך קבוצה למשתמשים שנבחרו</button>
                <button type="button" class="button" id="cpt-group-clear-assignment">נקה שיוך למשתלמים שנבחרו</button>
            </p>
        </form>

        <script>
        (function($) {
            $(function() {
                var $tbody = $('.cpt-groups-table tbody');
                if (!$tbody.length) {
                    return;
                }

                var originalRows = $tbody.find('tr').toArray();
                var sortState = { column: null, dir: 1 };

                function applySort() {
                    if (!sortState.column) {
                        return;
                    }
                    var col = sortState.column;
                    var dir = sortState.dir;
                    originalRows.sort(function(a, b) {
                        var $a = $(a);
                        var $b = $(b);
                        var valA = (($a.attr('data-' + col) || '') + '').toLowerCase();
                        var valB = (($b.attr('data-' + col) || '') + '').toLowerCase();
                        if (col === 'user-name' || col === 'user-login' || col === 'user-group') {
                            return dir * (valA.localeCompare(valB, 'he'));
                        }
                        if (col === 'user-registered') {
                            valA = $a.attr('data-' + col) || '';
                            valB = $b.attr('data-' + col) || '';
                            return dir * ((valA || '').localeCompare(valB || ''));
                        }
                        return 0;
                    });
                    $tbody.empty();
                    originalRows.forEach(function(row) {
                        $tbody.append(row);
                    });
                }

                function cptParseHebrewDate(str) {
                    if (!str) return '';
                    str = ('' + str).trim();
                    if (!str) return '';
                    str = str.replace(/[-]/g, '.').replace(/\//g, '.');
                    var parts = str.split('.');
                    if (parts.length < 3) return '';
                    var d = (parts[0] || '').trim();
                    var m = (parts[1] || '').trim();
                    var y = (parts[2] || '').trim();
                    if (!d || !m || !y) return '';
                    if (y.length === 2) {
                        y = (parseInt(y, 10) >= 70 ? '19' : '20') + y;
                    }
                    if (d.length === 1) d = '0' + d;
                    if (m.length === 1) m = '0' + m;
                    return y + '-' + m + '-' + d;
                }

                function applyGroupFilters() {
                    var search = ($('#cpt-group-user-search').val() || '').toLowerCase().trim();
                    var suffix = ($('#cpt-group-suffix-filter').val() || '').toLowerCase().trim();
                    var dateFromRaw = $('#cpt-group-date-from').val() || '';
                    var dateToRaw = $('#cpt-group-date-to').val() || '';
                    var dateFrom = cptParseHebrewDate(dateFromRaw) || '';
                    var dateTo = cptParseHebrewDate(dateToRaw) || '';
                    var groupFilter = ($('#cpt-group-existing-filter').val() || 'all').toLowerCase();

                    var rows = originalRows.slice();

                    rows.forEach(function(row) {
                        var $row = $(row);
                        var name = (($row.data('user-name') || '') + '').toLowerCase();
                        var login = (($row.data('user-login') || '') + '').toLowerCase();
                        var email = (($row.data('user-email') || '') + '').toLowerCase();
                        var registered = ($row.data('user-registered') || '') + '';
                        var group = (($row.data('user-group') || '') + '').toLowerCase();

                        var visible = true;

                        if (search) {
                            if (name.indexOf(search) === -1 &&
                                login.indexOf(search) === -1 &&
                                email.indexOf(search) === -1) {
                                visible = false;
                            }
                        }

                        if (visible && suffix) {
                            var s = suffix;
                            var matchesSuffix = false;
                            if (name.endsWith(s) || login.endsWith(s)) {
                                matchesSuffix = true;
                            }
                            if (!matchesSuffix) {
                                visible = false;
                            }
                        }

                        if (visible && groupFilter && groupFilter !== 'all') {
                            if (groupFilter === 'none') {
                                if (group) {
                                    visible = false;
                                }
                            } else {
                                if (group !== groupFilter) {
                                    visible = false;
                                }
                            }
                        }

                        if (visible && (dateFrom || dateTo)) {
                            if (!registered) {
                                visible = false;
                            } else {
                                if (dateFrom && registered < dateFrom) {
                                    visible = false;
                                }
                                if (dateTo && registered > dateTo) {
                                    visible = false;
                                }
                            }
                        }

                        $row.toggle(visible);
                        if (!visible) {
                            $row.find('input[type="checkbox"][name="cpt_user_ids[]"]').prop('checked', false);
                        }
                    });
                }

                $('#cpt-group-user-search, #cpt-group-suffix-filter').on('input', function() {
                    applyGroupFilters();
                });

                $('#cpt-group-date-from, #cpt-group-date-to, #cpt-group-existing-filter').on('change', function() {
                    applyGroupFilters();
                });

                $('#cpt-group-clear-filters').on('click', function() {
                    $('#cpt-group-user-search').val('');
                    $('#cpt-group-suffix-filter').val('');
                    $('#cpt-group-date-from').val('');
                    $('#cpt-group-date-to').val('');
                    $('#cpt-group-existing-filter').val('all');
                    applyGroupFilters();
                });

                $('#cpt-group-show-all').on('click', function() {
                    $('#cpt-group-existing-filter').val('all');
                    applyGroupFilters();
                });

                $('#cpt-group-clear-assignment').on('click', function() {
                    $('#cpt-group-name').val('');
                    $(this).closest('form').submit();
                });

                $('#cpt-group-select-all').on('change', function() {
                    var checked = $(this).is(':checked');
                    $tbody.find('tr:visible input[type="checkbox"][name="cpt_user_ids[]"]').prop('checked', checked);
                });

                $('.cpt-groups-table th.cpt-sortable').on('click', function() {
                    var col = $(this).attr('data-sort');
                    if (!col) return;
                    if (sortState.column === col) {
                        sortState.dir = sortState.dir === 1 ? -1 : 1;
                    } else {
                        sortState.column = col;
                        sortState.dir = 1;
                    }
                    $('.cpt-groups-table th.cpt-sortable').removeClass('cpt-sort-asc cpt-sort-desc');
                    $(this).addClass(sortState.dir === 1 ? 'cpt-sort-asc' : 'cpt-sort-desc');
                    applySort();
                });

                // מצב ברירת מחדל: להציג רק "ללא קבוצה"
                applyGroupFilters();
            });
        })(jQuery);
        </script>
    </div>
    <?php
}

/* 
 * =====================================================================================
 *  הוראות שימוש חשובות - גרסה 2.0.0
 * =====================================================================================
 * 
 * כדי שהתוסף יעבוד, יש "להזריק" נתונים לתוך הסקריפט שרץ ביחידות ה-HTML.
 * הדרך המומלצת לעשות זאת בוורדפרס היא להוסיף את הקוד הבא לקובץ `functions.php`
 * של תבנית העיצוב שלך (או תבנית בת).
 *
 * הקוד בודק אם המשתמש צופה בעמוד ספציפי (יש להתאים את התנאי), ורק אז
 * הוא טוען את סקריפט המעקב `course-tracker.js` ויוצר אובייקט JavaScript בשם 
 * `progress_tracker_data` שיהיה זמין לסקריפט.
 *
 * יש להתאים את התנאי `is_page('slug-of-your-course-page')` כך שיתאים לעמודים
 * שבהם מוטמע תוכן הקורס.
 *
 * חשוב: יש להוסיף attributes למעקב ביחידות ה-HTML:
 * - data-track-section על כל content-section
 * - data-track-video על כל iframe YouTube
 * - data-track-click על כפתורים חשובים
 * - data-track-manual על תיבת סימון בפרק המשימה
 *
 * ראה קובץ TRACKING_IMPLEMENTATION_GUIDE.md להנחיות מפורטות.
*/

// טעינת סקריפט המעקב מתוך התוסף (פועל גם בלי קוד ב-functions.php)
