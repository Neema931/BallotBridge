<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_time.php';
require_once __DIR__ . '/../includes/admin_dashboard_data.php';

if (is_post()) {
    verify_csrf();

    $action = isset($_POST['action']) && is_string($_POST['action'])
        ? $_POST['action']
        : '';
    $rawElectionId = isset($_POST['election_id']) && is_string($_POST['election_id'])
        ? filter_var($_POST['election_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $confirmedPause = isset($_POST['confirm_pause'])
        && is_string($_POST['confirm_pause'])
        && $_POST['confirm_pause'] === 'yes';

    if (!in_array($action, ['pause_election', 'resume_election'], true) || $rawElectionId === false) {
        set_flash('error', 'Invalid election action.');
        redirect(BASE_URL . '/admin/dashboard.php');
    }

    if ($action === 'pause_election' && !$confirmedPause) {
        set_flash('error', 'Confirm the emergency pause before submitting.');
        redirect(BASE_URL . '/admin/dashboard.php');
    }

    $electionId = (int) $rawElectionId;
    $pdo->beginTransaction();
    $electionQuery = $pdo->prepare(
        'SELECT id, title, status, start_datetime, end_datetime, timezone
         FROM elections
         WHERE id = :election_id
         FOR UPDATE'
    );
    $electionQuery->execute(['election_id' => $electionId]);
    $election = $electionQuery->fetch();
    $validAction = $action === 'pause_election'
        ? $election && $election['status'] === 'open' && election_is_within_schedule($election)
        : $election && $election['status'] === 'paused' && election_has_not_closed($election);

    if (!$validAction) {
        $pdo->rollBack();
        set_flash('error', 'The election state no longer allows that action.');
        redirect(BASE_URL . '/admin/dashboard.php');
    }

    $newStatus = $action === 'pause_election' ? 'paused' : 'open';
    $changeStatus = $pdo->prepare(
        'UPDATE elections SET status = :status WHERE id = :election_id'
    );
    $changeStatus->execute([
        'status' => $newStatus,
        'election_id' => $electionId,
    ]);
    $auditAction = $action === 'pause_election' ? 'election.paused' : 'election.resumed';
    $auditSummary = $action === 'pause_election'
        ? 'Emergency-paused election voting.'
        : 'Resumed paused election voting.';
    log_admin_action(
        $pdo,
        $auditAction,
        'election',
        $electionId,
        $auditSummary,
        ['status' => $election['status']],
        ['status' => $newStatus]
    );
    $pdo->commit();

    set_flash('success', $action === 'pause_election'
        ? 'Voting has been paused. No new ballots can be submitted.'
        : 'Voting has resumed.');
    redirect(BASE_URL . '/admin/dashboard.php');
}

$dashboardData = load_admin_dashboard_data($pdo);
$pageTitle = 'Admin dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section dashboard-command" data-admin-dashboard
    data-data-url="<?= BASE_URL ?>/admin/dashboard_data.php"
    data-last-closed-id="<?= $dashboardData['last_closed'] !== null
        ? (int) $dashboardData['last_closed']['id']
        : '' ?>"
    <?= $dashboardData['election'] !== null
        ? 'data-election-id="' . (int) $dashboardData['election']['id'] . '"'
        : '' ?>>
    <div class="container">
        <?php if ($dashboardData['election'] === null): ?>
            <section class="empty-election-state card" aria-labelledby="empty-election-title">
                <svg class="empty-election-state__illustration" viewBox="0 0 180 140"
                    role="img" aria-labelledby="empty-illustration-title">
                    <title id="empty-illustration-title">A ballot box waiting for an election</title>
                    <rect x="28" y="46" width="124" height="78" rx="12" fill="none" stroke="currentColor" stroke-width="5"/>
                    <path d="M56 46 64 25h52l8 21M71 25v29m38-29v29M74 81h32m-16-16v32"
                        fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="5"/>
                    <path d="m134 20 4 9 10 2-8 7 2 10-8-5-9 5 2-10-7-7 10-2z"
                        fill="currentColor"/>
                </svg>
                <p class="empty-election-state__eyebrow">Admin command center</p>
                <h1 id="empty-election-title">No Ongoing or Upcoming Elections</h1>
                <p>Create a new election to begin preparing ballots, positions, and voting schedules.</p>
                <p class="sr-only" data-empty-refresh-status aria-live="polite">Waiting for election updates.</p>
                <?php if ($dashboardData['last_closed'] !== null): ?>
                    <p class="empty-election-state__last">
                        Most recent election:
                        <strong><?= sanitize($dashboardData['last_closed']['title']) ?></strong>
                        <span class="badge badge--closed">Closed</span>
                    </p>
                <?php endif; ?>
                <div class="empty-election-state__actions">
                    <a class="btn btn--primary" href="<?= BASE_URL ?>/admin/manage_elections.php">
                        Create New Election
                    </a>
                    <a class="empty-election-state__secondary" href="<?= BASE_URL ?>/admin/results.php">
                        View past election results
                    </a>
                </div>
            </section>
        <?php else: ?>
            <?php
            $election = $dashboardData['election'];
            $kpis = $dashboardData['kpis'];
            $voterGroupTotal = array_sum($dashboardData['voter_groups']);
            $electionEnd = parse_stored_election_time($election['end_datetime'], $election['timezone']);
            if ($electionEnd === null) {
                throw new RuntimeException('The election closing time or timezone is invalid.');
            }
            $chartMax = max(1, ...array_column($dashboardData['vote_timeline'], 'votes'));
            $chartPoints = [];
            $chartCircles = [];
            foreach ($dashboardData['vote_timeline'] as $index => $point) {
                $x = 40 + ($index * (420 / 23));
                $y = 180 - (((int) $point['votes'] / $chartMax) * 145);
                $chartPoints[] = number_format($x, 1, '.', '') . ',' . number_format($y, 1, '.', '');
                $chartCircles[] = [
                    'x' => number_format($x, 1, '.', ''),
                    'y' => number_format($y, 1, '.', ''),
                ];
            }
            $firstChartLabel = $dashboardData['vote_timeline'][0]['label'] ?? '';
            $middleChartLabel = $dashboardData['vote_timeline'][12]['label'] ?? '';
            $lastChartLabel = $dashboardData['vote_timeline'][23]['label'] ?? '';
            $editElectionUrl = in_array($election['status'], ['draft', 'scheduled'], true)
                ? BASE_URL . '/admin/manage_elections.php?edit=' . (int) $election['id']
                : BASE_URL . '/admin/manage_elections.php';
            ?>

            <header class="dashboard-event-header">
                <div class="dashboard-event-header__details">
                    <p class="dashboard-event-header__eyebrow">Election command center</p>
                    <h1 data-election-title><?= sanitize($election['title']) ?></h1>
                    <?php if (
                        is_string($election['organization_logo_path'])
                        && preg_match('~\Aassets/img/elections/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $election['organization_logo_path']) === 1
                    ): ?>
                        <img class="dashboard-event-header__logo"
                            src="<?= BASE_URL ?>/<?= sanitize($election['organization_logo_path']) ?>"
                            alt="<?= sanitize($election['title']) ?> organization logo">
                    <?php endif; ?>
                    <div class="dashboard-event-header__status">
                        <span class="badge badge--<?= sanitize($election['status']) ?>"
                            data-election-status><?= sanitize(ucfirst($election['status'])) ?></span>
                        <p>
                            Polls close in
                            <time data-countdown data-close-at="<?= (int) $election['end_timestamp'] ?>"
                                datetime="<?= sanitize($electionEnd->format(DATE_ATOM)) ?>">Calculating…</time>
                        </p>
                    </div>
                </div>
                <div class="dashboard-event-header__actions">
                    <a class="btn btn--outline dashboard-event-header__settings" href="<?= sanitize($editElectionUrl) ?>">
                        <?= in_array($election['status'], ['draft', 'scheduled'], true)
                            ? 'Edit Election Settings'
                            : 'Election management' ?>
                    </a>
                    <?php if ($election['status'] === 'live'): ?>
                        <details class="emergency-pause">
                            <summary class="btn btn--black">Emergency pause</summary>
                            <form method="post" action="<?= BASE_URL ?>/admin/dashboard.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="pause_election">
                                <input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>">
                                <label>
                                    <input type="checkbox" name="confirm_pause" value="yes" required>
                                    I understand this immediately blocks new votes.
                                </label>
                                <button class="btn btn--black" type="submit">Pause voting now</button>
                            </form>
                        </details>
                    <?php elseif ($election['status'] === 'paused'): ?>
                        <?php if ($election['can_resume']): ?>
                            <form method="post" action="<?= BASE_URL ?>/admin/dashboard.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="resume_election">
                                <input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>">
                                <button class="btn btn--primary" type="submit">Resume voting</button>
                            </form>
                        <?php else: ?>
                            <p>Poll closing time has passed. Close this election from Election management.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </header>

            <section class="dashboard-kpis" aria-label="Election summary">
                <article class="dashboard-kpi dashboard-kpi--blue">
                    <span class="dashboard-kpi__label">Eligible voters</span>
                    <strong data-kpi="eligible_voters"><?= (int) $kpis['eligible_voters'] ?></strong>
                    <span>Active voter accounts</span>
                </article>
                <article class="dashboard-kpi dashboard-kpi--black">
                    <span class="dashboard-kpi__label">Ballots cast</span>
                    <strong data-kpi="ballots_cast"><?= (int) $kpis['ballots_cast'] ?></strong>
                    <span>Unique participating voters</span>
                </article>
                <article class="dashboard-kpi">
                    <span class="dashboard-kpi__label">Overall turnout</span>
                    <strong><span data-kpi="turnout"><?= number_format($kpis['turnout'], 1) ?></span>%</strong>
                    <span>Ballots cast / eligible voters</span>
                </article>
                <article class="dashboard-kpi">
                    <span class="dashboard-kpi__label">Active voter sessions</span>
                    <strong data-kpi="active_voter_sessions"><?= (int) $kpis['active_voter_sessions'] ?></strong>
                    <span>Activity in the last 5 minutes</span>
                </article>
            </section>

            <div class="dashboard-command-grid">
                <section class="card dashboard-panel" aria-labelledby="turnout-title">
                    <header class="dashboard-panel__header">
                        <div>
                            <h2 id="turnout-title">Turnout tracker</h2>
                            <p>Votes cast over the last 24 hours; refreshes every 30 seconds.</p>
                        </div>
                        <span class="dashboard-live-indicator">Live data</span>
                    </header>

                    <figure class="turnout-chart">
                        <svg viewBox="0 0 500 225" role="img"
                            aria-labelledby="votes-chart-title votes-chart-description"
                            data-vote-chart
                            data-vote-values="<?= sanitize(json_encode($dashboardData['vote_timeline'], JSON_THROW_ON_ERROR)) ?>">
                            <title id="votes-chart-title">Votes cast over time</title>
                            <desc id="votes-chart-description">Cumulative ballots recorded in hourly intervals during the last 24 hours.</desc>
                            <g class="turnout-chart__grid">
                                <line x1="40" y1="35" x2="460" y2="35"/>
                                <line x1="40" y1="82" x2="460" y2="82"/>
                                <line x1="40" y1="129" x2="460" y2="129"/>
                                <line x1="40" y1="180" x2="460" y2="180"/>
                            </g>
                            <polyline class="turnout-chart__line" data-vote-line
                                points="<?= sanitize(implode(' ', $chartPoints)) ?>"/>
                            <g class="turnout-chart__points" data-vote-points>
                                <?php foreach ($chartCircles as $circle): ?>
                                    <circle cx="<?= sanitize($circle['x']) ?>" cy="<?= sanitize($circle['y']) ?>" r="3"/>
                                <?php endforeach; ?>
                            </g>
                            <g class="turnout-chart__labels">
                                <text x="40" y="211"><?= sanitize($firstChartLabel) ?></text>
                                <text x="240" y="211"><?= sanitize($middleChartLabel) ?></text>
                                <text x="430" y="211"><?= sanitize($lastChartLabel) ?></text>
                            </g>
                        </svg>
                        <figcaption data-chart-empty <?= $kpis['ballots_cast'] > 0 ? 'hidden' : '' ?>>
                            No ballots have been cast in this election yet.
                        </figcaption>
                    </figure>

                    <section class="voter-group-breakdown" aria-labelledby="voter-groups-title">
                        <h3 id="voter-groups-title">Voter group breakdown</h3>
                        <p>Groups are based on voter account status.</p>
                        <?php foreach ([
                            'active' => 'Active',
                            'pending' => 'Pending',
                            'suspended' => 'Suspended',
                            'rejected' => 'Rejected',
                        ] as $groupKey => $groupLabel): ?>
                            <?php
                            $groupCount = $dashboardData['voter_groups'][$groupKey];
                            $groupPercent = $voterGroupTotal > 0
                                ? (int) round(($groupCount / $voterGroupTotal) * 100)
                                : 0;
                            ?>
                            <div class="voter-group" data-voter-group="<?= sanitize($groupKey) ?>">
                                <div class="voter-group__label">
                                    <span><?= sanitize($groupLabel) ?></span>
                                    <span><strong data-group-count><?= (int) $groupCount ?></strong>
                                        (<span data-group-percent><?= $groupPercent ?></span>%)</span>
                                </div>
                                <progress max="100" value="<?= $groupPercent ?>"
                                    aria-label="<?= sanitize($groupLabel) ?> voter accounts"></progress>
                            </div>
                        <?php endforeach; ?>
                    </section>
                </section>

                <aside class="dashboard-side-panels">
                    <section class="card dashboard-panel system-health" aria-labelledby="system-health-title">
                        <header class="dashboard-panel__header">
                            <h2 id="system-health-title">System health</h2>
                            <span class="dashboard-health-status" data-health-status data-status="operational"
                                role="status">Operational</span>
                        </header>
                        <ul class="system-health__list">
                            <li><span>Application server</span><strong>Online</strong></li>
                            <li><span>Database connection</span><strong>Connected</strong></li>
                            <li><span>Dashboard updates</span>
                                <strong data-refresh-status aria-live="polite">Live</strong>
                            </li>
                        </ul>
                    </section>

                    <section class="card dashboard-panel security-feed" aria-labelledby="security-feed-title">
                        <header class="dashboard-panel__header">
                            <div>
                                <h2 id="security-feed-title">Security &amp; audit feed</h2>
                                <p>Recent administrator activity</p>
                            </div>
                            <a href="<?= BASE_URL ?>/admin/audit_logs.php">View all logs</a>
                        </header>
                        <ol class="security-feed__list" data-audit-preview>
                            <?php if ($dashboardData['audit_logs'] === []): ?>
                                <li class="security-feed__empty">No recent administrator activity.</li>
                            <?php else: ?>
                                <?php foreach ($dashboardData['audit_logs'] as $auditLog): ?>
                                    <li>
                                        <strong><?= sanitize($auditLog['summary']) ?></strong>
                                        <span><?= sanitize($auditLog['actor_name'] ?? 'Deleted administrator') ?></span>
                                        <time><?= sanitize($auditLog['created_at']) ?></time>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ol>
                    </section>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
