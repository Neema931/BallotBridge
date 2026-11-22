<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$rawHash = isset($_GET['hash']) && is_string($_GET['hash']) ? trim($_GET['hash']) : '';
$hashProvided = $rawHash !== '';
$receiptHash = strtolower($rawHash);
$lookupError = null;
$verifiedReceipt = null;

if ($hashProvided) {
    if (preg_match('/\A[a-f0-9]{64}\z/', $receiptHash) !== 1) {
        $lookupError = 'Enter a valid 64-character receipt hash.';
    } else {
        $receiptQuery = $pdo->prepare(
            'SELECT br.receipt_hash, br.election_id, br.position_count,
                    UNIX_TIMESTAMP(br.cast_at) AS cast_epoch, e.title
             FROM ballot_receipts br
             INNER JOIN elections e ON e.id = br.election_id
             WHERE br.receipt_hash = :receipt_hash
             LIMIT 1'
        );
        $receiptQuery->execute(['receipt_hash' => $receiptHash]);
        $verifiedReceipt = $receiptQuery->fetch() ?: null;
        if ($verifiedReceipt === null) {
            $lookupError = 'No participation receipt was found for that code.';
        }
    }
}

$electionsQuery = $pdo->query(
    'SELECT e.id, e.title, e.status
     FROM elections e
     WHERE EXISTS (
         SELECT 1 FROM ballot_receipts br WHERE br.election_id = e.id
     )
     ORDER BY e.start_datetime DESC, e.id DESC'
);
$elections = $electionsQuery->fetchAll();
$selectedElectionId = null;
$requestedElectionId = isset($_GET['election_id']) && is_string($_GET['election_id'])
    ? filter_var($_GET['election_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($requestedElectionId !== false) {
    foreach ($elections as $availableElection) {
        if ((int) $availableElection['id'] === $requestedElectionId) {
            $selectedElectionId = $requestedElectionId;
            break;
        }
    }
}
if ($selectedElectionId === null && $verifiedReceipt !== null) {
    $selectedElectionId = (int) $verifiedReceipt['election_id'];
}
if ($selectedElectionId === null && $elections !== []) {
    $selectedElectionId = (int) $elections[0]['id'];
}

$selectedElection = null;
$bulletinEntries = [];
$ledgerTotals = null;
if ($selectedElectionId !== null) {
    foreach ($elections as $availableElection) {
        if ((int) $availableElection['id'] === $selectedElectionId) {
            $selectedElection = $availableElection;
            break;
        }
    }

    $totalsQuery = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*)
             FROM ballot_receipts
             WHERE election_id = :receipt_election_id) AS receipt_count,
            (SELECT COUNT(DISTINCT voter_id)
             FROM ballot_submissions
             WHERE election_id = :submission_election_id) AS voters_with_submissions,
            (SELECT COUNT(*)
             FROM voters v
             INNER JOIN users u ON u.id = v.user_id
             WHERE u.role = \'voter\' AND u.status = \'active\') AS approved_voters'
    );
    $totalsQuery->execute([
        'receipt_election_id' => $selectedElectionId,
        'submission_election_id' => $selectedElectionId,
    ]);
    $ledgerTotals = $totalsQuery->fetch();

    $bulletinQuery = $pdo->prepare(
        'SELECT receipt_hash, UNIX_TIMESTAMP(cast_at) AS cast_epoch
         FROM ballot_receipts
         WHERE election_id = :election_id
         ORDER BY cast_at DESC, receipt_hash
         LIMIT 50'
    );
    $bulletinQuery->execute(['election_id' => $selectedElectionId]);
    $bulletinEntries = $bulletinQuery->fetchAll();
}

