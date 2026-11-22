<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('voter');
require_once __DIR__ . '/../includes/csrf.php';

function voter_vote_fail(PDO $pdo, int $electionId, string $message): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', $message);
    redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
}

if (!is_post()) {
    redirect(BASE_URL . '/voter/dashboard.php');
}

verify_csrf();

$rawElectionId = isset($_POST['election_id']) && is_string($_POST['election_id'])
    ? filter_var($_POST['election_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($rawElectionId === false) {
    set_flash('error', 'Invalid election.');
    redirect(BASE_URL . '/voter/dashboard.php');
}
$electionId = (int) $rawElectionId;
$userId = (int) current_user()['id'];
$postedVotes = $_POST['votes'] ?? null;

if (!is_array($postedVotes) || $postedVotes === []) {
    set_flash('error', 'Select a candidate for each available position.');
    redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
}

$submittedVotes = [];
foreach ($postedVotes as $rawPositionId => $rawCandidateId) {
    if (
        (!is_int($rawPositionId) && !is_string($rawPositionId))
        || (!is_string($rawCandidateId) && !is_int($rawCandidateId))
    ) {
        set_flash('error', 'Invalid ballot selection.');
        redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
    }

    $positionId = filter_var((string) $rawPositionId, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $candidateId = filter_var((string) $rawCandidateId, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($positionId === false || $candidateId === false) {
        set_flash('error', 'Invalid ballot selection.');
        redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
    }

    $submittedVotes[(int) $positionId] = (int) $candidateId;
}

require_once __DIR__ . '/../config/database.php';

$pdo->beginTransaction();

try {
    $electionQuery = $pdo->prepare(
        "SELECT id FROM elections
         WHERE id = :election_id
           AND status = 'open'
           AND start_datetime <= NOW()
           AND end_datetime >= NOW()
         FOR UPDATE"
    );
    $electionQuery->execute(['election_id' => $electionId]);
    if ($electionQuery->fetchColumn() === false) {
        voter_vote_fail($pdo, $electionId, 'This election is not currently accepting votes.');
    }

    $voterQuery = $pdo->prepare(
        "SELECT v.id
         FROM voters v
         INNER JOIN users u ON u.id = v.user_id
         WHERE u.id = :user_id
           AND u.role = 'voter'
           AND u.status = 'active'
         LIMIT 1
         FOR UPDATE"
    );
    $voterQuery->execute(['user_id' => $userId]);
    $voterId = $voterQuery->fetchColumn();
    if ($voterId === false) {
        voter_vote_fail($pdo, $electionId, 'Your voter account must be active to cast a vote.');
    }
    $voterId = (int) $voterId;

    $positionQuery = $pdo->prepare(
        "SELECT p.id, COUNT(c.id) AS approved_candidate_count
         FROM positions p
         LEFT JOIN candidates c
           ON c.position_id = p.id
          AND c.election_id = p.election_id
          AND c.status = 'approved'
         WHERE p.election_id = :election_id
         GROUP BY p.id"
    );
    $positionQuery->execute(['election_id' => $electionId]);
    $requiredPositions = [];
    foreach ($positionQuery->fetchAll() as $position) {
        if ((int) $position['approved_candidate_count'] > 0) {
            $requiredPositions[(int) $position['id']] = true;
        }
    }

    $existingVotesQuery = $pdo->prepare(
        'SELECT position_id
         FROM votes
         WHERE voter_id = :voter_id AND election_id = :election_id
         FOR UPDATE'
    );
    $existingVotesQuery->execute([
        'voter_id' => $voterId,
        'election_id' => $electionId,
    ]);
    $alreadyVoted = [];
    foreach ($existingVotesQuery->fetchAll(PDO::FETCH_COLUMN) as $positionId) {
        $alreadyVoted[(int) $positionId] = true;
    }

    foreach ($alreadyVoted as $positionId => $_) {
        unset($requiredPositions[$positionId]);
    }

    if ($requiredPositions === []) {
        voter_vote_fail($pdo, $electionId, 'You have already voted in all available positions.');
    }
    if (count($submittedVotes) !== count($requiredPositions)) {
        voter_vote_fail($pdo, $electionId, 'Select one candidate for each remaining position.');
    }

    foreach ($submittedVotes as $positionId => $candidateId) {
        if (!isset($requiredPositions[$positionId])) {
            voter_vote_fail($pdo, $electionId, 'A selection is invalid or has already been submitted.');
        }

        $candidateQuery = $pdo->prepare(
            "SELECT c.id
             FROM candidates c
             INNER JOIN positions p
               ON p.id = c.position_id AND p.election_id = c.election_id
             WHERE c.id = :candidate_id
               AND c.position_id = :position_id
               AND c.election_id = :election_id
               AND c.status = 'approved'
             LIMIT 1"
        );
        $candidateQuery->execute([
            'candidate_id' => $candidateId,
            'position_id' => $positionId,
            'election_id' => $electionId,
        ]);
        if ($candidateQuery->fetchColumn() === false) {
            voter_vote_fail($pdo, $electionId, 'A selected candidate does not belong to that position.');
        }
    }

    $insertVote = $pdo->prepare(
        'INSERT INTO votes (election_id, position_id, candidate_id, voter_id)
         VALUES (:election_id, :position_id, :candidate_id, :voter_id)'
    );
    foreach ($submittedVotes as $positionId => $candidateId) {
        $insertVote->execute([
            'election_id' => $electionId,
            'position_id' => $positionId,
            'candidate_id' => $candidateId,
            'voter_id' => $voterId,
        ]);
    }

    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $isDuplicateVote = $exception->getCode() === '23000'
        && isset($exception->errorInfo[1])
        && (int) $exception->errorInfo[1] === 1062;

    if ($isDuplicateVote) {
        set_flash('error', 'A vote for one or more positions was already recorded. Refresh your ballot.');
        redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
    }

    throw $exception;
}

set_flash('success', 'Your vote has been recorded.');
redirect(BASE_URL . '/voter/my_vote.php');