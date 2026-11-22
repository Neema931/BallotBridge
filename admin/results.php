<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Cache-Control: no-store, private');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_scheduler.php';
require_once __DIR__ . '/../includes/election_time.php';
require_once __DIR__ . '/../includes/ranked_results.php';
require_once __DIR__ . '/../includes/results_summary.php';

function results_csv_cell(string $value): string
{
    return preg_match('/^[\s]*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
}

function results_candidate_photo(?string $path): ?string
{
    return $path !== null
        && preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $path) === 1
            ? $path
            : null;
}

function results_candidate_initials(string $name): string
{
    $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($words) || $words === []) {
        return '?';
    }

    preg_match('/^./u', $words[0], $firstInitial);
    $initials = $firstInitial[0] ?? '?';
    if (count($words) > 1) {
        preg_match('/^./u', $words[count($words) - 1], $lastInitial);
        $initials .= $lastInitial[0] ?? '';
    }

    return strtoupper($initials);
}

activate_due_auto_elections($pdo);

$electionsQuery = $pdo->prepare(
    'SELECT id, title, status, start_datetime, end_datetime, timezone, auto_launch,
            voting_system, closed_at, eligible_voter_count
     FROM elections ORDER BY id DESC'
);
$electionsQuery->execute();
$elections = $electionsQuery->fetchAll();

