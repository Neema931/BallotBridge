# Ballot Bridge

Ballot Bridge is a PHP and MySQL voting application with administrator, candidate, and voter areas. It includes account registration and login, election and position management, candidate review, ballot submission, vote receipts, and administrative results.

## Requirements

- PHP 8.1 or later with `PDO`, `pdo_mysql`, `fileinfo`, and sessions enabled
- MySQL 5.7+ or MariaDB 10.4+
- Apache with `.htaccess` support for the provided error documents and access restrictions
- No Composer or npm dependencies are required

## Local setup with XAMPP or WAMP

1. Copy the project folder into the web root:
   - XAMPP: `C:\xampp\htdocs\BallotBridge`
   - WAMP: `C:\wamp64\www\BallotBridge` (or your configured WAMP web root)
2. Start Apache and MySQL from the XAMPP/WAMP control panel.
3. Open phpMyAdmin, create a database named `ballot_bridge` using `utf8mb4`, and import `database/schema.sql`.
4. Configure the seeded administrator email and password before logging in. See [Default administrator login](#default-administrator-login).
5. Edit the database connection values at the top of [`config/database.php`](config/database.php):
   - `$dbHost`
   - `$dbName`
   - `$dbUser`
   - `$dbPassword`
6. Confirm `BASE_URL` in [`config/constants.php`](config/constants.php) matches the folder served by Apache. The default `/BallotBridge` works with the paths above.
7. Open `http://localhost/BallotBridge/` in your browser.

For an existing installation created from an older schema, review and run `database/migration_add_user_reg_number.sql` once if `users.reg_number` is missing. Run `database/migration_voter_review_and_email.sql` once to add voter rejection and review-email tracking to an existing database. Do not run these migrations on a fresh database created from the current `schema.sql`.

### Default administrator login

The schema seeds one active administrator account:

- **Email:** `admin@ballotbridge.local` by default; the CLI setup tool can change it.
- **Password:** Set during installation; the project intentionally does not ship a usable default password.

The seed's password hash is a placeholder. After importing the schema and configuring `config/database.php`, run the local CLI setup tool from the project directory:

```powershell
& 'C:\xampp\php\php.exe' .\tools\set_admin_password.php
```

For WAMP, use the path to its `php.exe`. The script prompts for an email and a password twice, hashes the password, and updates the seeded administrator. It is CLI-only and does not print the password or hash. If CLI access is unavailable, you can generate a hash with PHP's `password_hash()` and update the row through phpMyAdmin:

```sql
UPDATE users
SET password_hash = 'PASTE_THE_GENERATED_HASH_HERE'
WHERE email = 'admin@ballotbridge.local' AND role = 'admin';
```

Use the email and password you entered into the CLI tool to sign in. Do not use the schema placeholder as a real password or publish a generated hash.

### Local account and election setup

- New voter registrations are always `pending`. An administrator must approve or reject them from Manage voters before they can log in. Approved/rejected voters receive an email; failed notifications remain retryable from the voter list.
- Candidate accounts start as `pending` and must be approved by an administrator before login.
- Set `MAIL_FROM_ADDRESS` in [`config/mail.php`](config/mail.php) to an address authorized by your PHP mail transport. Approval and rejection notices use PHP's built-in `mail()` function. XAMPP/WAMP must be configured with a working SMTP/sendmail relay in `php.ini` (and `sendmail.ini` when using the bundled Sendmail utility); shared-hosting deployments should confirm that PHP mail is enabled and authorized for the chosen sender. The voter row shows failed email delivery and provides a retry action.
- When adding a voter from the admin area, set an initial password and share it with the voter securely; passwords are never emailed.
- Candidate election applications currently need to be created as candidate rows for an election and position; there is not yet a candidate-application form in the UI.
- Create a draft election and at least one position in the admin area, set a voting schedule, then open the election.

## Deployment checklist for shared hosting

- [ ] Use a supported PHP version and confirm `pdo_mysql`, `fileinfo`, and sessions are enabled.
- [ ] Create a dedicated MySQL database and a least-privilege database user. Set its credentials in `config/database.php`; do not use the hosting account's database administrator credentials.
- [ ] Import `database/schema.sql` into the production database. Set the seeded administrator's password hash before allowing login.
- [ ] Set `BASE_URL` in `config/constants.php` to the deployed URL path (for example, `/` when installed at the domain root or `/ballotbridge` in a subdirectory).
- [ ] Configure PHP mail/SMTP with an authorized sender, set `MAIL_FROM_ADDRESS` in `config/mail.php`, and test both voter approval and rejection emails. Keep SMTP credentials in server configuration, not source control.
- [ ] Update the `ErrorDocument` paths in the project-root `.htaccess` to match the deployed URL path. Keep the 403 and 404 pages reachable.
- [ ] Ensure Apache permits the project's `.htaccess` rules. The `config` and `database` directories are denied direct web access; if the host uses Nginx or disables `.htaccess`, configure equivalent restrictions at the web-server level.
- [ ] Keep `tools/` out of public web access. The administrator password setup tool is CLI-only; run it locally or through trusted SSH before deleting it from the deployed copy.
- [ ] Serve the site over HTTPS. Verify the session cookie has `Secure`, `HttpOnly`, and `SameSite=Lax` in the deployed browser.
- [ ] Set `APP_ERROR_LOG` to an absolute, writable log path outside the public web root. Keep PHP display errors disabled in the hosting configuration.
- [ ] Allow PHP to create and write candidate images only under `assets/img/candidates/`. Use the narrowest writable directory permissions the host supports.
- [ ] Check upload limits (`upload_max_filesize` and `post_max_size`) are compatible with the 2 MB candidate-photo limit.
- [ ] Do not deploy development backups, database exports, logs, or temporary files under the public web root. Keep independent database backups and test restoration.
- [ ] Test registration, account activation, role boundaries, election lifecycle, ballot submission, duplicate-vote handling, receipts, print output, and custom 403/404 pages on the deployed host.

## Manual testing

The role-by-role functional test matrix, including registration, login, upload validation, voting edge cases, the two-tab double-vote race, and error-response privacy, is in [`docs/manual-test-plan.md`](docs/manual-test-plan.md).