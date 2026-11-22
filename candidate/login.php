<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_time.php';

if (is_logged_in()) {
    if (($_SESSION['user']['role'] ?? null) === 'candidate') {
        redirect(BASE_URL . '/candidate/dashboard.php');
    }

    redirect(BASE_URL . '/index.php');
}

$cycleQuery = $pdo->query(
    "SELECT id, title, status, start_datetime, end_datetime, timezone, support_email
     FROM elections
    WHERE status IN ('open', 'paused', 'draft')
    ORDER BY CASE
        WHEN status = 'open' THEN 0
        WHEN status = 'paused' THEN 1
        ELSE 2
    END, start_datetime, id DESC"
);
$electionCycle = null;
foreach ($cycleQuery->fetchAll() as $election) {
    $now = new DateTimeImmutable('now', new DateTimeZone($election['timezone']));
    $isUpcoming = election_is_upcoming($election, $now);
    $isLive = election_is_within_schedule($election, $now);
    $isPaused = $election['status'] === 'paused' && election_has_not_closed($election, $now);
    if (
        ($election['status'] === 'open' && ($isUpcoming || $isLive))
        || $isPaused
        || ($election['status'] === 'draft' && $isUpcoming)
    ) {
        $electionCycle = $election;
        break;
    }
}

$pageTitle = 'Candidate Portal Login';
$hideSiteNavigation = true;
$hideFlashStack = true;
$hideFooter = true;
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section candidate-login">
    <div class="container candidate-login__container">
        <div class="card candidate-login__card">
            <header class="candidate-login__header">
                <a class="candidate-login__brand" href="<?= BASE_URL ?>/index.php">
                    <span class="candidate-login__brand-mark" aria-hidden="true">B</span>
                    <span><?= sanitize(APP_NAME) ?></span>
                </a>
                <h1>Candidate Portal Login</h1>
                <p>Sign in to manage your application and campaign profile.</p>
                <span class="candidate-login__cycle">
                    <?= $electionCycle
                        ? 'Election cycle · ' . sanitize($electionCycle['title'])
                        : 'Secure candidate access' ?>
                </span>
            </header>

            <?php if ($flashMessagesToDisplay !== [] || (isset($_GET['logged_out']) && $_GET['logged_out'] === '1')): ?>
                <div class="candidate-login__alerts" aria-live="polite">
                    <?php foreach ($flashMessagesToDisplay as $flash): ?>
                        <div class="alert <?= sanitize($flash['class']) ?>" role="<?= sanitize($flash['role']) ?>">
                            <?= sanitize($flash['message']) ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (isset($_GET['logged_out']) && $_GET['logged_out'] === '1'): ?>
                        <div class="alert alert--success" role="status">
                            You have been securely signed out.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form class="form candidate-login__form" data-loading-state method="post"
                action="<?= BASE_URL ?>/auth/process_login.php">
                <?= csrf_field() ?>
                <input type="hidden" name="portal" value="candidate">

                <div class="form__group">
                    <label class="form__label" for="candidate-identifier">Email address or Candidate / Voter ID</label>
                    <input class="form__control" id="candidate-identifier" name="identifier" type="text"
                        autocomplete="username" maxlength="254" required>
                </div>

                <div class="form__group">
                    <label class="form__label" for="candidate-password">Password</label>
                    <div class="password-field">
                        <input class="form__control" id="candidate-password" name="password" type="password"
                            autocomplete="current-password" maxlength="72" required>
                        <button class="password-toggle" type="button" aria-controls="candidate-password"
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

                <div class="candidate-login__options">
                    <label class="candidate-login__remember" for="candidate-remember">
                        <input id="candidate-remember" name="remember_me" type="checkbox" value="1">
                        <span>Remember me</span>
                    </label>
                    <a href="<?= BASE_URL ?>/candidate/forgot_password.php">Forgot Password?</a>
                </div>

                <button class="btn btn--primary candidate-login__submit" type="submit">
                    Sign In to Candidate Portal
                </button>
                <p class="loading-state" role="status" aria-live="polite"
                    data-loading-indicator hidden>
                    <span class="loading-state__spinner" aria-hidden="true"></span>
                    Signing in…
                </p>
            </form>

            <footer class="candidate-login__footer">
                <p>New applicant? <a href="<?= BASE_URL ?>/candidate/register.php">Submit your candidacy here</a></p>
                <a class="candidate-login__home" href="<?= BASE_URL ?>/index.php">Return to the home portal</a>
            </footer>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