$hasElectionParameter = array_key_exists('election_id', $_GET);
$requestedElectionId = $hasElectionParameter && is_string($_GET['election_id'])
    ? filter_var($_GET['election_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$selectedElection = null;
if (!$hasElectionParameter) {
    $selectedElection = $elections[0] ?? null;
} elseif ($requestedElectionId !== false) {
    foreach ($elections as $election) {
        if ((int) $election['id'] === $requestedElectionId) {
            $selectedElection = $election;
            break;
        }
    }
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$startAt = null;
$endAt = null;
$closedAt = null;
$displayStatus = '';
if ($selectedElection !== null) {
    $startAt = parse_stored_election_time(
        $selectedElection['start_datetime'],
        $selectedElection['timezone']
    );
    $endAt = parse_stored_election_time(
        $selectedElection['end_datetime'],
        $selectedElection['timezone']
    );
    if (is_string($selectedElection['closed_at']) && $selectedElection['closed_at'] !== '') {
        $closedAt = new DateTimeImmutable($selectedElection['closed_at'], new DateTimeZone('UTC'));
    }
    $displayStatus = ucfirst($selectedElection['status']);
    if ($selectedElection['status'] === 'open' && $startAt !== null && $endAt !== null) {
        if ($now < $startAt) {
            $displayStatus = 'Scheduled';
        } elseif ($now <= $endAt) {
            $displayStatus = 'Live';
        } else {
            $displayStatus = 'Awaiting closure';
        }
    } elseif (
        $selectedElection['status'] === 'draft'
        && (bool) $selectedElection['auto_launch']
        && $startAt !== null
        && $endAt !== null
        && $startAt > $now
        && $endAt > $now
    ) {
        $displayStatus = 'Scheduled';
    } elseif ($selectedElection['status'] === 'paused') {
        $displayStatus = 'Paused';
    }
}

$isOfficial = $selectedElection !== null && $selectedElection['status'] === 'closed';
$isCsvExport = isset($_GET['export']) && is_string($_GET['export']) && $_GET['export'] === 'csv';
if ($isCsvExport && !$isOfficial) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Official results are available only after the election is closed.');
}

$resultsByPosition = [];
$eligibleVoters = 0;
$eligibleVoterBasis = '';
$ballotsCast = 0;
$abstentions = 0;
$turnout = 0.0;
if ($isOfficial) {
    $positionQuery = $pdo->prepare(
        'SELECT id, title, contest_type, max_votes
         FROM positions
         WHERE election_id = :election_id
         ORDER BY id'
    );
    $positionQuery->execute(['election_id' => $selectedElection['id']]);
    foreach ($positionQuery->fetchAll() as $positionRow) {
        $positionId = (int) $positionRow['id'];
        $resultsByPosition[$positionId] = [
            'id' => $positionId,
            'title' => $positionRow['title'],
            'contest_type' => $positionRow['contest_type'],
            'max_votes' => (int) $positionRow['max_votes'],
            'candidates' => [],
            'total_submissions' => 0,
            'abstentions' => 0,
            'ballots' => [],
            'irv' => null,
            'winner_summary' => null,
        ];
    }

    $candidateQuery = $pdo->prepare(
        "SELECT c.id AS candidate_id, c.position_id, c.display_name, c.photo_path,
                c.affiliation, u.full_name,
                COALESCE(u.full_name, c.display_name, 'Unnamed option') AS candidate_name
         FROM candidates c
         LEFT JOIN users u ON u.id = c.user_id
         WHERE c.election_id = :election_id AND c.status = 'approved'
         ORDER BY c.position_id, c.id"
    );
    $candidateQuery->execute(['election_id' => $selectedElection['id']]);
    foreach ($candidateQuery->fetchAll() as $row) {
        $positionId = (int) $row['position_id'];
        if (!isset($resultsByPosition[$positionId])) {
            continue;
        }
        $candidateId = (int) $row['candidate_id'];
        $resultsByPosition[$positionId]['candidates'][$candidateId] = [
            'id' => $candidateId,
            'name' => $row['candidate_name'],
            'photo_path' => results_candidate_photo($row['photo_path']),
            'affiliation' => $row['affiliation'],
            'votes' => 0,
        ];
    }

    $submissionQuery = $pdo->prepare(
        'SELECT position_id, COUNT(*) AS total_submissions,
                COALESCE(SUM(abstained = 1), 0) AS abstentions
         FROM ballot_submissions
         WHERE election_id = :election_id
         GROUP BY position_id'
    );
    $submissionQuery->execute(['election_id' => $selectedElection['id']]);
    foreach ($submissionQuery->fetchAll() as $submission) {
        $positionId = (int) $submission['position_id'];
        if (isset($resultsByPosition[$positionId])) {
            $resultsByPosition[$positionId]['total_submissions'] = (int) $submission['total_submissions'];
            $resultsByPosition[$positionId]['abstentions'] = (int) $submission['abstentions'];
            $abstentions += (int) $submission['abstentions'];
        }
    }

    if ($selectedElection['voting_system'] === 'ranked_choice') {
        $votesQuery = $pdo->prepare(
            'SELECT position_id, candidate_id, voter_id, rank
             FROM votes
             WHERE election_id = :election_id'
        );
        $votesQuery->execute(['election_id' => $selectedElection['id']]);
        foreach ($votesQuery->fetchAll() as $vote) {
            $positionId = (int) $vote['position_id'];
            $candidateId = (int) $vote['candidate_id'];
            if (!isset($resultsByPosition[$positionId]['candidates'][$candidateId])) {
                continue;
            }
            if ((int) $vote['rank'] === 1) {
                $resultsByPosition[$positionId]['candidates'][$candidateId]['votes']++;
            }
            $voterId = (int) $vote['voter_id'];
            $resultsByPosition[$positionId]['ballots'][$voterId][$candidateId] = (int) $vote['rank'];
        }
    } else {
        $votesQuery = $pdo->prepare(
            'SELECT position_id, candidate_id, COUNT(*) AS vote_count
             FROM votes
             WHERE election_id = :election_id
             GROUP BY position_id, candidate_id'
        );
        $votesQuery->execute(['election_id' => $selectedElection['id']]);
        foreach ($votesQuery->fetchAll() as $vote) {
            $positionId = (int) $vote['position_id'];
            $candidateId = (int) $vote['candidate_id'];
            if (isset($resultsByPosition[$positionId]['candidates'][$candidateId])) {
                $resultsByPosition[$positionId]['candidates'][$candidateId]['votes'] = (int) $vote['vote_count'];
            }
        }
    }

    if ($selectedElection['eligible_voter_count'] !== null) {
        $eligibleVoters = (int) $selectedElection['eligible_voter_count'];
        $eligibleVoterBasis = 'Roster snapshot at poll closure';
    } else {
        $eligibleVoterQuery = $pdo->prepare(
            "SELECT COUNT(*) FROM users
             WHERE role = 'voter' AND status = 'active'"
        );
        $eligibleVoterQuery->execute();
        $eligibleVoters = (int) $eligibleVoterQuery->fetchColumn();
        $eligibleVoterBasis = 'Current active roster; no historical snapshot available';
    }

    $ballotCountQuery = $pdo->prepare(
        'SELECT COUNT(DISTINCT voter_id)
         FROM ballot_submissions
         WHERE election_id = :election_id'
    );
    $ballotCountQuery->execute(['election_id' => $selectedElection['id']]);
    $ballotsCast = (int) $ballotCountQuery->fetchColumn();
    $turnout = $eligibleVoters > 0 ? ($ballotsCast / $eligibleVoters) * 100 : 0.0;

    foreach ($resultsByPosition as &$position) {
        uasort($position['candidates'], static function (array $left, array $right): int {
            return ($right['votes'] <=> $left['votes'])
                ?: strcasecmp($left['name'], $right['name']);
        });
        if ($selectedElection['voting_system'] === 'ranked_choice') {
            $position['irv'] = calculate_instant_runoff(
                $position['candidates'],
                array_values($position['ballots'])
            );
        }
        $position['winner_summary'] = resolve_position_winners(
            $selectedElection['voting_system'],
            $position['candidates'],
            $position['max_votes'],
            $position['irv']
        );
        $position['total_votes'] = array_sum(array_column($position['candidates'], 'votes'));
    }
    unset($position);
}

if ($isCsvExport) {
    $filename = 'ballot-bridge-results-' . (int) $selectedElection['id'] . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to create the results report.');
    }
    fputcsv($output, [
        'Record type',
        'Election ID',
        'Election title',
        'Status',
        'Voting system',
        'Position ID',
        'Position',
        'Candidate ID',
        'Candidate / option',
        'Party / slate',
        'Count type',
        'Round',
        'Exact count',
        'Percentage of contest total',
        'Outcome',
        'Eligible voters',
        'Eligible voter basis',
        'Ballots cast',
        'Turnout percentage',
        'Abstentions',
    ]);
    $csvRow = static function (array $values) use ($output): void {
        fputcsv($output, array_map(
            static fn ($value): string => results_csv_cell((string) $value),
            $values
        ));
    };
    $csvRow([
        'summary',
        (int) $selectedElection['id'],
        $selectedElection['title'],
        'Closed',
        $selectedElection['voting_system'],
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        'Official results',
        $eligibleVoters,
        $eligibleVoterBasis,
        $ballotsCast,
        number_format($turnout, 2, '.', ''),
        $abstentions,
    ]);
    foreach ($resultsByPosition as $position) {
        $denominator = $position['total_votes'];
        foreach ($position['candidates'] as $candidateId => $candidate) {
            $percentage = $denominator > 0 ? ($candidate['votes'] / $denominator) * 100 : 0;
            $outcome = in_array($candidateId, $position['winner_summary']['winner_ids'], true)
                ? 'Winner'
                : (in_array($candidateId, $position['winner_summary']['tied_ids'], true) ? 'Tied' : '');
            $csvRow([
                'contest_result',
                (int) $selectedElection['id'],
                $selectedElection['title'],
                'Closed',
                $selectedElection['voting_system'],
                $position['id'],
                $position['title'],
                $candidateId,
                $candidate['name'],
                $candidate['affiliation'] ?? '',
                $selectedElection['voting_system'] === 'ranked_choice'
                    ? 'first preference'
                    : ($selectedElection['voting_system'] === 'multiple_selection' ? 'selection' : 'vote'),
                '',
                (int) $candidate['votes'],
                number_format($percentage, 2, '.', ''),
                $outcome,
                '',
                '',
                '',
                '',
                '',
            ]);
        }
        if ($selectedElection['voting_system'] === 'ranked_choice' && $position['irv'] !== null) {
            foreach ($position['irv']['rounds'] as $round) {
                foreach ($round['counts'] as $candidateId => $count) {
                    $percentage = $round['active_ballots'] > 0
                        ? ($count / $round['active_ballots']) * 100
                        : 0;
                    $outcome = $round['winner_id'] === $candidateId
                        ? 'Winner'
                        : ($round['winner_id'] !== null
                            ? 'Not elected'
                            : (in_array($candidateId, $round['eliminated'], true)
                                ? 'Eliminated'
                                : (in_array($candidateId, $round['tie'], true) ? 'Tied' : 'Continuing')));
                    $csvRow([
                        'ranked_round',
                        (int) $selectedElection['id'],
                        $selectedElection['title'],
                        'Closed',
                        $selectedElection['voting_system'],
                        $position['id'],
                        $position['title'],
                        $candidateId,
                        $position['candidates'][$candidateId]['name'],
                        $position['candidates'][$candidateId]['affiliation'] ?? '',
                        'active preference',
                        (int) $round['number'],
                        (int) $count,
                        number_format($percentage, 2, '.', ''),
                        $outcome,
                        '',
                        '',
                        '',
                        '',
                        '',
                    ]);
                }
            }
        }
    }
    fclose($output);
    exit;
}

$pageTitle = 'Election results';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section results-dashboard">
    <div class="container">
        <header class="dashboard__header results-page-header">
            <div>
                <p class="results-eyebrow">Official election reporting</p>
                <h1>See results</h1>
                <p>Publication-ready outcomes with ballot secrecy enforced until poll closure.</p>
            </div>
        </header>

        <?php if ($elections === []): ?>
            <div class="empty-state card" role="status">
                <h2>No elections to report</h2>
                <p>Create an election before results can be displayed.</p>
            </div>
        <?php else: ?>
            <form class="form results-filter" method="get" action="<?= BASE_URL ?>/admin/results.php">
                <div class="form__group">
                    <label for="election_id">Election</label>
                    <select id="election_id" name="election_id" required>
                        <?php foreach ($elections as $election): ?>
                            <option value="<?= (int) $election['id'] ?>"
                                <?= $selectedElection !== null && (int) $selectedElection['id'] === (int) $election['id'] ? 'selected' : '' ?>>
                                <?= sanitize($election['title']) ?> (<?= sanitize(ucfirst($election['status'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn--primary" type="submit">View results</button>
            </form>

            <?php if ($selectedElection === null): ?>
                <div class="alert alert--error" role="alert">The selected election was not found.</div>
            <?php elseif (!$isOfficial): ?>
                <?php
                $closingTimestamp = $endAt === null ? null : $endAt->getTimestamp();
                $closingLabel = $endAt === null ? null : $endAt->format('Y-m-d H:i T');
                ?>
                <section class="results-privacy-lock"
                    aria-labelledby="results-lock-title"
                    data-results-lock
                    data-status-url="<?= BASE_URL ?>/admin/results_status.php"
                    data-election-id="<?= (int) $selectedElection['id'] ?>"
                    data-current-status="<?= sanitize($displayStatus) ?>">
                    <div class="results-privacy-lock__status">
                        <span class="results-status-badge results-status-badge--locked">
                            <?= sanitize($displayStatus) ?>
                        </span>
                        <span class="results-privacy-lock__label">Results are sealed</span>
                    </div>
                    <div class="results-privacy-lock__panel">
                        <span class="results-privacy-lock__icon" aria-hidden="true">
                            <svg viewBox="0 0 48 48" focusable="false">
                                <path d="M14 21v-6a10 10 0 0 1 20 0v6M11 21h26v21H11z" />
                                <path d="M24 29v6" />
                            </svg>
                        </span>
                        <div>
                            <p class="results-eyebrow">Ballot secrecy protection</p>
                            <h2 id="results-lock-title"><?= sanitize($selectedElection['title']) ?></h2>
                            <p>Candidate and contest totals are hidden until the election is officially closed.</p>
                            <?php if ($closingTimestamp !== null): ?>
                                <p class="results-lock__closing">
                                    Polls close: <time datetime="<?= sanitize($endAt->format(DateTimeInterface::ATOM)) ?>">
                                        <?= sanitize($closingLabel) ?>
                                    </time>
                                </p>
                                <p class="results-lock__countdown">
                                    <span>Time until poll close</span>
                                    <time data-results-countdown data-close-at="<?= $closingTimestamp ?>">
                                        Calculating…
                                    </time>
                                </p>
                            <?php else: ?>
                                <p class="results-lock__closing">Poll closing time is unavailable.</p>
                            <?php endif; ?>
                            <p class="results-lock__refresh" data-results-refresh>
                                This page will unlock automatically after closure is recorded.
                            </p>
                        </div>
                    </div>
                </section>
                <script src="<?= BASE_URL ?>/assets/js/results.js" defer></script>
            <?php else: ?>
                <?php
                $votingSystemLabel = ucwords(str_replace('_', ' ', $selectedElection['voting_system']));
                $csvUrl = BASE_URL . '/admin/results.php?' . http_build_query([
                    'election_id' => (int) $selectedElection['id'],
                    'export' => 'csv',
                ]);
                $closeLabel = $closedAt !== null
                    ? 'Officially closed ' . $closedAt->format('Y-m-d H:i T')
                    : ($endAt !== null
                        ? 'Scheduled close ' . $endAt->format('Y-m-d H:i T')
                        : 'Close time unavailable');
                ?>
                <header class="results-official-header">
                    <div>
                        <div class="results-official-header__badges">
                            <span class="results-status-badge results-status-badge--closed">Closed</span>
                            <span class="results-official-badge">Official Results</span>
                        </div>
                        <h2><?= sanitize($selectedElection['title']) ?></h2>
                        <p><?= sanitize($votingSystemLabel) ?> · <?= sanitize($closeLabel) ?></p>
                    </div>
                    <div class="results-export-actions no-print">
                        <a class="btn btn--outline" href="<?= sanitize($csvUrl) ?>">Export Raw CSV</a>
                        <button class="btn btn--primary" type="button" data-print-page>
                            Download Official PDF Report
                        </button>
                        <span class="results-export-note">Opens the print dialog; choose Save as PDF.</span>
                    </div>
                </header>

                <div class="print-only results-print-heading">
                    <p><?= sanitize(APP_NAME) ?> · Official election report</p>
                    <p>Generated <?= gmdate('Y-m-d H:i:s') ?> UTC</p>
                    <p>Ballot secrecy note: aggregate results only; no voter selections or identifiers are included.</p>
                </div>

                <section class="results-kpis" aria-label="Final turnout summary">
                    <article class="results-kpi">
                        <span>Eligible voters</span>
                        <strong><?= number_format($eligibleVoters) ?></strong>
                        <small><?= sanitize($eligibleVoterBasis) ?></small>
                    </article>
                    <article class="results-kpi">
                        <span>Ballots cast</span>
                        <strong><?= number_format($ballotsCast) ?></strong>
                    </article>
                    <article class="results-kpi results-kpi--turnout">
                        <span>Final turnout</span>
                        <strong><?= number_format($turnout, 1) ?><small>%</small></strong>
                    </article>
                    <article class="results-kpi">
                        <span>Abstentions</span>
                        <strong><?= number_format($abstentions) ?></strong>
                    </article>
                </section>

                <?php if ($resultsByPosition === []): ?>
                    <div class="empty-state card" role="status">
                        <h3>No positions to report</h3>
                        <p>This closed election has no ballot contests.</p>
                    </div>
                <?php else: ?>
                    <div class="results-position-list">
                        <?php foreach ($resultsByPosition as $position): ?>
                            <?php
                            $winnerIds = $position['winner_summary']['winner_ids'];
                            $tiedIds = $position['winner_summary']['tied_ids'];
                            $resultLabel = $selectedElection['voting_system'] === 'ranked_choice'
                                ? 'First-preference votes'
                                : ($selectedElection['voting_system'] === 'multiple_selection' ? 'Selections' : 'Votes');
                            $shareLabel = $selectedElection['voting_system'] === 'ranked_choice'
                                ? 'Share of first preferences'
                                : ($selectedElection['voting_system'] === 'multiple_selection'
                                    ? 'Share of selections'
                                    : 'Share of votes');
                            ?>
                            <article class="results-position card<?= $winnerIds !== [] ? ' results-position--has-winner' : '' ?>">
                                <header class="results-position__header">
                                    <div>
                                        <p class="results-position__eyebrow">
                                            <?= $position['contest_type'] === 'referendum' ? 'Referendum' : 'Position' ?>
                                            <?= count($resultsByPosition) > 1 ? ' · Contest ' . (int) $position['id'] : '' ?>
                                        </p>
                                        <h3><?= sanitize($position['title']) ?></h3>
                                    </div>
                                    <p class="results-position__meta">
                                        <?= number_format($position['total_submissions']) ?> completed ballots
                                        <span aria-hidden="true">·</span>
                                        <?= number_format($position['abstentions']) ?> abstentions
                                    </p>
                                </header>

                                <?php if ($winnerIds !== []): ?>
                                    <div class="results-winner" role="status">
                                        <svg class="results-winner__trophy" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
                                            <path d="M9 4h14v8a7 7 0 0 1-14 0V4Zm0 3H4v3a6 6 0 0 0 6 6m13-9h5v3a6 6 0 0 1-6 6M16 19v6m-6 3h12m-10-3h8" />
                                        </svg>
                                        <div>
                                            <strong><?= count($winnerIds) === 1 ? 'Declared winner' : 'Declared winners' ?></strong>
                                            <p>
                                                <?php foreach ($winnerIds as $winnerIndex => $winnerId): ?>
                                                    <?= $winnerIndex > 0 ? ', ' : '' ?><?= sanitize($position['candidates'][$winnerId]['name']) ?>
                                                <?php endforeach; ?>
                                            </p>
                                            <?php if ($position['winner_summary']['outcome'] === 'partial_tie'): ?>
                                                <small>
                                                    Tie for the remaining selection<?= count($tiedIds) === 1 ? '' : 's' ?>:
                                                    <?php foreach ($tiedIds as $tieIndex => $tiedId): ?>
                                                        <?= $tieIndex > 0 ? ', ' : '' ?><?= sanitize($position['candidates'][$tiedId]['name']) ?>
                                                    <?php endforeach; ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php elseif ($position['winner_summary']['outcome'] === 'tie'): ?>
                                    <p class="results-outcome results-outcome--tie" role="status">
                                        Tie — no winner declared<?= $tiedIds !== []
                                            ? ': ' . sanitize(implode(', ', array_map(
                                                static fn (int $id): string => $position['candidates'][$id]['name'],
                                                $tiedIds
                                            )))
                                            : '' ?>.
                                    </p>
                                <?php else: ?>
                                    <p class="results-outcome" role="status">No votes were cast for this contest.</p>
                                <?php endif; ?>

                                <?php if ($position['candidates'] === []): ?>
                                    <p>No approved candidates or referendum options were on this ballot.</p>
                                <?php else: ?>
                                    <div class="table-wrap results-table-wrap">
                                        <table class="table results-table">
                                            <caption><?= sanitize($position['title']) ?> — <?= sanitize($resultLabel) ?> and share</caption>
                                            <thead>
                                                <tr>
                                                    <th scope="col"><?= $position['contest_type'] === 'referendum' ? 'Option' : 'Candidate' ?></th>
                                                    <th scope="col"><?= sanitize($resultLabel) ?></th>
                                                    <th scope="col"><?= sanitize($shareLabel) ?></th>
                                                    <th scope="col">Vote distribution</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($position['candidates'] as $candidateId => $candidate): ?>
                                                    <?php
                                                    $percentage = $position['total_votes'] > 0
                                                        ? ($candidate['votes'] / $position['total_votes']) * 100
                                                        : 0.0;
                                                    $barWidth = number_format($percentage, 2, '.', '');
                                                    $isWinner = in_array($candidateId, $winnerIds, true);
                                                    ?>
                                                    <tr<?= $isWinner ? ' class="results-table__winner"' : '' ?>>
                                                        <td>
                                                            <div class="results-candidate">
                                                                <?php if ($candidate['photo_path'] !== null): ?>
                                                                    <img class="results-candidate__photo"
                                                                        src="<?= BASE_URL ?>/<?= sanitize($candidate['photo_path']) ?>"
                                                                        alt="<?= sanitize($candidate['name']) ?>">
                                                                <?php else: ?>
                                                                    <span class="results-candidate__photo results-candidate__photo--fallback"
                                                                        aria-hidden="true">
                                                                        <?= sanitize(results_candidate_initials($candidate['name'])) ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                                <span class="results-candidate__identity">
                                                                    <strong><?= sanitize($candidate['name']) ?></strong>
                                                                    <?php if (is_string($candidate['affiliation']) && $candidate['affiliation'] !== ''): ?>
                                                                        <small><?= sanitize($candidate['affiliation']) ?></small>
                                                                    <?php elseif ($position['contest_type'] === 'referendum'): ?>
                                                                        <small>Referendum option</small>
                                                                    <?php endif; ?>
                                                                    <?php if ($isWinner): ?>
                                                                        <span class="results-candidate__winner">Winner</span>
                                                                    <?php elseif (in_array($candidateId, $tiedIds, true)): ?>
                                                                        <span class="results-candidate__tied">Tied for selection</span>
                                                                    <?php endif; ?>
                                                                </span>
                                                            </div>
                                                        </td>
                                                        <td><?= number_format((int) $candidate['votes']) ?></td>
                                                        <td><?= number_format($percentage, 1) ?>%</td>
                                                        <td>
                                                            <div class="result-bar" role="img"
                                                                aria-label="<?= sanitize($candidate['name']) ?>: <?= number_format($percentage, 1) ?> percent">
                                                                <span class="result-bar__fill<?= $isWinner ? ' result-bar__fill--winner' : '' ?>"
                                                                    style="width: <?= sanitize($barWidth) ?>%"></span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if ($selectedElection['voting_system'] === 'multiple_selection'): ?>
                                        <p class="results-method-note">
                                            Percentages are shares of all candidate/option selections in this contest.
                                            Up to <?= (int) $position['max_votes'] ?> selections could be made per ballot.
                                        </p>
                                    <?php elseif ($selectedElection['voting_system'] === 'ranked_choice'): ?>
                                        <p class="results-method-note">
                                            The table shows first preferences; the declared outcome uses instant-runoff transfers.
                                        </p>
                                        <?php foreach ($position['irv']['rounds'] as $round): ?>
                                            <details class="results-round">
                                                <summary>
                                                    Round <?= (int) $round['number'] ?> —
                                                    <?= (int) $round['active_ballots'] ?> active,
                                                    <?= (int) $round['exhausted_ballots'] ?> exhausted
                                                </summary>
                                                <div class="table-wrap">
                                                    <table class="table">
                                                        <thead><tr>
                                                            <th scope="col"><?= $position['contest_type'] === 'referendum' ? 'Option' : 'Candidate' ?></th>
                                                            <th scope="col">Active preferences</th>
                                                            <th scope="col">Round outcome</th>
                                                        </tr></thead>
                                                        <tbody>
                                                            <?php foreach ($round['counts'] as $candidateId => $count): ?>
                                                                <tr>
                                                                    <td><?= sanitize($position['candidates'][$candidateId]['name']) ?></td>
                                                                    <td><?= number_format((int) $count) ?></td>
                                                                    <td>
                                                                        <?php if ($round['winner_id'] === $candidateId): ?>
                                                                            Winner
                                                                        <?php elseif ($round['winner_id'] !== null): ?>
                                                                            Not elected
                                                                        <?php elseif (in_array($candidateId, $round['eliminated'], true)): ?>
                                                                            Eliminated
                                                                        <?php elseif (in_array($candidateId, $round['tie'], true)): ?>
                                                                            Tied
                                                                        <?php else: ?>
                                                                            Continuing
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </details>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
