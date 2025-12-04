# הנחיות להחלת מעקב התקדמות על יחידות נוספות

## הקדמה

מסמך זה מכיל הוראות שלב אחר שלב להחלת מערכת המעקב על יחידות HTML נוספות. המערכת עוקבת אחר פעילויות אמיתיות של הלומדים: צפייה בסרטונים, קליקים על כפתורים, גלילה ושהייה, תגובות בדיון, וסימון ידני.

## דרישות מוקדמות

1. התוסף `course-progress-tracker` מותקן ופעיל ב-WordPress
2. הקובץ `course-tracker.js` נמצא בתיקיית התוסף
3. יש גישה לעריכת קבצי ה-HTML של היחידות

## שלב 1: הוספת data-track-section לכל content-section

לכל `<div class="content-section">` יש להוסיף את ה-attribute `data-track-section` עם ערך המתאים ל-section_id של הסקציה.

### דוגמה:

```html
<!-- לפני -->
<div class="content-section">
    <h1>מבט על</h1>
    ...

<!-- אחרי -->
<div class="content-section" data-track-section="overview">
    <h1>מבט על</h1>
    ...
```

### רשימת sections נפוצים:

- `overview` - פרק מבט על
- `tools` - פרק כלים כללי
- `tools_demo` - סרטוני הדגמה של כלים
- `tools_intermediaries` - מתווכי בינה
- `help_tools` - כלי עזר
- `inspiration` - השראה
- `discussion` - דיון
- `task` או `assignment` - משימה

**חשוב:** הערך של `data-track-section` צריך להתאים ל-`data-section` של הפריט בתפריט הניווט.

## שלב 2: הוספת data-track-video לכל iframe YouTube

לכל `<iframe>` שמכיל סרטון YouTube יש להוסיף את ה-attribute `data-track-video` עם ערך המתאים ל-section_id.

### דוגמה:

```html
<!-- לפני -->
<iframe src="https://www.youtube.com/embed/Bhw1OPsekKw" allowfullscreen></iframe>

<!-- אחרי -->
<iframe src="https://www.youtube.com/embed/Bhw1OPsekKw" allowfullscreen data-track-video="overview"></iframe>
```

**הערה:** המערכת תעקוב אחר צפייה של 50%+ מהסרטון אוטומטית.

## שלב 3: הוספת data-track-click לכפתורים וקישורים חשובים

לכל כפתור או קישור חשוב יש להוסיף את ה-attribute `data-track-click` (ללא ערך).

### דוגמה:

```html
<!-- לפני -->
<a href="https://gemini.google.com/app" target="_blank" class="styled-button">ג'מיני</a>

<!-- אחרי -->
<a href="https://gemini.google.com/app" target="_blank" class="styled-button" data-track-click>ג'מיני</a>
```

**כפתורים שכדאי לעקוב אחריהם:**
- קישורים לכלים חיצוניים
- קישורים למאגרים (כמו "חומר פתוח")
- קישורים למשאבים נוספים

## שלב 4: הוספת תיבת סימון ידנית לפרק המשימה

בפרק המשימה יש להוסיף תיבת סימון עם `data-track-manual`.

### דוגמה:

```html
<div style="margin: 20px 0; padding: 15px; background: #f0f7ff; border-right: 4px solid #4A90E2; border-radius: 4px;">
    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: bold;">
        <input type="checkbox" data-track-manual style="width: 20px; height: 20px; cursor: pointer;">
        <span>העליתי פוסט בחומר פתוח עם התגית המתאימה</span>
    </label>
</div>
```

**התאמת צבע:** שנה את `border-right: 4px solid #4A90E2` לצבע הראשי של היחידה שלך.

## שלב 5: הגדרת WordPress לטעינת הסקריפט

יש להוסיף את הקוד הבא לקובץ `functions.php` של התבנית (או תבנית בת):

