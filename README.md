# Ballot Bridge

Ballot Bridge is a PHP and MySQL voting application with administrator, candidate, and voter areas. It includes account registration and login, election and position management, candidate review, ballot submission, vote receipts, and administrative results.

## Requirements

- PHP 8.1 or later with `PDO`, `pdo_mysql`, `fileinfo`, `dom`, and sessions enabled
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

For an existing installation created from an older schema, review and run any needed migrations once in this order: `migration_add_user_reg_number.sql`, `migration_voter_review_and_email.sql`, `migration_add_audit_logs.sql`, `migration_admin_dashboard.sql`, `migration_election_setup_wizard.sql`, `migration_candidate_ballot_builder.sql`, `migration_election_settings.sql`, `migration_audit_ledger.sql`, `migration_election_results.sql`, `migration_voter_credentials.sql`, `migration_candidate_dashboard.sql`, `migration_candidate_application_source.sql`, then `migration_ballot_receipt_ledger.sql`. The election-setup migration adds timezone-aware schedules, voting rules, ranked-choice data, per-position ballot submissions, and email verification. The ballot-builder migration adds referendum contests, per-contest order randomization, and account-less referendum choices. The election-settings migration adds a per-election support email. The audit-ledger migration adds actor snapshots, IP addresses, and before/after JSON, then installs database triggers that reject audit-row updates and deletes. The election-results migration adds the UTC closure time and eligible-voter count snapshot used for stable final turnout. The voter-credentials migration creates storage for hashed, expiring, single-use password setup links. The candidate-dashboard migration adds optional nomination deadlines, campaign slogans, and administrator rejection feedback. The candidate-application-source migration tracks self-nominations separately from administrator-assigned accounts for accurate review counts. The ballot-receipt-ledger migration adds opaque participation hashes; historical submissions remain without hashes. These receipts prove that a participation record was issued, not that selections were tallied or that the database provides voter anonymity. Historical closed elections keep a null snapshot because their close-time voter roster cannot be reconstructed reliably. The application cannot alter audit rows, but a database administrator with schema privileges can remove those triggers; retain independent database backups for stronger forensic assurance. Do not run migrations on a fresh database created from the current `schema.sql`; back up an existing database before migrating it.

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

