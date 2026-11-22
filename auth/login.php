<?php

require_once __DIR__ . '/../includes/csrf.php';

$hideGuestAuthLinks = true;
$hideFooter = true;
$pageTitle = 'Log in';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <div class="form-card">
            <header class="form-card__header">
                <h1>Log in</h1>
                <p>Enter your account details to continue.</p>
            </header>

            <form class="form" data-loading-state method="post"
                action="<?= BASE_URL ?>/auth/process_login.php">
                <?= csrf_field() ?>

                <div class="form__group">
                    <label class="form__label" for="email">Email address</label>
                    <input class="form__control" id="email" name="email" type="email"
                        autocomplete="username" maxlength="254" required>
                </div>

                <div class="form__group">
                    <label class="form__label" for="password">Password</label>
                    <div class="password-field">
                        <input class="form__control" id="password" name="password" type="password"
                            autocomplete="current-password" maxlength="72" required>
                        <button class="password-toggle" type="button" aria-controls="password"
                            aria-label="Show password" aria-pressed="false" data-password-toggle>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
                                data-password-toggle-show hidden>
                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
                                data-password-toggle-hide>
                                <path d="M3 3 21 21M10.6 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.6 7 10 7a10.7 10.7 0 0 0 3-.4"/>
                                <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-card__actions">
                    <button class="btn btn--primary" type="submit">Log in</button>
                </div>
                <p class="loading-state" role="status" aria-live="polite"
                    data-loading-indicator hidden>
                    <span class="loading-state__spinner" aria-hidden="true"></span>
                    Signing in…
                </p>
            </form>

            <p class="auth-login__voter-link">
                Voter? <a href="<?= BASE_URL ?>/voter/login.php">Sign in to the Voter Portal</a>
            </p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>