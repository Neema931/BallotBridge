<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_scheduler.php';
require_once __DIR__ . '/../includes/election_time.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$rawElectionId = isset($_GET['election_id']) && is_string($_GET['election_id'])
    ? filter_var($_GET['election_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($rawElectionId === false) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid election ID is required.']);
    exit;
}

activate_due_auto_elections($pdo);
$electionQuery = $pdo->prepare(
    'SELECT status, start_datetime, end_datetime, timezone, auto_launch
     FROM elections WHERE id = :election_id'
);
$electionQuery->execute(['election_id' => (int) $rawElectionId]);
$election = $electionQuery->fetch();
if (!$election) {
    http_response_code(404);
    echo json_encode(['error' => 'Election not found.']);
    exit;
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$start = parse_stored_election_time($election['start_datetime'], $election['timezone']);
$end = parse_stored_election_time($election['end_datetime'], $election['timezone']);
$displayStatus = ucfirst($election['status']);
if ($election['status'] === 'open' && $start !== null && $end !== null) {
    if ($now < $start) {
        $displayStatus = 'Scheduled';
    } elseif ($now <= $end) {
        $displayStatus = 'Live';
    } else {
        $displayStatus = 'Awaiting closure';
    }
} elseif ($election['status'] === 'paused') {
    $displayStatus = 'Paused';
} elseif (
    $election['status'] === 'draft'
    && (bool) $election['auto_launch']
    && $start !== null
    && $end !== null
    && $start > $now
    && $end > $now
) {
    $displayStatus = 'Scheduled';
}

echo json_encode([
    'closed' => $election['status'] === 'closed',
    'status' => $displayStatus,
], JSON_THROW_ON_ERROR);
