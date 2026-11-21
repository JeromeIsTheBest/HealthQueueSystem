# Consultation fee changes

Main physician: Manage Clinic → Consultation fee → proposed fee, effective date and reason → Submit fee change request.
Admin: Clinics → Fee change requests → Approve or Decline. Clinic navigation badge includes pending fee requests. Sub-accounts cannot request changes. Initial fee remains configured during Admin clinic registration; assigned clinics use this approval process thereafter.

Only one pending or future-approved change is allowed per clinic. Approved changes apply automatically by Philippine database date, without a worker or transfer. A request whose effective date has passed must be declined and resubmitted. Changes on today's date apply to bookings created after approval. Earlier bookings on that date retain their original quote.

Clinic.BaseConsultationFee retains the initial fee. `currentClinicFee()` resolves the latest approved request whose effective date has arrived. Do not read the initial fee alone for new booking prices. Old approvals stay in the audit history.

Appointments.BookingFeeAmount saves the server's quote when created, including walk-ins. Checkout uses it even after an approved clinic fee change; rescheduling does not overwrite it. Refunds and 90/10 revenue recognition continue using wallet payment evidence, never a clinic's later price. No action initiates real payouts or sends SMS/email.

Install on another copy: `C:\xampp\php\php.exe scripts/setup-clinic-fees.php --apply`. This adds ClinicFeeRequests and the booking quote column. Existing quotes are frozen from their recorded wallet debit if present, otherwise the current initial clinic fee; missing historical price evidence is not invented. Existing wallet balances, payment records and revenue are unchanged. The local migration has been applied.

Validation: scripts/test-clinic-fees.php (temporary tables), scripts/test-managed-clinic.php, scripts/test-workflow-fixes.php, scripts/test-concurrent-audit.php (isolated database, removed afterward). Test data never uses real messages, AI calls or payment providers.

Physician alerts: apply `scripts/setup-physician-notifications.php --apply` after the fee migration. Future approvals and declines create one personal PhysicianNotifications entry for the requesting main physician, atomically with the decision. The physician bell and Notifications page show unread alerts; opening the shared message modal marks only the owner's notification Read. These are in-app alerts, visible on page reload/navigation; no SMS/email or historical backfill is sent.
