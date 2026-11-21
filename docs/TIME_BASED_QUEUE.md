# Time-based queues

The shared planner in `includes/queue-estimates.php` orders each physician independently. Regular online bookings and walk-ins share the same unscheduled line; their ticket numbers never change. Scheduled visits keep their original appointment time and ticket.

A projected consultation takes 15 minutes. The planner inserts future timed appointments into that timeline rather than leaving them at their registration position. When the physician is actually idle, a regular patient may fill the gap before a scheduled visit if **at least 10 minutes** remain. With less than 10 minutes, the scheduled visit is held next and cannot be called until its booked time. Once a timed visit is due it takes priority, unless that patient explicitly reported being late and yielded to a named successor. The approved 10-minute exception can delay a timed appointment because a visit is estimated at 15 minutes; it does not guarantee punctual starts.

Example at 3:30 PM: regular #1 at approximately 3:30, walk-in #2 at 3:45, S-1 booked for 4:00, next regular #3 at 4:15. If the first two finish early, the following regular may be called at 3:50; at 3:51 the system waits for S-1. Calling or Serving patients make only their own physician busy.

The staff queue, call-next eligibility, staff dashboard, waiting-room display, physician up-next list, patient progress and current appointment estimates use the shared ordering. SMS estimates use the same planner within published clinic/physician hours; email reminders follow the shared order. Assigned schedules and fee/payment records are never changed to implement an estimate.

## Running late

A patient's own Waiting or Calling entry for today can be moved behind its next waiting entry for the same physician. Calling returns to Waiting when that patient reports late. Serving visits are protected. A future timed successor is never called before its booked time, so yielding can mean waiting for that successor's scheduled slot. With no successor, staff still receive the notice and no other queue changes. A database timestamp enforces a ten-minute cooldown across sessions/devices, under the same clinic lock as queue calling and staff reordering. A successful request redirects so browser refresh does not submit it again. Patients are told this action lets the following patient go first.

`LateAfterQueueID` preserves the one-successor yield even for an overdue timed appointment; inactive/deleted/cancelled successors do not block the line. Staff manually reordering a physician's line clears the late dependencies in that line and normalizes its positions; scheduled times remain protected. Existing accepted SMS events are not automatically resent after a late notice; staff can use the existing recall action when appropriate.

## Setup and validation

Run `C:\xampp\php\php.exe scripts/setup-time-based-queue.php --apply` on another installation before deploying the PHP changes. Migration `20261119_time_based_queue.sql` adds nullable metadata only and does not rewrite live bookings or positions.

`test-time-based-queue.php` uses connection-local temporary tables and a fixed connection clock for the scenario, 10-minute boundary, timed priority, ownership, late swaps and active-visit protection. `test-concurrent-audit.php` uses an isolated disposable database and covers simultaneous late reports and simultaneous calls. SMS, email and workflow regression suites use temporary fixtures or mock senders; none sends a real test message.

## Scheduled arrival policy

Newly created timed entries additionally require staff-confirmed arrival. At their booked time an absent patient is held out of the active line during a 30-minute grace period, extendable once through a timely late notice. See `SCHEDULED_ARRIVAL_GRACE.md` for worker setup, reschedule handling, legacy opt-in and automatic no-show safeguards. This attendance policy takes precedence over due-time priority; arrived timed patients still receive priority when due.