```php
add_action('wp_enqueue_scripts', 'cpt_enqueue_course_scripts');
function cpt_enqueue_course_scripts() {
    // שנה את התנאי כאן כדי שיתאים לעמודי הקורס שלך
    // למשל: is_singular('course_unit') או is_page(['unit-1', 'unit-2'])
    if (is_page('your-course-page-slug')) { 
        
        // ודא ש-jQuery נטען
        wp_enqueue_script('jquery');

        // טען את סקריפט המעקב
        $plugin_url = plugin_dir_url(__FILE__);
        wp_enqueue_script('cpt-course-tracker', 
            $plugin_url . 'wp-content/plugins/course-progress-tracker/course-tracker.js', 
            ['jquery'], 
            '2.0.0', 
            true
        );

        // הזרק את הנתונים לסקריפט
        wp_localize_script('cpt-course-tracker', 'progress_tracker_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'post_id'  => get_the_ID(),
            'nonce'    => wp_create_nonce('cpt_progress_nonce'),
            'get_action' => 'cpt_get_progress',
            'save_action' => 'cpt_save_progress',
            'track_action' => 'cpt_track_activity',
            'check_comment_action' => 'cpt_check_comment_status',
            'manual_check_action' => 'cpt_save_manual_check',
            'get_progress_action' => 'cpt_get_activity_progress',
        ));
    }
}
```

**חשוב:** עדכן את `is_page('your-course-page-slug')` כך שיתאים לעמודי הקורס שלך.

## Checklist להחלה על יחידה חדשה

- [ ] הוספתי `data-track-section` לכל `content-section`
- [ ] הוספתי `data-track-video` לכל `iframe` YouTube
- [ ] הוספתי `data-track-click` לכפתורים וקישורים חשובים
- [ ] הוספתי תיבת סימון ידנית בפרק המשימה
- [ ] עדכנתי את `functions.php` לטעינת הסקריפט
- [ ] בדקתי שהערכים של `data-track-section` תואמים ל-`data-section` בתפריט

## הסבר על סוגי הפעילויות

### 1. video_watch
- **מתי נשמר:** כשהמשתמש צופה ב-50%+ מהסרטון
- **איך להוסיף:** `data-track-video="section_id"` על ה-iframe
- **דרישות השלמה:**
  - פרק "מבט על": 1 סרטון
  - פרק "כלים": 4 סרטונים

### 2. button_click
- **מתי נשמר:** כשהמשתמש לוחץ על כפתור/קישור
- **איך להוסיף:** `data-track-click` על האלמנט
- **דרישות השלמה:** אין דרישה ספציפית, רק מעקב

### 3. scroll
- **מתי נשמר:** כשהמשתמש גולל ושהה לפחות 30 שניות בפרק
- **איך להוסיף:** אוטומטי - נדרש רק `data-track-section`
- **דרישות השלמה:** אין דרישה ספציפית

### 4. comment
- **מתי נשמר:** כשהמשתמש מגיב בדיון WordPress באותו עמוד
- **איך להוסיף:** אוטומטי - נדרש רק `data-track-section="discussion"`
- **דרישות השלמה:** 1 תגובה

### 5. manual_check
- **מתי נשמר:** כשהמשתמש מסמן את התיבה
- **איך להוסיף:** `data-track-manual` על ה-checkbox
- **דרישות השלמה:** 1 סימון

## לוגיקת חישוב אחוזי השלמה

המערכת מחשבת אחוזי השלמה אוטומטית לפי סוג הפרק:

- **פרק "מבט על"** (`overview`): דורש צפייה בסרטון אחד (50%+)
- **פרק "כלים"** (`tools*`): דורש צפייה ב-4 סרטונים (50%+ כל אחד)
- **פרק "דיון"** (`discussion`): דורש תגובה WordPress באותו עמוד
- **פרק "משימה"** (`task`/`assignment`): דורש סימון ידני

## פתרון בעיות נפוצות

### הסקריפט לא נטען
- ודא ש-`progress_tracker_data` מוגדר ב-console (F12)
- בדוק שהתנאי ב-`functions.php` מתאים לעמוד
- ודא שהנתיב ל-`course-tracker.js` נכון

### פעילויות לא נשמרות
- בדוק שהמשתמש מחובר ל-WordPress
- בדוק את ה-console לשגיאות JavaScript
- ודא שה-`nonce` תקין

