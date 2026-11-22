<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('voter');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_time.php';
require_once __DIR__ . '/../includes/election_scheduler.php';
require_once __DIR__ . '/../includes/rich_text.php';

activate_due_auto_elections($pdo);

$userId = (int) current_user()['id'];
$voterQuery = $pdo->prepare(
    "SELECT v.id, u.status, u.email, u.full_name
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
        "SELECT id, title, description, support_email, start_datetime, end_datetime, timezone,
                voting_system, verification_methods, allow_abstentions,
                organization_logo_path
         FROM elections
         WHERE id = :election_id AND status = 'open'
         LIMIT 1"
    );
    $electionQuery->execute(['election_id' => $rawElectionId]);
    $election = $electionQuery->fetch();
    if ($election) {
        $election['voting_available'] = election_is_within_schedule($election);
    }
}

$positions = [];
$alreadyVoted = [];
$emailVerified = false;
$emailVerificationRequired = $election
    && in_array('email_token', explode(',', $election['verification_methods']), true);
if ($election) {
    $existingSubmissionsQuery = $pdo->prepare(
        'SELECT position_id FROM ballot_submissions
         WHERE election_id = :election_id AND voter_id = :voter_id'
    );
    $existingSubmissionsQuery->execute([
        'election_id' => $election['id'],
        'voter_id' => $voter['id'],
    ]);
    foreach ($existingSubmissionsQuery->fetchAll(PDO::FETCH_COLUMN) as $positionId) {
        $alreadyVoted[(int) $positionId] = true;
    }

    if ($emailVerificationRequired) {
        $emailVerificationQuery = $pdo->prepare(
            'SELECT (verified_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)) AS recently_verified
             FROM election_voter_verifications
             WHERE election_id = :election_id AND voter_id = :voter_id'
        );
        $emailVerificationQuery->execute([
            'election_id' => $election['id'],
            'voter_id' => $voter['id'],
        ]);
        $emailVerified = (bool) $emailVerificationQuery->fetchColumn();
    }

    $candidateQuery = $pdo->prepare(
        "SELECT p.id AS position_id, p.title AS position_title, p.max_votes,
                p.contest_type, p.randomize_candidates,
                c.id AS candidate_id, c.display_name, c.manifesto, c.photo_path, c.affiliation,
                COALESCE(u.full_name, c.display_name, 'Unnamed option') AS candidate_name
         FROM positions p
         LEFT JOIN candidates c
           ON c.position_id = p.id
          AND c.election_id = p.election_id
          AND c.status = 'approved'
         LEFT JOIN users u ON u.id = c.user_id
         WHERE p.election_id = :election_id
         ORDER BY p.id,
             CASE WHEN p.randomize_candidates = 1 THEN RAND() ELSE 0 END,
             COALESCE(u.full_name, c.display_name), c.id"
    );
    $candidateQuery->execute(['election_id' => $election['id']]);

    foreach ($candidateQuery->fetchAll() as $row) {
        $positionId = (int) $row['position_id'];
        if (!isset($positions[$positionId])) {
            $positions[$positionId] = [
                'id' => $positionId,
                'title' => $row['position_title'],
                'max_votes' => (int) $row['max_votes'],
                'contest_type' => $row['contest_type'],
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
$showBallot = $election
    && (bool) $election['voting_available']
    && $voter['status'] === 'active'
    && $availablePositionCount > 0
    && (!$emailVerificationRequired || $emailVerified);
$electionStart = $election
    ? format_election_local_time($election['start_datetime'], $election['timezone'])
    : '';
$electionEnd = $election
    ? format_election_local_time($election['end_datetime'], $election['timezone'])
    : '';

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
                Voting is not currently within the scheduled dates
                (<?= sanitize($electionStart) ?> to <?= sanitize($electionEnd) ?>,
                <?= sanitize($election['timezone']) ?>).
            </div>
        <?php elseif ($voter['status'] !== 'active'): ?>
            <div class="alert alert--error" role="alert">
                Your voter account must be active to cast a vote.
            </div>
        <?php else: ?>
            <?php if (
                is_string($election['organization_logo_path'])
                && preg_match('~\Aassets/img/elections/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $election['organization_logo_path']) === 1
            ): ?>
                <img class="election-logo voter-election-logo"
                    src="<?= BASE_URL ?>/<?= sanitize($election['organization_logo_path']) ?>"
                    alt="<?= sanitize($election['title']) ?> organization logo">
            <?php endif; ?>

            <?php if (
                ($election['description'] !== null && $election['description'] !== '')
                || (is_string($election['support_email']) && $election['support_email'] !== '')
            ): ?>
                <section class="card voter-election-instructions" aria-labelledby="election-instructions-title">
                    <h2 id="election-instructions-title">Election instructions</h2>
                    <?php if ($election['description'] !== null && $election['description'] !== ''): ?>
                        <div class="rich-text-content"><?= sanitize_rich_text($election['description']) ?></div>
                    <?php endif; ?>
                    <?php if (is_string($election['support_email']) && $election['support_email'] !== ''): ?>
                        <p>Need help? Contact <a href="mailto:<?= sanitize($election['support_email']) ?>">
                            <?= sanitize($election['support_email']) ?>
                        </a>.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($emailVerificationRequired && !$emailVerified && $availablePositionCount > 0): ?>
                <section class="card election-verification" aria-labelledby="verification-title">
                    <h2 id="verification-title">Verify your email</h2>
                    <p>A six-digit code is required before you can submit this ballot. It expires in 10 minutes.</p>
                    <form method="post" action="<?= BASE_URL ?>/voter/verify_election.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>">
                        <button class="btn btn--outline" type="submit" name="action" value="send_code">
                            Send verification code
                        </button>
                    </form>
                    <form class="form" method="post" action="<?= BASE_URL ?>/voter/verify_election.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>">
                        <input type="hidden" name="action" value="verify_code">
                        <div class="form__group">
                            <label for="verification_code">Six-digit email code</label>
                            <input id="verification_code" name="verification_code" type="text"
                                inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                        </div>
                        <button class="btn btn--primary" type="submit">Verify email</button>
                    </form>
                </section>
            <?php elseif ($emailVerificationRequired && $emailVerified): ?>
                <p class="alert alert--success" role="status">Email verified for this ballot.</p>
            <?php endif; ?>

            <?php if ($availablePositionCount === 0): ?>
                <div class="empty-state" role="status">
                    <h2>No available positions</h2>
                    <p>There are no unsubmitted contests with approved options in this election.</p>
                </div>
            <?php elseif (!$showBallot): ?>
                <p class="alert alert--info" role="status">Verify your email above to continue to the ballot.</p>
            <?php else: ?>
                <?php if ($alreadyVoted !== []): ?>
                    <div class="alert alert--info" role="status">
                        Your previous selections are recorded. You can submit the remaining positions.
                    </div>
                <?php endif; ?>

                <form class="ballot-form" data-ballot-form data-loading-state
                    data-voting-system="<?= sanitize($election['voting_system']) ?>"
                    data-allow-abstentions="<?= $election['allow_abstentions'] ? 'true' : 'false' ?>"
                    method="post" action="<?= BASE_URL ?>/voter/process_vote.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>">
                    <div class="ballot-security">
                        <h2>Voter verification</h2>
                        <?php if (in_array('voter_pin', explode(',', $election['verification_methods']), true)): ?>
                            <p>Enter your voter ID and account password to verify this ballot.</p>
                            <div class="wizard-fields">
                                <div class="form__group">
                                    <label for="voter_reg_number">Voter ID / registration number</label>
                                    <input id="voter_reg_number" name="voter_reg_number" type="text"
                                        autocomplete="username" maxlength="100" required>
                                </div>
                                <div class="form__group">
                                    <label for="voter_pin">PIN / account password</label>
                                    <input id="voter_pin" name="voter_pin" type="password"
                                        autocomplete="current-password" maxlength="72" required>
                                </div>
                            </div>
                        <?php else: ?>
                            <p><?= $emailVerificationRequired
                                ? 'Email verification is complete.'
                                : 'No additional verification is required.' ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="ballot-positions">
                        <?php foreach ($positions as $position): ?>
                            <?php
                            $positionId = (int) $position['id'];
                            $candidateCount = count($position['candidates']);
                            ?>
                            <section class="ballot-position"
                                data-ballot-position="<?= $positionId ?>"
                                data-max-votes="<?= (int) $position['max_votes'] ?>"
                                aria-labelledby="position-<?= $positionId ?>">
                                <div class="ballot-position__header">
                                    <h2 id="position-<?= $positionId ?>"><?= sanitize($position['title']) ?></h2>
                                    <?php if (isset($alreadyVoted[$positionId])): ?>
                                        <span class="badge badge--success">Submitted</span>
                                    <?php endif; ?>
                                </div>

                                <?php if (isset($alreadyVoted[$positionId])): ?>
                                    <p>Your selection or abstention for this position has already been submitted.</p>
                                <?php elseif ($candidateCount === 0): ?>
                                    <p>No approved ballot options are available for this contest.</p>
                                <?php else: ?>
                                    <?php if ($election['voting_system'] === 'fptp'): ?>
                                        <fieldset class="candidate-options" data-ballot-choice>
                                            <legend class="sr-only">
                                                Choose one candidate for <?= sanitize($position['title']) ?>
                                            </legend>
                                            <?php foreach ($position['candidates'] as $candidateIndex => $candidate): ?>
                                                <label class="candidate-option" data-candidate-option>
                                                    <input type="radio" name="votes[<?= $positionId ?>]"
                                                        value="<?= (int) $candidate['candidate_id'] ?>"
                                                        <?= $candidateIndex === 0 ? 'required' : '' ?>>
                                                    <span class="candidate-option__content">
                                                        <?php if (
                                                            is_string($candidate['photo_path'])
                                                            && preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $candidate['photo_path']) === 1
                                                        ): ?>
                                                            <img class="candidate-option__photo"
                                                                src="<?= BASE_URL ?>/<?= sanitize($candidate['photo_path']) ?>"
                                                                alt="">
                                                        <?php endif; ?>
                                                        <span class="candidate-option__details">
                                                            <strong class="candidate-option__name"><?= sanitize($candidate['candidate_name']) ?></strong>
                                                            <?php if (is_string($candidate['affiliation']) && $candidate['affiliation'] !== ''): ?>
                                                                <span class="candidate-option__affiliation"><?= sanitize($candidate['affiliation']) ?></span>
                                                            <?php endif; ?>
                                                            <span><?= $candidate['manifesto'] !== null && $candidate['manifesto'] !== ''
                                                                ? nl2br(sanitize($candidate['manifesto']))
                                                                : 'No manifesto provided.' ?></span>
                                                        </span>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                            <?php if ($election['allow_abstentions']): ?>
                                                <label class="candidate-option candidate-option--abstain">
                                                    <input type="radio" name="votes[<?= $positionId ?>]" value="abstain">
                                                    <span><strong>Abstain</strong></span>
                                                </label>
                                            <?php endif; ?>
                                        </fieldset>
                                    <?php elseif ($election['voting_system'] === 'multiple_selection'): ?>
                                        <fieldset class="candidate-options" data-ballot-choice data-selection-limit="<?= (int) $position['max_votes'] ?>">
                                            <legend>
                                                Choose up to <?= (int) $position['max_votes'] ?> option(s) for
                                                <?= sanitize($position['title']) ?>
                                            </legend>
                                            <?php foreach ($position['candidates'] as $candidate): ?>
                                                <label class="candidate-option" data-candidate-option>
                                                    <input type="checkbox" name="votes[<?= $positionId ?>][]"
                                                        value="<?= (int) $candidate['candidate_id'] ?>">
                                                    <span class="candidate-option__details">
                                                        <strong class="candidate-option__name"><?= sanitize($candidate['candidate_name']) ?></strong>
                                                        <?php if (is_string($candidate['affiliation']) && $candidate['affiliation'] !== ''): ?>
                                                            <span class="candidate-option__affiliation"><?= sanitize($candidate['affiliation']) ?></span>
                                                        <?php endif; ?>
                                                        <span><?= $candidate['manifesto'] !== null && $candidate['manifesto'] !== ''
                                                            ? nl2br(sanitize($candidate['manifesto']))
                                                            : 'No manifesto provided.' ?></span>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                            <?php if ($election['allow_abstentions']): ?>
                                                <label class="candidate-option candidate-option--abstain">
                                                    <input type="checkbox" name="abstain[<?= $positionId ?>]" value="1" data-abstain>
                                                    <span><strong>Abstain from this position</strong></span>
                                                </label>
                                            <?php endif; ?>
                                        </fieldset>
                                    <?php else: ?>
                                        <fieldset class="ranked-candidates" data-ballot-choice
                                            data-ranking-count="<?= $candidateCount ?>">
                                            <legend>Rank options from 1 (first choice) onward. Leave others unranked.</legend>
                                            <?php foreach ($position['candidates'] as $candidate): ?>
                                                <label class="ranked-candidate">
                                                    <span class="candidate-option__name">
                                                        <?= sanitize($candidate['candidate_name']) ?>
                                                        <?php if (is_string($candidate['affiliation']) && $candidate['affiliation'] !== ''): ?>
                                                            <small><?= sanitize($candidate['affiliation']) ?></small>
                                                        <?php endif; ?>
                                                    </span>
                                                    <select name="votes[<?= $positionId ?>][<?= (int) $candidate['candidate_id'] ?>]"
                                                        data-rank-choice aria-label="Rank <?= sanitize($candidate['candidate_name']) ?>">
                                                        <option value="0">Not ranked</option>
                                                        <?php for ($rank = 1; $rank <= $candidateCount; $rank++): ?>
                                                            <option value="<?= $rank ?>"><?= $rank ?></option>
                                                        <?php endfor; ?>
                                                    </select>
                                                </label>
                                            <?php endforeach; ?>
                                            <?php if ($election['allow_abstentions']): ?>
                                                <label class="candidate-option candidate-option--abstain">
                                                    <input type="checkbox" name="abstain[<?= $positionId ?>]" value="1" data-abstain>
                                                    <span><strong>Abstain from this position</strong></span>
                                                </label>
                                            <?php endif; ?>
                                        </fieldset>
                                    <?php endif; ?>
                                    <p class="ballot-position__error" data-position-error role="alert" hidden></p>
                                <?php endif; ?>
                            </section>
                        <?php endforeach; ?>
                    </div>

                    <div class="ballot-actions">
                        <button class="btn btn--primary" type="button" data-review-ballot>Review selections</button>
                        <a href="<?= BASE_URL ?>/voter/dashboard.php">Cancel</a>
                    </div>
                    <p class="loading-state" role="status" aria-live="polite" data-loading-indicator hidden>
                        <span class="loading-state__spinner" aria-hidden="true"></span>
                        Submitting your ballot…
                    </p>

                    <div class="modal ballot-review" data-ballot-review aria-hidden="true">
                        <div class="modal__dialog" role="dialog" aria-modal="true"
                            aria-labelledby="ballot-review-title" tabindex="-1">
                            <header class="modal__header"><h2 id="ballot-review-title">Confirm your ballot</h2></header>
                            <p>Review your selections before submitting. Submitted votes cannot be changed.</p>
                            <ul class="ballot-review__summary" data-ballot-summary></ul>
                            <div class="modal__footer">
                                <button class="btn btn--outline" type="button" data-close-review>Go back</button>
                                <button class="btn btn--primary" type="submit" data-confirm-ballot>Confirm and submit</button>
                            </div>
                        </div>
                    </div>
                </form>
                <noscript><p>JavaScript is required to review and confirm your ballot.</p></noscript>
                <script src="<?= BASE_URL ?>/assets/js/voting.js" defer></script>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
