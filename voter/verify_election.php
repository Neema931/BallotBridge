<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('voter');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/election_time.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_scheduler.php';

activate_due_auto_elections($pdo);

if (!is_post()) {
    redirect(BASE_URL . '/voter/dashboard.php');
}

verify_csrf();

$rawElectionId = isset($_POST['election_id']) && is_string($_POST['election_id'])
    ? filter_var($_POST['election_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
if ($rawElectionId === false || !in_array($action, ['send_code', 'verify_code'], true)) {
    set_flash('error', 'Invalid election verification request.');
    redirect(BASE_URL . '/voter/dashboard.php');
}
$electionId = (int) $rawElectionId;
$userId = (int) current_user()['id'];

$voterQuery = $pdo->prepare(
    "SELECT v.id, u.full_name, u.email, u.status
     FROM voters v
     INNER JOIN users u ON u.id = v.user_id
     WHERE v.user_id = :user_id AND u.role = 'voter'
     LIMIT 1"
);
$voterQuery->execute(['user_id' => $userId]);
$voter = $voterQuery->fetch();
$electionQuery = $pdo->prepare(
    "SELECT id, title, status, start_datetime, end_datetime, timezone, verification_methods
     FROM elections WHERE id = :election_id LIMIT 1"
);
$electionQuery->execute(['election_id' => $electionId]);
$election = $electionQuery->fetch();

if (
    !$voter
    || $voter['status'] !== 'active'
    || !$election
    || $election['status'] !== 'open'
    || !in_array('email_token', explode(',', $election['verification_methods']), true)
    || !election_is_within_schedule($election)
) {
    set_flash('error', 'This election is not available for verification.');
    redirect(BASE_URL . '/voter/dashboard.php');
}

if ($action === 'send_code') {
    $token = (string) random_int(100000, 999999);
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    if (!is_string($tokenHash)) {
        throw new RuntimeException('Unable to secure the election verification token.');
    }

    $pdo->beginTransaction();
    $existingQuery = $pdo->prepare(
        'SELECT verified_at, last_sent_at
         FROM election_voter_verifications
         WHERE election_id = :election_id AND voter_id = :voter_id
         FOR UPDATE'
    );
    $existingQuery->execute([
        'election_id' => $electionId,
        'voter_id' => $voter['id'],
    ]);
    $existing = $existingQuery->fetch();
    $timeQuery = $pdo->prepare(
        'SELECT
            (:verified_at_a IS NOT NULL AND :verified_at_b >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)) AS recently_verified,
            (:last_sent_at_a IS NOT NULL AND :last_sent_at_b > DATE_SUB(NOW(), INTERVAL 60 SECOND)) AS sent_recently'
    );
    $timeQuery->execute([
        'verified_at_a' => $existing['verified_at'] ?? null,
        'verified_at_b' => $existing['verified_at'] ?? null,
        'last_sent_at_a' => $existing['last_sent_at'] ?? null,
        'last_sent_at_b' => $existing['last_sent_at'] ?? null,
    ]);
    $rateState = $timeQuery->fetch();

    if ((bool) $rateState['recently_verified']) {
        $pdo->commit();
        set_flash('info', 'Your email is already verified for this ballot.');
        redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
    }
    if ((bool) $rateState['sent_recently']) {
        $pdo->rollBack();
        set_flash('error', 'Please wait one minute before requesting another verification code.');
        redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
    }

    $saveToken = $pdo->prepare(
        'INSERT INTO election_voter_verifications
            (election_id, voter_id, token_hash, expires_at, verified_at, last_sent_at, attempt_count)
         VALUES
            (:election_id, :voter_id, :token_hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NULL, NOW(), 0)
         ON DUPLICATE KEY UPDATE
            token_hash = VALUES(token_hash),
            expires_at = VALUES(expires_at),
            verified_at = NULL,
            last_sent_at = NOW(),
            attempt_count = 0'
    );
    $saveToken->execute([
        'election_id' => $electionId,
        'voter_id' => $voter['id'],
        'token_hash' => $tokenHash,
    ]);
    $pdo->commit();

    $emailSent = send_election_email_token(
        $voter['email'],
        $voter['full_name'],
        $token,
        $election['title']
    );
    if (!$emailSent) {
        $clearToken = $pdo->prepare(
            'UPDATE election_voter_verifications
             SET token_hash = NULL, expires_at = NULL, last_sent_at = NULL, attempt_count = 0
             WHERE election_id = :election_id AND voter_id = :voter_id AND token_hash = :token_hash'
        );
        $clearToken->execute([
            'election_id' => $electionId,
            'voter_id' => $voter['id'],
            'token_hash' => $tokenHash,
        ]);
        set_flash('error', 'The verification email could not be sent. Please try again later or contact an administrator.');
        redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
    }

    set_flash('success', 'A verification code was sent to your registered email address.');
    redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
}

$token = isset($_POST['verification_code']) && is_string($_POST['verification_code'])
    ? trim($_POST['verification_code'])
    : '';
if (preg_match('/\A[0-9]{6}\z/', $token) !== 1) {
    set_flash('error', 'Enter the six-digit verification code.');
    redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
}

$pdo->beginTransaction();
$verificationQuery = $pdo->prepare(
    'SELECT token_hash, expires_at, attempt_count, (expires_at >= NOW()) AS unexpired
     FROM election_voter_verifications
     WHERE election_id = :election_id AND voter_id = :voter_id
     FOR UPDATE'
);
$verificationQuery->execute([
    'election_id' => $electionId,
    'voter_id' => $voter['id'],
]);
$verification = $verificationQuery->fetch();

if (
    !$verification
    || !is_string($verification['token_hash'])
    || !(bool) $verification['unexpired']
    || (int) $verification['attempt_count'] >= 5
) {
    $pdo->rollBack();
    set_flash('error', 'The verification code is invalid or expired. Request a new code.');
    redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
}

if (!password_verify($token, $verification['token_hash'])) {
    $attempts = (int) $verification['attempt_count'] + 1;
    $updateAttempts = $pdo->prepare(
        'UPDATE election_voter_verifications
         SET attempt_count = :attempt_count,
             token_hash = IF(:attempt_limit >= 5, NULL, token_hash),
             expires_at = IF(:expiry_limit >= 5, NULL, expires_at)
         WHERE election_id = :election_id AND voter_id = :voter_id'
    );
    $updateAttempts->execute([
        'attempt_count' => $attempts,
        'attempt_limit' => $attempts,
        'expiry_limit' => $attempts,
        'election_id' => $electionId,
        'voter_id' => $voter['id'],
    ]);
    $pdo->commit();
    set_flash('error', $attempts >= 5
        ? 'Too many incorrect codes. Request a new verification code.'
        : 'The verification code is incorrect.');
    redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
}

$markVerified = $pdo->prepare(
    'UPDATE election_voter_verifications
     SET token_hash = NULL, expires_at = NULL, verified_at = NOW(), attempt_count = 0
     WHERE election_id = :election_id AND voter_id = :voter_id'
);
$markVerified->execute([
    'election_id' => $electionId,
    'voter_id' => $voter['id'],
]);
$pdo->commit();

set_flash('success', 'Email verification complete. You can now submit your ballot.');
redirect(BASE_URL . '/voter/vote.php?election_id=' . $electionId);
