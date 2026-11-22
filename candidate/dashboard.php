<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('candidate');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_scheduler.php';

activate_due_auto_elections($pdo);

$userId = (int) current_user()['id'];
$profileQuery = $pdo->prepare(
    "SELECT full_name, email, reg_number, status
     FROM users
     WHERE id = :user_id AND role = 'candidate'"
);
$profileQuery->execute(['user_id' => $userId]);
$profile = $profileQuery->fetch();

if (!$profile) {
    throw new RuntimeException('Candidate account was not found.');
}

$applicationsQuery = $pdo->prepare(
    'SELECT c.id, c.manifesto, c.campaign_slogan, c.photo_path, c.affiliation,
            c.status AS application_status, c.rejection_feedback,
            e.id AS election_id, e.title AS election_title, e.status AS election_status,
            e.start_datetime, e.end_datetime, e.timezone, e.nomination_deadline,
            p.title AS position_title
     FROM candidates c
     INNER JOIN elections e ON e.id = c.election_id
     INNER JOIN positions p ON p.id = c.position_id
     WHERE c.user_id = :user_id
     ORDER BY CASE
         WHEN e.status = \'open\' THEN 0
         WHEN e.status = \'paused\' THEN 1
         WHEN e.status = \'draft\' THEN 2
         ELSE 3
     END, e.start_datetime, p.title'
);
$applicationsQuery->execute(['user_id' => $userId]);
$applications = $applicationsQuery->fetchAll();

