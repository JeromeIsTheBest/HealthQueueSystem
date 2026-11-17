# HealthQueue backup and recovery

Run from the project folder:

```powershell
C:\xampp\php\php.exe scripts\backup-and-check.php --run
```

The script creates a timestamped SQL backup under `storage/backups`, imports it into a newly generated `healthqueue_restore_check_...` database, verifies table row counts, and drops only that disposable database. It never restores over `healthqueue_db`. Run while users are not changing records so count comparison remains meaningful. A failed count check retains the backup for review.

The SQL file contains private application data. `storage` is blocked from HTTP access by the root `.htaccess`. Keep backups on access-controlled storage, copy them to a separate protected device, and do not commit them to Git.

Also back up `storage/audio`, `storage/medical-records`, and `assets/uploads`: the SQL file contains file references, not attachment bytes. Keep the files and database backup from the same maintenance window. Configuration credentials belong in a separate access-controlled recovery record.

For recovery, first restore into a separate database using MySQL's client and review its counts, relationships, application settings, and attachment paths. Only an authorized operator should replace the live database after taking another backup and stopping user activity. The automated check does not overwrite the live database or modify scheduled tasks.

After restoration, sign in with each role, inspect a booking and completed visit, verify a protected attachment, and check wallet and payout totals. Keep SMS/email workers disabled while validating an isolated restored environment to avoid duplicate delivery.
