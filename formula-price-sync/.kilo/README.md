# FPS AI Harness Files — آماده برای استفاده

این پوشه حاوی تمام فایل‌های کنترل هارنس است.

## نحوه استفاده

1. محتویات پوشه `.ai/` را کپی کنید به ریشه پروژه پلاگین (هم‌سطح با `formula-price-sync.php` یا داخل پوشه پلاگین):

```bash
cp -r .ai /path/to/your/plugin/
```

یا اگر پروژه شما مونورپو است، داخل پوشه `formula-price-sync/`:

```bash
cp -r .ai /path/to/formula-price-sync/
```

2. ساختار نهایی باید به این شکل باشد:

```
formula-price-sync/
├── .ai/
│   ├── HARNESS.md
│   ├── BACKLOG.md
│   ├── PROGRESS.md
│   ├── CURRENT_TASK.md          ← الان روی تسک 1.1 تنظیم شده
│   ├── ARCHITECTURE_CONSTRAINTS.md
│   └── VERIFICATION_LOG.md
├── formula-price-sync.php
├── includes/
└── ...
```

3. در Cursor / Windsurf / Claude / Aider این پرامپت را بدهید:

```text
Read .ai/HARNESS.md, .ai/CURRENT_TASK.md, .ai/ARCHITECTURE_CONSTRAINTS.md and .ai/BACKLOG.md.

Execute ONLY the current task using the Task Execution Loop.
After verification, update PROGRESS.md and CURRENT_TASK.md, then stop.
```

4. بعد از اتمام هر تسک، Agent باید خودش فایل‌ها را آپدیت کند. شما فقط تأیید نهایی را انجام دهید.

## وضعیت اولیه

- **Current Task:** 1.1 — Create Frontend structure
- همه تسک‌ها در حالت Pending هستند.

موفق باشید.
