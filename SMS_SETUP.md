# Semaphore SMS

HealthQueue queues appointment confirmations when staff accept requests. The
CLI worker checks today's queue for patients with at most two eligible waiting
patients ahead, and patients currently being called. Patients do not need to
keep a page open. Run the worker every minute; alerts are not instantaneous and
provider delivery may take longer. Repeated calls do not send another SMS for
the same queue entry. Reschedule/cancellation SMS and OTP are not included.

## Setup

1. Obtain a Semaphore API key, approved sender name and SMS credits.
   Provider documentation: https://semaphore.co/docs
2. Back up the database. Apply
   `database/migrations/20261112_create_sms_outbox.sql` once using phpMyAdmin.
   Fresh installations include this table in `database/schema.sql`.
   The PatientProfileDetails migration must also be installed.
3. Enable PHP cURL for CLI PHP. Configure these environment variables for
   **both Apache/PHP and the scheduled CLI process**:

   ```text
   HQ_SMS_ENABLED=1
   HQ_SEMAPHORE_API_KEY=your-private-api-key
   HQ_SMS_SENDER_NAME=your-approved-sender-name
   ```

   Keys belong in the server environment, never in Git or browser code.
   Restart Apache after environment changes. Missing configuration disables SMS.
4. Save a valid Philippine mobile number on a test patient and enable their
   SMS Notifications preference. Accepted formats include 09xxxxxxxxx,
   639xxxxxxxxx and +639xxxxxxxxx. Existing accounts with no saved preference
   follow the profile's existing default of enabled. Obtain permission before
   using real recipients; use your own number for initial testing.
5. Run manually (this sends real, billable SMS when enabled):

   ```powershell
   C:\xampp\php\php.exe C:\xampp\htdocs\healthqueue\scripts\process-sms.php
   ```

6. Schedule every minute using Windows Task Scheduler, or Linux cron:

   ```cron
   * * * * * /usr/bin/php /var/www/healthqueue/scripts/process-sms.php >> /var/log/healthqueue-sms.log 2>&1
   ```

   Use your actual installation path. Ensure the scheduled process receives
   the environment variables and database access. Restrict web access to
   scripts, SQL files and configuration during deployment.

## Operation and testing

Accept an appointment, then run the worker. For a current appointment, progress
the queue and run again to check near-turn and call alerts. Run twice to verify
no duplicates. Disable the preference before dispatch to verify it is skipped.
Cancelled appointments, inactive accounts and invalid numbers are skipped.
Run `php scripts/test-sms.php --database` for checks using session-local
temporary tables. This requires a local MySQL connection and sends no SMS.
Queue alerts older than ten minutes or whose queue state changed are skipped.

Inspect `SmsOutbox` using an administrator database tool. `accepted` means the
provider returned a message ID, not confirmed handset delivery. ProviderStatus
stores the initial provider status. Use that ID in Semaphore's dashboard/API
to check subsequent delivery; automatic status polling is not included.

Explicit HTTP 429 rate limits retry after five minutes, up to three attempts.
Timeouts, server errors and interrupted sends become `unknown` and are not
automatically resent: check Semaphore before manually retrying to avoid duplicate
charges. Other explicit client errors fail. A database lock serializes workers.
No live SMS is sent as part of installation or automated checks.

SMS content contains operational notices only, without clinical notes. Limit
database access and periodically remove old outbox rows according to your
retention policy. Preserve event keys while related bookings remain active to
keep duplicate protection effective.
