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
