<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../config/database.php';

$statsQuery = $pdo->prepare(
    'SELECT
        (SELECT COUNT(*) FROM voters) AS total_voters,
        (SELECT COUNT(*) FROM candidates) AS total_candidates,
        (SELECT COUNT(*) FROM elections) AS total_elections,
        (SELECT COUNT(*) FROM votes) AS total_votes'
);
$statsQuery->execute();
$stats = $statsQuery->fetch();
$pageTitle = 'Admin dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>Admin dashboard</h1>
                <p>Monitor election activity and manage Ballot Bridge.</p>
            </div>
        </header>

        <div class="dashboard__stats">
            <article class="stat-card">
                <span class="stat-card__label">Total voters</span>
                <strong class="stat-card__value"><?= (int) $stats['total_voters'] ?></strong>
                <a href="<?= BASE_URL ?>/admin/manage_voters.php">Manage voters</a>
            </article>
            <article class="stat-card">
                <span class="stat-card__label">Candidates</span>
                <strong class="stat-card__value"><?= (int) $stats['total_candidates'] ?></strong>
                <a href="<?= BASE_URL ?>/admin/manage_candidates.php">Review candidates</a>
            </article>
            <article class="stat-card">
                <span class="stat-card__label">Elections</span>
                <strong class="stat-card__value"><?= (int) $stats['total_elections'] ?></strong>
                <a href="<?= BASE_URL ?>/admin/manage_elections.php">Manage elections</a>
            </article>
            <article class="stat-card">
                <span class="stat-card__label">Votes cast</span>
                <strong class="stat-card__value"><?= (int) $stats['total_votes'] ?></strong>
                <a href="<?= BASE_URL ?>/admin/results.php">View results</a>
            </article>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>