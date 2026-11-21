# Workflow fixes and demo rehearsal

Implemented: shared transactional staff completion and revenue recognition; latest-note finalization; validated staff rescheduling with queue replacement; physician availability/capacity checks; synchronized no-show statuses; independent physician calling and patient progress; two-hour patient cutoff; clinic calendar bounds; safe legacy direct calling; explicit queue-visit time labels; source-bound AI summaries and stale-generation rejection.

No-show behavior follows the existing forfeiture action: the appointment becomes NoShow, active queue entries become Forfeited_Late, pending reschedule requests are withdrawn, any paid fee is retained, no completed-visit commission is recognized, and the patient may book again. The patient is told to contact the clinic if this is incorrect. Cancellation/refund is a separate workflow. Do not use no-show for a visit with finalized notes.

## Existing exceptions needing verified input

- Account owners correct invalid mobile numbers through Profile using their actual 09-prefixed, unique 11-digit numbers. Validation remains enforced when saving; do not invent replacement numbers.
- Admin ? Revenue ? Wallet reconciliation lists historical refund discrepancies. Verify original payment evidence before any corrective ledger transaction.
- Staff → Appointments → Needs review lists past Pending and Confirmed visits. Expired Pending requests can be declined, refunding eligible wallet payments once. Staff verifies attendance, then confirms completion if the latest note is Finalized or confirms a genuine no-show if there are no finalized notes. Dates alone never automatically change clinical outcomes.
- Historical audio cleanup is planned in `GIT_AUDIO_HISTORY_CLEANUP.md`; published history has not been rewritten.

## Deployment

Run `C:\xampp\php\php.exe scripts/setup-workflow-fixes.php --apply` to install the nullable source transcript FK. This has already been run on the local demo database. Existing AI history is not relabeled with guessed source versions. Newly approved summaries must have a verified source version; existing historical completed records are preserved.

## Automated rehearsal performed

`C:\xampp\php\php.exe scripts/test-workflow-fixes.php` uses connection-local temporary copies of all tables. It rehearses booking → wallet payment → approval → calling → finalized notes → staff completion → 90/10 recognition → demo settlement → withdrawal reservation → simulated payout. It also covers both completion controllers, no-show rebooking and fee retention, invalid/full/cross-clinic/mismatched-queue rescheduling, request replay, clinic hours, cutoff, separate physician lines and changed AI source versions.

`scripts/test-concurrent-audit.php` uses a generated isolated test database, removed in finally. It verifies simultaneous completion creates one payment and simultaneous reschedules cannot both take the final capacity, alongside the earlier approval, top-up, booking, mobile-number and withdrawal checks.

The other existing test scripts continue to validate account permissions, wallet refunds, source approval, SMS payloads/provider responses and mocked email dispatch. Tests do not send real messages, run Whisper/Ollama or transfer money.

## Device rehearsal still required before presentation

1. Use designated demo accounts and the actual desktop/mobile browsers. Test invalid and duplicate phone messages and sign-in permissions for Main/Sub physicians.
2. Book an available slot, pay from the demo wallet and approve it as Staff. Check the displayed queue date and physician.
3. Call patients for two different physicians. Verify each physician receives only their assigned visits and each patient sees their own physician's queue progress.
4. With a consenting demo participant, start and stop recording. Confirm automatic transcription, correction, summary generation, review and finalization on the actual microphone/device.
5. Complete a paid visit through each staff route using separate appointments. Confirm one revenue record per visit, 90/10 amounts, automatic release and self-service simulated owner withdrawal. Confirm Sub Account financial access remains blocked.
6. Run SMS/email delivery checks only with explicit permission and designated recipient accounts. Verify inbox/phone receipt separately from queued/provider-accepted status. Automatic workers may incur provider/SIM charges.
7. Exercise cancellation, no-show and rescheduling, including a full slot and the two-hour cutoff. Refresh after saving to verify no duplicate financial effects.

No real-device, external-delivery or AI-quality success is implied by the automated tests.