### אחוזי השלמה לא מתעדכנים
- ודא שה-`section_id` תואם בין `data-track-section` ל-`data-section`
- בדוק שהלוגיקה ב-`cpt_calculate_section_progress()` מתאימה לסוג הפרק

## דוגמאות קוד מלאות

### דוגמה לפרק מבט על:

```html
<div class="content-section" data-track-section="overview">
    <h1>מבט על</h1>
    <p>תוכן...</p>
    <div class="video-container">
        <iframe src="https://www.youtube.com/embed/VIDEO_ID" 
                allowfullscreen 
                data-track-video="overview"></iframe>
    </div>
</div>
```

### דוגמה לפרק כלים:

```html
<div class="content-section" data-track-section="tools_demo">
    <h2>סרטוני הדגמה</h2>
    <div class="video-container">
        <iframe src="https://www.youtube.com/embed/VIDEO1" 
                allowfullscreen 
                data-track-video="tools_demo"></iframe>
    </div>
    <div class="button-wrapper">
        <a href="https://example.com" 
           target="_blank" 
           class="styled-button" 
           data-track-click>קישור</a>
    </div>
</div>
```

### דוגמה לפרק דיון:

```html
<div class="content-section" data-track-section="discussion">
    <h1>דיון</h1>
    <p>כתבו את תגובתכם בדיון...</p>
    <!-- תגובות WordPress יופיעו כאן -->
</div>
```

### דוגמה לפרק משימה:

```html
<div class="content-section" data-track-section="task">
    <h1>משימה</h1>
    <p>הנחיות...</p>
    <div class="button-wrapper">
        <a href="https://openstuff.co.il/" 
           target="_blank" 
           class="styled-button" 
           data-track-click>חומר פתוח</a>
    </div>
    <div style="margin: 20px 0; padding: 15px; background: #f0f7ff; border-right: 4px solid #COLOR; border-radius: 4px;">
        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: bold;">
            <input type="checkbox" data-track-manual style="width: 20px; height: 20px; cursor: pointer;">
            <span>העליתי פוסט בחומר פתוח עם התגית המתאימה</span>
        </label>
    </div>
</div>
```

## תמיכה

אם נתקלת בבעיות או יש לך שאלות, בדוק:
1. את קובץ `course-tracker.js` - יש בו הערות מפורטות
2. את קובץ `course-progress-tracker.php` - יש בו הערות על כל פונקציה
3. את ה-console בדפדפן (F12) לשגיאות JavaScript

## עדכונים עתידיים

כשמעדכנים יחידה קיימת:
1. ודא שכל ה-attributes עדיין במקומם
2. הוסף attributes חדשים לפריטים חדשים
3. בדוק שהערכים תואמים בין התפריט לתוכן

## עדכון עיצוב הניווט (גרסה 2.5.0+)

### הסרת צביעה אוטומטית אחרי זמן

**חשוב:** יש להסיר את הפונקציה `setItemCompleted` ואת הקריאות אליה מהקוד JavaScript ב-HTML.

#### שלב 1: הסר את הפונקציה

מצא והסר את הקוד הבא:

```javascript
function setItemCompleted(item, section) {
    const navItem = item.closest('.nav-item');
    if (!navItem.classList.contains('completed')) {
        clearTimeout(timers[section]);
        timers[section] = setTimeout(() => {
            navItem.classList.add('completed');
            updateProgress();
        }, 10000);
    }
}
```

החלף ב:

```javascript
// Removed setItemCompleted - completion is now handled by course-tracker.js based on actual progress
```

#### שלב 2: הסר קריאות לפונקציה

מצא והסר את הקריאות הבאות:

```javascript
setItemCompleted(mainItem, section);
setItemCompleted(subItem, section);
```

החלף ב:

```javascript
// Completion is handled by course-tracker.js
```

### עדכון CSS לעיצוב חדש

#### שלב 1: עדכן את סגנון ה-completed

החלף את הקוד הישן:

