<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('voter');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_time.php';
require_once __DIR__ . '/../includes/election_scheduler.php';
require_once __DIR__ . '/../includes/rich_text.php';

activate_due_auto_elections($pdo);

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
    "SELECT e.id, e.title, e.description, e.start_datetime, e.end_datetime, e.timezone,
            (SELECT COUNT(*) FROM ballot_submissions bs
             WHERE bs.election_id = e.id AND bs.voter_id = :submitted_voter_id) AS submitted_positions,
            (SELECT COUNT(*)
             FROM positions p
             WHERE p.election_id = e.id
               AND EXISTS (
                   SELECT 1 FROM candidates c
                   WHERE c.position_id = p.id AND c.election_id = e.id AND c.status = 'approved'
               )) AS available_positions
     FROM elections e
     WHERE e.status = 'open'
     ORDER BY e.start_datetime, e.title"
);
$electionsQuery->execute([
    'submitted_voter_id' => $voter['id'],
]);
$elections = $electionsQuery->fetchAll();
foreach ($elections as &$election) {
    $election['voting_available'] = election_is_within_schedule($election);
    $election['is_complete'] = (int) $election['available_positions'] > 0
        && (int) $election['submitted_positions'] >= (int) $election['available_positions'];
}
unset($election);

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
                        <?php
                        $canVote = $voter['status'] === 'active'
                            && $election['voting_available']
                            && !$election['is_complete']
                            && (int) $election['available_positions'] > 0;
                        ?>
                        <article class="card voter-election-card">
                            <h3><?= sanitize($election['title']) ?></h3>
                            <?php if ($election['description'] !== null && $election['description'] !== ''): ?>
                                <div class="rich-text-content">
                                    <?= sanitize_rich_text($election['description']) ?>
                                </div>
                            <?php endif; ?>
                            <p>
                                Voting window:
                                <?= sanitize(format_election_local_time($election['start_datetime'], $election['timezone'])) ?>
                                to <?= sanitize(format_election_local_time($election['end_datetime'], $election['timezone'])) ?>
                                (<?= sanitize($election['timezone']) ?>)
                            </p>
                            <p>
                                Your progress:
                                <?php if ($election['is_complete']): ?>
                                    <span class="badge badge--success">Ballot complete</span>
                                <?php elseif ((int) $election['submitted_positions'] > 0): ?>
                                    <span class="badge badge--pending">
                                        <?= (int) $election['submitted_positions'] ?> of
                                        <?= (int) $election['available_positions'] ?> positions submitted
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge--pending">Not started</span>
                                <?php endif; ?>
                            </p>
                            <?php if ($canVote): ?>
                                <a class="btn btn--primary"
                                    href="<?= BASE_URL ?>/voter/vote.php?election_id=<?= (int) $election['id'] ?>">
                                    <?= (int) $election['submitted_positions'] > 0 ? 'Continue voting' : 'View ballot' ?>
                                </a>
                            <?php elseif ($election['is_complete']): ?>
                                <p>Your ballot is complete. You can view the receipt on your voting receipts page.</p>
                            <?php else: ?>
                                <p>
                                    <?= $voter['status'] !== 'active'
                                        ? 'Voting is unavailable until your account is activated.'
                                        : (!$election['voting_available']
                                            ? 'Voting is outside the scheduled date range.'
                                            : 'No positions with approved candidates are available yet.') ?>
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
