# Gmail notifications

Install the PHP dependencies from the project root:

```sh
composer install
```

Configure these environment variables for the PHP/Apache process:

- `HQ_SMTP_USERNAME`: Gmail address used to authenticate.
- `HQ_SMTP_PASSWORD`: Google App Password for that account (not the normal account password).
- `HQ_MAIL_FROM`: sender email address, usually the same Gmail address.
- `HQ_MAIL_FROM_NAME`: optional sender label; defaults to `HealthQueue`.
- `HQ_SMTP_HOST`: optional SMTP host; defaults to `smtp.gmail.com`.
- `HQ_SMTP_PORT`: optional SMTP port; defaults to `587` (STARTTLS).
- `HQ_APP_URL`: public base URL of this application, with no trailing slash, for example `https://example.com`.

Password reset messages are sent after a user requests a reset. Queue emails are sent when the patient reaches two or fewer waiting entries ahead of them, and when staff call them. Gmail errors are logged and do not interrupt application actions. For local XAMPP, configure environment variables in the Apache service environment and restart Apache after changes.

## Patient email verification

The signup form’s **Verify email** button sits beside the email field and sends a one-time link (valid for 24 hours). Opening it shows a success message; the original signup page polls for confirmation and displays a green check. Returning through the success page also opens signup with the email marked verified. Account creation consumes the verified token and signs the patient in. Verification requests are limited to one email per minute. Staff and administrator sign-in is unchanged. Existing accounts are marked verified by the migration. Accounts created by the previous signup flow can still use the login page’s resend verification link.

Before using the updated registration flow on an existing database, apply `database/migrations/20261028_create_signup_email_verifications.sql` once. If you have not yet applied `database/migrations/20261028_add_patient_email_verification.sql`, apply it first. The main `database/schema.sql` includes both token tables for fresh installs. Configure `HQ_APP_URL` to the full public app URL (including `/healthqueue` for a typical local XAMPP URL) so links point to the right location.
