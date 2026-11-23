<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (is_logged_in()) {
    $role = $_SESSION['user']['role'] ?? null;
    $destinations = [
        'admin' => '/admin/dashboard.php',
        'candidate' => '/candidate/dashboard.php',
        'voter' => '/voter/dashboard.php',
    ];
    $destination = is_string($role) ? ($destinations[$role] ?? '/index.php') : '/index.php';
    redirect(BASE_URL . $destination);
}

$hideSiteNavigation = true;
$hideFlashStack = true;
$hideFooter = true;
$pageTitle = 'Log in';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section auth-login">
    <div class="container auth-login__container">
        <div class="auth-login__card">
            <aside class="auth-login__intro">
                <a class="auth-login__brand" href="<?= BASE_URL ?>/index.php">
                    <span class="auth-login__brand-mark" aria-hidden="true">B</span>
                    <span><?= sanitize(APP_NAME) ?></span>
                </a>
                <div class="auth-login__welcome">
                    <h1>Welcome back</h1>
                    <p>Log in to continue to your Ballot Bridge account.</p>
                </div>
            </aside>

            <div class="auth-login__content">
                <header class="auth-login__header">
                    <h2>Log in</h2>
                </header>

                <?php if ($flashMessagesToDisplay !== []): ?>
                    <div class="auth-login__alerts" aria-live="polite">
                        <?php foreach ($flashMessagesToDisplay as $flash): ?>
                            <div class="alert <?= sanitize($flash['class']) ?>"
                                role="<?= sanitize($flash['role']) ?>">
                                <?= sanitize($flash['message']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form class="form auth-login__form" data-loading-state method="post"
                    action="<?= BASE_URL ?>/auth/process_login.php">
                    <?= csrf_field() ?>

                    <section class="auth-login__credentials" aria-labelledby="auth-login-credentials-title">
                        <h3 id="auth-login-credentials-title">Account credentials</h3>
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
                    </section>

                    <button class="btn btn--primary auth-login__submit" type="submit">Log in</button>
                    <p class="loading-state" role="status" aria-live="polite"
                        data-loading-indicator hidden>
                        <span class="loading-state__spinner" aria-hidden="true"></span>
                        Signing in…
                    </p>
                </form>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
