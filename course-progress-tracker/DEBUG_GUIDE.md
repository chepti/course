# מדריך דיבוג - Course Progress Tracker

## בעיות נפוצות ופתרונות

### 1. הסקריפט לא נטען

**איך לבדוק:**
1. פתח את הקונסול בדפדפן (F12)
2. רענן את העמוד
3. חפש הודעות שמתחילות ב-"Course Progress Tracker"

**אם אתה רואה:**
- `Course Progress Tracker: No tracker data found` - הקוד מ-`FUNCTIONS_PHP_CODE.txt` לא נוסף ל-`functions.php` או שהתנאי לא מתאים לעמוד
- `Course Progress Tracker: Missing required data!` - הנתונים לא מועברים נכון לסקריפט

**פתרון:**
- ודא שהוספת את הקוד מ-`FUNCTIONS_PHP_CODE.txt` ל-`functions.php` של התבנית
- ודא שהתנאי `is_page()` או `get_page_by_path()` מתאים לעמודי הקורס שלך
- ודא שהעמוד הנוכחי עונה על התנאי

### 2. AJAX לא עובד

**איך לבדוק:**
1. פתח את הקונסול (F12)
2. לחץ על Network/רשת
3. בצע פעולה (למשל, סמן תיבת סימון)
4. חפש בקשות AJAX ל-`admin-ajax.php`

**אם אתה רואה שגיאות:**
- `403 Forbidden` - בעיית nonce או הרשאות
- `400 Bad Request` - נתונים חסרים או לא תקינים
- `500 Internal Server Error` - שגיאת PHP בצד השרת

**פתרון:**
- בדוק את הקונסול - יש הודעות שגיאה מפורטות
- ודא שהמשתמש מחובר
- בדוק את לוגי השגיאות של WordPress

### 3. נתונים לא נשמרים במסד הנתונים

**איך לבדוק:**
1. היכנס ל-phpMyAdmin או כלי ניהול מסד נתונים
2. בדוק את הטבלאות:
   - `wp_course_progress` - התקדמות כללית
   - `wp_course_activity` - פעילויות מפורטות

**אם הטבלאות ריקות:**
- בדוק שהתוסף הופעל (activation) - זה יוצר את הטבלאות
- בדוק שהסקריפט נטען ופועל (ראה סעיף 1)
- בדוק שה-AJAX עובד (ראה סעיף 2)

### 4. דף האדמין לא מציג נתונים

**איך לבדוק:**
1. היכנס ל-WordPress Admin
2. לחץ על "התקדמות בקורס" בתפריט
3. בדוק אם יש משתמשים ברשימה

**אם אין משתמשים:**
- בדוק שהטבלאות במסד הנתונים מכילות נתונים
- בדוק את הקונסול בדף האדמין - אולי יש שגיאות JavaScript
- ודא שהמשתמשים ביצעו פעילויות (צפו בסרטונים, כתבו תגובות וכו')

### 5. ה-shortcode לא מציג התקדמות

**איך לבדוק:**
1. ודא שה-shortcode `[user_course_progress]` נוסף לעמוד הפרופיל
2. בדוק שהמשתמש מחובר
3. בדוק שהמשתמש ביצע פעילויות

**אם אין התקדמות:**
- בדוק שהטבלאות במסד הנתונים מכילות נתונים עבור המשתמש
- בדוק שהסקריפט נטען ופועל בעמודי הקורס
- בדוק שה-AJAX עובד

## בדיקות מהירות

### בדיקה 1: האם הסקריפט נטען?
```javascript
// פתח קונסול בדפדפן וכתוב:
console.log(typeof progress_tracker_data);
// אם זה מחזיר "undefined" - הסקריפט לא נטען
```

### בדיקה 2: האם יש נתונים?
```javascript
// פתח קונסול וכתוב:
console.log(progress_tracker_data);
// זה צריך להציג אובייקט עם post_id, ajax_url, nonce וכו'
```

### בדיקה 3: בדיקת AJAX ידנית
```javascript
// פתח קונסול וכתוב:
jQuery.ajax({
    url: progress_tracker_data.ajax_url,
    type: 'GET',
    data: {
        action: 'cpt_get_activity_progress',
        post_id: progress_tracker_data.post_id,
        nonce: progress_tracker_data.nonce
    },
    success: function(response) {
        console.log('Success:', response);
    },
    error: function(xhr, status, error) {
        console.error('Error:', error, xhr.responseText);
    }
});
```

## לוגים בקונסול

הסקריפט מדפיס הודעות בקונסול:
- `Course Progress Tracker: Initializing...` - הסקריפט מתחיל
- `Course Progress Tracker: Tracking activity` - פעילות נעקבת
- `Course Progress Tracker: Activity tracked` - פעילות נשמרה בהצלחה
- שגיאות מתחילות ב-`Course Progress Tracker: ... error`

אם אתה לא רואה הודעות כלל - הסקריפט לא נטען.

## צור קשר

אם הבעיה נמשכת, שלח:
1. הודעות מהקונסול (כולל שגיאות)
2. תוצאות של הבדיקות המהירות לעיל
3. מידע על התבנית והתוספים המותקנים

