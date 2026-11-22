<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('voter');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';

$userId = (int) current_user()['id'];
$voterQuery = $pdo->prepare(
    "SELECT v.id, u.status
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

$rawElectionId = isset($_GET['election_id']) && is_string($_GET['election_id'])
    ? filter_var($_GET['election_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$election = false;
if ($rawElectionId !== false) {
    $electionQuery = $pdo->prepare(
        "SELECT id, title, description, start_datetime, end_datetime,
                (NOW() BETWEEN start_datetime AND end_datetime) AS voting_available
         FROM elections
         WHERE id = :election_id AND status = 'open'
         LIMIT 1"
    );
    $electionQuery->execute(['election_id' => $rawElectionId]);
    $election = $electionQuery->fetch();
}

$positions = [];
$alreadyVoted = [];
if ($election) {
    $existingVotesQuery = $pdo->prepare(
        'SELECT position_id FROM votes WHERE election_id = :election_id AND voter_id = :voter_id'
    );
    $existingVotesQuery->execute([
        'election_id' => $election['id'],
        'voter_id' => $voter['id'],
    ]);
    foreach ($existingVotesQuery->fetchAll(PDO::FETCH_COLUMN) as $positionId) {
        $alreadyVoted[(int) $positionId] = true;
    }

    $candidateQuery = $pdo->prepare(
        "SELECT p.id AS position_id, p.title AS position_title,
                c.id AS candidate_id, c.manifesto, c.photo_path,
                u.full_name AS candidate_name
         FROM positions p
         LEFT JOIN candidates c
           ON c.position_id = p.id
          AND c.election_id = p.election_id
          AND c.status = 'approved'
         LEFT JOIN users u ON u.id = c.user_id
         WHERE p.election_id = :election_id
         ORDER BY p.id, u.full_name"
    );
    $candidateQuery->execute(['election_id' => $election['id']]);

    foreach ($candidateQuery->fetchAll() as $row) {
        $positionId = (int) $row['position_id'];
        if (!isset($positions[$positionId])) {
            $positions[$positionId] = [
                'id' => $positionId,
                'title' => $row['position_title'],
                'candidates' => [],
            ];
        }
        if ($row['candidate_id'] !== null) {
            $positions[$positionId]['candidates'][] = $row;
        }
    }
}

$availablePositionCount = 0;
foreach ($positions as $position) {
    if (!isset($alreadyVoted[$position['id']]) && $position['candidates'] !== []) {
        $availablePositionCount++;
    }
}

$pageTitle = 'Cast your vote';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>Cast your vote</h1>
                <?php if ($election): ?>
                    <p><?= sanitize($election['title']) ?></p>
                <?php else: ?>
                    <p>Select an open election from your dashboard.</p>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!$election): ?>
            <div class="alert alert--error" role="alert">
                The selected election is unavailable or is not open.
            </div>
            <a class="btn btn--outline" href="<?= BASE_URL ?>/voter/dashboard.php">Return to dashboard</a>
        <?php elseif (!(bool) $election['voting_available']): ?>
            <div class="alert alert--info" role="status">
                This election is outside its scheduled voting dates.
            </div>
        <?php elseif ($voter['status'] !== 'active'): ?>
            <div class="alert alert--error" role="alert">
                Your voter account must be active to cast a vote.
            </div>
        <?php elseif ($availablePositionCount === 0): ?>
            <div class="empty-state" role="status">
                <h2>No available positions</h2>
                <p>There are no unvoted positions with approved candidates in this election.</p>
            </div>
        <?php else: ?>
            <?php if (array_filter($alreadyVoted) !== []): ?>
                <div class="alert alert--info" role="status">
                    Your previous votes are recorded. You can vote in the remaining positions.
                </div>
            <?php endif; ?>

            <?php if (array_filter(array_map(
                static function (array $position) use ($alreadyVoted): bool {
                    return !isset($alreadyVoted[$position['id']]) && $position['candidates'] === [];
                },
                $positions
            )) !== []): ?>
                <div class="alert alert--info" role="status">
                    Some positions have no approved candidates and are not available on this ballot.
                </div>
            <?php endif; ?>

            <form class="ballot-form" data-ballot-form data-loading-state method="post"
                action="<?= BASE_URL ?>/voter/process_vote.php">
                <?= csrf_field() ?>
                <input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>">

                <div class="ballot-positions">
                    <?php foreach ($positions as $position): ?>
                        <section class="ballot-position" aria-labelledby="position-<?= (int) $position['id'] ?>">
                            <div class="ballot-position__header">
                                <h2 id="position-<?= (int) $position['id'] ?>">
                                    <?= sanitize($position['title']) ?>
                                </h2>
                                <?php if (isset($alreadyVoted[$position['id']])): ?>
                                    <span class="badge badge--success">Vote recorded</span>
                                <?php endif; ?>
                            </div>

                            <?php if (isset($alreadyVoted[$position['id']])): ?>
                                <p>Your vote for this position has already been recorded.</p>
                            <?php elseif ($position['candidates'] === []): ?>
                                <p>No approved candidates are available for this position.</p>
                            <?php else: ?>
                                <fieldset class="candidate-options">
                                    <legend class="sr-only">
                                        Choose one candidate for <?= sanitize($position['title']) ?>
                                    </legend>
                                    <?php foreach ($position['candidates'] as $index => $candidate): ?>
                                        <label class="candidate-option" data-candidate-option>
                                            <input type="radio"
                                                name="votes[<?= (int) $position['id'] ?>]"
                                                value="<?= (int) $candidate['candidate_id'] ?>"
                                                <?= $index === 0 ? 'required' : '' ?>>
                                            <span class="candidate-option__content">
                                                <?php if (
                                                    is_string($candidate['photo_path'])
                                                    && preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png)\z~', $candidate['photo_path']) === 1
                                                ): ?>
                                                    <img class="candidate-option__photo"
                                                        src="<?= BASE_URL ?>/<?= sanitize($candidate['photo_path']) ?>"
                                                        alt="">
                                                <?php endif; ?>
                                                <span class="candidate-option__details">
                                                    <strong class="candidate-option__name">
                                                        <?= sanitize($candidate['candidate_name']) ?>
                                                    </strong>
                                                    <span>
                                                        <?= $candidate['manifesto'] !== null && $candidate['manifesto'] !== ''
                                                            ? nl2br(sanitize($candidate['manifesto']))
                                                            : 'No manifesto provided.' ?>
                                                    </span>
                                                </span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>

                <div class="ballot-actions">
                    <button class="btn btn--primary" type="button" data-review-ballot>
                        Review selections
                    </button>
                    <a href="<?= BASE_URL ?>/voter/dashboard.php">Cancel</a>
                </div>
                <p class="loading-state" role="status" aria-live="polite"
                    data-loading-indicator hidden>
                    <span class="loading-state__spinner" aria-hidden="true"></span>
                    Submitting your ballot…
                </p>

                <div class="modal ballot-review" data-ballot-review aria-hidden="true">
                    <div class="modal__dialog" role="dialog" aria-modal="true"
                        aria-labelledby="ballot-review-title" tabindex="-1">
                        <header class="modal__header">
                            <h2 id="ballot-review-title">Confirm your ballot</h2>
                        </header>
                        <p>Review your selections before submitting. Submitted votes cannot be changed.</p>
                        <ul class="ballot-review__summary" data-ballot-summary></ul>
                        <div class="modal__footer">
                            <button class="btn btn--outline" type="button" data-close-review>
                                Go back
                            </button>
                            <button class="btn btn--primary" type="submit" data-confirm-ballot>
                                Confirm and submit
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <noscript>
                <p>JavaScript is required to review and confirm your ballot.</p>
            </noscript>
            <script src="<?= BASE_URL ?>/assets/js/voting.js" defer></script>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>