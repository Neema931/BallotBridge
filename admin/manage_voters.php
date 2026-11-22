<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/voter_credentials.php';
require_once __DIR__ . '/../config/database.php';

$redirectVoters = static function (): void {
    $query = [];
    foreach (['tab', 'q', 'election_id', 'voting_status'] as $key) {
        if (isset($_POST[$key]) && is_string($_POST[$key]) && $_POST[$key] !== '') {
            $query[$key] = $_POST[$key];
        }
    }

    $url = BASE_URL . '/admin/manage_voters.php';
    redirect($query === [] ? $url : $url . '?' . http_build_query($query));
};

$readVoterDetails = static function (array $source = []): ?array {
    $source = $source === [] ? $_POST : $source;
    $fullName = isset($source['full_name']) && is_string($source['full_name'])
        ? trim($source['full_name'])
        : '';
    $email = isset($source['email']) && is_string($source['email'])
        ? strtolower(trim($source['email']))
        : '';
    $regNumber = isset($source['reg_number']) && is_string($source['reg_number'])
        ? trim($source['reg_number'])
        : '';
    $phone = isset($source['phone']) && is_string($source['phone'])
        ? trim($source['phone'])
        : '';
    $nameLength = preg_match_all('/./us', $fullName);

    if (
        $nameLength === false
        || $nameLength < 1
        || $nameLength > 150
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || strlen($email) > 254
        || $regNumber === ''
        || strlen($regNumber) > 100
        || strlen($phone) > 32
        || preg_match('/[\x00-\x1F\x7F]/', $phone) === 1
    ) {
        return null;
    }

    return [
        'full_name' => $fullName,
        'email' => $email,
        'reg_number' => $regNumber,
        'phone' => $phone === '' ? null : $phone,
    ];
};

