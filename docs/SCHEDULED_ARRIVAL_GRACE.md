# Scheduled arrival grace

For newly created timed queue entries, staff confirm physical arrival using **Patient arrived** in the queue row More menu or Awaiting arrival panel. This records `ArrivedAt` without setting Serving; the physician starts consultation separately. Check-in may be recorded before the booked time. Regular online queue bookings and walk-ins retain their existing behavior.

At the booked time, a patient without staff-confirmed arrival is excluded from call-next eligibility and estimated consultation slots. They appear in **Awaiting arrival**, with an arrival deadline rather than a fabricated consultation time. Other eligible patients continue in the physician's line. Staff still control the Call next action; the system does not announce a patient automatically.

For a 4:00 PM appointment:

- From 4:00 until before 4:30, the patient may report late or submit an available future date/time for rescheduling.
- Reporting late during that original period grants exactly one extension until 5:00 PM. Repeat reports cannot extend past 5:00 PM.
- Without arrival or an accepted patient action before 4:30, maintenance marks the appointment NoShow and queue Forfeited_Late.
- If extended but still absent at 5:00, maintenance applies the same outcome.
- A timely reschedule request is retained for staff review; it is not automatically approved, refunded, or marked No-show. Staff must resolve its outcome, including a declined request after the deadline.
- Existing cancellation cutoffs stay in effect. The grace exception applies only to rescheduling.

Arrival, patient late reports, reschedule submissions, calling and automatic expiry use clinic/appointment locks. A consultation already Serving is protected. The automatic worker also leaves visits with existing clinical notes or recordings for manual review rather than guessing that they were unattended. No-show retains the paid fee under the existing policy, creates no completion commission, and does not initiate a transfer or refund. Patient and clinic in-app notices plus an audit entry are recorded once. No-show SMS/email delivery is not added by this feature.

## Installation and operation

Run `C:\xampp\php\php.exe scripts/setup-scheduled-grace.php --apply` before deploying the updated PHP files. The nullable `GracePolicyEnabledAt` opt-in is written when a new appointment queue entry is created. Existing queue entries are not retroactively opted into automatic absence classification because they lack reliable arrival history. They remain staff-reviewed; no outcomes were guessed during migration.

The local hidden Windows task **HealthQueue Queue Grace** runs `process-queue-grace.ps1 -WatchSeconds 55 -PollSeconds 5` every minute. It performs local database maintenance independently of SMS credentials. It must remain enabled, with Windows awake and MySQL available. Deadline handling occurs on the next worker pass, not at a guaranteed exact second. Existing past-day visits are left for manual review; a grace window crossing midnight is included on the following day.

`process-queue-grace.php --check` is read-only: transactions roll back and no messages or status updates are committed. It never calls paid AI, SMTP, textbee or a payout provider. CLI worker paths remain blocked from public HTTP access.

## Validation

`test-scheduled-grace.php` uses temporary tables and a connection-local clock for the exact grace boundaries, single extension, ownership, arrival check-in, reschedule exception, clinical/legacy protections and no-show financial behavior. `test-concurrent-audit.php` adds isolated-database arrival-versus-expiry and late-extension-versus-expiry races. Queue ordering, SMS, email and existing appointment workflow suites are also exercised with fixtures or mocked senders.
