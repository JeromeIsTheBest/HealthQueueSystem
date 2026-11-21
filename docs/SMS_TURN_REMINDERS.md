# Approval SMS and approximately 15-minute turn reminders

Approval messages keep the booked time for scheduled appointments. For queue bookings with no specific time, they show an **Estimated turn** marked **approx.**, calculated from the assigned physician's active line and published hours. If there is no remaining working window, the message asks the clinic to confirm rather than inventing a time after closing.

The SMS worker evaluates each physician independently. It assumes 15 minutes per consultation, subtracts elapsed time from a serving visit, respects adjusted queue positions, gives due scheduled visits priority, and pauses estimates across gaps in published hours. Timed visits are never estimated before their scheduled time.

A Waiting patient gets one near-turn event when the estimated wait reaches 15 minutes or less. A timed visit can therefore be reminded before it becomes callable. Its SMS still displays its appointed schedule, even if the estimated queue is running late. A queue booking displays its estimated turn time. A separate called event remains available when staff actually call the patient.

Unique per-queue event keys prevent duplicates. The worker rechecks the live line and refreshes the alert text before sending. If a patient moves farther back, that pending alert is skipped. Only a never-attempted skipped alert can be revived when the patient becomes near again; provider-accepted and uncertain outcomes are never automatically resent. Cancellation, inactive accounts, invalid numbers and SMS opt-out prevent delivery attempts.

These estimates are advisory. Consultations may take longer than 15 minutes, and Android/textbee/carrier delays can change actual receipt time. API acceptance or dispatched status is not confirmed handset delivery.

The existing Windows task `HealthQueue SMS Reminders` still starts every minute, with `-WatchSeconds 55 -PollSeconds 5` added to its PowerShell action. This checks pending work approximately every five seconds during each run instead of waiting up to a minute. Manual runs remain one-shot; `-Check` always checks configuration once without sending. Worker locking, event deduplication and retry limits remain in effect. Called and near-turn alerts are selected before confirmation messages. A task XML backup was saved under protected, ignored `storage/` before changing its action.

On November 18, a read-only provider check showed one alert accepted by textbee within a few seconds of local creation, but its push reached the Android phone roughly 12.5 minutes later. It was subsequently delivered. Faster local polling cannot fix that phone-side delay. On the Samsung gateway, set textbee battery use to Unrestricted, add it to Never sleeping apps, enable its sticky notification, disable power saving, and keep mobile/Wi-Fi connectivity available. See https://textbee.dev/docs/troubleshooting/keep-the-phone-online. A separate confirmation reported `CARRIER_0x44`; do not treat provider acceptance as successful delivery or automatically resend uncertain outcomes.

Regression checks: `C:\xampp\php\php.exe scripts/test-sms-near-turn.php` and `scripts/test-sms.php --database`. Both use temporary fixtures and make no network requests or real SMS sends.
