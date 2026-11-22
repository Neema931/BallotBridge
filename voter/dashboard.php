<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('voter');
require_once __DIR__ . '/../config/database.php';

$userId = (int) current_user()['id'];
$voterQuery = $pdo->prepare(
    "SELECT v.id, u.full_name, u.status
     FROM voters v
     INNER JOIN users u ON u.id = v.user_id
     WHERE u.id = :user_id AND u.role = 'voter'
     LIMIT 1"
);
$voterQuery->execute(['user_id' => $userId]);
$voter = $voterQuery->fetch();

if (!$voter) {
    throw new RuntimeException('Voter profile was not found.');
}

$electionsQuery = $pdo->prepare(
    'SELECT e.id, e.title, e.description, e.start_datetime, e.end_datetime,
            (NOW() BETWEEN e.start_datetime AND e.end_datetime) AS voting_available,
            EXISTS (
                SELECT 1 FROM votes v
                WHERE v.election_id = e.id AND v.voter_id = :voter_id
            ) AS has_voted
     FROM elections e
     WHERE e.status = \'open\'
     ORDER BY e.start_datetime, e.title'
);
$electionsQuery->execute(['voter_id' => $voter['id']]);
$elections = $electionsQuery->fetchAll();

$pageTitle = 'Voter dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>Voter dashboard</h1>
                <p>Welcome, <?= sanitize($voter['full_name']) ?>.</p>
            </div>
            <span class="badge badge--<?= sanitize($voter['status']) ?>">
                Account: <?= sanitize(ucfirst($voter['status'])) ?>
            </span>
        </header>

        <?php if ($voter['status'] !== 'active'): ?>
            <div class="alert alert--info" role="status">
                Your account must be active before you can cast a vote.
            </div>
        <?php endif; ?>

        <div class="voter-elections">
            <h2>Open elections</h2>
            <?php if ($elections === []): ?>
                <div class="empty-state" role="status">
                    <h3>No open elections</h3>
                    <p>Check back when the next voting period begins.</p>
                </div>
            <?php else: ?>
                <div class="grid grid--2">
                    <?php foreach ($elections as $election): ?>
                        <?php $canVote = $voter['status'] === 'active' && (bool) $election['voting_available']; ?>
                        <article class="card voter-election-card">
                            <h3><?= sanitize($election['title']) ?></h3>
                            <?php if ($election['description'] !== null && $election['description'] !== ''): ?>
                                <p><?= nl2br(sanitize($election['description'])) ?></p>
                            <?php endif; ?>
                            <p>
                                Voting window:
                                <?= sanitize($election['start_datetime']) ?> to
                                <?= sanitize($election['end_datetime']) ?>
                            </p>
                            <p>
                                Your status:
                                <?php if ((bool) $election['has_voted']): ?>
                                    <span class="badge badge--success">Already voted</span>
                                <?php else: ?>
                                    <span class="badge badge--pending">Not voted yet</span>
                                <?php endif; ?>
                            </p>
                            <?php if ($canVote): ?>
                                <a class="btn btn--primary"
                                    href="<?= BASE_URL ?>/voter/vote.php?election_id=<?= (int) $election['id'] ?>">
                                    <?= (bool) $election['has_voted'] ? 'Continue voting' : 'View ballot' ?>
                                </a>
                            <?php else: ?>
                                <p>
                                    <?= $voter['status'] !== 'active'
                                        ? 'Voting is unavailable until your account is activated.'
                                        : 'Voting is outside the scheduled date range.' ?>
                                </p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <p class="voter-receipt-link">
            <a href="<?= BASE_URL ?>/voter/my_vote.php">View my voting receipts</a>
        </p>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>