$pageTitle = 'Election verification';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section receipt-verification">
    <div class="container">
        <header class="receipt-verification__header">
            <div>
                <p class="eyebrow">Public read-only lookup</p>
                <h1>Election Verification</h1>
                <p>Check whether a participation receipt exists in the published receipt ledger.</p>
            </div>
            <?php if (is_logged_in() && (current_user()['role'] ?? null) === 'voter'): ?>
                <a class="btn btn--outline" href="<?= BASE_URL ?>/voter/my_vote.php">My Vote Receipts</a>
            <?php endif; ?>
        </header>

        <section class="card receipt-lookup" aria-labelledby="receipt-lookup-title">
            <h2 id="receipt-lookup-title">Verify receipt inclusion</h2>
            <form method="get" class="receipt-lookup__form">
                <div class="form__group">
                    <label for="receipt-hash">Receipt hash</label>
                    <input id="receipt-hash" name="hash" type="text"
                        value="<?= sanitize($hashProvided ? $receiptHash : '') ?>"
                        minlength="64" maxlength="64" pattern="[a-fA-F0-9]{64}"
                        spellcheck="false" autocapitalize="off" autocomplete="off" required
                        aria-describedby="receipt-hash-help">
                    <small id="receipt-hash-help" class="form__hint">
                        Paste the full 64-character code shown on your receipt.
                    </small>
                </div>
                <button class="btn btn--primary" type="submit">Verify Inclusion</button>
            </form>

            <?php if ($lookupError !== null): ?>
                <div class="alert alert--error" role="alert"><?= sanitize($lookupError) ?></div>
            <?php elseif ($verifiedReceipt !== null): ?>
                <?php
                $verifiedAtUtc = gmdate('Y-m-d\TH:i:s\Z', (int) $verifiedReceipt['cast_epoch']);
                $verifiedAtLabel = gmdate('Y-m-d H:i:s \U\T\C', (int) $verifiedReceipt['cast_epoch']);
                ?>
                <article class="receipt-verification-result" role="status">
                    <span class="badge badge--success"><span aria-hidden="true">✓</span> Receipt entry found</span>
                    <h3><?= sanitize($verifiedReceipt['title']) ?></h3>
                    <p>
                        The receipt hash is present in the participation ledger.
                        This confirms receipt issuance only; it does not prove candidate selections or tally inclusion.
                    </p>
                    <dl>
                        <div>
                            <dt>Recorded at (UTC)</dt>
                            <dd><time datetime="<?= sanitize($verifiedAtUtc) ?>"><?= sanitize($verifiedAtLabel) ?></time></dd>
                        </div>
                        <div>
                            <dt>Positions in this submission</dt>
                            <dd><?= (int) $verifiedReceipt['position_count'] ?></dd>
                        </div>
                    </dl>
                    <code class="receipt-verification-result__hash"><?= sanitize($verifiedReceipt['receipt_hash']) ?></code>
                </article>
            <?php endif; ?>
        </section>

        <section class="receipt-bulletin" aria-labelledby="receipt-bulletin-title">
            <header class="receipt-bulletin__header">
                <div>
                    <p class="eyebrow">Read-only public record</p>
                    <h2 id="receipt-bulletin-title">Participation Receipt Bulletin</h2>
                    <p>
                        Entries show opaque receipt codes and UTC timestamps only. They are participation records,
                        not a public tally or a guarantee of database-level anonymity.
                    </p>
                </div>
                <?php if (count($elections) > 1): ?>
                    <form method="get" class="receipt-bulletin__filter">
                        <?php if ($hashProvided): ?>
                            <input type="hidden" name="hash" value="<?= sanitize($receiptHash) ?>">
                        <?php endif; ?>
                        <label for="bulletin-election">Election</label>
                        <select id="bulletin-election" name="election_id">
                            <?php foreach ($elections as $availableElection): ?>
                                <option value="<?= (int) $availableElection['id'] ?>"
                                    <?= $selectedElectionId === (int) $availableElection['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($availableElection['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn--outline" type="submit">Show election</button>
                    </form>
                <?php endif; ?>
            </header>

            <?php if ($selectedElection === null || $ledgerTotals === null): ?>
                <div class="card receipt-bulletin__empty" role="status">
                    <p>No participation receipts have been published yet.</p>
                </div>
            <?php else: ?>
                <div class="receipt-bulletin__summary">
                    <article class="card receipt-bulletin-stat">
                        <span>Receipt records</span>
                        <strong><?= (int) $ledgerTotals['receipt_count'] ?></strong>
                    </article>
                    <article class="card receipt-bulletin-stat">
                        <span>Voters with submissions</span>
                        <strong><?= (int) $ledgerTotals['voters_with_submissions'] ?></strong>
                    </article>
                    <article class="card receipt-bulletin-stat">
                        <span>Approved voter accounts</span>
                        <strong><?= (int) $ledgerTotals['approved_voters'] ?></strong>
                    </article>
                </div>

                <div class="card receipt-bulletin__ledger">
                    <h3><?= sanitize($selectedElection['title']) ?></h3>
                    <p>Showing the latest <?= count($bulletinEntries) ?> receipt records.</p>
                    <?php if ($bulletinEntries === []): ?>
                        <p>No receipts for this election.</p>
                    <?php else: ?>
                        <div class="receipt-bulletin__table-wrap">
                            <table class="receipt-bulletin__table">
                                <thead>
                                    <tr>
                                        <th scope="col">Receipt hash</th>
                                        <th scope="col">Recorded at (UTC)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bulletinEntries as $entry): ?>
                                        <?php
                                        $castEpoch = (int) $entry['cast_epoch'];
                                        $castAtUtc = gmdate('Y-m-d\TH:i:s\Z', $castEpoch);
                                        $castAtLabel = gmdate('Y-m-d H:i:s \U\T\C', $castEpoch);
                                        ?>
                                        <tr>
                                            <td><code><?= sanitize(substr($entry['receipt_hash'], 0, 12)) ?>…<?= sanitize(substr($entry['receipt_hash'], -6)) ?></code></td>
                                            <td><time datetime="<?= sanitize($castAtUtc) ?>"><?= sanitize($castAtLabel) ?></time></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <aside class="card receipt-verification__notice">
            <h2>What this verification means</h2>
            <p>
                Receipt hashes are generated from cryptographically secure random bytes and stored with participation
                submissions. A matching entry proves that the platform issued a participation receipt, not that any
                particular candidate choice was included in the tally. The current application stores vote rows and
                receipt-to-participation mappings alongside voter IDs; this public page exposes neither those IDs nor
                candidate selections.
            </p>
        </aside>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
