# תמונת מצב של התוכן החי

התוכן של תשע היחידות נשמר באתר (tikshuv.chepti.com) כאופציות וורדפרס `cpt_unit_content_1` עד `cpt_unit_content_9`, ונערך בעורך הקדמי. הקבצים כאן הם **גיבוי קריא** של האופציות האלה (JSON), ללא קשר לתיקיית `course-progress-tracker/units/*.html` — שם נשארו קבצי המקור הישנים, שמשמשים כגיבוי בלבד כשאין תוכן שמור.

- מקור האמת: האתר החי. הקבצים כאן מתעדכנים ידנית.
- התיקייה לא חלק מהתוסף ולא עולה לשרת בפריסה.
- ייצוא מחדש:
  `ssh -F "T:\.ssh\config" hostinger "cd /home/u630483490/public_html/tikshuv && wp option get cpt_unit_content_N --format=json"`
- שחזור (כתיבה לאתר! רק בכוונה): `wp option update cpt_unit_content_N --format=json < unit-N.json`

עודכן לאחרונה: 15.10.2026
