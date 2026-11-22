<?php

http_response_code(404);
$pageTitle = 'Page not found';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="error-page" aria-labelledby="error-title">
    <div class="container">
        <div class="card error-page__card">
            <p class="error-page__code" aria-hidden="true">404</p>
            <h1 id="error-title">We couldn't find that page</h1>
            <p>The address may be incorrect, or the page may have moved.</p>
            <nav class="error-page__actions" aria-label="Recovery options">
                <a class="btn btn--primary" href="<?= BASE_URL ?>/index.php">Go to home</a>
                <a class="btn btn--outline" href="<?= BASE_URL ?>/auth/login.php">Log in</a>
            </nav>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