$application = $applications[0] ?? null;
$requestedApplicationId = isset($_GET['candidate_id']) && is_string($_GET['candidate_id'])
    ? filter_var($_GET['candidate_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($requestedApplicationId !== false) {
    foreach ($applications as $candidateApplication) {
        if ((int) $candidateApplication['id'] === $requestedApplicationId) {
            $application = $candidateApplication;
            break;
        }
    }
}

$electionStatusLabel = '';
$countdownTarget = null;
$countdownLabel = '';
$startTime = null;
$endTime = null;
$nominationDeadline = null;
$canEditProfile = false;
if ($application !== null) {
    $startTime = parse_stored_election_time($application['start_datetime'], $application['timezone']);
    $endTime = parse_stored_election_time($application['end_datetime'], $application['timezone']);
    if ($startTime === null || $endTime === null) {
        throw new RuntimeException('The election has an invalid stored schedule.');
    }
    if (is_string($application['nomination_deadline']) && $application['nomination_deadline'] !== '') {
        $nominationDeadline = parse_stored_election_time(
            $application['nomination_deadline'],
            $application['timezone']
        );
        if ($nominationDeadline === null) {
            throw new RuntimeException('The election has an invalid nomination deadline.');
        }
    }

    $now = new DateTimeImmutable('now', new DateTimeZone($application['timezone']));
    if ($application['election_status'] === 'closed' || $now > $endTime) {
        $electionStatusLabel = 'Closed';
    } elseif ($application['election_status'] === 'paused') {
        $electionStatusLabel = 'Paused';
        $countdownTarget = $endTime;
        $countdownLabel = 'Polls close in';
    } elseif ($application['election_status'] === 'draft') {
        $electionStatusLabel = 'Draft';
        $countdownTarget = $startTime;
        $countdownLabel = 'Scheduled opening in';
    } elseif ($now < $startTime) {
        $electionStatusLabel = 'Scheduled';
        $countdownTarget = $startTime;
        $countdownLabel = 'Polls open in';
    } else {
        $electionStatusLabel = 'Live';
        $countdownTarget = $endTime;
        $countdownLabel = 'Polls close in';
    }

    $canEditProfile = in_array($electionStatusLabel, ['Draft', 'Scheduled'], true)
        && ($nominationDeadline === null || $now < $nominationDeadline);
}

$candidateStatus = $application['application_status'] ?? null;
$pageTitle = 'Candidate dashboard';
$candidateDashboardStylesheet = true;
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section candidate-dashboard">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>Candidate dashboard</h1>
                <p>Welcome, <?= sanitize($profile['full_name']) ?>. Track your application and campaign readiness.</p>
            </div>
        </header>

        <?php if ($applications === []): ?>
            <section class="card candidate-dashboard__empty" role="status">
                <h2>No election applications yet</h2>
                <p>Your election application and ballot preview will appear here when an administrator adds you to a contest.</p>
            </section>
        <?php else: ?>
            <?php if (count($applications) > 1): ?>
                <nav class="candidate-dashboard__application-picker" aria-label="Your election applications">
                    <?php foreach ($applications as $candidateApplication): ?>
                        <a href="<?= BASE_URL ?>/candidate/dashboard.php?candidate_id=<?= (int) $candidateApplication['id'] ?>"
                            <?= (int) $application['id'] === (int) $candidateApplication['id'] ? 'aria-current="page"' : '' ?>>
                            <?= sanitize($candidateApplication['election_title'] . ' — ' . $candidateApplication['position_title']) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <?php
            $candidateBannerClass = 'candidate-status-banner--' . sanitize($candidateStatus);
            $candidateStatusTitle = match ($candidateStatus) {
                'pending' => 'Pending Admin Review',
                'rejected' => 'Application Rejected',
                'approved' => 'Approved & Confirmed on Ballot',
                default => 'Application status unavailable',
            };
            ?>
            <section class="candidate-status-banner <?= $candidateBannerClass ?>"
                aria-labelledby="candidate-status-title">
                <div>
                    <p class="eyebrow">Application status</p>
                    <h2 id="candidate-status-title"><?= sanitize($candidateStatusTitle) ?></h2>
                    <?php if ($candidateStatus === 'rejected'): ?>
                        <p class="candidate-status-banner__feedback">
                            <?= $application['rejection_feedback'] !== null && $application['rejection_feedback'] !== ''
                                ? nl2br(sanitize($application['rejection_feedback']))
                                : 'The administrator did not include additional feedback.' ?>
                        </p>
                    <?php elseif ($candidateStatus === 'pending'): ?>
                        <p>Your application is awaiting an administrator’s review.</p>
                    <?php else: ?>
                        <p>Your approved profile will appear in the voter-facing ballot.</p>
                    <?php endif; ?>
                </div>
                <?php if ($candidateStatus === 'rejected' && $canEditProfile): ?>
                    <a class="btn btn--primary"
                        href="<?= BASE_URL ?>/candidate/myprofile.php?candidate_id=<?= (int) $application['id'] ?>">
                        Edit &amp; Resubmit Application
                    </a>
                <?php endif; ?>
            </section>

            <section class="candidate-election-overview" aria-label="Election overview">
                <div class="candidate-election-overview__identity">
                    <p class="eyebrow">Election</p>
                    <h2><?= sanitize($application['election_title']) ?></h2>
                    <p>Contesting <?= sanitize($application['position_title']) ?></p>
                </div>
                <span class="candidate-election-status candidate-election-status--<?= strtolower($electionStatusLabel) ?>">
                    <?= sanitize($electionStatusLabel) ?>
                </span>
                <?php if ($countdownTarget !== null): ?>
                    <div class="candidate-countdown" data-countdown
                        data-countdown-unix="<?= $countdownTarget->getTimestamp() ?>">
                        <span><?= sanitize($countdownLabel) ?></span>
                        <strong data-countdown-value aria-live="off">Calculating…</strong>
                    </div>
                <?php else: ?>
                    <p class="candidate-election-overview__schedule">
                        <?= $electionStatusLabel === 'Draft' ? 'Poll dates are set for ' : 'Polls closed ' ?>
                        <?= sanitize($electionStatusLabel === 'Draft'
                            ? $startTime->format('M j, Y g:i A T')
                            : $endTime->format('M j, Y g:i A T')) ?>
                    </p>
                <?php endif; ?>
                <?php if ($canEditProfile): ?>
                    <a class="btn btn--outline"
                        href="<?= BASE_URL ?>/candidate/myprofile.php?candidate_id=<?= (int) $application['id'] ?>">
                        Edit Profile &amp; Manifesto
                    </a>
                <?php else: ?>
                    <button class="btn btn--outline" type="button" disabled
                        aria-describedby="profile-edit-lock">Edit Profile &amp; Manifesto</button>
                    <span class="sr-only" id="profile-edit-lock">Profile editing is locked after the nomination freeze or once voting begins.</span>
                <?php endif; ?>
            </section>

            <div class="candidate-dashboard__columns">
                <section class="card candidate-ballot-preview" aria-labelledby="ballot-preview-title">
                    <header class="candidate-dashboard-card__header">
                        <div>
                            <p class="eyebrow">Voter-facing preview</p>
                            <h2 id="ballot-preview-title">Live Ballot Preview</h2>
                        </div>
                        <span class="badge badge--<?= sanitize($candidateStatus) ?>">
                            <?= sanitize(ucfirst($candidateStatus)) ?>
                        </span>
                    </header>
                    <article class="candidate-ballot-preview__candidate">
                        <?php if (
                            is_string($application['photo_path'])
                            && preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png)\z~', $application['photo_path']) === 1
                        ): ?>
                            <img class="candidate-ballot-preview__photo"
                                src="<?= BASE_URL ?>/<?= sanitize($application['photo_path']) ?>"
                                alt="Profile photo of <?= sanitize($profile['full_name']) ?>">
                        <?php else: ?>
                            <div class="candidate-ballot-preview__photo candidate-ballot-preview__photo--empty"
                                aria-hidden="true"><?= sanitize(strtoupper(substr($profile['full_name'], 0, 1))) ?></div>
                        <?php endif; ?>
                        <div class="candidate-ballot-preview__details">
                            <p class="eyebrow"><?= sanitize($application['position_title']) ?></p>
                            <h3><?= sanitize($profile['full_name']) ?></h3>
                            <?php if (is_string($application['affiliation']) && $application['affiliation'] !== ''): ?>
                                <p class="candidate-ballot-preview__affiliation"><?= sanitize($application['affiliation']) ?></p>
                            <?php endif; ?>
                            <?php if (is_string($application['campaign_slogan']) && $application['campaign_slogan'] !== ''): ?>
                                <p class="candidate-ballot-preview__slogan"><?= sanitize($application['campaign_slogan']) ?></p>
                            <?php endif; ?>
                            <p class="candidate-ballot-preview__manifesto">
                                <?= $application['manifesto'] !== null && $application['manifesto'] !== ''
                                    ? nl2br(sanitize($application['manifesto']))
                                    : 'Add a manifesto to introduce your campaign to voters.' ?>
                            </p>
                        </div>
                    </article>
                </section>

                <section class="card candidate-campaign-checklist" aria-labelledby="campaign-checklist-title">
                    <header class="candidate-dashboard-card__header">
                        <div>
                            <p class="eyebrow">Prepare for election day</p>
                            <h2 id="campaign-checklist-title">Campaign Timeline &amp; Checklist</h2>
                        </div>
                    </header>
                    <ol class="candidate-milestones">
                        <li>
                            <span class="candidate-milestones__marker" aria-hidden="true"></span>
                            <div>
                                <strong>Nomination freeze</strong>
                                <span><?= $nominationDeadline
                                    ? sanitize($nominationDeadline->format('M j, Y g:i A T'))
                                    : 'No separate deadline set' ?></span>
                            </div>
                        </li>
                        <li>
                            <span class="candidate-milestones__marker" aria-hidden="true"></span>
                            <div>
                                <strong>Poll opening</strong>
                                <span><?= sanitize($startTime->format('M j, Y g:i A T')) ?></span>
                            </div>
                        </li>
                        <li>
                            <span class="candidate-milestones__marker" aria-hidden="true"></span>
                            <div>
                                <strong>Poll closing</strong>
                                <span><?= sanitize($endTime->format('M j, Y g:i A T')) ?></span>
                            </div>
                        </li>
                    </ol>
                    <h3 class="candidate-checklist__title">Profile readiness</h3>
                    <ul class="candidate-checklist">
                        <?php foreach ([
                            'Profile photo' => is_string($application['photo_path']) && $application['photo_path'] !== '',
                            'Campaign slogan' => is_string($application['campaign_slogan']) && trim($application['campaign_slogan']) !== '',
                            'Manifesto' => is_string($application['manifesto']) && trim($application['manifesto']) !== '',
                        ] as $readinessLabel => $isReady): ?>
                            <li class="<?= $isReady ? 'is-ready' : '' ?>">
                                <span aria-hidden="true"><?= $isReady ? '✓' : '○' ?></span>
                                <?= sanitize($readinessLabel) ?>
                                <strong><?= $isReady ? 'Ready' : 'Needed' ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            </div>
        <?php endif; ?>
    </div>
</section>
<script src="<?= BASE_URL ?>/assets/js/candidate-dashboard.js?v=1" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