- New voter registrations are always `pending`. Manage voters supports approval/rejection, CSV imports (`full_name,email,reg_number` headers; optional `phone`), manual onboarding, edits, and eligibility revocation. Approved/rejected voters receive a decision email; rejection can include an administrator-provided reason. Eligible voters can be sent a one-time password setup link; passwords are never emailed. Imported accounts receive the same setup link and remain pending until approved. Links are stored hashed, expire after 30 minutes, and work once. Run `migration_voter_credentials.sql` on existing installations before using this feature.
- Manage voters shows participation for the selected election using only ballot-submission records; it never queries or displays candidate selections in the voter table.
- Candidates can apply through [`candidate/register.php`](candidate/register.php) when a Draft or scheduled election has open candidate positions, then sign in through [`candidate/login.php`](candidate/login.php) after administrator review. Candidate accounts and applications start as `pending`; administrator decisions send the applicant an approval or feedback email. Rejected applications retain an active candidate account so the candidate can review administrator feedback, edit their profile, and resubmit; the next review resets their application to pending.
- Candidate dashboards show each application's status, rejection feedback, election countdown and milestones, and ballot preview. My Profile lets candidates manage public ballot details and account email/password; public profile edits lock after the nomination-freeze deadline or when voting is live, paused, or closed. Election setup and Settings can optionally set a nomination-freeze deadline no later than poll opening; campaign slogans are shown in candidate previews and on voter ballots.
- The admin Audit logs page records voter, candidate, election, and position management actions, failed sign-in and throttling events, UTC timestamps, actor, IP address, and available before/after JSON. Use its filters to search and export CSV, or open the print report and choose the browser's Save as PDF option. Existing installations must run the audit-log migrations before opening that page.
- See results keeps every count, candidate breakdown, and export server-side hidden unless the selected election status is `closed`. The lock screen counts down to poll close and checks the status every 15 seconds; once closed, the report shows aggregate turnout and contest outcomes, with a print-to-PDF report and aggregate-only CSV (never voter-level ballot selections). New closures snapshot the active eligible-voter count so later account-status changes do not alter final turnout; older closed elections use the current active roster and are labeled accordingly.
- The admin dashboard selects the current live, scheduled, draft, or paused election and refreshes turnout and audit data every 30 seconds. Existing installations must run the admin-dashboard migration to enable pause/resume and active voter-session metrics.
- The Settings page edits general election information, schedule, voting rules, and authentication methods. Those settings become read-only when voting starts or ballot activity exists. Status changes, settings saves, vote resets, and election deletion are recorded in Audit logs. Reset Test Votes is restricted to Draft elections; deleting an election requires a typed title and is blocked when ballot activity remains.
- Set `MAIL_FROM_ADDRESS` in [`config/mail.php`](config/mail.php) to an address authorized by your PHP mail transport. Voter approval/rejection notices and election email-token verification use PHP's built-in `mail()` function. XAMPP/WAMP must be configured with a working SMTP/sendmail relay in `php.ini` (and `sendmail.ini` when using the bundled Sendmail utility); shared-hosting deployments should confirm that PHP mail is enabled and authorized for the chosen sender. The voter row shows failed review-email delivery and provides a retry action.
- When adding a voter manually, set an initial password and share it securely; passwords are never emailed. CSV imports create a random inaccessible initial password and send a one-time setup link instead.
- Create a draft election, then use Manage candidates to add candidate positions or referendums. Assign registered candidate accounts to candidate positions and approve their applications; add referendum options directly. Candidate contests and referendum choices follow the election-wide voting system, and optional order randomization affects only the voter-facing ballot.
- The election setup wizard supports First-Past-the-Post, Multiple Selection (limited by each position's maximum), and Ranked Choice (instant-runoff voting). In IRV, candidates tied for the lowest count are eliminated together; if all remaining candidates are tied, results show no winner. Abstentions are recorded per position. Email-token and voter-ID/password verification are required when selected; SMS OTP remains unavailable until an SMS provider is configured.
- Election times are stored as local wall-clock values together with the selected IANA timezone. Existing elections are migrated with `UTC`; review and correct their timezone in Manage elections if their old date fields used another zone.
- Automatic launch requires at least one position. The scheduler also runs on application requests; configure a periodic CLI task for timely activation even when nobody is using the site. On Windows Task Scheduler, run every minute:

  ```powershell
  C:\xampp\php\php.exe C:\xampp\htdocs\BallotBridge\tools\activate_scheduled_elections.php
  ```

  Adjust both paths for the installation. On shared hosting, add the equivalent PHP CLI command as a cron job if shell/cron access is available; request-driven processing remains the fallback.

## Deployment checklist for shared hosting

- [ ] Use a supported PHP version and confirm `pdo_mysql`, `fileinfo`, `dom`, and sessions are enabled.
- [ ] Create a dedicated MySQL database and a least-privilege database user. Set its credentials in `config/database.php`; do not use the hosting account's database administrator credentials.
- [ ] Import `database/schema.sql` into the production database. Set the seeded administrator's password hash before allowing login.
- [ ] Set `BASE_URL` in `config/constants.php` to the deployed URL path (for example, `/` when installed at the domain root or `/ballotbridge` in a subdirectory).
- [ ] Configure PHP mail/SMTP with an authorized sender, set `MAIL_FROM_ADDRESS` in `config/mail.php`, and test both voter approval and rejection emails. Keep SMTP credentials in server configuration, not source control.
- [ ] Update the `ErrorDocument` paths in the project-root `.htaccess` to match the deployed URL path. Keep the 403 and 404 pages reachable.
- [ ] Ensure Apache permits the project's `.htaccess` rules. The `config` and `database` directories are denied direct web access; if the host uses Nginx or disables `.htaccess`, configure equivalent restrictions at the web-server level.
- [ ] Keep `tools/` out of public web access. The administrator password setup tool is CLI-only; run it locally or through trusted SSH before deleting it from the deployed copy.
- [ ] Serve the site over HTTPS. Verify the session cookie has `Secure`, `HttpOnly`, and `SameSite=Lax` in the deployed browser.
- [ ] Set `APP_ERROR_LOG` to an absolute, writable log path outside the public web root. Keep PHP display errors disabled in the hosting configuration.
- [ ] Allow PHP to create and write candidate images under `assets/img/candidates/` and election logos under `assets/img/elections/`. Use the narrowest writable directory permissions the host supports.
- [ ] Check upload limits (`upload_max_filesize` and `post_max_size`) are compatible with the 2 MB candidate-photo and election-logo limits.
- [ ] Do not deploy development backups, database exports, logs, or temporary files under the public web root. Keep independent database backups and test restoration.
- [ ] Configure a cron/CLI schedule for `tools/activate_scheduled_elections.php` where available; verify request-driven activation as a fallback.
- [ ] Test registration, account activation, role boundaries, election setup, schedule/timezone behavior, candidate assignments, all voting systems, verification methods, abstentions, duplicate-submission handling, IRV rounds/ties, receipts, print output, and custom 403/404 pages on the deployed host.

## Manual testing

The role-by-role functional test matrix, including registration, login, upload validation, voting edge cases, the two-tab double-vote race, and error-response privacy, is in [`docs/manual-test-plan.md`](docs/manual-test-plan.md).