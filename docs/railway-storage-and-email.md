# Railway Storage and Production Email Readiness

This application stores uploaded avatars and catalog images on Laravel's `public` filesystem disk. Paths in the database stay relative (for example, `avatars/<uuid>.jpg`); application code does not contain a Railway-specific filesystem path.

## Persistent image storage

1. In Railway, attach a **Volume to the Laravel application service**, never to the MySQL service.
2. Set the Volume mount path exactly to:

   ```text
   /app/storage/app/public
   ```

3. Deploy the application normally. After each release, ensure the standard Laravel public-storage link exists:

   ```bash
   php artisan storage:link
   ```

   Laravel's command is safe to rerun when the correct link already exists. Do not replace it with a manually created link or copy uploads into the repository.
4. In the Admin area, upload an Avatar, Night Market, Stall, or Food image. Confirm that it displays from `/storage/...` on a public page.
5. Redeploy the same application version and reload that page. The exact image must still display. If it does not, stop further uploads and confirm the Volume is attached to the Laravel service at the exact mount path above.

The local placeholder SVGs remain the intended fallback when no image exists or a stored path is unsafe. User-uploaded files must never be committed to Git.

### Recovery and rollback

- A code rollback must retain the same attached Volume; do not detach, recreate, or mount an empty Volume as part of rollback.
- If a Volume attachment was wrong, reattach the original Volume to the Laravel service, deploy, rerun `storage:link`, and verify an existing known image before accepting new uploads.
- Database records retain relative paths. Do not mass-edit them to an absolute Railway path.

## Production SMTP readiness

Testing continues to use Laravel's array mailer. Development behavior is unchanged. Production must use a real SMTP transport for registration verification and password-reset mail; the `log` mailer is not suitable for those user journeys.

Set these Railway service variables in the Railway dashboard or other secret manager, never in source control:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="Night Market Selangor"
```

Use the hostname, port, encryption mode, username, password, and verified sender supplied by the selected SMTP provider. Do not commit a provider selection, password, verification URL, or reset token. After configuration, register a disposable test account and request a password reset; verify delivery, sender identity, HTTPS links, and token expiry. Remove the test account through the normal Admin workflow if appropriate.

`MAIL_ENCRYPTION=tls` uses Laravel's normal SMTP transport; `MAIL_ENCRYPTION=ssl` is mapped to the secure `smtps` transport. `MAIL_SCHEME`, when intentionally set, remains the explicit Laravel 12 override.

Application code must not add custom logging of SMTP secrets, verification links, reset tokens, or plaintext passwords. Laravel's existing notification tests use fake notifications and do not send real mail.
