<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('voter');
require_once __DIR__ . '/../config/database.php';

$userId = (int) current_user()['id'];
$voterQuery = $pdo->prepare(
    "SELECT v.id
     FROM voters v
     INNER JOIN users u ON u.id = v.user_id
     WHERE u.id = :user_id AND u.role = 'voter'
     LIMIT 1"
);
$voterQuery->execute(['user_id' => $userId]);
$voterId = $voterQuery->fetchColumn();

if ($voterId === false) {
    throw new RuntimeException('Voter profile was not found.');
}

$receiptsQuery = $pdo->prepare(
    'SELECT e.id, e.title, MAX(v.cast_at) AS latest_vote_at
     FROM votes v
     INNER JOIN elections e ON e.id = v.election_id
     WHERE v.voter_id = :voter_id
     GROUP BY e.id, e.title
     ORDER BY latest_vote_at DESC'
);
$receiptsQuery->execute(['voter_id' => $voterId]);
$receipts = $receiptsQuery->fetchAll();

$pageTitle = 'My voting receipts';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>My voting receipts</h1>
                <p>Receipts confirm when your votes were recorded, without displaying your selections.</p>
            </div>
        </header>

        <?php if ($receipts === []): ?>
            <div class="empty-state" role="status">
                <h2>No receipts yet</h2>
                <p>No votes have been recorded for your account.</p>
            </div>
        <?php else: ?>
            <div class="grid grid--2">
                <?php foreach ($receipts as $receipt): ?>
                    <article class="card voter-receipt">
                        <span class="badge badge--success">Vote recorded</span>
                        <h2><?= sanitize($receipt['title']) ?></h2>
                        <p>
                            <strong>Latest vote recorded:</strong>
                            <time datetime="<?= sanitize($receipt['latest_vote_at']) ?>">
                                <?= sanitize($receipt['latest_vote_at']) ?>
                            </time>
                        </p>
                        <p>Your candidate selections are not shown on this receipt.</p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>