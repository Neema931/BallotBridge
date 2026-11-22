<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../config/database.php';

$electionsQuery = $pdo->prepare(
    'SELECT id, title, status, start_datetime, end_datetime
     FROM elections ORDER BY id DESC'
);
$electionsQuery->execute();
$elections = $electionsQuery->fetchAll();

$requestedElectionId = isset($_GET['election_id']) && is_string($_GET['election_id'])
    ? filter_var($_GET['election_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$selectedElection = null;
foreach ($elections as $election) {
    if (
        ($requestedElectionId !== false && (int) $election['id'] === $requestedElectionId)
        || ($requestedElectionId === false && $selectedElection === null)
    ) {
        $selectedElection = $election;
        if ($requestedElectionId !== false) {
            break;
        }
    }
}

$resultsByPosition = [];
if ($selectedElection !== null) {
    $resultsQuery = $pdo->prepare(
        "SELECT p.id AS position_id, p.title AS position_title,
                c.id AS candidate_id, u.full_name AS candidate_name,
                COUNT(v.id) AS vote_count
         FROM positions p
         LEFT JOIN candidates c
           ON c.position_id = p.id
          AND c.election_id = p.election_id
          AND c.status = 'approved'
         LEFT JOIN users u ON u.id = c.user_id
         LEFT JOIN votes v
           ON v.candidate_id = c.id
          AND v.position_id = p.id
          AND v.election_id = p.election_id
         WHERE p.election_id = :election_id
         GROUP BY p.id, p.title, c.id, u.full_name
         ORDER BY p.id, u.full_name"
    );
    $resultsQuery->execute(['election_id' => $selectedElection['id']]);

    foreach ($resultsQuery->fetchAll() as $result) {
        $positionId = (int) $result['position_id'];
        if (!isset($resultsByPosition[$positionId])) {
            $resultsByPosition[$positionId] = [
                'title' => $result['position_title'],
                'candidates' => [],
            ];
        }
        $resultsByPosition[$positionId]['candidates'][] = $result;
    }
}

$pageTitle = 'Election results';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>Election results</h1>
                <p>Review vote totals by election, position, and approved candidate.</p>
            </div>
        </header>

        <?php if ($elections === []): ?>
            <div class="empty-state" role="status">
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
            <?php else: ?>
                <?php if ($selectedElection['status'] !== 'closed'): ?>
                    <div class="alert alert--info results-warning" role="alert">
                        Preview only: this election is <?= sanitize($selectedElection['status']) ?>.
                        Results are not final until it is closed.
                    </div>
                <?php endif; ?>

                <h2 class="results-election-title"><?= sanitize($selectedElection['title']) ?></h2>
                <div class="print-only">
                    <p><?= sanitize(APP_NAME) ?> — official results</p>
                    <p>Printed <?= sanitize(date('Y-m-d H:i:s')) ?></p>
                </div>
                <div class="results-actions no-print">
                    <button class="btn btn--outline" type="button" data-print-page>
                        Print results
                    </button>
                </div>

                <?php if ($resultsByPosition === []): ?>
                    <div class="empty-state" role="status">
                        <h3>No positions yet</h3>
                        <p>This election has no results to display.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($resultsByPosition as $position): ?>
                        <?php
                        $positionTotal = 0;
                        foreach ($position['candidates'] as $candidate) {
                            $positionTotal += (int) $candidate['vote_count'];
                        }
                        ?>
                        <section class="results-position" aria-label="<?= sanitize($position['title']) ?> results">
                            <header class="results-position__header">
                                <h3><?= sanitize($position['title']) ?></h3>
                                <p><?= $positionTotal ?> total votes</p>
                            </header>

                            <?php if ($position['candidates'][0]['candidate_id'] === null): ?>
                                <p>No approved candidates for this position.</p>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th scope="col">Candidate</th>
                                                <th scope="col">Votes</th>
                                                <th scope="col">Share</th>
                                                <th scope="col">Chart</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($position['candidates'] as $candidate): ?>
                                                <?php
                                                $voteCount = (int) $candidate['vote_count'];
                                                $percentage = $positionTotal > 0
                                                    ? ($voteCount / $positionTotal) * 100
                                                    : 0;
                                                $barWidth = number_format($percentage, 2, '.', '');
                                                ?>
                                                <tr>
                                                    <td><?= sanitize($candidate['candidate_name']) ?></td>
                                                    <td><?= $voteCount ?></td>
                                                    <td><?= number_format($percentage, 1) ?>%</td>
                                                    <td>
                                                        <div class="result-bar"
                                                            role="img"
                                                            aria-label="<?= sanitize($candidate['candidate_name']) ?>: <?= number_format($percentage, 1) ?> percent">
                                                            <span class="result-bar__fill"
                                                                style="width: <?= sanitize($barWidth) ?>%"></span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>