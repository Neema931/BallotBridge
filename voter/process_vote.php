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

function voter_vote_position_id($value): int|false
{
    if (!is_int($value) && !is_string($value)) {
        return false;
    }
    $positionId = filter_var((string) $value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    return $positionId === false ? false : (int) $positionId;
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

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_time.php';
require_once __DIR__ . '/../includes/election_scheduler.php';

$electionId = (int) $rawElectionId;
$userId = (int) current_user()['id'];
activate_due_auto_elections($pdo);

$pdo->beginTransaction();

try {
    $electionQuery = $pdo->prepare(
        'SELECT id, start_datetime, end_datetime, timezone, voting_system,
                verification_methods, allow_abstentions
         FROM elections
         WHERE id = :election_id AND status = \'open\'
         FOR UPDATE'
    );
    $electionQuery->execute(['election_id' => $electionId]);
    $election = $electionQuery->fetch();
    if (!$election || !election_is_within_schedule($election)) {
        voter_vote_fail($pdo, $electionId, 'This election is not currently accepting votes.');
    }

    $voterQuery = $pdo->prepare(
        "SELECT v.id, v.reg_number, u.password_hash
         FROM voters v
         INNER JOIN users u ON u.id = v.user_id
         WHERE u.id = :user_id
           AND u.role = 'voter'
           AND u.status = 'active'
         LIMIT 1
         FOR UPDATE"
    );
    $voterQuery->execute(['user_id' => $userId]);
    $voter = $voterQuery->fetch();
    if (!$voter) {
        voter_vote_fail($pdo, $electionId, 'Your voter account must be active to cast a vote.');
    }
    $voterId = (int) $voter['id'];
    $verificationMethods = explode(',', $election['verification_methods']);

    if (in_array('email_token', $verificationMethods, true)) {
        $verificationQuery = $pdo->prepare(
            'SELECT (verified_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)) AS recently_verified
             FROM election_voter_verifications
             WHERE election_id = :election_id AND voter_id = :voter_id
             LIMIT 1'
        );
        $verificationQuery->execute([
            'election_id' => $electionId,
            'voter_id' => $voterId,
        ]);
        if (!(bool) $verificationQuery->fetchColumn()) {
            voter_vote_fail($pdo, $electionId, 'Verify your registered email before submitting this ballot.');
        }
    }

    if (in_array('voter_pin', $verificationMethods, true)) {
        $registrationNumber = isset($_POST['voter_reg_number']) && is_string($_POST['voter_reg_number'])
            ? trim($_POST['voter_reg_number'])
            : '';
        $password = isset($_POST['voter_pin']) && is_string($_POST['voter_pin'])
            ? $_POST['voter_pin']
            : '';
        if (
            $registrationNumber === ''
            || !hash_equals((string) $voter['reg_number'], $registrationNumber)
            || !password_verify($password, $voter['password_hash'])
        ) {
            voter_vote_fail($pdo, $electionId, 'The voter ID or account password is incorrect.');
        }
    }

    $positionsQuery = $pdo->prepare(
        "SELECT p.id, p.max_votes
         FROM positions p
         WHERE p.election_id = :election_id
           AND EXISTS (
               SELECT 1 FROM candidates c
               WHERE c.position_id = p.id
                 AND c.election_id = p.election_id
                 AND c.status = 'approved'
           )
         ORDER BY p.id
         FOR UPDATE"
    );
    $positionsQuery->execute(['election_id' => $electionId]);
    $positions = [];
    foreach ($positionsQuery->fetchAll() as $position) {
        $positions[(int) $position['id']] = [
            'max_votes' => (int) $position['max_votes'],
            'candidates' => [],
        ];
    }

    $candidateQuery = $pdo->prepare(
        "SELECT c.id, c.position_id
         FROM candidates c
         INNER JOIN positions p ON p.id = c.position_id AND p.election_id = c.election_id
         WHERE c.election_id = :election_id AND c.status = 'approved'"
    );
    $candidateQuery->execute(['election_id' => $electionId]);
    foreach ($candidateQuery->fetchAll() as $candidate) {
        $positions[(int) $candidate['position_id']]['candidates'][(int) $candidate['id']] = true;
    }

    $existingSubmissionsQuery = $pdo->prepare(
        'SELECT position_id
         FROM ballot_submissions
         WHERE voter_id = :voter_id AND election_id = :election_id
         FOR UPDATE'
    );
    $existingSubmissionsQuery->execute([
        'voter_id' => $voterId,
        'election_id' => $electionId,
    ]);
    $alreadySubmitted = [];
    foreach ($existingSubmissionsQuery->fetchAll(PDO::FETCH_COLUMN) as $positionId) {
        $alreadySubmitted[(int) $positionId] = true;
    }

    $requiredPositions = array_diff_key($positions, $alreadySubmitted);
    if ($requiredPositions === []) {
        voter_vote_fail($pdo, $electionId, 'You have already submitted all available positions.');
    }

    $postedVotes = $_POST['votes'] ?? [];
    $postedAbstentions = $_POST['abstain'] ?? [];
    if (!is_array($postedVotes) || !is_array($postedAbstentions)) {
        voter_vote_fail($pdo, $electionId, 'Invalid ballot data.');
    }

    $normalizedVotes = [];
    foreach ($postedVotes as $rawPositionId => $selection) {
        $positionId = voter_vote_position_id($rawPositionId);
        if ($positionId === false || !isset($requiredPositions[$positionId])) {
            voter_vote_fail($pdo, $electionId, 'A ballot selection is invalid or has already been submitted.');
        }
        $normalizedVotes[$positionId] = $selection;
    }
    $normalizedAbstentions = [];
    foreach ($postedAbstentions as $rawPositionId => $value) {
        $positionId = voter_vote_position_id($rawPositionId);
        if (
            $positionId === false
            || !isset($requiredPositions[$positionId])
            || $value !== '1'
        ) {
            voter_vote_fail($pdo, $electionId, 'An abstention selection is invalid.');
        }
        $normalizedAbstentions[$positionId] = true;
    }
    if (count($normalizedVotes) + count($normalizedAbstentions) < count($requiredPositions)) {
        voter_vote_fail($pdo, $electionId, 'Complete each remaining position or select an allowed abstention.');
    }

    $votesToInsert = [];
    foreach ($requiredPositions as $positionId => $position) {
        $isAbstention = isset($normalizedAbstentions[$positionId]);
        $selection = $normalizedVotes[$positionId] ?? null;
        if ($election['voting_system'] === 'fptp' && $selection === 'abstain') {
            $isAbstention = true;
        }
        if ($isAbstention && !(bool) $election['allow_abstentions']) {
            voter_vote_fail($pdo, $electionId, 'Abstentions are not allowed in this election.');
        }

        if ($election['voting_system'] === 'fptp') {
            if ($isAbstention && $selection !== null && $selection !== 'abstain') {
                voter_vote_fail($pdo, $electionId, 'Choose either a candidate or abstain for each position.');
            }
            if (!$isAbstention) {
                $candidateId = is_string($selection) || is_int($selection)
                    ? filter_var((string) $selection, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : false;
                if ($candidateId === false || !isset($position['candidates'][(int) $candidateId])) {
                    voter_vote_fail($pdo, $electionId, 'Select one approved candidate for each position.');
                }
                $votesToInsert[$positionId] = [[(int) $candidateId, null]];
            }
        } elseif ($election['voting_system'] === 'multiple_selection') {
            if ($isAbstention && $selection !== null) {
                voter_vote_fail($pdo, $electionId, 'Choose either candidates or abstain for each position.');
            }
            if ($isAbstention) {
                continue;
            }
            if (!is_array($selection) || $selection === []) {
                voter_vote_fail($pdo, $electionId, 'Select at least one approved candidate or abstain.');
            }
            $selectedCandidates = [];
            foreach ($selection as $rawCandidateId) {
                $candidateId = (is_string($rawCandidateId) || is_int($rawCandidateId))
                    ? filter_var((string) $rawCandidateId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : false;
                if (
                    $candidateId === false
                    || !isset($position['candidates'][(int) $candidateId])
                    || isset($selectedCandidates[(int) $candidateId])
                ) {
                    voter_vote_fail($pdo, $electionId, 'A selected candidate is invalid or duplicated.');
                }
                $selectedCandidates[(int) $candidateId] = true;
            }
            if (count($selectedCandidates) > $position['max_votes']) {
                voter_vote_fail($pdo, $electionId, 'You selected more candidates than this position allows.');
            }
            $votesToInsert[$positionId] = array_map(
                static fn (int $candidateId): array => [$candidateId, null],
                array_keys($selectedCandidates)
            );
        } else {
            if ($isAbstention && $selection !== null) {
                voter_vote_fail($pdo, $electionId, 'Choose either candidate rankings or abstain for each position.');
            }
            if ($isAbstention) {
                continue;
            }
            if (!is_array($selection) || $selection === []) {
                voter_vote_fail($pdo, $electionId, 'Rank at least one approved candidate or abstain.');
            }
            $rankedCandidates = [];
            $usedRanks = [];
            foreach ($selection as $rawCandidateId => $rawRank) {
                $candidateId = voter_vote_position_id($rawCandidateId);
                $rank = (is_string($rawRank) || is_int($rawRank))
                    ? filter_var((string) $rawRank, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
                    : false;
                if (
                    $candidateId === false
                    || !isset($position['candidates'][$candidateId])
                    || $rank === false
                    || $rank > count($position['candidates'])
                ) {
                    voter_vote_fail($pdo, $electionId, 'A ranked-choice selection is invalid.');
                }
                if ($rank > 0) {
                    if (isset($usedRanks[$rank])) {
                        voter_vote_fail($pdo, $electionId, 'Each ranked-choice rank can only be used once.');
                    }
                    $usedRanks[$rank] = true;
                    $rankedCandidates[] = [$candidateId, (int) $rank];
                }
            }
            if ($rankedCandidates === []) {
                voter_vote_fail($pdo, $electionId, 'Rank at least one candidate or abstain.');
            }
            $votesToInsert[$positionId] = $rankedCandidates;
        }

    }

    $receiptHash = hash('sha256', random_bytes(32));
    $insertReceipt = $pdo->prepare(
        'INSERT INTO ballot_receipts (receipt_hash, election_id, position_count)
         VALUES (:receipt_hash, :election_id, :position_count)'
    );
    $insertReceipt->execute([
        'receipt_hash' => $receiptHash,
        'election_id' => $electionId,
        'position_count' => count($requiredPositions),
    ]);

    $insertVote = $pdo->prepare(
        'INSERT INTO votes (election_id, position_id, candidate_id, voter_id, rank)
         VALUES (:election_id, :position_id, :candidate_id, :voter_id, :rank)'
    );
    $insertSubmission = $pdo->prepare(
        'INSERT INTO ballot_submissions
            (election_id, position_id, voter_id, abstained, receipt_hash)
         VALUES (:election_id, :position_id, :voter_id, :abstained, :receipt_hash)'
    );
    foreach ($requiredPositions as $positionId => $_position) {
        $abstained = isset($normalizedAbstentions[$positionId])
            || (
                $election['voting_system'] === 'fptp'
                && ($normalizedVotes[$positionId] ?? null) === 'abstain'
            );
        foreach ($votesToInsert[$positionId] ?? [] as [$candidateId, $rank]) {
            $insertVote->execute([
                'election_id' => $electionId,
                'position_id' => $positionId,
                'candidate_id' => $candidateId,
                'voter_id' => $voterId,
                'rank' => $rank,
            ]);
        }
        $insertSubmission->execute([
            'election_id' => $electionId,
            'position_id' => $positionId,
            'voter_id' => $voterId,
            'abstained' => $abstained ? 1 : 0,
            'receipt_hash' => $receiptHash,
        ]);
    }

    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $isDuplicateSubmission = $exception->getCode() === '23000'
        && isset($exception->errorInfo[1])
        && (int) $exception->errorInfo[1] === 1062;
    if ($isDuplicateSubmission) {
        set_flash('error', 'A ballot for one or more positions was already recorded. Refresh your ballot.');
        redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
    }

    throw $exception;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

set_flash('success', 'Your ballot has been recorded.');
redirect(BASE_URL . '/voter/my_vote.php');