if (is_post()) {
    verify_csrf();

    $action = isset($_POST['action']) && is_string($_POST['action'])
        ? $_POST['action']
        : '';

    if (in_array($action, ['add', 'update_details'], true)) {
        $details = $readVoterDetails();
        $userId = $action === 'update_details'
            && isset($_POST['user_id'])
            && is_string($_POST['user_id'])
                ? filter_var($_POST['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;

        if ($details === null || ($action === 'update_details' && $userId === false)) {
            set_flash('error', 'Please check the voter details and try again.');
            $redirectVoters();
        }

        if ($action === 'add') {
            $password = isset($_POST['password']) && is_string($_POST['password'])
                ? $_POST['password']
                : '';
            if (strlen($password) < 8 || strlen($password) > 72) {
                set_flash('error', 'The initial password must be between 8 and 72 bytes.');
                $redirectVoters();
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Unable to hash the voter password.');
            }

            try {
                $pdo->beginTransaction();
                $insertUser = $pdo->prepare(
                    "INSERT INTO users (full_name, email, reg_number, password_hash, role, status)
                     VALUES (:full_name, :email, :reg_number, :password_hash, 'voter', 'pending')"
                );
                $insertUser->execute([
                    'full_name' => $details['full_name'],
                    'email' => $details['email'],
                    'reg_number' => $details['reg_number'],
                    'password_hash' => $passwordHash,
                ]);

                $insertVoter = $pdo->prepare(
                    "INSERT INTO voters (user_id, reg_number, phone, review_email_status)
                     VALUES (:user_id, :reg_number, :phone, 'pending')"
                );
                $newUserId = (int) $pdo->lastInsertId();
                $insertVoter->execute([
                    'user_id' => $newUserId,
                    'reg_number' => $details['reg_number'],
                    'phone' => $details['phone'],
                ]);
                log_admin_action(
                    $pdo,
                    'voter.created',
                    'voter',
                    $newUserId,
                    'Created a pending voter account.',
                    null,
                    $details + ['status' => 'pending']
                );
                $pdo->commit();
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $isDuplicate = $exception->getCode() === '23000'
                    && isset($exception->errorInfo[1])
                    && (int) $exception->errorInfo[1] === 1062;
                if ($isDuplicate) {
                    set_flash('error', 'That email address or registration number is already in use.');
                    $redirectVoters();
                }

                throw $exception;
            }

            set_flash('success', 'Voter added. The account is pending approval.');
            $redirectVoters();
        }

        try {
            $pdo->beginTransaction();
            $existingVoter = $pdo->prepare(
                'SELECT u.full_name, u.email, u.reg_number, v.phone
                 FROM voters v
                 INNER JOIN users u ON u.id = v.user_id
                 WHERE v.user_id = :user_id AND u.role = \'voter\'
                 FOR UPDATE'
            );
            $existingVoter->execute(['user_id' => $userId]);
            $existingDetails = $existingVoter->fetch();
            if (!$existingDetails) {
                $pdo->rollBack();
                set_flash('error', 'Voter account not found.');
                $redirectVoters();
            }

            $updateUser = $pdo->prepare(
                "UPDATE users
                 SET full_name = :full_name, email = :email, reg_number = :reg_number
                 WHERE id = :user_id AND role = 'voter'"
            );
            $updateUser->execute([
                'full_name' => $details['full_name'],
                'email' => $details['email'],
                'reg_number' => $details['reg_number'],
                'user_id' => $userId,
            ]);
            $updateVoter = $pdo->prepare(
                'UPDATE voters SET reg_number = :reg_number, phone = :phone WHERE user_id = :user_id'
            );
            $updateVoter->execute([
                'reg_number' => $details['reg_number'],
                'phone' => $details['phone'],
                'user_id' => $userId,
            ]);
            log_admin_action(
                $pdo,
                'voter.details_updated',
                'voter',
                $userId,
                'Updated voter details.',
                $existingDetails,
                $details
            );
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $isDuplicate = $exception->getCode() === '23000'
                && isset($exception->errorInfo[1])
                && (int) $exception->errorInfo[1] === 1062;
            if ($isDuplicate) {
                set_flash('error', 'That email address or registration number is already in use.');
                $redirectVoters();
            }

            throw $exception;
        }

        set_flash('success', 'Voter details updated.');
        $redirectVoters();
    }

    if ($action === 'import_csv') {
        $upload = $_FILES['voter_csv'] ?? null;
        if (
            !is_array($upload)
            || !isset($upload['error'], $upload['tmp_name'], $upload['size'])
            || (int) $upload['error'] !== UPLOAD_ERR_OK
            || !is_string($upload['tmp_name'])
            || !is_uploaded_file($upload['tmp_name'])
            || (int) $upload['size'] < 1
            || (int) $upload['size'] > 2 * 1024 * 1024
        ) {
            set_flash('error', 'Choose a CSV file smaller than 2 MB and try again.');
            $redirectVoters();
        }

        $csv = fopen($upload['tmp_name'], 'rb');
        if ($csv === false) {
            throw new RuntimeException('Unable to read the voter import file.');
        }
        $header = fgetcsv($csv, 4096, ',', '"', '\\');
        if (!is_array($header)) {
            fclose($csv);
            set_flash('error', 'The CSV file is empty or has no header row.');
            $redirectVoters();
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(
            static fn ($column): string => strtolower(trim((string) $column)),
            $header
        );
        $requiredHeaders = ['full_name', 'email', 'reg_number'];
        if (array_diff($requiredHeaders, $header) !== []) {
            fclose($csv);
            set_flash('error', 'CSV headers must include full_name, email, and reg_number; phone is optional.');
            $redirectVoters();
        }
        if (count($header) !== count(array_unique($header))) {
            fclose($csv);
            set_flash('error', 'The CSV header contains duplicate columns.');
            $redirectVoters();
        }

        $headerIndexes = array_flip($header);
        $importedCount = 0;
        $emailSentCount = 0;
        $rowErrors = [];
        $rowNumber = 1;
        $processedRows = 0;
        while (($cells = fgetcsv($csv, 4096, ',', '"', '\\')) !== false) {
            $rowNumber++;
            if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                continue;
            }
            if ($processedRows >= 500) {
                $rowErrors[] = 'Only the first 500 data rows were processed.';
                break;
            }
            $processedRows++;

            $source = [];
            foreach (['full_name', 'email', 'reg_number', 'phone'] as $field) {
                $source[$field] = isset($headerIndexes[$field], $cells[$headerIndexes[$field]])
                    ? trim((string) $cells[$headerIndexes[$field]])
                    : '';
            }
            $details = $readVoterDetails($source);
            if ($details === null) {
                $rowErrors[] = 'Row ' . $rowNumber . ': invalid or incomplete voter details.';
                continue;
            }

            try {
                $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                if (!is_string($passwordHash)) {
                    throw new RuntimeException('Unable to secure an imported voter account.');
                }

                $pdo->beginTransaction();
                $insertUser = $pdo->prepare(
                    "INSERT INTO users (full_name, email, reg_number, password_hash, role, status)
                     VALUES (:full_name, :email, :reg_number, :password_hash, 'voter', 'pending')"
                );
                $insertUser->execute($details + ['password_hash' => $passwordHash]);
                $userId = (int) $pdo->lastInsertId();
                $insertVoter = $pdo->prepare(
                    "INSERT INTO voters (user_id, reg_number, phone, review_email_status)
                     VALUES (:user_id, :reg_number, :phone, 'pending')"
                );
                $insertVoter->execute([
                    'user_id' => $userId,
                    'reg_number' => $details['reg_number'],
                    'phone' => $details['phone'],
                ]);
                $passwordToken = issue_voter_password_token($pdo, $userId);
                log_admin_action(
                    $pdo,
                    'voter.imported',
                    'voter',
                    $userId,
                    'Imported a pending voter from CSV.',
                    null,
                    $details + ['status' => 'pending']
                );
                $pdo->commit();
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $isDuplicate = $exception->getCode() === '23000'
                    && isset($exception->errorInfo[1])
                    && (int) $exception->errorInfo[1] === 1062;
                if (!$isDuplicate) {
                    fclose($csv);
                    throw $exception;
                }
                $rowErrors[] = 'Row ' . $rowNumber . ': email or voter ID is already in use.';
                continue;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                fclose($csv);
                throw $exception;
            }

            $importedCount++;
            if (send_voter_password_setup_email(
                $details['email'],
                $details['full_name'],
                $passwordToken
            )) {
                $emailSentCount++;
            } else {
                $rowErrors[] = 'Row ' . $rowNumber . ': account imported but password setup email failed.';
            }
        }
        fclose($csv);

        $message = $importedCount . ' voter account' . ($importedCount === 1 ? '' : 's')
            . ' imported as pending; ' . $emailSentCount . ' password setup email'
            . ($emailSentCount === 1 ? '' : 's') . ' sent.';
        if ($rowErrors !== []) {
            $message .= ' ' . implode(' ', array_slice($rowErrors, 0, 8));
            if (count($rowErrors) > 8) {
                $message .= ' And ' . (count($rowErrors) - 8) . ' more row issue(s).';
            }
        }
        set_flash($rowErrors === [] ? 'success' : 'info', $message);
        $redirectVoters();
    }

    if ($action === 'review') {
        $userId = isset($_POST['user_id']) && is_string($_POST['user_id'])
            ? filter_var($_POST['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $decision = isset($_POST['decision']) && is_string($_POST['decision'])
            ? $_POST['decision']
            : '';
        $newStatus = ['approve' => 'active', 'reject' => 'rejected'][$decision] ?? null;
        $rejectionReason = isset($_POST['rejection_reason']) && is_string($_POST['rejection_reason'])
            ? trim($_POST['rejection_reason'])
            : 'invalid details';
        if ($rejectionReason === '') {
            $rejectionReason = 'invalid details';
        }
        if (strlen($rejectionReason) > 250) {
            set_flash('error', 'The rejection reason must be 250 characters or fewer.');
            $redirectVoters();
        }

        if ($userId === false || $newStatus === null) {
            set_flash('error', 'Invalid voter review action.');
            $redirectVoters();
        }

        $pdo->beginTransaction();
        $voterQuery = $pdo->prepare(
            "SELECT u.full_name, u.email, u.status
             FROM users u
             INNER JOIN voters v ON v.user_id = u.id
             WHERE u.id = :user_id AND u.role = 'voter'
             FOR UPDATE"
        );
        $voterQuery->execute(['user_id' => $userId]);
        $voter = $voterQuery->fetch();
        if (!$voter || $voter['status'] !== 'pending') {
            $pdo->rollBack();
            set_flash('error', 'Only a pending voter account can be reviewed.');
            $redirectVoters();
        }

        $updateStatus = $pdo->prepare(
            "UPDATE users SET status = :status
             WHERE id = :user_id AND role = 'voter' AND status = 'pending'"
        );
        $updateStatus->execute([
            'status' => $newStatus,
            'user_id' => $userId,
        ]);
        $updateEmailStatus = $pdo->prepare(
            "UPDATE voters SET review_email_status = 'pending' WHERE user_id = :user_id"
        );
        $updateEmailStatus->execute(['user_id' => $userId]);
        $decisionLabel = $decision === 'approve' ? 'approved' : 'rejected';
        log_admin_action(
            $pdo,
            'voter.' . $decisionLabel,
            'voter',
            $userId,
            'Reviewed voter registration as ' . $decisionLabel . '.',
            ['status' => $voter['status']],
            $decision === 'reject'
                ? ['status' => $newStatus, 'rejection_reason' => $rejectionReason]
                : ['status' => $newStatus]
        );
        $pdo->commit();

        $emailSent = send_voter_review_email(
            $voter['email'],
            $voter['full_name'],
            $newStatus,
            $rejectionReason
        );
        $emailStatus = $emailSent ? 'sent' : 'failed';
        $saveEmailStatus = $pdo->prepare(
            'UPDATE voters SET review_email_status = :email_status WHERE user_id = :user_id'
        );
        $pdo->beginTransaction();
        $saveEmailStatus->execute([
            'email_status' => $emailStatus,
            'user_id' => $userId,
        ]);
        log_admin_action(
            $pdo,
            'voter.review_email_' . $emailStatus,
            'voter',
            $userId,
            'Review email ' . $emailStatus . '.',
            ['review_email_status' => 'pending'],
            ['review_email_status' => $emailStatus]
        );
        $pdo->commit();

        if ($emailSent) {
            set_flash('success', 'Voter ' . $decisionLabel . ' and notification email sent.');
        } else {
            set_flash(
                'error',
                'Voter ' . $decisionLabel . ', but the notification email failed. Check mail settings and retry it.'
            );
        }
        $redirectVoters();
    }

    if ($action === 'retry_email') {
        $userId = isset($_POST['user_id']) && is_string($_POST['user_id'])
            ? filter_var($_POST['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($userId === false) {
            set_flash('error', 'Invalid voter notification action.');
            $redirectVoters();
        }

        if ($action === 'resend_credentials') {
            $userId = isset($_POST['user_id']) && is_string($_POST['user_id'])
                ? filter_var($_POST['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            if ($userId === false) {
                set_flash('error', 'Invalid voter credential request.');
                $redirectVoters();
            }

            $pdo->beginTransaction();
            $voterQuery = $pdo->prepare(
                "SELECT full_name, email FROM users
                 WHERE id = :user_id AND role = 'voter' AND status = 'active'
                 FOR UPDATE"
            );
            $voterQuery->execute(['user_id' => $userId]);
            $voter = $voterQuery->fetch();
            if (!$voter) {
                $pdo->rollBack();
                set_flash('error', 'Only an eligible voter can receive a new password setup link.');
                $redirectVoters();
            }
            $passwordToken = issue_voter_password_token($pdo, (int) $userId);
            log_admin_action(
                $pdo,
                'voter.credentials_resent',
                'voter',
                (int) $userId,
                'Issued a one-time voter password setup link.',
                null,
                ['delivery' => 'email', 'expires_in_minutes' => 30]
            );
            $pdo->commit();

            $emailSent = send_voter_password_setup_email(
                $voter['email'],
                $voter['full_name'],
                $passwordToken
            );
            set_flash(
                $emailSent ? 'success' : 'error',
                $emailSent
                    ? 'A one-time password setup link was emailed. No password was included in the email.'
                    : 'The password setup email could not be sent. Check the mail settings and try again.'
            );
            $redirectVoters();
        }

        $voterQuery = $pdo->prepare(
            'SELECT u.full_name, u.email, u.status, v.review_email_status
             FROM users u
             INNER JOIN voters v ON v.user_id = u.id
             WHERE u.id = :user_id AND u.role = \'voter\''
        );
        $voterQuery->execute(['user_id' => $userId]);
        $voter = $voterQuery->fetch();
        if (
            !$voter
            || !in_array($voter['status'], ['active', 'rejected'], true)
            || !in_array($voter['review_email_status'], ['pending', 'failed'], true)
        ) {
            set_flash('error', 'There is no failed review email to retry for this voter.');
            $redirectVoters();
        }

        $emailSent = send_voter_review_email($voter['email'], $voter['full_name'], $voter['status']);
        $saveEmailStatus = $pdo->prepare(
            'UPDATE voters SET review_email_status = :email_status WHERE user_id = :user_id'
        );
        $pdo->beginTransaction();
        $saveEmailStatus->execute([
            'email_status' => $emailSent ? 'sent' : 'failed',
            'user_id' => $userId,
        ]);
        $emailStatus = $emailSent ? 'sent' : 'failed';
        log_admin_action(
            $pdo,
            'voter.review_email_retried',
            'voter',
            $userId,
            'Retried review email; delivery ' . $emailStatus . '.',
            ['review_email_status' => $voter['review_email_status']],
            ['review_email_status' => $emailStatus]
        );
        $pdo->commit();

        set_flash(
            $emailSent ? 'success' : 'error',
            $emailSent
                ? 'Review email sent.'
                : 'The review email could not be sent. Check the mail settings and try again.'
        );
        $redirectVoters();
    }

    if ($action === 'status') {
        $userId = isset($_POST['user_id']) && is_string($_POST['user_id'])
            ? filter_var($_POST['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $newStatus = isset($_POST['status']) && is_string($_POST['status'])
            ? $_POST['status']
            : '';
        if ($userId === false || !in_array($newStatus, ['active', 'suspended'], true)) {
            set_flash('error', 'Invalid voter account action.');
            $redirectVoters();
        }

        $voterQuery = $pdo->prepare(
            "SELECT status FROM users WHERE id = :user_id AND role = 'voter' LIMIT 1"
        );
        $voterQuery->execute(['user_id' => $userId]);
        $currentStatus = $voterQuery->fetchColumn();
        $validTransition = ($currentStatus === 'active' && $newStatus === 'suspended')
            || ($currentStatus === 'suspended' && $newStatus === 'active');
        if (!$validTransition) {
            set_flash('error', 'Only active or suspended voter accounts can be changed this way.');
            $redirectVoters();
        }

        $pdo->beginTransaction();
        $updateVoter = $pdo->prepare(
            "UPDATE users SET status = :status WHERE id = :user_id AND role = 'voter'"
        );
        $updateVoter->execute([
            'status' => $newStatus,
            'user_id' => $userId,
        ]);
        log_admin_action(
            $pdo,
            'voter.' . $newStatus,
            'voter',
            $userId,
            'Changed voter account status to ' . $newStatus . '.',
            ['status' => $currentStatus],
            ['status' => $newStatus]
        );
        $pdo->commit();
        set_flash('success', 'Voter account ' . $newStatus . '.');
        $redirectVoters();
    }

    set_flash('error', 'Unknown voter action.');
    $redirectVoters();
}

$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$search = substr($search, 0, 100);
$tab = isset($_GET['tab']) && is_string($_GET['tab'])
    && in_array($_GET['tab'], ['pending', 'approved', 'rejected', 'all'], true)
        ? $_GET['tab']
        : 'pending';
$votingStatus = isset($_GET['voting_status']) && is_string($_GET['voting_status'])
    && in_array($_GET['voting_status'], ['all', 'voted', 'not_voted'], true)
        ? $_GET['voting_status']
        : 'all';

$elections = $pdo->query(
    'SELECT id, title, status FROM elections ORDER BY
     CASE status WHEN \'open\' THEN 0 WHEN \'paused\' THEN 1 ELSE 2 END, id DESC'
)->fetchAll();
$requestedElectionId = isset($_GET['election_id']) && is_string($_GET['election_id'])
    ? filter_var($_GET['election_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$selectedElectionId = null;
if ($requestedElectionId !== false) {
    foreach ($elections as $election) {
        if ((int) $election['id'] === $requestedElectionId) {
            $selectedElectionId = $requestedElectionId;
            break;
        }
    }
}
if ($selectedElectionId === null && $elections !== []) {
    $selectedElectionId = (int) $elections[0]['id'];
}
if ($selectedElectionId === null) {
    $votingStatus = 'all';
}

$countQuery = $pdo->query(
    "SELECT u.status, COUNT(*) AS voter_count
     FROM users u
     INNER JOIN voters v ON v.user_id = u.id
     WHERE u.role = 'voter'
     GROUP BY u.status"
);
$statusCounts = ['pending' => 0, 'active' => 0, 'rejected' => 0, 'suspended' => 0];
foreach ($countQuery->fetchAll() as $countRow) {
    $statusCounts[$countRow['status']] = (int) $countRow['voter_count'];
}
$totalVoters = array_sum($statusCounts);

$voterSql = "
    SELECT u.id AS user_id, u.full_name, u.email, u.status, u.created_at,
           v.id AS voter_id, v.reg_number, v.phone, v.review_email_status,
           " . ($selectedElectionId === null
                ? 'NULL AS has_voted'
                : "EXISTS (
                    SELECT 1 FROM ballot_submissions bs
                    WHERE bs.election_id = :participation_election
                      AND bs.voter_id = v.id
                ) AS has_voted") . "
    FROM voters v
    INNER JOIN users u ON u.id = v.user_id
    WHERE u.role = 'voter'
";
$voterParameters = [];
if ($selectedElectionId !== null) {
    $voterParameters['participation_election'] = $selectedElectionId;
}
if ($tab === 'pending') {
    $voterSql .= " AND u.status = 'pending'";
} elseif ($tab === 'approved') {
    $voterSql .= " AND u.status = 'active'";
} elseif ($tab === 'rejected') {
    $voterSql .= " AND u.status = 'rejected'";
}
if ($search !== '') {
    $voterSql .= ' AND (u.full_name LIKE :name_search OR v.reg_number LIKE :number_search OR u.email LIKE :email_search)';
    $voterParameters += [
        'name_search' => '%' . $search . '%',
        'number_search' => '%' . $search . '%',
        'email_search' => '%' . $search . '%',
    ];
}
if ($tab === 'approved' && $selectedElectionId !== null && $votingStatus !== 'all') {
    $voterSql .= $votingStatus === 'voted'
        ? ' AND EXISTS (SELECT 1 FROM ballot_submissions bs_filter WHERE bs_filter.election_id = :filter_election AND bs_filter.voter_id = v.id)'
        : ' AND NOT EXISTS (SELECT 1 FROM ballot_submissions bs_filter WHERE bs_filter.election_id = :filter_election AND bs_filter.voter_id = v.id)';
    $voterParameters['filter_election'] = $selectedElectionId;
}
$voterSql .= " ORDER BY u.created_at DESC, u.full_name";
$voterQuery = $pdo->prepare($voterSql);
$voterQuery->execute($voterParameters);
$voters = $voterQuery->fetchAll();

$voterReturnFields = static function () use (
    $tab,
    $search,
    $selectedElectionId,
    $votingStatus
): string {
    $fields = [
        'tab' => $tab,
        'q' => $search,
        'election_id' => $selectedElectionId === null ? '' : (string) $selectedElectionId,
        'voting_status' => $votingStatus,
    ];
    $html = '';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . sanitize($name) . '" value="'
            . sanitize($value) . '">' . PHP_EOL;
    }
    return $html;
};

$pageTitle = 'Manage voters';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section voter-workspace">
    <div class="container">
        <header class="dashboard__header voter-workspace__header">
            <div>
                <p class="results-eyebrow">Electorate administration</p>
                <h1>Manage voters</h1>
                <p>Review registrations, manage eligibility, and track participation without exposing ballot choices.</p>
            </div>
            <div class="voter-toolbar">
                <button class="btn btn--outline" type="button" data-open-dialog="voter-import-dialog">Import CSV</button>
                <button class="btn btn--primary" type="button" data-open-dialog="voter-add-dialog">+ Add Voter Manually</button>
            </div>
        </header>

        <nav class="voter-tabs" aria-label="Voter status">
            <?php
            $tabs = [
                'pending' => ['Pending Approval', $statusCounts['pending']],
                'approved' => ['Approved / Eligible', $statusCounts['active']],
                'rejected' => ['Rejected', $statusCounts['rejected']],
                'all' => ['All Voters', $totalVoters],
            ];
            foreach ($tabs as $tabKey => [$tabLabel, $tabCount]):
                $tabUrl = BASE_URL . '/admin/manage_voters.php?' . http_build_query([
                    'tab' => $tabKey,
                    'election_id' => $selectedElectionId,
                ]);
            ?>
                <a class="voter-tab<?= $tab === $tabKey ? ' is-active' : '' ?>"
                    href="<?= sanitize($tabUrl) ?>"
                    <?= $tab === $tabKey ? 'aria-current="page"' : '' ?>>
                    <span><?= sanitize($tabLabel) ?></span>
                    <span class="voter-tab__count<?= $tabKey === 'pending' && $tabCount > 0 ? ' voter-tab__count--alert' : '' ?>"
                        <?= $tabKey === 'pending' && $tabCount > 0 ? 'aria-label="' . (int) $tabCount . ' pending registrations"' : '' ?>>
                        <?= number_format($tabCount) ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($tab === 'pending'): ?>
            <section class="voter-panel" aria-labelledby="pending-voters-title">
                <header class="voter-panel__header">
                    <div>
                        <h2 id="pending-voters-title">Pending approval</h2>
                        <p>Confirm the submitted identity details before activating voter eligibility.</p>
                    </div>
                    <span class="badge badge--pending"><?= number_format($statusCounts['pending']) ?> awaiting review</span>
                </header>
                <form class="form voter-search" method="get" action="<?= BASE_URL ?>/admin/manage_voters.php">
                    <input type="hidden" name="tab" value="pending">
                    <?php if ($selectedElectionId !== null): ?>
                        <input type="hidden" name="election_id" value="<?= $selectedElectionId ?>">
                    <?php endif; ?>
                    <div class="form__group">
                        <label for="pending-search">Search name, voter ID, or email</label>
                        <input id="pending-search" name="q" type="search" maxlength="100" value="<?= sanitize($search) ?>">
                    </div>
                    <button class="btn btn--primary" type="submit">Search queue</button>
                </form>
                <div class="table-wrap">
                    <table class="table voter-table">
                        <thead>
                            <tr>
                                <th scope="col">Applicant</th>
                                <th scope="col">Voter ID / National ID</th>
                                <th scope="col">Email address</th>
                                <th scope="col">Registered</th>
                                <th scope="col">Review</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($voters === []): ?>
                                <tr><td colspan="5"><div class="empty-state" role="status">No registrations are waiting for review.</div></td></tr>
                            <?php else: ?>
                                <?php foreach ($voters as $voter): ?>
                                    <tr>
                                        <td><strong><?= sanitize($voter['full_name']) ?></strong></td>
                                        <td><?= sanitize($voter['reg_number']) ?></td>
                                        <td><?= sanitize($voter['email']) ?></td>
                                        <td><time datetime="<?= sanitize(date(DATE_ATOM, strtotime($voter['created_at']))) ?>"><?= sanitize($voter['created_at']) ?></time></td>
                                        <td>
                                            <div class="admin-actions voter-review-actions">
                                                <form method="post" action="<?= BASE_URL ?>/admin/manage_voters.php">
                                                    <?= csrf_field() ?><?= $voterReturnFields() ?>
                                                    <input type="hidden" name="action" value="review">
                                                    <input type="hidden" name="user_id" value="<?= (int) $voter['user_id'] ?>">
                                                    <button class="btn btn--approve" type="submit" name="decision" value="approve">
                                                        <span aria-hidden="true">✓</span> Approve
                                                    </button>
                                                </form>
                                                <button class="btn btn--reject" type="button"
                                                    data-reject-voter="<?= (int) $voter['user_id'] ?>"
                                                    data-reject-name="<?= sanitize($voter['full_name']) ?>">
                                                    <span aria-hidden="true">×</span> Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php else: ?>
            <section class="voter-panel" aria-labelledby="voter-list-title">
                <header class="voter-panel__header">
                    <div>
                        <h2 id="voter-list-title"><?= sanitize($tabs[$tab][0]) ?></h2>
                        <p>Participation status shows ballot submission only; voter selections are never displayed here.</p>
                    </div>
                </header>
                <form class="form voter-filters" method="get" action="<?= BASE_URL ?>/admin/manage_voters.php">
                    <input type="hidden" name="tab" value="<?= sanitize($tab) ?>">
                    <div class="form__group voter-filters__search">
                        <label for="voter-search">Search voters</label>
                        <input id="voter-search" name="q" type="search" maxlength="100"
                            placeholder="Name, ID number, or email" value="<?= sanitize($search) ?>">
                    </div>
                    <div class="form__group">
                        <label for="participation-election">Election participation</label>
                        <select id="participation-election" name="election_id" <?= $elections === [] ? 'disabled' : '' ?>>
                            <?php foreach ($elections as $election): ?>
                                <option value="<?= (int) $election['id'] ?>"
                                    <?= $selectedElectionId === (int) $election['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($election['title']) ?> — <?= sanitize(ucfirst($election['status'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($tab === 'approved'): ?>
                        <div class="form__group">
                            <label for="voting-status">Voting status</label>
                            <select id="voting-status" name="voting_status" <?= $selectedElectionId === null ? 'disabled' : '' ?>>
                                <option value="all" <?= $votingStatus === 'all' ? 'selected' : '' ?>>All participation</option>
                                <option value="voted" <?= $votingStatus === 'voted' ? 'selected' : '' ?>>Voted</option>
                                <option value="not_voted" <?= $votingStatus === 'not_voted' ? 'selected' : '' ?>>Not voted</option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <button class="btn btn--primary" type="submit">Apply filters</button>
                    <?php if ($search !== '' || $votingStatus !== 'all'): ?>
                        <a class="voter-clear-filter" href="<?= BASE_URL ?>/admin/manage_voters.php?<?= http_build_query(['tab' => $tab, 'election_id' => $selectedElectionId]) ?>">Clear filters</a>
                    <?php endif; ?>
                </form>
                <?php if ($selectedElectionId === null): ?>
                    <p class="alert alert--info" role="status">Create an election to see participation status.</p>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="table voter-table">
                        <thead>
                            <tr>
                                <th scope="col">Voter name</th>
                                <th scope="col">ID number</th>
                                <th scope="col">Email</th>
                                <th scope="col">Approval status</th>
                                <th scope="col">Participation</th>
                                <th scope="col">Registered</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($voters === []): ?>
                                <tr><td colspan="7"><div class="empty-state" role="status">No voters match these filters.</div></td></tr>
                            <?php else: ?>
                                <?php foreach ($voters as $voter): ?>
                                    <?php
                                    $votedLabel = $voter['has_voted'] === null
                                        ? 'Not available'
                                        : ((int) $voter['has_voted'] === 1 ? 'Voted' : 'Not voted');
                                    ?>
                                    <tr>
                                        <td><strong><?= sanitize($voter['full_name']) ?></strong></td>
                                        <td><?= sanitize($voter['reg_number']) ?></td>
                                        <td><?= sanitize($voter['email']) ?></td>
                                        <td><span class="badge badge--<?= sanitize($voter['status']) ?>"><?= sanitize(ucfirst($voter['status'])) ?></span></td>
                                        <td><span class="voter-participation<?= (int) $voter['has_voted'] === 1 ? ' voter-participation--voted' : '' ?>"><?= sanitize($votedLabel) ?></span></td>
                                        <td><?= sanitize($voter['created_at']) ?></td>
                                        <td>
                                            <div class="admin-actions voter-row-actions">
                                                <?php if ($voter['status'] === 'pending'): ?>
                                                    <form method="post" action="<?= BASE_URL ?>/admin/manage_voters.php">
                                                        <?= csrf_field() ?><?= $voterReturnFields() ?>
                                                        <input type="hidden" name="action" value="review">
                                                        <input type="hidden" name="user_id" value="<?= (int) $voter['user_id'] ?>">
                                                        <button class="btn btn--approve" type="submit" name="decision" value="approve">Approve</button>
                                                    </form>
                                                    <button class="btn btn--reject" type="button"
                                                        data-reject-voter="<?= (int) $voter['user_id'] ?>"
                                                        data-reject-name="<?= sanitize($voter['full_name']) ?>">Reject</button>
                                                <?php else: ?>
                                                    <button class="btn btn--outline" type="button"
                                                        data-edit-voter
                                                        data-user-id="<?= (int) $voter['user_id'] ?>"
                                                        data-full-name="<?= sanitize($voter['full_name']) ?>"
                                                        data-email="<?= sanitize($voter['email']) ?>"
                                                        data-reg-number="<?= sanitize($voter['reg_number']) ?>"
                                                        data-phone="<?= sanitize((string) ($voter['phone'] ?? '')) ?>">
                                                        Edit details
                                                    </button>
                                                    <?php if ($voter['status'] === 'active'): ?>
                                                        <form method="post" action="<?= BASE_URL ?>/admin/manage_voters.php">
                                                            <?= csrf_field() ?><?= $voterReturnFields() ?>
                                                            <input type="hidden" name="action" value="resend_credentials">
                                                            <input type="hidden" name="user_id" value="<?= (int) $voter['user_id'] ?>">
                                                            <button class="btn btn--outline" type="submit">Resend voting credentials</button>
                                                        </form>
                                                        <form method="post" action="<?= BASE_URL ?>/admin/manage_voters.php"
                                                            data-confirm-submit="Revoke this voter’s access? Their participation record will be retained.">
                                                            <?= csrf_field() ?><?= $voterReturnFields() ?>
                                                            <input type="hidden" name="action" value="status">
                                                            <input type="hidden" name="user_id" value="<?= (int) $voter['user_id'] ?>">
                                                            <input type="hidden" name="status" value="suspended">
                                                            <button class="btn btn--black" type="submit">Revoke access</button>
                                                        </form>
                                                    <?php elseif ($voter['status'] === 'suspended'): ?>
                                                        <form method="post" action="<?= BASE_URL ?>/admin/manage_voters.php">
                                                            <?= csrf_field() ?><?= $voterReturnFields() ?>
                                                            <input type="hidden" name="action" value="status">
                                                            <input type="hidden" name="user_id" value="<?= (int) $voter['user_id'] ?>">
                                                            <input type="hidden" name="status" value="active">
                                                            <button class="btn btn--primary" type="submit">Restore access</button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if (
                                                    in_array($voter['status'], ['active', 'rejected'], true)
                                                    && in_array($voter['review_email_status'], ['pending', 'failed'], true)
                                                ): ?>
                                                    <form method="post" action="<?= BASE_URL ?>/admin/manage_voters.php">
                                                        <?= csrf_field() ?><?= $voterReturnFields() ?>
                                                        <input type="hidden" name="action" value="retry_email">
                                                        <input type="hidden" name="user_id" value="<?= (int) $voter['user_id'] ?>">
                                                        <button class="btn btn--outline" type="submit">Retry review email</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</section>

<dialog class="voter-dialog" id="voter-add-dialog" aria-labelledby="voter-add-title">
    <form class="form voter-dialog__form" method="post" action="<?= BASE_URL ?>/admin/manage_voters.php">
        <header class="voter-dialog__header">
            <h2 id="voter-add-title">Add voter manually</h2>
            <button class="voter-dialog__close" type="button" data-close-dialog aria-label="Close">×</button>
        </header>
        <?= csrf_field() ?><?= $voterReturnFields() ?>
        <input type="hidden" name="action" value="add">
        <div class="form__group"><label for="new_full_name">Full name</label><input id="new_full_name" name="full_name" type="text" maxlength="150" required></div>
        <div class="form__group"><label for="new_email">Email address</label><input id="new_email" name="email" type="email" maxlength="254" required></div>
        <div class="form__group"><label for="new_reg_number">Voter ID / National ID</label><input id="new_reg_number" name="reg_number" type="text" maxlength="100" required></div>
        <div class="form__group"><label for="new_phone">Phone (optional)</label><input id="new_phone" name="phone" type="tel" maxlength="32"></div>
        <div class="form__group"><label for="new_password">Initial password</label><input id="new_password" name="password" type="password" minlength="8" maxlength="72" required autocomplete="new-password"></div>
        <p>New accounts remain pending until approved. Share the initial password securely; passwords are never emailed.</p>
        <button class="btn btn--primary" type="submit">Add pending voter</button>
    </form>
</dialog>

<dialog class="voter-dialog" id="voter-import-dialog" aria-labelledby="voter-import-title">
    <form class="form voter-dialog__form" method="post" enctype="multipart/form-data" action="<?= BASE_URL ?>/admin/manage_voters.php">
        <header class="voter-dialog__header">
            <h2 id="voter-import-title">Import voters from CSV</h2>
            <button class="voter-dialog__close" type="button" data-close-dialog aria-label="Close">×</button>
        </header>
        <?= csrf_field() ?><?= $voterReturnFields() ?>
        <input type="hidden" name="action" value="import_csv">
        <p>Required headers: <code>full_name,email,reg_number</code>. Optional: <code>phone</code>. Imports create pending accounts and email a one-time password setup link.</p>
        <div class="form__group">
            <label for="voter_csv">CSV file (up to 2 MB / 500 rows)</label>
            <input id="voter_csv" name="voter_csv" type="file" accept=".csv,text/csv" required>
        </div>
        <button class="btn btn--primary" type="submit">Import CSV</button>
    </form>
</dialog>

<dialog class="voter-dialog voter-dialog--drawer" id="voter-edit-dialog" aria-labelledby="voter-edit-title">
    <form class="form voter-dialog__form" method="post" action="<?= BASE_URL ?>/admin/manage_voters.php" data-edit-voter-form>
        <header class="voter-dialog__header">
            <h2 id="voter-edit-title">Edit voter details</h2>
            <button class="voter-dialog__close" type="button" data-close-dialog aria-label="Close">×</button>
        </header>
        <?= csrf_field() ?><?= $voterReturnFields() ?>
        <input type="hidden" name="action" value="update_details">
        <input type="hidden" name="user_id" value="">
        <div class="form__group"><label for="edit_full_name">Full name</label><input id="edit_full_name" name="full_name" type="text" maxlength="150" required></div>
        <div class="form__group"><label for="edit_email">Email address</label><input id="edit_email" name="email" type="email" maxlength="254" required></div>
        <div class="form__group"><label for="edit_reg_number">Voter ID / National ID</label><input id="edit_reg_number" name="reg_number" type="text" maxlength="100" required></div>
        <div class="form__group"><label for="edit_phone">Phone (optional)</label><input id="edit_phone" name="phone" type="tel" maxlength="32"></div>
        <button class="btn btn--primary" type="submit">Save voter details</button>
    </form>
</dialog>

<dialog class="voter-dialog voter-dialog--compact" id="voter-reject-dialog" aria-labelledby="voter-reject-title">
    <form class="form voter-dialog__form" method="post" action="<?= BASE_URL ?>/admin/manage_voters.php" data-reject-form>
        <header class="voter-dialog__header">
            <h2 id="voter-reject-title">Reject voter registration</h2>
            <button class="voter-dialog__close" type="button" data-close-dialog aria-label="Close">×</button>
        </header>
        <?= csrf_field() ?><?= $voterReturnFields() ?>
        <input type="hidden" name="action" value="review">
        <input type="hidden" name="decision" value="reject">
        <input type="hidden" name="user_id" value="">
        <p>Rejecting <strong data-reject-applicant></strong> will remove their voting eligibility and send the reason by email.</p>
        <div class="form__group">
            <label for="rejection_reason">Reason (optional)</label>
            <textarea id="rejection_reason" name="rejection_reason" maxlength="250">invalid details</textarea>
        </div>
        <div class="voter-toolbar">
            <button class="btn btn--outline" type="button" data-close-dialog>Cancel</button>
            <button class="btn btn--reject" type="submit">Confirm rejection</button>
        </div>
    </form>
</dialog>

<script src="<?= BASE_URL ?>/assets/js/manage_voters.js" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
