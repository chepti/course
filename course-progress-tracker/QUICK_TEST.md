# בדיקה מהירה - Course Progress Tracker

## שלב 1: בדוק אם הסקריפט נטען

1. פתח את עמוד הקורס בדפדפן
2. לחץ F12 כדי לפתוח את כלי המפתחים
3. לחץ על הכרטיסייה **Console** (קונסול)
4. רענן את העמוד (F5)

### מה לחפש:

**אם אתה רואה:**
```
Course Progress Tracker: Initializing... {post_id: 123, ajax_url: "...", ...}
```
✅ הסקריפט נטען בהצלחה!

**אם אתה רואה:**
```
Course Progress Tracker: No tracker data found...
```
❌ הקוד מ-`FUNCTIONS_PHP_CODE.txt` לא נוסף ל-`functions.php` או שהתנאי לא מתאים

**אם אתה לא רואה כלום:**
❌ הסקריפט לא נטען כלל - בדוק את הקוד ב-`functions.php`

---

## שלב 2: בדוק ידנית אם הנתונים מועברים

בקונסול, כתוב:
```javascript
console.log(typeof progress_tracker_data);
```

**אם אתה רואה:**
- `"object"` ✅ הנתונים מועברים
- `"undefined"` ❌ הנתונים לא מועברים - הסקריפט לא נטען

אם זה `"object"`, כתוב גם:
```javascript
console.log(progress_tracker_data);
```

זה צריך להציג משהו כמו:
```javascript
{
  ajax_url: "https://yoursite.com/wp-admin/admin-ajax.php",
  post_id: 123,
  nonce: "abc123...",
  ...
}
```

---

## שלב 3: בדוק אם AJAX עובד

בקונסול, כתוב:
```javascript
jQuery.ajax({
    url: progress_tracker_data.ajax_url,
    type: 'GET',
    data: {
        action: 'cpt_get_activity_progress',
        post_id: progress_tracker_data.post_id,
        nonce: progress_tracker_data.nonce
    },
    success: function(response) {
        console.log('✅ AJAX Success:', response);
    },
    error: function(xhr, status, error) {
        console.error('❌ AJAX Error:', error, xhr.responseText);
    }
});
```

**אם אתה רואה:**
- `✅ AJAX Success: {success: true, data: {...}}` ✅ AJAX עובד!
- `❌ AJAX Error: ...` ❌ יש בעיה ב-AJAX - בדוק את השגיאה

---

## שלב 4: בדוק אם פעילויות נשמרות

1. צפה בסרטון (לפחות 60 שניות או לחץ עליו)
2. סמן תיבת סימון בפרק המשימה
3. כתוב תגובה בפרק הדיון

בקונסול, אתה אמור לראות:
```
Course Progress Tracker: Tracking activity {type: "video_watch", ...}
Course Progress Tracker: Activity tracked {success: true, ...}
```

**אם אתה רואה שגיאות:**
- העתק את כל השגיאה ושלח לי

---

## שלב 5: בדוק במסד הנתונים

1. היכנס ל-phpMyAdmin או כלי ניהול מסד נתונים
2. בחר את מסד הנתונים של WordPress
3. בדוק את הטבלאות:
   - `wp_course_activity` - צריך להכיל רשומות של פעילויות
   - `wp_course_progress` - צריך להכיל רשומות של סעיפים שהושלמו (100%)

**אם הטבלאות ריקות:**
- הנתונים לא נשמרים - בדוק את שלבים 1-4

---

## סיכום - מה לשלוח לי

אם משהו לא עובד, שלח לי:

1. ✅ או ❌ מכל שלב לעיל
2. כל ההודעות מהקונסול (העתק-הדבק)
3. כל שגיאות (אדומות) מהקונסול
4. תוצאה של `console.log(progress_tracker_data)` אם זה עובד

---

## פתרון מהיר לבעיות נפוצות

### הסקריפט לא נטען:
1. ודא שהוספת את הקוד מ-`FUNCTIONS_PHP_CODE.txt` ל-`functions.php`
2. ודא שהתנאי `get_page_by_path('aia')` מתאים לעמוד שלך
3. בדוק שאין שגיאות PHP ב-`functions.php` (פתח את הקובץ ועדכן אותו)

### AJAX לא עובד:
1. ודא שהמשתמש מחובר
2. בדוק את ה-nonce (אולי צריך לרענן את העמוד)
3. בדוק את לוגי השגיאות של WordPress

### נתונים לא נשמרים:
1. בדוק שהתוסף הופעל (activation) - זה יוצר את הטבלאות
2. בדוק שהסקריפט נטען ופועל
3. בדוק שה-AJAX עובד

