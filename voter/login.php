<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_time.php';
require_once __DIR__ . '/../includes/election_scheduler.php';

activate_due_auto_elections($pdo);

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

$electionsQuery = $pdo->query(
    "SELECT id, title, status, start_datetime, end_datetime, timezone
     FROM elections
     WHERE status IN ('open', 'paused', 'draft')
     ORDER BY CASE
         WHEN status = 'open' THEN 0
         WHEN status = 'paused' THEN 1
         ELSE 2
     END, start_datetime, id DESC"
);
$elections = $electionsQuery->fetchAll();
$electionPhase = 'Polls Closed';
$electionPhaseNote = 'No election is currently open for voting.';
$phaseElectionTitle = null;

foreach ($elections as $election) {
    if (
        $election['status'] === 'open'
        && election_is_within_schedule($election)
    ) {
        $electionPhase = 'Polls Live';
        $electionPhaseNote = 'Voting is currently open.';
        $phaseElectionTitle = $election['title'];
        break;
    }
}

if ($phaseElectionTitle === null) {
    foreach ($elections as $election) {
        if (
            $election['status'] === 'paused'
            && election_has_not_closed($election)
        ) {
            $electionPhaseNote = 'Voting is temporarily paused.';
            $phaseElectionTitle = $election['title'];
            break;
        }
    }
}

if ($phaseElectionTitle === null) {
    foreach ($elections as $election) {
        if (
            in_array($election['status'], ['open', 'draft'], true)
            && election_is_upcoming($election)
        ) {
            $electionPhase = 'Registration Open';
            $electionPhaseNote = 'An upcoming election is being prepared.';
            $phaseElectionTitle = $election['title'];
            break;
        }
    }
}

$voterLoginNotice = get_flash('voter_login_notice');
$voterLoginNotice = in_array($voterLoginNotice, ['pending', 'rejected', 'registered'], true)
    ? $voterLoginNotice
    : null;
$loggedOut = isset($_GET['logged_out'])
    && is_string($_GET['logged_out'])
    && $_GET['logged_out'] === '1';

$pageTitle = 'Voter Portal Sign In';
$hideSiteNavigation = true;
$hideFlashStack = true;
$hideFooter = true;
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section voter-login">
    <div class="container voter-login__container">
        <div class="card voter-login__card">
            <header class="voter-login__header">
                <a class="voter-login__brand" href="<?= BASE_URL ?>/index.php">
                    <span class="voter-login__brand-mark" aria-hidden="true">B</span>
                    <span><?= sanitize(APP_NAME) ?></span>
                </a>
                <h1>Voter Portal Sign In</h1>
                <p>Sign in securely to check your eligibility and access the voter portal.</p>
                <div class="voter-login__phase" aria-label="Current election phase">
                    <span><?= sanitize($electionPhase) ?></span>
                    <?php if (is_string($phaseElectionTitle) && $phaseElectionTitle !== ''): ?>
                        <span class="voter-login__phase-title">
                            <?= sanitize($phaseElectionTitle) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <p class="voter-login__phase-note"><?= sanitize($electionPhaseNote) ?></p>
            </header>

            <?php if (
                $voterLoginNotice !== null
                || $flashMessagesToDisplay !== []
                || $loggedOut
            ): ?>
                <div class="voter-login__alerts" aria-live="polite">
                    <?php if ($voterLoginNotice === 'pending'): ?>
                        <div class="alert voter-login__alert--pending" role="status">
                            <strong>Account Pending Approval</strong>
                            <p>Your registration is awaiting administrator review. You can sign in once it is approved.</p>
                        </div>
                    <?php elseif ($voterLoginNotice === 'rejected'): ?>
                        <div class="alert voter-login__alert--rejected" role="alert">
                            <strong>Registration Rejected</strong>
                            <p>Your voter registration was not approved. Please check your email for the review details.</p>
                        </div>
                    <?php elseif ($voterLoginNotice === 'registered'): ?>
                        <div class="alert voter-login__alert--success" role="status">
                            <strong>Registration submitted</strong>
                            <p>Your account is pending administrator approval. Watch your email for a review update.</p>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($flashMessagesToDisplay as $flash): ?>
                        <div class="alert <?= sanitize($flash['class']) ?> voter-login__alert--flash"
                            role="<?= sanitize($flash['role']) ?>">
                            <?= sanitize($flash['message']) ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($loggedOut): ?>
                        <div class="alert voter-login__alert--success" role="status">
                            You have been securely signed out.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form class="form voter-login__form" data-loading-state method="post"
                action="<?= BASE_URL ?>/auth/process_login.php">
                <?= csrf_field() ?>
                <input type="hidden" name="portal" value="voter">

                <div class="form__group">
                    <label class="form__label" for="voter-identifier">Email Address or Voter ID Number</label>
                    <input class="form__control" id="voter-identifier" name="identifier" type="text"
                        autocomplete="username" maxlength="254" required>
                </div>

                <div class="form__group">
                    <label class="form__label" for="voter-password">Password</label>
                    <div class="password-field">
                        <input class="form__control" id="voter-password" name="password" type="password"
                            autocomplete="current-password" maxlength="72" required>
                        <button class="password-toggle" type="button" aria-controls="voter-password"
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

                <div class="voter-login__options">
                    <label class="voter-login__remember" for="voter-remember">
                        <input id="voter-remember" name="remember_me" type="checkbox" value="1">
                        <span>Remember me</span>
                    </label>
                    <a href="<?= BASE_URL ?>/voter/forgot_password.php">Forgot Password?</a>
                </div>

                <button class="btn btn--primary voter-login__submit" type="submit">
                    Sign In to Vote
                </button>
                <p class="loading-state" role="status" aria-live="polite"
                    data-loading-indicator hidden>
                    <span class="loading-state__spinner" aria-hidden="true"></span>
                    Signing in…
                </p>
            </form>

            <footer class="voter-login__footer">
                <p>Not yet registered? <a href="<?= BASE_URL ?>/voter/register.php">Register to vote here</a></p>
                <a class="voter-login__home" href="<?= BASE_URL ?>/index.php">Return to the home portal</a>
            </footer>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
