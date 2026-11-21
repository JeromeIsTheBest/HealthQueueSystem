# Automatic demo earnings and self-service withdrawals

Current demo flow: a valid demo-wallet payment + physician-finalized note + Staff-confirmed completion creates one 90/10 payment record and one automatic release in the same transaction. Replaying completion cannot create another payment or release. Unpaid, cancelled, no-show, refunded or unsupported-payment visits do not qualify.

Main physicians: Earnings → Withdraw earnings → enter amount and demo destination → Review withdrawal → Confirm simulated withdrawal. Confirmation records PaidSimulated immediately. It never calls a payout provider. No Admin approval is needed. The owner balance lock, available-balance validation and unique request key prevent overspending and duplicate payouts. Sub-accounts cannot access financial balances or withdraw.

Available = eligible released physician share − existing pending reservations − completed simulated payouts. Existing pending requests stay reserved and are not auto-paid during deployment. Their financial owner can confirm them from Earnings → Withdrawal history. Repeat confirmations are harmless. Historical rejected requests remain rejected. Patient wallet balances are unaffected by release and simulated withdrawal.

Admin Revenue and Physician withdrawals are monitoring screens. Old settlement/review POST actions are rejected with HTTP 405. Transaction histories, audit logs, wallet reconciliation and revenue export remain available. The older internal reservation/review functions remain only for compatibility and regression tests; production pages do not expose Admin approval actions.

Deployment on another copy: `C:\xampp\php\php.exe scripts/setup-auto-demo-release.php --apply` safely releases any already eligible payments not included in a prior settlement, without paying withdrawals or changing patient wallets. No new database columns are needed. Local deployment was run; it found no unreleased eligible records. Future completion releases automatically without a worker.

Verification: test-auto-demo-earnings.php, test-demo-revenue.php, test-physician-withdrawals.php, test-workflow-fixes.php and test-concurrent-audit.php. These use temporary tables or a disposable isolated database. No external messages, AI calls or real transfers are made.

## Automatic platform demo-bank recording

Only HealthQueue's PlatformCommission is recorded to PlatformDemoBankTransfers after an eligible completed payment is released. One unique PaymentID allows one demo-bank receipt; replay cannot duplicate it. The physician share stays outside this ledger. All records are CompletedSimulated, with an explicitly fictional HealthQueue Demo Bank account ending 0000. No bank API is connected and no real money moves. Admin Revenue remains monitoring-only, showing commission, demo-bank totals and receipt history.

For another copy, run `scripts/setup-platform-demo-bank.php --apply` before `scripts/setup-auto-demo-release.php --apply`. The first installs the ledger and records existing eligible released commissions once. The second handles any remaining unreleased eligible payments. Existing patient wallets and physician payouts are not changed. Local deployment created five historical simulated commission receipts.
