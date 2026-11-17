# Capstone demo revenue

This module never contacts a bank or payment gateway. Wallet credits and settlements shown here are simulated.

## Setup

Run `C:\xampp\php\php.exe scripts/setup-demo-revenue.php --apply` from the project folder. This creates the demo settlement and physician withdrawal tables and recognizes existing completed wallet-paid visits only when an actual BookingPayment ledger entry exists and no refund exists. Existing AppointmentPayments rows are left unchanged.

## Demo flow

1. Pay for a booking with demo wallet credits.
2. Complete the physician notes and have staff confirm the consultation complete.
3. Staff confirmation records the ledger amount into AppointmentPayments once, splitting 90% to the physician and the remainder to the platform. No new wallet debit occurs.
4. Admin opens Revenue & Commission. Only eligible completed, wallet-backed payments are included in this demo dashboard.
5. Simulate settlement allocates all currently eligible unallocated payments to a demo payout, using transaction locks and a unique request key. It never releases physician funds or changes wallet balances.
6. Export report downloads demo totals and settlement history.

A refresh does not repeat a successful submission. No fake sample transactions are seeded. Previously recorded non-wallet payments remain in existing reports but are excluded from this demo dashboard. Bank destination is a fixed fictional Demo Bank ending 1234. No gateway fees are simulated. A zero balance disables settlement. Physician payable subtracts simulated paid withdrawals. Pending requests remain part of the payable amount but are reserved against the physician available balance.

Pending settlement is an aggregate of unallocated eligible payments, not a fictitious pending payout in the history table. History shows up to 30 actual simulated settlement records; export includes all.

Test: `C:\xampp\php\php.exe scripts/test-demo-revenue.php` uses connection-local temporary tables only.

## Physician demo withdrawals

Physician sidebar > Earnings & Withdrawals shows eligible demo earnings only. Funds become withdrawable after demo settlement. Requests reserve their amount atomically under a physician row lock; pending plus paid requests cannot exceed settled physician earnings. No patient wallet balance is changed.

GCash/Maya require an 11-digit 09 mobile number. Bank destinations use a bank name and 6-34 numeric account digits. Use fictional details for the capstone. List views mask destination numbers. The admin review modal can view submitted details. There is no external payout provider or real transfer.

Admin > Revenue > Physician withdrawals allows reviewing pending requests. Simulate payout moves reserved money to Paid - Simulated. Reject releases the reservation. Reviewed requests cannot be paid a second time or switched to another decision. Duplicate form submissions reuse unique request keys.

Withdrawal history shows the last 50 requests for the signed-in physician; admin shows the most recent 100 matching the selected status. All balance calculations include the full history, not just displayed rows.

Test: `C:\xampp\php\php.exe scripts/test-physician-withdrawals.php` uses connection-local temporary fixtures.
