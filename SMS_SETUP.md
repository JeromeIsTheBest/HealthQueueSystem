# textbee SMS setup

HealthQueue sends booking confirmations, approaching-turn alerts (0, 1 or 2 eligible waiting patients ahead), and called-patient alerts through textbee. Messages are queued in SmsOutbox; only the CLI worker contacts textbee. No branded sender name is needed: the recipient sees your Android SIM number.

1. Verify your textbee account email, register an Android phone, grant SMS permission and keep it online with SMS allowance. Set battery usage to unrestricted. Select a default device or copy its device ID.
2. Create a textbee API key. Store it only in local server configuration, never in Git or chat.
3. Edit existing entries in C:\xampp\apache\conf\extra\httpd-xampp.conf:

```apache
SetEnv HQ_SMS_ENABLED "1"
SetEnv HQ_TEXTBEE_API_KEY "YOUR_PRIVATE_TEXTBEE_API_KEY"
SetEnv HQ_TEXTBEE_DEVICE_ID "YOUR_DEVICE_ID"
```

Device ID is optional if you have a default device. Restart Apache after editing. Legacy provider credentials are no longer used.

4. Check without sending:

```powershell
powershell.exe -NoProfile -File C:\xampp\htdocs\healthqueue\scripts\process-sms.ps1 -Check
```

5. Use your own number and enable SMS Notifications in the patient profile. Approve a valid test booking, then send eligible queued messages:

```powershell
powershell.exe -NoProfile -File C:\xampp\htdocs\healthqueue\scripts\process-sms.ps1
```

The launcher reads only SMS SetEnv values into its process environment. Direct php.exe invocation instead requires the same environment variables in that shell. Run the worker every minute using Windows Task Scheduler for background alerts. The local Windows task `HealthQueue SMS Reminders` is now enabled and runs this launcher every minute. It runs while the current Windows user is logged in; keep the laptop, MySQL and Android sender phone available.

6. Inspect SmsOutbox. accepted means textbee accepted a batch for the phone, not confirmed delivery. ProviderMessageID stores smsBatchId. Check actual delivery in textbee and on the recipient phone. failed means configuration/request error; pending HTTP429 retries after five minutes up to three attempts; unknown outcomes are not resent automatically.

Existing unknown/failed/accepted entries from the previous provider are not reset or resent during migration. Use a fresh test booking after configuration to avoid duplicating earlier messages.

No schema change is needed. Automated safe checks: `C:\xampp\php\php.exe scripts/test-sms.php --database`. They use temporary database tables and send no messages.

Official documentation: https://textbee.dev/docs/sending-sms/sending-sms
