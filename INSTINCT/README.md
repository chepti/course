# INSTINCT — מתכונים למורה עם אינסטינקט

חי ב: https://chepti.com/instinct/ → `~/public_html/instinct/` בהוסטינגר.

- `site/index.html` — העמוד (פרונט מלא, כולל מצב ניהול שמופיע אוטומטית למנהלת מחוברת לוורדפרס).
- `site/api.php` — API: ציבורי (list/submit/hit/feedback) ומנהלת (ops/state/export). חוזה מלא: `?action=help`.
- `site/config.php` — מפתח סוכן (`agent_key`). **לא ב-git.** תבנית: `config.sample.php`.
- `site/data/recipes.json` — **זה רק זרע התחלתי.** המקור האמיתי הוא הקובץ בשרת (מתכונים שהוגשו/נערכו חיים שם).

## פריסה
להעלות רק: `index.html api.php .htaccess llms.txt AGENT.md config.sample.php` (+ `data/.htaccess`).
**לעולם לא לדרוס `data/recipes.json` בשרת** — מושכים אותו קודם (`scp hostinger:.../instinct/data/recipes.json .`) אם צריך עותק מקומי.
