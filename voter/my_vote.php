<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('voter');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_time.php';

header('Cache-Control: no-store, private');

$userId = (int) current_user()['id'];
$receiptsQuery = $pdo->prepare(
    'SELECT e.id, e.title, bs.receipt_hash,
            COALESCE(
                UNIX_TIMESTAMP(br.cast_at),
                UNIX_TIMESTAMP(MAX(bs.submitted_at))
            ) AS cast_epoch,
            COALESCE(br.position_count, COUNT(DISTINCT bs.position_id)) AS submitted_positions,
            COALESCE(SUM(bs.abstained = 1), 0) AS abstentions
     FROM voters v
     INNER JOIN ballot_submissions bs ON bs.voter_id = v.id
     INNER JOIN elections e ON e.id = bs.election_id
     LEFT JOIN ballot_receipts br
       ON br.election_id = bs.election_id
      AND br.receipt_hash = bs.receipt_hash
     WHERE v.user_id = :user_id
     GROUP BY e.id, e.title, bs.receipt_hash, br.cast_at, br.position_count
     ORDER BY cast_epoch DESC'
);
$receiptsQuery->execute(['user_id' => $userId]);
$receipts = $receiptsQuery->fetchAll();

$availableElectionsQuery = $pdo->query(
    "SELECT e.id, e.title, e.start_datetime, e.end_datetime, e.timezone
     FROM elections e
     WHERE e.status = 'open'
       AND EXISTS (
           SELECT 1
           FROM positions p
           INNER JOIN candidates c
             ON c.position_id = p.id
            AND c.election_id = p.election_id
            AND c.status = 'approved'
           WHERE p.election_id = e.id
       )
     ORDER BY e.start_datetime, e.id"
);
$availableElections = [];
foreach ($availableElectionsQuery->fetchAll() as $availableElection) {
    if (election_is_within_schedule($availableElection)) {
        $availableElections[] = $availableElection;
    }
}

$pageTitle = 'My voting receipts';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section voter-receipts-page">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <p class="eyebrow">Voter portal</p>
                <h1>My Vote Receipts</h1>
                <p>Review participation receipts for ballots submitted from your account.</p>
            </div>
            <a class="btn btn--outline" href="<?= BASE_URL ?>/voter/dashboard.php">Return to dashboard</a>
        </header>

        <?php if ($receipts === []): ?>
            <section class="card voter-receipt-empty" role="status">
                <span class="voter-receipt-empty__icon" aria-hidden="true">!</span>
                <div>
                    <h2>No ballot submissions yet</h2>
                    <p>Your receipts will appear here after you submit a ballot.</p>
                </div>
                <?php if ($availableElections !== []): ?>
                    <div class="voter-receipt-empty__actions">
                        <?php foreach ($availableElections as $availableElection): ?>
                            <a class="btn btn--primary"
                                href="<?= BASE_URL ?>/voter/vote.php?election_id=<?= (int) $availableElection['id'] ?>">
                                Vote in <?= sanitize($availableElection['title']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <a class="btn btn--outline" href="<?= BASE_URL ?>/voter/dashboard.php">View election status</a>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <div class="voter-receipts-list">
                <?php foreach ($receipts as $receipt): ?>
                    <?php
                    $castEpoch = (int) $receipt['cast_epoch'];
                    $castAtUtc = gmdate('Y-m-d\TH:i:s\Z', $castEpoch);
                    $castAtLabel = gmdate('Y-m-d H:i:s \U\T\C', $castEpoch);
                    $hasReceiptHash = is_string($receipt['receipt_hash']) && preg_match('/\A[a-f0-9]{64}\z/', $receipt['receipt_hash']) === 1;
                    $receiptId = $hasReceiptHash
                        ? 'receipt-' . $receipt['receipt_hash']
                        : 'receipt-legacy-' . (int) $receipt['id'];
                    ?>
                    <article class="card voter-receipt-card" id="<?= sanitize($receiptId) ?>"
                        <?= $hasReceiptHash ? 'data-receipt-card' : '' ?>>
                        <div class="voter-receipt-card__header">
                            <div>
                                <span class="badge badge--success voter-receipt-card__badge">
                                    <span aria-hidden="true">✓</span> Participation recorded
                                </span>
                                <h2><?= sanitize($receipt['title']) ?></h2>
                            </div>
                            <span class="voter-receipt-card__type">Digital participation receipt</span>
                        </div>

                        <dl class="voter-receipt-card__details">
                            <div>
                                <dt>Recorded at (UTC)</dt>
                                <dd>
                                    <time datetime="<?= sanitize($castAtUtc) ?>"><?= sanitize($castAtLabel) ?></time>
                                </dd>
                            </div>
                            <div>
                                <dt>Positions included</dt>
                                <dd><?= (int) $receipt['submitted_positions'] ?></dd>
                            </div>
                            <div>
                                <dt>Abstentions</dt>
                                <dd><?= (int) $receipt['abstentions'] ?></dd>
                            </div>
                        </dl>

                        <?php if ($hasReceiptHash): ?>
                            <div class="voter-receipt-hash">
                                <div class="voter-receipt-hash__heading">
                                    <h3>Cryptographic Ballot Receipt Hash</h3>
                                    <p>This random receipt identifier does not encode your selections.</p>
                                </div>
                                <div class="voter-receipt-hash__controls">
                                    <code data-receipt-code><?= sanitize($receipt['receipt_hash']) ?></code>
                                    <button class="btn btn--outline" type="button" data-copy-receipt>
                                        Copy Code
                                    </button>
                                </div>
                                <p class="voter-receipt-copy-status" data-copy-status role="status" aria-live="polite"></p>
                            </div>
                            <div class="voter-receipt-card__actions">
                                <a class="btn btn--primary"
                                    href="<?= BASE_URL ?>/voter/verify_elections.php?hash=<?= rawurlencode($receipt['receipt_hash']) ?>">
                                    Verify My Ballot on Public Ledger
                                </a>
                                <button class="btn btn--outline" type="button" data-print-receipt
                                    data-receipt-target="<?= sanitize($receiptId) ?>"
                                    title="Open the print dialog and choose Save as PDF">
                                    Download PDF Receipt
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="alert alert--info" role="status">
                                This historical submission predates receipt hashes. Its submission details are shown,
                                but it cannot be checked on the public receipt ledger.
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <aside class="card voter-receipt-privacy">
                <h2>Privacy and verification</h2>
                <p>
                    Candidate selections are intentionally omitted from this receipt. A public lookup confirms
                    that a participation receipt was issued; it does not prove that particular selections were
                    included in the tally or provide database-level anonymity.
                </p>
            </aside>
        <?php endif; ?>
    </div>
</section>

<?php if ($receipts !== []): ?>
    <script src="<?= BASE_URL ?>/assets/js/receipts.js?v=1" defer></script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
