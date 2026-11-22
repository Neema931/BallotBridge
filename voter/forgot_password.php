<?php

require_once __DIR__ . '/../config/database.php';

$supportQuery = $pdo->query(
    "SELECT title, support_email
     FROM elections
     WHERE support_email IS NOT NULL AND support_email <> ''
     ORDER BY CASE
         WHEN status = 'open' THEN 0
         WHEN status = 'paused' THEN 1
         ELSE 2
     END, start_datetime DESC, id DESC
     LIMIT 1"
);
$supportContact = $supportQuery->fetch();
$supportEmail = $supportContact
    && is_string($supportContact['support_email'])
    && filter_var($supportContact['support_email'], FILTER_VALIDATE_EMAIL) !== false
    ? $supportContact['support_email']
    : null;

$pageTitle = 'Voter password help';
$hideSiteNavigation = true;
$hideFooter = true;
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section voter-login voter-login--help">
    <div class="container voter-login__container">
        <div class="card voter-login__card">
            <header class="voter-login__header">
                <a class="voter-login__brand" href="<?= BASE_URL ?>/index.php">
                    <span class="voter-login__brand-mark" aria-hidden="true">B</span>
                    <span><?= sanitize(APP_NAME) ?></span>
                </a>
                <h1>Voter password help</h1>
                <p>Password resets are handled by your election administrator.</p>
            </header>

            <?php if ($supportEmail !== null): ?>
                <div class="alert alert--info" role="status">
                    <?php if (is_string($supportContact['title']) && $supportContact['title'] !== ''): ?>
                        Contact the administrator for <?= sanitize($supportContact['title']) ?> at
                    <?php else: ?>
                        Contact the election administrator at
                    <?php endif; ?>
                    <a href="mailto:<?= sanitize($supportEmail) ?>?subject=Voter%20account%20password%20help">
                        <?= sanitize($supportEmail) ?>
                    </a>.
                    Include your account email or Voter ID. Never send your password.
                </div>
            <?php else: ?>
                <div class="alert alert--info" role="status">
                    No support email is configured. Please contact your election administrator through your organization.
                    Never send your password.
                </div>
            <?php endif; ?>

            <div class="voter-login__help-links">
                <a class="btn btn--primary" href="<?= BASE_URL ?>/voter/login.php">Back to Voter Portal Sign In</a>
                <a href="<?= BASE_URL ?>/index.php">Return to the home portal</a>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
