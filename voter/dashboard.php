<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('voter');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_time.php';
require_once __DIR__ . '/../includes/election_scheduler.php';

activate_due_auto_elections($pdo);

$userId = (int) current_user()['id'];
$voterQuery = $pdo->prepare(
    "SELECT v.id, v.reg_number, u.full_name, u.status
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
    "SELECT e.id, e.title, e.status, e.start_datetime, e.end_datetime, e.closed_at, e.timezone,
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
     WHERE e.status IN ('open', 'paused')
        OR e.id = (
            SELECT id FROM elections WHERE status = 'closed'
            ORDER BY COALESCE(closed_at, end_datetime) DESC, id DESC
            LIMIT 1
        )"
);
$electionsQuery->execute(['submitted_voter_id' => $voter['id']]);
$elections = [];
foreach ($electionsQuery->fetchAll() as $election) {
    $start = parse_stored_election_time($election['start_datetime'], $election['timezone']);
    $end = parse_stored_election_time($election['end_datetime'], $election['timezone']);
    if ($start === null || $end === null) {
        throw new RuntimeException('An election has an invalid stored schedule.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone($election['timezone']));
    if ($election['status'] === 'closed' || $now > $end) {
        $election['state'] = 'closed';
        $election['state_label'] = 'Closed';
        $election['countdown_target'] = null;
        $election['countdown_label'] = 'Polls are closed';
    } elseif ($election['status'] === 'paused') {
        $election['state'] = 'paused';
        $election['state_label'] = 'Paused';
        $election['countdown_target'] = $end;
        $election['countdown_label'] = 'Scheduled close';
    } elseif ($now < $start) {
        $election['state'] = 'scheduled';
        $election['state_label'] = 'Scheduled';
        $election['countdown_target'] = $start;
        $election['countdown_label'] = 'Polls open in';
    } else {
        $election['state'] = 'live';
        $election['state_label'] = 'Live';
        $election['countdown_target'] = $end;
        $election['countdown_label'] = 'Polls close in';
    }

    $election['start_epoch'] = $start->getTimestamp();
    $election['countdown_epoch'] = $election['countdown_target'] instanceof DateTimeImmutable
        ? $election['countdown_target']->getTimestamp()
        : null;
    $election['submitted_positions'] = (int) $election['submitted_positions'];
    $election['available_positions'] = (int) $election['available_positions'];
    $election['remaining_positions'] = max(
        0,
        $election['available_positions'] - $election['submitted_positions']
    );
    $election['has_submissions'] = $election['submitted_positions'] > 0;
    $election['is_complete'] = $election['available_positions'] > 0
        && $election['submitted_positions'] >= $election['available_positions'];
    $election['can_continue'] = $voter['status'] === 'active'
        && $election['state'] === 'live'
        && $election['remaining_positions'] > 0;
    $elections[] = $election;
}

$statePriority = ['live' => 0, 'scheduled' => 1, 'paused' => 2, 'closed' => 3];
usort($elections, static function (array $left, array $right) use ($statePriority): int {
    $priorityDifference = $statePriority[$left['state']] - $statePriority[$right['state']];
    if ($priorityDifference !== 0) {
        return $priorityDifference;
    }

    if ($left['state'] === 'closed') {
        return $right['start_epoch'] <=> $left['start_epoch'];
    }

    return $left['start_epoch'] <=> $right['start_epoch'];
});

$requestedElectionId = isset($_GET['election_id']) && is_string($_GET['election_id'])
    ? filter_var($_GET['election_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$election = $elections[0] ?? null;
if ($requestedElectionId !== false) {
    foreach ($elections as $availableElection) {
        if ((int) $availableElection['id'] === $requestedElectionId) {
            $election = $availableElection;
            break;
        }
    }
}

$candidatePositions = [];
if ($election !== null) {
    $candidateQuery = $pdo->prepare(
        "SELECT p.id AS position_id, p.title AS position_title,
                c.id AS candidate_id, c.manifesto, c.campaign_slogan, c.photo_path, c.affiliation,
                COALESCE(u.full_name, c.display_name, 'Unnamed candidate') AS candidate_name
         FROM positions p
         LEFT JOIN candidates c
           ON c.position_id = p.id
          AND c.election_id = p.election_id
          AND c.status = 'approved'
         LEFT JOIN users u ON u.id = c.user_id
         WHERE p.election_id = :election_id AND p.contest_type = 'candidate'
         ORDER BY p.id, COALESCE(u.full_name, c.display_name), c.id"
    );
    $candidateQuery->execute(['election_id' => $election['id']]);
    foreach ($candidateQuery->fetchAll() as $row) {
        $positionId = (int) $row['position_id'];
        if (!isset($candidatePositions[$positionId])) {
            $candidatePositions[$positionId] = [
                'id' => $positionId,
                'title' => $row['position_title'],
                'candidates' => [],
            ];
        }
        if ($row['candidate_id'] !== null) {
            $candidatePositions[$positionId]['candidates'][] = $row;
        }
    }
}

$voterIsApproved = $voter['status'] === 'active';
$profileStatusClass = $voterIsApproved ? 'badge--success' : 'badge--pending';
$hasSubmissions = $election !== null && $election['has_submissions'];
$hasRecordedBallot = $hasSubmissions && (
    $election['is_complete']
    || $election['state'] === 'closed'
    || $election['remaining_positions'] === 0
);
$pageTitle = 'Voter dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section voter-dashboard">
    <div class="container">
        <header class="voter-dashboard__header">
            <div>
                <p class="eyebrow">Voter portal</p>
                <h1>Welcome, <?= sanitize($voter['full_name']) ?></h1>
                <p>Your election status and next steps are all in one place.</p>
            </div>
            <?php if (count($elections) > 1): ?>
                <form class="voter-dashboard__election-picker" method="get">
                    <label for="voter-election-picker">Election</label>
                    <select id="voter-election-picker" name="election_id" data-election-picker>
                        <?php foreach ($elections as $electionOption): ?>
                            <option value="<?= (int) $electionOption['id'] ?>"
                                <?= $election !== null && (int) $election['id'] === (int) $electionOption['id'] ? 'selected' : '' ?>>
                                <?= sanitize($electionOption['title'] . ' · ' . $electionOption['state_label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn btn--outline" type="submit">Open election</button></noscript>
                </form>
            <?php endif; ?>
        </header>

        <?php if ($election === null): ?>
            <section class="voter-participation voter-participation--muted" role="status">
                <div>
                    <p class="eyebrow">Participation status</p>
                    <h2>Polls Closed / No Active Election</h2>
                    <p>There is no current or upcoming election available to vote in. Check back for future election announcements.</p>
                </div>
            </section>
        <?php elseif ($election['state'] === 'closed' && !$hasSubmissions): ?>
            <section class="voter-participation voter-participation--muted" role="status">
                <div>
                    <p class="eyebrow">Participation status</p>
                    <h2>Polls Closed</h2>
                    <p><?= sanitize($election['title']) ?> is no longer accepting votes.</p>
                </div>
            </section>
        <?php elseif ($hasRecordedBallot): ?>
            <section class="voter-participation voter-participation--recorded" role="status">
                <div>
                    <p class="eyebrow">Participation status</p>
                    <h2>Ballot Cast &amp; Recorded</h2>
                    <p>
                        Your participation in <?= sanitize($election['title']) ?> has been recorded.
                        Your receipt confirms submission details without displaying your selections.
                    </p>
                </div>
                <a class="btn btn--primary" href="<?= BASE_URL ?>/voter/my_vote.php">View Voting Receipt</a>
            </section>
        <?php elseif ($election['state'] === 'live' && $hasSubmissions): ?>
            <section class="voter-participation voter-participation--action" role="status">
                <div>
                    <p class="eyebrow">Participation status</p>
                    <h2>Ballot In Progress &amp; Recorded</h2>
                    <p>
                        <?= $election['submitted_positions'] ?> of <?= $election['available_positions'] ?>
                        positions have been recorded. Continue with the remaining positions.
                    </p>
                </div>
                <div class="voter-participation__actions">
                    <?php if ($election['can_continue']): ?>
                        <a class="btn btn--primary"
                            href="<?= BASE_URL ?>/voter/vote.php?election_id=<?= (int) $election['id'] ?>">
                            Continue to Ballot
                        </a>
                    <?php endif; ?>
                    <a class="voter-participation__secondary" href="<?= BASE_URL ?>/voter/my_vote.php">
                        View Voting Receipt
                    </a>
                </div>
            </section>
        <?php elseif ($election['state'] === 'live' && !$voterIsApproved): ?>
            <section class="voter-participation voter-participation--muted" role="status">
                <div>
                    <p class="eyebrow">Participation status</p>
                    <h2>Voter Approval Required</h2>
                    <p>Your voter account must be approved before you can cast a ballot.</p>
                </div>
            </section>
        <?php elseif ($election['state'] === 'live' && $election['available_positions'] === 0): ?>
            <section class="voter-participation voter-participation--muted" role="status">
                <div>
                    <p class="eyebrow">Participation status</p>
                    <h2>Ballot Not Available Yet</h2>
                    <p>There are no approved candidates or ballot options ready for this election.</p>
                </div>
            </section>
        <?php elseif ($election['state'] === 'live' && $election['can_continue']): ?>
            <section class="voter-participation voter-participation--action" role="status">
                <div>
                    <p class="eyebrow">Participation status</p>
                    <h2>Action Required: You Have Not Voted Yet</h2>
                    <p>Voting is open for <?= sanitize($election['title']) ?>. Review the candidates before entering your ballot.</p>
                </div>
                <a class="btn btn--primary"
                    href="<?= BASE_URL ?>/voter/vote.php?election_id=<?= (int) $election['id'] ?>">
                    Proceed to Ballot
                </a>
            </section>
        <?php elseif ($election['state'] === 'scheduled'): ?>
            <section class="voter-participation voter-participation--scheduled" role="status">
                <div>
                    <p class="eyebrow">Participation status</p>
                    <h2>Election Scheduled</h2>
                    <p>
                        <?= sanitize($election['title']) ?> has not opened yet.
                        <?= $voterIsApproved ? 'You can review the candidates below in the meantime.' : 'Your voter account is not yet approved.' ?>
                    </p>
                </div>
            </section>
        <?php else: ?>
            <section class="voter-participation voter-participation--paused" role="status">
                <div>
                    <p class="eyebrow">Participation status</p>
                    <h2>Voting Temporarily Paused</h2>
                    <p>Voting in <?= sanitize($election['title']) ?> is paused. Your participation status remains unchanged.</p>
                </div>
                <?php if ($hasSubmissions): ?>
                    <a class="voter-participation__secondary" href="<?= BASE_URL ?>/voter/my_vote.php">
                        View Voting Receipt
                    </a>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="voter-dashboard__summary" aria-label="Voter and election status">
            <article class="card voter-status-card">
                <p class="eyebrow">Election</p>
                <?php if ($election !== null): ?>
                    <h2><?= sanitize($election['title']) ?></h2>
                    <span class="badge badge--<?= $election['state'] === 'live'
                        ? 'live'
                        : ($election['state'] === 'scheduled'
                            ? 'scheduled'
                            : ($election['state'] === 'closed' ? 'closed' : 'paused')) ?>">
                        <?= sanitize($election['state_label']) ?>
                    </span>
                    <?php if ($election['available_positions'] > 0): ?>
                        <p>
                            Progress: <?= $election['submitted_positions'] ?> of
                            <?= $election['available_positions'] ?> positions recorded
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <h2>No active election</h2>
                    <span class="badge badge--pending">Unavailable</span>
                <?php endif; ?>
            </article>

            <article class="card voter-status-card voter-status-card--countdown">
                <p class="eyebrow">Election countdown</p>
                <?php if ($election !== null && $election['countdown_epoch'] !== null): ?>
                    <p><?= sanitize($election['countdown_label']) ?></p>
                    <time datetime="<?= sanitize($election['countdown_target']->format(DATE_ATOM)) ?>"
                        data-voter-countdown
                        data-countdown-at="<?= (int) $election['countdown_epoch'] ?>">
                        <?= sanitize(format_election_local_time(
                            $election['countdown_target']->format('Y-m-d H:i:s'),
                            $election['timezone']
                        )) ?>
                    </time>
                    <small><?= sanitize($election['timezone']) ?></small>
                <?php else: ?>
                    <p class="voter-status-card__static">
                        <?= $election !== null ? sanitize($election['countdown_label']) : 'No countdown available' ?>
                    </p>
                <?php endif; ?>
            </article>

            <article class="card voter-status-card voter-status-card--profile">
                <p class="eyebrow">Verified voter profile</p>
                <dl>
                    <div>
                        <dt>Name</dt>
                        <dd><?= sanitize($voter['full_name']) ?></dd>
                    </div>
                    <div>
                        <dt>Voter ID</dt>
                        <dd><?= sanitize($voter['reg_number']) ?></dd>
                    </div>
                </dl>
                <span class="badge <?= $profileStatusClass ?>">
                    <?= $voterIsApproved ? 'Approved' : sanitize(ucfirst($voter['status'])) ?>
                </span>
            </article>
        </section>

        <div class="voter-dashboard__main">
            <section class="card voter-guide" aria-labelledby="voter-guide-title">
                <p class="eyebrow">A simple, secure process</p>
                <h2 id="voter-guide-title">How to Vote &amp; Security Assurance</h2>
                <ol class="voter-guide__steps">
                    <li>
                        <strong>Review the ballot</strong>
                        <span>Browse the approved candidates and read their profiles before making selections.</span>
                    </li>
                    <li>
                        <strong>Verify and choose</strong>
                        <span>Complete any election-specific verification, then make a choice for each available position.</span>
                    </li>
                    <li>
                        <strong>Submit and confirm</strong>
                        <span>Submit your ballot and check your receipt to confirm that your participation was recorded.</span>
                    </li>
                </ol>
                <div class="voter-guide__privacy">
                    <h3>Ballot privacy</h3>
                    <p>
                        This dashboard and your voting receipt show participation details only;
                        neither displays your candidate selections.
                    </p>
                </div>
            </section>

            <section class="voter-directory" aria-labelledby="candidate-directory-title">
                <header class="voter-directory__header">
                    <div>
                        <p class="eyebrow">Explore the ballot</p>
                        <h2 id="candidate-directory-title">Meet the Candidates</h2>
                        <?php if ($election !== null): ?>
                            <p><?= sanitize($election['title']) ?></p>
                        <?php endif; ?>
                    </div>
                </header>

                <?php if ($election === null): ?>
                    <div class="card voter-directory__empty" role="status">
                        <p>Candidate profiles will appear when an election is available.</p>
                    </div>
                <?php elseif ($candidatePositions === []): ?>
                    <div class="card voter-directory__empty" role="status">
                        <p>No approved candidate profiles are available for this election yet.</p>
                    </div>
                <?php else: ?>
                    <div class="voter-directory__positions">
                        <?php foreach ($candidatePositions as $position): ?>
                            <section class="card voter-directory-position">
                                <h3><?= sanitize($position['title']) ?></h3>
                                <?php if ($position['candidates'] === []): ?>
                                    <p class="form__hint">No approved candidates yet.</p>
                                <?php else: ?>
                                    <div class="voter-directory-position__candidates">
                                        <?php foreach ($position['candidates'] as $candidate): ?>
                                            <?php
                                            $photoIsValid = is_string($candidate['photo_path'])
                                                && preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $candidate['photo_path']) === 1;
                                            ?>
                                            <article class="voter-directory-candidate">
                                                <?php if ($photoIsValid): ?>
                                                    <img class="voter-directory-candidate__photo"
                                                        src="<?= BASE_URL ?>/<?= sanitize($candidate['photo_path']) ?>"
                                                        alt="">
                                                <?php else: ?>
                                                    <span class="voter-directory-candidate__photo voter-directory-candidate__photo--empty"
                                                        aria-hidden="true"><?= sanitize(strtoupper(substr($candidate['candidate_name'], 0, 1))) ?></span>
                                                <?php endif; ?>
                                                <div class="voter-directory-candidate__details">
                                                    <h4><?= sanitize($candidate['candidate_name']) ?></h4>
                                                    <?php if (is_string($candidate['affiliation']) && $candidate['affiliation'] !== ''): ?>
                                                        <p class="voter-directory-candidate__affiliation"><?= sanitize($candidate['affiliation']) ?></p>
                                                    <?php endif; ?>
                                                    <?php if (is_string($candidate['campaign_slogan']) && $candidate['campaign_slogan'] !== ''): ?>
                                                        <p class="voter-directory-candidate__slogan"><?= sanitize($candidate['campaign_slogan']) ?></p>
                                                    <?php endif; ?>
                                                    <?php if (is_string($candidate['manifesto']) && trim($candidate['manifesto']) !== ''): ?>
                                                        <details class="voter-directory-candidate__manifesto">
                                                            <summary>Read manifesto</summary>
                                                            <p><?= nl2br(sanitize($candidate['manifesto'])) ?></p>
                                                        </details>
                                                    <?php else: ?>
                                                        <p class="form__hint">No manifesto provided.</p>
                                                    <?php endif; ?>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>

<script src="<?= BASE_URL ?>/assets/js/voter-dashboard.js?v=1" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
