# Capstone audit fixes

Apply the schema and safe normalization with:

```powershell
C:\xampp\php\php.exe scripts\setup-audit-fixes.php --apply
```

Existing sessions must sign in again. Session identity is revalidated against the active account, role/clinic, password hash fingerprint, and current email. Password changes invalidate old sessions; recovery tokens are consumed atomically.

The web server now returns 403 for private storage, database, scripts, includes, configuration, vendor, docs and hidden repository directories. The PHP streaming pages continue reading authorized files from the filesystem. Keep the root `.htaccess` when deploying; verify the same 403 checks on the destination server.

Refunds use the original wallet debit. An old paid flag without a debit cannot create a new wallet credit. Existing historical refunds are not silently reversed: Admin > Revenue > Review exceptions shows records needing payment evidence. Phone numbers are converted only when their recognized international/formatting variant represents an unambiguous Philippine mobile; unknown values need correction on the profile page. Names are capitalized without lowercasing intentional internal capitals.

Booking creation serializes on the patient row and reads current active bookings under lock. Approval, walk-in numbering and call-next serialize per clinic. Submitted physician IDs must be active physicians at that clinic. Dashboard and queue calling use the same ordering and notification outbox.

Queue email and in-app notifications respect saved preferences. Security verification/recovery emails remain available independently of optional reminder preferences. Rate limits use hashed identities and fixed 15-minute windows. Old rate buckets may be removed during maintenance; their counters automatically restart when their window expires.

Top-ups have unique request keys and redirect after success. CSV exports escape formula-leading text. Calendar inputs are checked as actual calendar values.

An AI summary is not patient-visible until the physician explicitly checks its approval box when finalizing notes. Approval is linked to that finalized note version and rejected if a newer transcript or summary exists. Existing unapproved historical AI summaries remain hidden; their finalized physician notes are retained. Audio replacement validates the detected media type, keeps the previous file until the new reference is committed, and cleans up failed replacements.

Regression checks (no actual messages or transfers):

```powershell
C:\xampp\php\php.exe scripts\test-audit-fixes.php
C:\xampp\php\php.exe scripts\test-concurrent-audit.php
C:\xampp\php\php.exe scripts\test-email-reminders.php
C:\xampp\php\php.exe scripts\test-sms.php --database
C:\xampp\php\php.exe scripts\test-physician-withdrawals.php
```

Concurrency fixtures use a newly generated isolated test database and remove only that database. Other tests use connection-local temporary tables. See BACKUP_RESTORE.md for private backup and restoration checks. Actual microphone/browser-device behavior, real delivery, and AI accuracy still need an interactive rehearsal.
