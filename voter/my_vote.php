<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('voter');
require_once __DIR__ . '/../config/database.php';

$userId = (int) current_user()['id'];
$receiptsQuery = $pdo->prepare(
    'SELECT e.id, e.title, e.timezone,
            MAX(bs.submitted_at) AS latest_submission_at,
            COUNT(*) AS submitted_positions,
            SUM(bs.abstained = 1) AS abstentions
     FROM voters v
     INNER JOIN ballot_submissions bs ON bs.voter_id = v.id
     INNER JOIN elections e ON e.id = bs.election_id
     WHERE v.user_id = :user_id
     GROUP BY e.id, e.title, e.timezone
     ORDER BY latest_submission_at DESC'
);
$receiptsQuery->execute(['user_id' => $userId]);
$receipts = $receiptsQuery->fetchAll();

$pageTitle = 'My voting receipts';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>My voting receipts</h1>
                <p>Receipts confirm when your ballot was recorded, without displaying your selections.</p>
            </div>
        </header>

        <?php if ($receipts === []): ?>
            <div class="empty-state" role="status">
                <h2>No receipts yet</h2>
                <p>No ballot submissions have been recorded for your account.</p>
            </div>
        <?php else: ?>
            <div class="grid grid--2">
                <?php foreach ($receipts as $receipt): ?>
                    <article class="card voter-receipt">
                        <span class="badge badge--success">Ballot recorded</span>
                        <h2><?= sanitize($receipt['title']) ?></h2>
                        <p>
                            <strong>Latest submission:</strong>
                            <time datetime="<?= sanitize($receipt['latest_submission_at']) ?>">
                                <?= sanitize($receipt['latest_submission_at']) ?>
                            </time>
                        </p>
                        <p>
                            <?= (int) $receipt['submitted_positions'] ?> position(s) submitted;
                            <?= (int) $receipt['abstentions'] ?> abstention(s).
                        </p>
                        <p>Your candidate selections are not shown on this receipt.</p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