```css
#interactive-unit-container .completed > .main-item,
#interactive-unit-container .nav-item.completed > .sub-item {
    background: linear-gradient(45deg, var(--primary-color), #27ae60);
    border-color: var(--primary-color);
    color: white;
}

#interactive-unit-container .completed .completion-circle {
    background: white;
    border-color: white;
}

#interactive-unit-container .completed .completion-circle::after {
    content: "✓";
    color: var(--primary-color);
}
```

ב:

```css
/* Completed sections - circle gets checkmark with colored background */
#interactive-unit-container .nav-item.completed .completion-circle {
    background: var(--primary-color);
    border-color: var(--primary-color);
    border-width: 2px;
}

#interactive-unit-container .nav-item.completed .completion-circle::after {
    content: "✓";
    color: white;
    font-weight: bold;
}

/* Don't change background color for completed items - only active items get colored background */
```

#### שלב 2: עדכן את סגנון ה-active

החלף את הקוד הישן:

```css
#interactive-unit-container .main-item.active,
#interactive-unit-container .sub-item.active {
    background: linear-gradient(45deg, #e8f4fd, #d0e9fc) !important;
    border: 3px solid var(--primary-color) !important;
    border-color: var(--primary-color) !important;
    color: var(--primary-color) !important;
    position: relative;
    box-shadow: 0 0 0 2px rgba(74, 144, 226, 0.1);
}

#interactive-unit-container .main-item.active::before,
#interactive-unit-container .sub-item.active::before {
    content: "";
    position: absolute;
    right: -30px;
    top: -2px;
    bottom: -2px;
    width: 30px;
    background: linear-gradient(to left, rgba(74, 144, 226, 0.25), rgba(74, 144, 226, 0.1));
    z-index: 1;
    border-radius: 0 10px 10px 0;
}

#interactive-unit-container .main-item.active .completion-circle,
#interactive-unit-container .sub-item.active .completion-circle {
    border-color: var(--primary-color) !important;
    background: var(--primary-color) !important;
}

#interactive-unit-container .main-item.active .completion-circle::after,
#interactive-unit-container .sub-item.active .completion-circle::after {
    content: "●";
    color: white;
    font-size: 12px;
}
```

ב:

```css
/* Active section - colored background, no side marker */
#interactive-unit-container .main-item.active,
#interactive-unit-container .sub-item.active {
    background: linear-gradient(45deg, var(--primary-color), #27ae60) !important;
    border: 2px solid var(--primary-color) !important;
    border-color: var(--primary-color) !important;
    color: white !important;
    position: relative;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

#interactive-unit-container .main-item.active .completion-circle,
#interactive-unit-container .sub-item.active .completion-circle {
    border-color: white !important;
    background: white !important;
}

#interactive-unit-container .main-item.active .completion-circle::after,
#interactive-unit-container .sub-item.active .completion-circle::after {
    content: "";
}
```

### סיכום השינויים בעיצוב

1. **הסרת צביעה אוטומטית:** הלשוניות לא נצבעות אוטומטית אחרי זמן - רק לפי התקדמות אמיתית
2. **עיגול מושלם:** כשפרק הושלם (100%), העיגול מקבל רקע צבעוני עם וי לבן
3. **לשונית פעילה:** הלשונית הפעילה מקבלת רקע צבעוני משמעותי (לא תכלת בהיר)
4. **הסרת כתם צד:** הוסר הכתם הצבעוני בצד של הלשונית הפעילה
5. **עיגול פעיל:** העיגול של הלשונית הפעילה הוא לבן (ללא תוכן)

### Checklist לעדכון יחידה קיימת

- [ ] הסרתי את הפונקציה `setItemCompleted`
- [ ] הסרתי את כל הקריאות ל-`setItemCompleted`
- [ ] עדכנתי את CSS של `.completed` - עיגול צבעוני עם וי לבן
- [ ] עדכנתי את CSS של `.active` - רקע צבעוני משמעותי, ללא כתם צד
- [ ] בדקתי שהעיצוב נראה נכון - לשונית פעילה צבועה, עיגול מושלם צבעוני

