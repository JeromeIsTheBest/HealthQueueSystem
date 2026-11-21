# Main Physician and Sub Accounts

Each clinic has one Main Physician in `PhysicianAccountOwners`. Other physician accounts in that clinic are Sub Accounts. Existing clinics were initialized using the lowest existing physician UserID (earliest account); existing appointments, consultation authors, passwords, availability and account statuses were preserved. For a newly onboarded clinic, the first physician created by Admin becomes Main. Further physicians are Sub Accounts.

The Main Physician uses **Manage Sub Accounts** to create, edit, activate or deactivate their clinic's physicians. Each Sub Account signs in separately, edits their own profile and availability, and handles their own assigned consultations, recordings, AI drafts and history. Main status does not grant access to another physician's clinical notes. Unassigned scheduled visits must be assigned by Staff before appearing in a physician's clinical lists.

Only Main can open **Clinic Earnings & Withdrawals**, read a balance, or submit a withdrawal. These checks run on the server as well as in navigation. Admin still reviews simulated withdrawals. All payouts remain school-demo simulations.

Patients see all active, non-deleted physicians in the selected active clinic, without internal account-type labels. Cards show the physician's saved photo, optional specialty and availability status. **View availability** shows actual date/time capacity and continues into booking with that physician selected. The booking save still rechecks availability.

Admin's Physicians page shows main/sub totals, each account's type, a sub-account count for Main accounts, and a **View sub-accounts** modal with names, statuses and creation dates. Existing account editing and status controls remain available. Clinic reassignment is blocked to avoid silently transferring account ownership and clinical history; ownership transfer requires a separately designed workflow.

## Financial attribution

`Appointments.PhysicianID` continues to identify the performing physician. `AppointmentPayments.FinancialOwnerUserID` snapshots the clinic's Main Physician when an eligible completed payment is recognized. Historical payments are initialized once to their clinic's Main without changing amounts, appointment assignments, settlement items or the 90/10 split.

`PhysicianWithdrawals.PhysicianID` preserves the original requester; `FinancialOwnerUserID` records the balance against which it is reserved. Historical pending and simulated-paid requests are included in the pooled Main balance so migration cannot release reservations or make paid funds withdrawable again. Rejection releases the pooled reservation. Payment and withdrawal owner snapshots are not rewritten on migration reruns.

## Deployment and validation

Run `C:\xampp\php\php.exe scripts/setup-physician-accounts.php --apply` after updating the source. The local demo database has already been migrated. The SQL migration is `database/migrations/20261117_physician_accounts.sql` and is included in `database/schema.sql`.

Isolated tests:

- `scripts/test-physician-accounts.php`: temporary schema fixtures for account creation, naming/mobile validation, clinic isolation, sub-account financial denial, patient eligibility, revenue ownership and reservations.
- `scripts/test-physician-withdrawals.php`: settlement eligibility, reservation/release, duplicates, rejection, simulated payment, amounts and owner isolation.
- `scripts/test-concurrent-audit.php`: generated synthetic database for simultaneous withdrawals, reviews, bookings, approvals and top-ups; removed in `finally`.
- `scripts/test-audit-fixes.php`: existing authentication, refunds, queue, preferences and AI-publication regression checks.

Tests do not send SMS/email, run AI inference or initiate real transfers. Browser previews use synthetic records in temporary tables rather than application records.
