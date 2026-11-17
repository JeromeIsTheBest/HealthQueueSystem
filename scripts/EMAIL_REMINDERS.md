# Queue email reminders

`process-email-reminders.php` checks confirmed, due appointments in today's clinic queue independently of patient pages. The first three eligible waiting patients receive a near-turn email (0, 1 or 2 waiting ahead); Calling patients receive a separate called email. No clinical notes are included. SMTP configuration is shared with account recovery.

`QueueEmailOutbox` tracks near/called events uniquely per queue entry. Successful sends are not repeated. Failed sends retry after five minutes, up to three attempts. Interrupted sends become unknown for manual checking rather than automatic resend. Inactive patients, invalid email addresses and no-longer-eligible queue entries are skipped. A MySQL lock prevents overlapping workers. Provider acceptance does not guarantee inbox delivery.

Migration: `database/migrations/20261116_queue_email_outbox.sql` (already applied locally).

On this XAMPP installation, `process-email-reminders.ps1` reads only SMTP/mail SetEnv settings from local Apache config into the child process environment without printing or persisting credentials. Run every minute using Windows Task Scheduler. Task name: `HealthQueue Queue Email Reminders`. Apache/MySQL and the laptop must remain available; the task runs as the current Windows user while logged on.

Manual invocation sends actual emails:

```powershell
powershell.exe -NoProfile -NonInteractive -WindowStyle Hidden -File C:\xampp\htdocs\healthqueue\scripts\process-email-reminders.ps1
```

Safe automated tests use temporary tables and a mock sender, not real email:

```powershell
C:\xampp\php\php.exe scripts/test-email-reminders.php
```
