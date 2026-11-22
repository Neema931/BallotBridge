<?php

http_response_code(403);
$pageTitle = 'Access denied';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="error-page" aria-labelledby="error-title">
    <div class="container">
        <div class="card error-page__card">
            <p class="error-page__code" aria-hidden="true">403</p>
            <h1 id="error-title">Access denied</h1>
            <p>
                You don't have permission to view this page, or the form may have expired.
                Return to the form and reload it before trying again.
            </p>
            <nav class="error-page__actions" aria-label="Recovery options">
                <a class="btn btn--primary" href="<?= BASE_URL ?>/index.php">Go to home</a>
                <a class="btn btn--outline" href="<?= BASE_URL ?>/auth/login.php">Log in</a>
            </nav>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
