<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_time.php';
require_once __DIR__ . '/../includes/election_scheduler.php';
require_once __DIR__ . '/../includes/rich_text.php';

function election_manager_positive_int($value)
{
    if (!is_string($value) && !is_int($value)) {
        return false;
    }

    $number = filter_var((string) $value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    return $number === false ? false : (int) $number;
}

function election_manager_store_logo($upload): string|false|null
{
    if (!is_array($upload)) {
        return null;
    }

    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException('The PHP fileinfo extension is required for election logo uploads.');
    }

    $temporaryPath = $upload['tmp_name'] ?? '';
    $size = $upload['size'] ?? 0;
    if (
        $error !== UPLOAD_ERR_OK
        || !is_string($temporaryPath)
        || !is_uploaded_file($temporaryPath)
        || !is_int($size)
        || $size < 1
        || $size > 2 * 1024 * 1024
    ) {
        return false;
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $actualSize = filesize($temporaryPath);
    if (
        !isset($extensions[$mimeType])
        || $actualSize === false
        || $actualSize < 1
        || $actualSize > 2 * 1024 * 1024
    ) {
        return false;
    }

    $uploadDirectory = __DIR__ . '/../assets/img/elections';
    if (
        !is_dir($uploadDirectory)
        && !mkdir($uploadDirectory, 0755, true)
        && !is_dir($uploadDirectory)
    ) {
        throw new RuntimeException('Unable to create the election logo directory.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($temporaryPath, $uploadDirectory . DIRECTORY_SEPARATOR . $fileName)) {
        throw new RuntimeException('Unable to save the election logo.');
    }

    return 'assets/img/elections/' . $fileName;
}

function election_manager_delete_logo(?string $path): void
{
    if (
        $path === null
        || preg_match('~\Aassets/img/elections/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $path) !== 1
    ) {
        return;
    }

    $fullPath = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($fullPath) && !unlink($fullPath)) {
        throw new RuntimeException('Unable to remove a replaced election logo.');
    }
}

function election_manager_delete_candidate_photo(?string $path): void
{
    if (
        $path === null
        || preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $path) !== 1
    ) {
        return;
    }

    $fullPath = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($fullPath) && !unlink($fullPath)) {
        throw new RuntimeException('Unable to remove a candidate photo after deleting its position.');
    }
}

activate_due_auto_elections($pdo);

if (is_post()) {
    verify_csrf();

    $action = isset($_POST['action']) && is_string($_POST['action'])
        ? $_POST['action']
        : '';

    if ($action === 'save_election') {
        $rawId = $_POST['election_id'] ?? '';
        $electionId = !is_string($rawId)
            ? false
            : ($rawId === '' ? null : election_manager_positive_int($rawId));
        $intent = isset($_POST['intent']) && is_string($_POST['intent'])
            ? $_POST['intent']
            : '';
        $title = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
        $rawDescription = isset($_POST['description']) && is_string($_POST['description'])
            ? $_POST['description']
            : '';
        $timezone = isset($_POST['timezone']) && is_string($_POST['timezone'])
            ? $_POST['timezone']
            : '';
        $startDate = isset($_POST['start_datetime']) && is_string($_POST['start_datetime'])
            ? parse_election_local_time($_POST['start_datetime'], $timezone)
            : null;
        $endDate = isset($_POST['end_datetime']) && is_string($_POST['end_datetime'])
            ? parse_election_local_time($_POST['end_datetime'], $timezone)
            : null;
        $nominationDeadlineInputValid = !array_key_exists('nomination_deadline', $_POST)
            || is_string($_POST['nomination_deadline']);
        $rawNominationDeadline = isset($_POST['nomination_deadline']) && is_string($_POST['nomination_deadline'])
            ? trim($_POST['nomination_deadline'])
            : '';
        $nominationDeadline = $rawNominationDeadline === ''
            ? null
            : parse_election_local_time($rawNominationDeadline, $timezone);
        $votingSystem = isset($_POST['voting_system']) && is_string($_POST['voting_system'])
            ? $_POST['voting_system']
            : '';
        $verificationMethods = $_POST['verification_methods'] ?? [];
        if (!is_array($verificationMethods)) {
            $verificationMethods = [];
        }
        $allowAbstentions = isset($_POST['allow_abstentions'])
            && is_string($_POST['allow_abstentions'])
            && $_POST['allow_abstentions'] === '1';
        $autoLaunch = isset($_POST['auto_launch'])
            && is_string($_POST['auto_launch'])
            && $_POST['auto_launch'] === '1';
        $removeLogo = isset($_POST['remove_logo'])
            && is_string($_POST['remove_logo'])
            && $_POST['remove_logo'] === '1';
        $uploadedLogo = null;
        $allowedMethods = ['email_token', 'voter_pin'];
        $validMethods = array_reduce(
            $verificationMethods,
            static function (bool $valid, $method) use ($allowedMethods): bool {
                return $valid && is_string($method) && in_array($method, $allowedMethods, true);
            },
            true
        );
        $description = sanitize_rich_text(trim($rawDescription));

        if (
            ($rawId !== '' && $electionId === false)
            || !in_array($intent, ['draft', 'add_candidates'], true)
            || $title === ''
            || strlen($title) > 200
            || strlen($rawDescription) > 100000
            || strlen($description) > 100000
            || $startDate === null
            || $endDate === null
            || $endDate <= $startDate
            || !$nominationDeadlineInputValid
            || ($rawNominationDeadline !== '' && (
                $nominationDeadline === null
                || $nominationDeadline > $startDate
            ))
            || !is_valid_election_timezone($timezone)
            || !in_array($votingSystem, ['fptp', 'multiple_selection', 'ranked_choice'], true)
            || !$validMethods
        ) {
            set_flash('error', 'Check the title, instructions, schedule, nomination deadline, timezone, voting rules, verification options, and logo file.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $uploadedLogo = election_manager_store_logo($_FILES['organization_logo'] ?? null);
        if ($uploadedLogo === false) {
            set_flash('error', 'Choose a valid JPG, PNG, or WebP logo no larger than 2 MB.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $description = $description === '' ? null : $description;
        $startDate = $startDate->format('Y-m-d H:i:s');
        $endDate = $endDate->format('Y-m-d H:i:s');
        $nominationDeadline = $nominationDeadline?->format('Y-m-d H:i:s');
        $verificationMethods = array_values(array_unique($verificationMethods));
        $methodsValue = implode(',', $verificationMethods);
        $existing = false;
        $oldLogoPath = null;
        $newLogoPath = $uploadedLogo;

        try {
            $pdo->beginTransaction();
            if ($electionId !== null) {
                $existingQuery = $pdo->prepare(
                    'SELECT title, description, status, start_datetime, end_datetime, timezone,
                            nomination_deadline,
                            auto_launch, voting_system, verification_methods, allow_abstentions,
                            organization_logo_path
                     FROM elections WHERE id = :election_id FOR UPDATE'
                );
                $existingQuery->execute(['election_id' => $electionId]);
                $existing = $existingQuery->fetch();
                $canEditScheduled = $existing
                    && $existing['status'] === 'open'
                    && election_is_upcoming($existing);

                if (!$existing || ($existing['status'] !== 'draft' && !$canEditScheduled)) {
                    $pdo->rollBack();
                    election_manager_delete_logo($newLogoPath);
                    set_flash('error', 'Only draft or not-yet-started scheduled elections can be edited.');
                    redirect(BASE_URL . '/admin/manage_elections.php');
                }

                $oldLogoPath = is_string($existing['organization_logo_path'])
                    ? $existing['organization_logo_path']
                    : null;
                $logoPath = $newLogoPath ?? ($removeLogo ? null : $oldLogoPath);
                $saveElection = $pdo->prepare(
                    'UPDATE elections
                     SET title = :title, description = :description,
                         start_datetime = :start_datetime, end_datetime = :end_datetime,
                         timezone = :timezone, nomination_deadline = :nomination_deadline,
                         auto_launch = :auto_launch,
                         voting_system = :voting_system, verification_methods = :verification_methods,
                         allow_abstentions = :allow_abstentions,
                         organization_logo_path = :organization_logo_path,
                         status = IF(:save_as_draft = 1, \'draft\', status)
                     WHERE id = :election_id'
                );
                $saveElection->execute([
                    'title' => $title,
                    'description' => $description,
                    'start_datetime' => $startDate,
                    'end_datetime' => $endDate,
                    'timezone' => $timezone,
                    'nomination_deadline' => $nominationDeadline,
                    'auto_launch' => $autoLaunch ? 1 : 0,
                    'voting_system' => $votingSystem,
                    'verification_methods' => $methodsValue,
                    'allow_abstentions' => $allowAbstentions ? 1 : 0,
                    'organization_logo_path' => $logoPath,
                    'save_as_draft' => $intent === 'draft' ? 1 : 0,
                    'election_id' => $electionId,
                ]);
                $beforeValues = [
                    'title' => $existing['title'],
                    'description' => $existing['description'],
                    'status' => $existing['status'],
                    'start_datetime' => $existing['start_datetime'],
                    'end_datetime' => $existing['end_datetime'],
                    'timezone' => $existing['timezone'],
                    'nomination_deadline' => $existing['nomination_deadline'],
                    'auto_launch' => (int) $existing['auto_launch'],
                    'voting_system' => $existing['voting_system'],
                    'verification_methods' => $existing['verification_methods'],
                    'allow_abstentions' => (int) $existing['allow_abstentions'],
                    'organization_logo_path' => $existing['organization_logo_path'],
                ];
                $afterValues = [
                    'title' => $title,
                    'description' => $description,
                    'status' => $intent === 'draft' ? 'draft' : $existing['status'],
                    'start_datetime' => $startDate,
                    'end_datetime' => $endDate,
                    'timezone' => $timezone,
                    'nomination_deadline' => $nominationDeadline,
                    'auto_launch' => $autoLaunch ? 1 : 0,
                    'voting_system' => $votingSystem,
                    'verification_methods' => $methodsValue,
                    'allow_abstentions' => $allowAbstentions ? 1 : 0,
                    'organization_logo_path' => $logoPath,
                ];
                log_admin_action(
                    $pdo,
                    'election.updated',
                    'election',
                    $electionId,
                    'Updated election setup details.',
                    $beforeValues,
                    $afterValues
                );
            } else {
                $status = 'draft';
                $logoPath = $newLogoPath;
                $saveElection = $pdo->prepare(
                    'INSERT INTO elections
                        (title, description, start_datetime, end_datetime, timezone, auto_launch,
                         nomination_deadline,
                         voting_system, verification_methods, allow_abstentions,
                         organization_logo_path, status, created_by)
                     VALUES
                        (:title, :description, :start_datetime, :end_datetime, :timezone, :auto_launch,
                         :nomination_deadline,
                         :voting_system, :verification_methods, :allow_abstentions,
                         :organization_logo_path, :status, :created_by)'
                );
                $saveElection->execute([
                    'title' => $title,
                    'description' => $description,
                    'start_datetime' => $startDate,
                    'end_datetime' => $endDate,
                    'timezone' => $timezone,
                    'nomination_deadline' => $nominationDeadline,
                    'auto_launch' => $autoLaunch ? 1 : 0,
                    'voting_system' => $votingSystem,
                    'verification_methods' => $methodsValue,
                    'allow_abstentions' => $allowAbstentions ? 1 : 0,
                    'organization_logo_path' => $logoPath,
                    'status' => $status,
                    'created_by' => (int) current_user()['id'],
                ]);
                $electionId = (int) $pdo->lastInsertId();
                log_admin_action(
                    $pdo,
                    'election.created',
                    'election',
                    $electionId,
                    'Created an election.',
                    null,
                    [
                        'title' => $title,
                        'description' => $description,
                        'status' => $status,
                        'start_datetime' => $startDate,
                        'end_datetime' => $endDate,
                        'timezone' => $timezone,
                        'nomination_deadline' => $nominationDeadline,
                        'auto_launch' => $autoLaunch ? 1 : 0,
                        'voting_system' => $votingSystem,
                        'verification_methods' => $methodsValue,
                        'allow_abstentions' => $allowAbstentions ? 1 : 0,
                        'organization_logo_path' => $logoPath,
                    ]
                );
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            election_manager_delete_logo($newLogoPath);
            throw $exception;
        }

        if ($newLogoPath !== null && $oldLogoPath !== $newLogoPath) {
            election_manager_delete_logo($oldLogoPath);
        } elseif ($removeLogo) {
            election_manager_delete_logo($oldLogoPath);
        }

        if ($intent === 'add_candidates') {
            set_flash('success', 'Election saved. Continue to candidate management.');
            redirect(BASE_URL . '/admin/manage_candidates.php?election_id=' . (int) $electionId);
        }

        set_flash('success', 'Election saved as a draft.');
        redirect(BASE_URL . '/admin/manage_elections.php?edit=' . (int) $electionId);
    }

    if ($action === 'delete_election') {
        $electionId = election_manager_positive_int($_POST['election_id'] ?? null);
        if ($electionId === false) {
            set_flash('error', 'Invalid election.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $electionQuery = $pdo->prepare(
            'SELECT title, status, organization_logo_path
             FROM elections WHERE id = :election_id'
        );
        $electionQuery->execute(['election_id' => $electionId]);
        $election = $electionQuery->fetch();

        if (!$election) {
            set_flash('error', 'Election not found.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }
        if (in_array($election['status'], ['open', 'paused'], true)) {
            set_flash('error', 'Close an open or paused election before deleting it.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $voteCountQuery = $pdo->prepare(
            'SELECT COUNT(*) FROM votes WHERE election_id = :election_id'
        );
        $voteCountQuery->execute(['election_id' => $electionId]);
        $submissionCountQuery = $pdo->prepare(
            'SELECT COUNT(*) FROM ballot_submissions WHERE election_id = :election_id'
        );
        $submissionCountQuery->execute(['election_id' => $electionId]);
        if (
            (int) $voteCountQuery->fetchColumn() > 0
            || (int) $submissionCountQuery->fetchColumn() > 0
        ) {
            set_flash('error', 'An election with completed ballot submissions cannot be deleted.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $pdo->beginTransaction();
        $deleteElection = $pdo->prepare('DELETE FROM elections WHERE id = :election_id');
        $deleteElection->execute(['election_id' => $electionId]);
        log_admin_action(
            $pdo,
            'election.deleted',
            'election',
            $electionId,
            'Deleted an election.',
            ['title' => $election['title'], 'status' => $election['status']],
            null
        );
        $pdo->commit();
        election_manager_delete_logo($election['organization_logo_path']);
        set_flash('success', 'Election deleted.');
        redirect(BASE_URL . '/admin/manage_elections.php');
    }

    if (in_array($action, ['open_election', 'resume_election', 'close_election'], true)) {
        $electionId = election_manager_positive_int($_POST['election_id'] ?? null);
        if ($electionId === false) {
            set_flash('error', 'Invalid election.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $electionQuery = $pdo->prepare(
            'SELECT status, start_datetime, end_datetime, timezone
             FROM elections WHERE id = :election_id'
        );
        $electionQuery->execute(['election_id' => $electionId]);
        $election = $electionQuery->fetch();

        if (!$election) {
            set_flash('error', 'Election not found.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        if ($action === 'open_election') {
            if ($election['status'] !== 'draft') {
                set_flash('error', 'Only draft elections can be opened.');
                redirect(BASE_URL . '/admin/manage_elections.php');
            }

            $positionCountQuery = $pdo->prepare(
                'SELECT COUNT(*) FROM positions WHERE election_id = :election_id'
            );
            $positionCountQuery->execute(['election_id' => $electionId]);
            if ((int) $positionCountQuery->fetchColumn() === 0) {
                set_flash('error', 'Add at least one position before opening the election.');
                redirect(BASE_URL . '/admin/manage_elections.php');
            }

            $newStatus = 'open';
        } elseif ($action === 'resume_election') {
            if ($election['status'] !== 'paused' || !election_has_not_closed($election)) {
                set_flash('error', 'Only a paused election before its closing time can be resumed.');
                redirect(BASE_URL . '/admin/manage_elections.php');
            }
            $newStatus = 'open';
        } else {
            if (!in_array($election['status'], ['open', 'paused'], true)) {
                set_flash('error', 'Only open or paused elections can be closed.');
                redirect(BASE_URL . '/admin/manage_elections.php');
            }
            $newStatus = 'closed';
        }

        $pdo->beginTransaction();
        if ($newStatus === 'closed') {
            $eligibleVoterQuery = $pdo->prepare(
                "SELECT COUNT(*) FROM users WHERE role = 'voter' AND status = 'active'"
            );
            $eligibleVoterQuery->execute();
            $eligibleVotersAtClose = (int) $eligibleVoterQuery->fetchColumn();
            $changeStatus = $pdo->prepare(
                'UPDATE elections
                 SET status = :status, closed_at = UTC_TIMESTAMP(),
                     eligible_voter_count = :eligible_voter_count
                 WHERE id = :election_id'
            );
            $changeStatus->execute([
                'status' => $newStatus,
                'eligible_voter_count' => $eligibleVotersAtClose,
                'election_id' => $electionId,
            ]);
        } else {
            $changeStatus = $pdo->prepare(
                'UPDATE elections SET status = :status WHERE id = :election_id'
            );
            $changeStatus->execute([
                'status' => $newStatus,
                'election_id' => $electionId,
            ]);
        }
        $auditAfterStatus = ['status' => $newStatus];
        if ($newStatus === 'closed') {
            $auditAfterStatus['eligible_voter_count'] = $eligibleVotersAtClose;
        }
        log_admin_action(
            $pdo,
            'election.' . $newStatus,
            'election',
            $electionId,
            'Changed election status to ' . $newStatus . '.',
            ['status' => $election['status']],
            $auditAfterStatus
        );
        $pdo->commit();
        set_flash('success', 'Election ' . $newStatus . '.');
        redirect(BASE_URL . '/admin/manage_elections.php');
    }

    if ($action === 'save_position') {
        $rawPositionId = $_POST['position_id'] ?? '';
        $positionId = !is_string($rawPositionId)
            ? false
            : ($rawPositionId === '' ? null : election_manager_positive_int($rawPositionId));
        $electionId = election_manager_positive_int($_POST['election_id'] ?? null);
        $title = isset($_POST['position_title']) && is_string($_POST['position_title'])
            ? trim($_POST['position_title'])
            : '';
        $maxVotes = isset($_POST['max_votes']) && is_string($_POST['max_votes'])
            ? filter_var($_POST['max_votes'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 4294967295],
            ])
            : false;

        if (
            $positionId === false
            || $electionId === false
            || $title === ''
            || strlen($title) > 150
            || $maxVotes === false
        ) {
            set_flash('error', 'Enter a valid position title and maximum vote count.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $electionQuery = $pdo->prepare(
            'SELECT status FROM elections WHERE id = :election_id'
        );
        $electionQuery->execute(['election_id' => $electionId]);
        $election = $electionQuery->fetch();

        if (!$election || $election['status'] !== 'draft') {
            set_flash('error', 'Positions can only be changed in a draft election.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        if ($positionId !== null) {
            $positionQuery = $pdo->prepare(
                'SELECT title, max_votes FROM positions
                 WHERE id = :position_id AND election_id = :election_id'
            );
            $positionQuery->execute([
                'position_id' => $positionId,
                'election_id' => $electionId,
            ]);
            $existingPosition = $positionQuery->fetch();
            if (!$existingPosition) {
                set_flash('error', 'Position not found in the selected election.');
                redirect(BASE_URL . '/admin/manage_elections.php');
            }

            $pdo->beginTransaction();
            $savePosition = $pdo->prepare(
                'UPDATE positions SET title = :title, max_votes = :max_votes
                 WHERE id = :position_id AND election_id = :election_id'
            );
            $savePosition->execute([
                'title' => $title,
                'max_votes' => $maxVotes,
                'position_id' => $positionId,
                'election_id' => $electionId,
            ]);
            log_admin_action(
                $pdo,
                'position.updated',
                'position',
                $positionId,
                'Updated an election position.',
                ['title' => $existingPosition['title'], 'max_votes' => (int) $existingPosition['max_votes']],
                ['title' => $title, 'max_votes' => (int) $maxVotes]
            );
            $pdo->commit();
            set_flash('success', 'Position updated.');
        } else {
            $pdo->beginTransaction();
            $savePosition = $pdo->prepare(
                'INSERT INTO positions (election_id, title, max_votes)
                 VALUES (:election_id, :title, :max_votes)'
            );
            $savePosition->execute([
                'election_id' => $electionId,
                'title' => $title,
                'max_votes' => $maxVotes,
            ]);
            $positionId = (int) $pdo->lastInsertId();
            log_admin_action(
                $pdo,
                'position.created',
                'position',
                $positionId,
                'Created an election position.',
                null,
                ['election_id' => $electionId, 'title' => $title, 'max_votes' => (int) $maxVotes]
            );
            $pdo->commit();
            set_flash('success', 'Position created.');
        }

        redirect(BASE_URL . '/admin/manage_elections.php');
    }

    if ($action === 'delete_position') {
        $positionId = election_manager_positive_int($_POST['position_id'] ?? null);
        if ($positionId === false) {
            set_flash('error', 'Invalid position.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $positionQuery = $pdo->prepare(
            'SELECT p.election_id, p.title, p.max_votes, e.status AS election_status
             FROM positions p
             INNER JOIN elections e ON e.id = p.election_id
             WHERE p.id = :position_id'
        );
        $positionQuery->execute(['position_id' => $positionId]);
        $position = $positionQuery->fetch();

        if (!$position || $position['election_status'] !== 'draft') {
            set_flash('error', 'Only positions in draft elections can be deleted.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $activityQuery = $pdo->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM votes WHERE position_id = :vote_position)
                OR EXISTS(SELECT 1 FROM ballot_submissions WHERE position_id = :submission_position)'
        );
        $activityQuery->execute([
            'vote_position' => $positionId,
            'submission_position' => $positionId,
        ]);
        if ((bool) $activityQuery->fetchColumn()) {
            set_flash('error', 'A position with ballot activity cannot be deleted.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $photosQuery = $pdo->prepare('SELECT photo_path FROM candidates WHERE position_id = :position_id');
        $photosQuery->execute(['position_id' => $positionId]);
        $candidatePhotos = $photosQuery->fetchAll(PDO::FETCH_COLUMN);
        $pdo->beginTransaction();
        $deletePosition = $pdo->prepare('DELETE FROM positions WHERE id = :position_id');
        $deletePosition->execute(['position_id' => $positionId]);
        log_admin_action(
            $pdo,
            'position.deleted',
            'position',
            $positionId,
            'Deleted an election position.',
            ['election_id' => (int) $position['election_id'], 'title' => $position['title'], 'max_votes' => (int) $position['max_votes']],
            null
        );
        $pdo->commit();
        foreach ($candidatePhotos as $candidatePhoto) {
            election_manager_delete_candidate_photo(is_string($candidatePhoto) ? $candidatePhoto : null);
        }
        set_flash('success', 'Position deleted.');
        redirect(BASE_URL . '/admin/manage_elections.php');
    }

    set_flash('error', 'Unknown election management action.');
    redirect(BASE_URL . '/admin/manage_elections.php');
}

$electionsQuery = $pdo->prepare(
    'SELECT id, title, description, start_datetime, end_datetime, timezone,
            nomination_deadline,
            auto_launch, voting_system, verification_methods, allow_abstentions,
            organization_logo_path, status
     FROM elections ORDER BY id DESC'
);
$electionsQuery->execute();
$elections = $electionsQuery->fetchAll();
foreach ($elections as &$electionRow) {
    $electionRow['is_upcoming'] = $electionRow['status'] === 'open'
        && election_is_upcoming($electionRow);
}
unset($electionRow);
$positionsQuery = $pdo->prepare(
    'SELECT id, election_id, title, max_votes FROM positions ORDER BY election_id, id'
);
$positionsQuery->execute();
$positions = $positionsQuery->fetchAll();
$positionsByElection = [];
foreach ($positions as $position) {
    $positionsByElection[(int) $position['election_id']][] = $position;
}

$editElectionId = election_manager_positive_int($_GET['edit'] ?? null);
$editElection = false;
if ($editElectionId !== false) {
    $editElectionQuery = $pdo->prepare(
        'SELECT id, title, description, start_datetime, end_datetime, timezone,
                nomination_deadline,
                auto_launch, voting_system, verification_methods, allow_abstentions,
                organization_logo_path, status
         FROM elections WHERE id = :election_id'
    );
    $editElectionQuery->execute(['election_id' => $editElectionId]);
    $editElection = $editElectionQuery->fetch();
    if (
        $editElection
        && $editElection['status'] !== 'draft'
        && !($editElection['status'] === 'open' && election_is_upcoming($editElection))
    ) {
        $editElection = false;
    }
}

$editPositionId = election_manager_positive_int($_GET['edit_position'] ?? null);
$editPosition = false;
if ($editPositionId !== false) {
    $editPositionQuery = $pdo->prepare(
        "SELECT p.id, p.election_id, p.title, p.max_votes
         FROM positions p
         INNER JOIN elections e ON e.id = p.election_id
         WHERE p.id = :position_id AND e.status = 'draft'"
    );
    $editPositionQuery->execute(['position_id' => $editPositionId]);
    $editPosition = $editPositionQuery->fetch();
}

$draftElections = array_values(array_filter(
    $elections,
    static function (array $election): bool {
        return $election['status'] === 'draft';
    }
));
$defaultTimezone = date_default_timezone_get();
if (!is_valid_election_timezone($defaultTimezone)) {
    $defaultTimezone = 'UTC';
}
$electionTimezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
$electionTimezones[] = 'UTC';
$electionTimezones = array_values(array_unique($electionTimezones));
sort($electionTimezones, SORT_STRING);
$pageTitle = 'Manage elections';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>Manage elections</h1>
                <p>Create elections and positions, then open or close voting. Scheduled elections can be edited before they start.</p>
            </div>
        </header>

        <section class="form-card election-wizard" aria-labelledby="election-form-title">
            <header class="form-card__header">
                <h2 id="election-form-title"><?= $editElection ? 'Edit election setup' : 'Create an election' ?></h2>
                <p>Complete the three steps to prepare your election.</p>
            </header>

            <nav class="wizard-progress" aria-label="Election setup progress">
                <ol>
                    <li><button type="button" data-wizard-nav="0" aria-current="step">
                        <span>1</span> Basic Details
                    </button></li>
                    <li><button type="button" data-wizard-nav="1">
                        <span>2</span> Schedule &amp; Timeline
                    </button></li>
                    <li><button type="button" data-wizard-nav="2">
                        <span>3</span> Voting Rules &amp; Security
                    </button></li>
                </ol>
            </nav>

            <form class="form election-wizard__form" data-election-wizard novalidate
                method="post" enctype="multipart/form-data"
                action="<?= BASE_URL ?>/admin/manage_elections.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_election">
                <input type="hidden" name="election_id" value="<?= $editElection ? (int) $editElection['id'] : '' ?>">

                <section class="wizard-step" data-wizard-step="0" aria-labelledby="wizard-step-one">
                    <h3 id="wizard-step-one" tabindex="-1">Step 1: Basic Details</h3>
                    <div class="form__group">
                        <label for="title">Election title</label>
                        <input id="title" name="title" type="text" maxlength="200"
                            value="<?= $editElection ? sanitize($editElection['title']) : '' ?>" required>
                    </div>

                    <div class="form__group">
                        <span class="form__label" id="description-label">Description and voter instructions</span>
                        <div class="rich-text-toolbar" role="toolbar" aria-label="Description formatting">
                            <button type="button" data-rich-command="bold" aria-label="Bold">B</button>
                            <button type="button" data-rich-command="italic" aria-label="Italic"><em>I</em></button>
                            <button type="button" data-rich-command="insertUnorderedList" aria-label="Bulleted list">Bullets</button>
                            <button type="button" data-rich-command="insertOrderedList" aria-label="Numbered list">Numbered list</button>
                        </div>
                        <div class="rich-text-editor" contenteditable="true" role="textbox"
                            aria-multiline="true" aria-labelledby="description-label"
                            data-rich-editor><?= $editElection
                                ? sanitize_rich_text((string) $editElection['description'])
                                : '' ?></div>
                        <textarea name="description" data-rich-content hidden><?= $editElection
                            ? sanitize_rich_text((string) $editElection['description'])
                            : '' ?></textarea>
                        <p class="form__hint">Use concise, voter-friendly instructions. Formatting is limited to safe text, lists, and links.</p>
                    </div>

                    <div class="form__group">
                        <label for="organization_logo">Organization logo <span>(optional)</span></label>
                        <input id="organization_logo" name="organization_logo" type="file"
                            accept="image/jpeg,image/png,image/webp">
                        <p class="form__hint">JPG, PNG, or WebP; maximum 2 MB.</p>
                        <?php if (
                            $editElection
                            && is_string($editElection['organization_logo_path'])
                            && preg_match('~\Aassets/img/elections/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $editElection['organization_logo_path']) === 1
                        ): ?>
                            <div class="election-logo-preview">
                                <img src="<?= BASE_URL ?>/<?= sanitize($editElection['organization_logo_path']) ?>"
                                    alt="Current organization logo">
                                <label><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="wizard-step__actions">
                        <button class="btn btn--primary" type="button" data-wizard-next>Continue to schedule</button>
                    </div>
                </section>

                <section class="wizard-step" data-wizard-step="1" aria-labelledby="wizard-step-two" hidden>
                    <h3 id="wizard-step-two" tabindex="-1">Step 2: Schedule &amp; Timeline</h3>
                    <div class="wizard-fields">
                        <div class="form__group">
                            <label for="start_datetime">Poll opening</label>
                            <input id="start_datetime" name="start_datetime" type="datetime-local"
                                value="<?= $editElection
                                    ? sanitize(str_replace(' ', 'T', substr($editElection['start_datetime'], 0, 16)))
                                    : '' ?>"
                                required>
                        </div>
                        <div class="form__group">
                            <label for="end_datetime">Poll closing</label>
                            <input id="end_datetime" name="end_datetime" type="datetime-local"
                                value="<?= $editElection
                                    ? sanitize(str_replace(' ', 'T', substr($editElection['end_datetime'], 0, 16)))
                                    : '' ?>"
                                required>
                        </div>
                        <div class="form__group">
                            <label for="nomination_deadline">Nomination freeze deadline <span>(optional)</span></label>
                            <input id="nomination_deadline" name="nomination_deadline" type="datetime-local"
                                value="<?= $editElection && is_string($editElection['nomination_deadline'])
                                    ? sanitize(str_replace(' ', 'T', substr($editElection['nomination_deadline'], 0, 16)))
                                    : '' ?>">
                            <p class="form__hint">Must be on or before poll opening. Leave blank to keep nominations open through the Draft and scheduled phases, until polls begin; add a candidate position in Manage Candidates to accept applications.</p>
                        </div>
                    </div>
                    <div class="form__group">
                        <label for="timezone">Election timezone</label>
                        <select id="timezone" name="timezone" required>
                            <?php foreach ($electionTimezones as $timezoneOption): ?>
                                <option value="<?= sanitize($timezoneOption) ?>"
                                    <?= ($editElection['timezone'] ?? $defaultTimezone) === $timezoneOption ? 'selected' : '' ?>>
                                    <?= sanitize(str_replace('_', ' ', $timezoneOption)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form__hint">All schedule times are interpreted in this timezone.</p>
                    </div>
                    <div class="wizard-toggle">
                        <label for="auto_launch">Automatically launch at the scheduled opening time</label>
                        <input id="auto_launch" name="auto_launch" type="checkbox" value="1"
                            <?= $editElection && (bool) $editElection['auto_launch'] ? 'checked' : '' ?>>
                        <p class="form__hint">Automatic launch is processed by the scheduled task or on the next app request; it requires at least one position.</p>
                    </div>
                    <div class="wizard-step__actions">
                        <button class="btn btn--outline" type="button" data-wizard-back>Back</button>
                        <button class="btn btn--primary" type="button" data-wizard-next>Continue to voting rules</button>
                    </div>
                </section>

                <section class="wizard-step" data-wizard-step="2" aria-labelledby="wizard-step-three" hidden>
                    <h3 id="wizard-step-three" tabindex="-1">Step 3: Voting Rules &amp; Security</h3>
                    <fieldset class="wizard-options">
                        <legend>Voting system</legend>
                        <?php foreach ([
                            'fptp' => ['First-Past-the-Post', 'Select one candidate per position.'],
                            'multiple_selection' => ['Multiple Selection', 'Select up to the position limit.'],
                            'ranked_choice' => ['Ranked Choice', 'Rank candidates; results use instant-runoff voting.'],
                        ] as $votingSystemOption => [$votingSystemLabel, $votingSystemHelp]): ?>
                            <label class="wizard-radio-card">
                                <input type="radio" name="voting_system" value="<?= sanitize($votingSystemOption) ?>"
                                    <?= ($editElection['voting_system'] ?? 'fptp') === $votingSystemOption ? 'checked' : '' ?>
                                    required>
                                <span><strong><?= sanitize($votingSystemLabel) ?></strong>
                                    <small><?= sanitize($votingSystemHelp) ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <fieldset class="wizard-options">
                        <legend>Voter verification methods <span>(all selected methods are required)</span></legend>
                        <?php $savedMethods = $editElection ? explode(',', $editElection['verification_methods']) : []; ?>
                        <label class="wizard-check-card">
                            <input type="checkbox" name="verification_methods[]" value="email_token"
                                <?= in_array('email_token', $savedMethods, true) ? 'checked' : '' ?>>
                            <span><strong>Email Token</strong><small>Send a one-time code to the voter's registered email.</small></span>
                        </label>
                        <label class="wizard-check-card is-disabled">
                            <input type="checkbox" value="sms_otp" disabled>
                            <span><strong>SMS OTP</strong><small>Unavailable until an SMS provider is configured.</small></span>
                        </label>
                        <label class="wizard-check-card">
                            <input type="checkbox" name="verification_methods[]" value="voter_pin"
                                <?= in_array('voter_pin', $savedMethods, true) ? 'checked' : '' ?>>
                            <span><strong>Voter ID + PIN</strong><small>Use the registration number and account password.</small></span>
                        </label>
                    </fieldset>

                    <div class="wizard-toggle">
                        <label for="allow_abstentions">Allow voters to abstain on individual positions</label>
                        <input id="allow_abstentions" name="allow_abstentions" type="checkbox" value="1"
                            <?= $editElection && (bool) $editElection['allow_abstentions'] ? 'checked' : '' ?>>
                        <p class="form__hint">An abstention completes that position and cannot be changed later.</p>
                    </div>

                    <div class="wizard-step__actions">
                        <button class="btn btn--outline" type="button" data-wizard-back>Back</button>
                    </div>
                </section>
                <div class="wizard-error" data-wizard-error role="alert" hidden>
                    Complete the required fields in this step before continuing.
                </div>
                <div class="wizard-action-bar">
                    <button class="btn btn--outline" type="submit" name="intent" value="draft">
                        Save as Draft
                    </button>
                    <button class="btn btn--primary" type="submit" name="intent" value="add_candidates">
                        <?= $editElection ? 'Save & Add Candidates' : 'Create Election & Add Candidates' ?>
                    </button>
                </div>
                <?php if ($editElection): ?>
                    <a class="election-wizard__cancel" href="<?= BASE_URL ?>/admin/manage_elections.php">Cancel edit</a>
                <?php endif; ?>
            </form>
        </section>

        <details class="election-positions-manager" <?= $editPosition ? 'open' : '' ?>>
            <summary>Manage election positions</summary>
            <section class="form-card" aria-labelledby="position-form-title">
                <header class="form-card__header">
                    <h2 id="position-form-title"><?= $editPosition ? 'Edit position' : 'Add a position' ?></h2>
                </header>
                <?php if ($draftElections === []): ?>
                    <p>Save the election as a draft before adding positions.</p>
                <?php else: ?>
                    <form class="form" method="post" action="<?= BASE_URL ?>/admin/manage_elections.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_position">
                        <input type="hidden" name="position_id"
                            value="<?= $editPosition ? (int) $editPosition['id'] : '' ?>">
                        <div class="form__group">
                            <?php if ($editPosition): ?>
                                <span class="form__label">Election</span>
                                <input type="hidden" name="election_id" value="<?= (int) $editPosition['election_id'] ?>">
                                <p><?= sanitize($editPosition['title']) ?> belongs to
                                    <?php foreach ($draftElections as $draftElection): ?>
                                        <?php if ((int) $draftElection['id'] === (int) $editPosition['election_id']): ?>
                                            <?= sanitize($draftElection['title']) ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </p>
                            <?php else: ?>
                                <label for="position_election_id">Election</label>
                                <select id="position_election_id" name="election_id" required>
                                    <?php foreach ($draftElections as $draftElection): ?>
                                        <option value="<?= (int) $draftElection['id'] ?>">
                                            <?= sanitize($draftElection['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="form__group">
                            <label for="position_title">Position title</label>
                            <input id="position_title" name="position_title" type="text" maxlength="150"
                                value="<?= $editPosition ? sanitize($editPosition['title']) : '' ?>" required>
                        </div>
                        <div class="form__group">
                            <label for="max_votes">Maximum votes</label>
                            <input id="max_votes" name="max_votes" type="number" min="1" step="1"
                                value="<?= $editPosition ? (int) $editPosition['max_votes'] : 1 ?>" required>
                            <p class="form__hint">Used as the selection limit for Multiple Selection elections.</p>
                        </div>
                        <div class="form-card__actions">
                            <button class="btn btn--primary" type="submit">
                                <?= $editPosition ? 'Save position' : 'Add a position' ?>
                            </button>
                            <?php if ($editPosition): ?>
                                <a href="<?= BASE_URL ?>/admin/manage_elections.php">Cancel edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        </details>

        <h2 class="election-list-title">Elections</h2>
        <?php if ($elections === []): ?>
            <div class="empty-state" role="status">
                <h3>No elections yet</h3>
                <p>Create a draft election to get started.</p>
            </div>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($elections as $election): ?>
                    <?php $electionId = (int) $election['id']; ?>
                    <?php
                    $displayStatus = (bool) $election['is_upcoming']
                        || (
                            $election['status'] === 'draft'
                            && (bool) $election['auto_launch']
                            && election_is_upcoming($election)
                        )
                            ? 'scheduled'
                            : $election['status'];
                    ?>
                    <article class="card election-card">
                        <div class="election-card__header">
                            <div>
                                <h3><?= sanitize($election['title']) ?></h3>
                                <p>
                                    <?= sanitize(format_election_local_time($election['start_datetime'], $election['timezone'])) ?>
                                    to <?= sanitize(format_election_local_time($election['end_datetime'], $election['timezone'])) ?>
                                </p>
                            </div>
                            <span class="badge badge--<?= sanitize($displayStatus) ?>">
                                <?= sanitize(ucfirst($displayStatus)) ?>
                            </span>
                        </div>
                        <?php if (
                            is_string($election['organization_logo_path'])
                            && preg_match('~\Aassets/img/elections/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $election['organization_logo_path']) === 1
                        ): ?>
                            <img class="election-card__logo"
                                src="<?= BASE_URL ?>/<?= sanitize($election['organization_logo_path']) ?>"
                                alt="<?= sanitize($election['title']) ?> organization logo">
                        <?php endif; ?>
                        <?php if ($election['description'] !== null && $election['description'] !== ''): ?>
                            <div class="election-card__description">
                                <?= sanitize_rich_text($election['description']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="admin-actions">
                            <?php if ($election['status'] === 'draft'): ?>
                                <a class="btn btn--outline"
                                    href="<?= BASE_URL ?>/admin/manage_elections.php?edit=<?= $electionId ?>">
                                    Edit
                                </a>
                                <form method="post" action="<?= BASE_URL ?>/admin/manage_elections.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="open_election">
                                    <input type="hidden" name="election_id" value="<?= $electionId ?>">
                                    <button class="btn btn--primary" type="submit">Open election</button>
                                </form>
                            <?php elseif ($election['status'] === 'open'): ?>
                                <?php if ((bool) $election['is_upcoming']): ?>
                                    <a class="btn btn--outline"
                                        href="<?= BASE_URL ?>/admin/manage_elections.php?edit=<?= $electionId ?>">
                                        Edit election settings
                                    </a>
                                <?php endif; ?>
                                <form method="post" action="<?= BASE_URL ?>/admin/manage_elections.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="close_election">
                                    <input type="hidden" name="election_id" value="<?= $electionId ?>">
                                    <button class="btn btn--black" type="submit">Close election</button>
                                </form>
                            <?php elseif ($election['status'] === 'paused'): ?>
                                <form method="post" action="<?= BASE_URL ?>/admin/manage_elections.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="resume_election">
                                    <input type="hidden" name="election_id" value="<?= $electionId ?>">
                                    <button class="btn btn--primary" type="submit">Resume election</button>
                                </form>
                                <form method="post" action="<?= BASE_URL ?>/admin/manage_elections.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="close_election">
                                    <input type="hidden" name="election_id" value="<?= $electionId ?>">
                                    <button class="btn btn--black" type="submit">Close election</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!in_array($election['status'], ['open', 'paused'], true)): ?>
                                <form method="post" action="<?= BASE_URL ?>/admin/manage_elections.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_election">
                                    <input type="hidden" name="election_id" value="<?= $electionId ?>">
                                    <button class="btn btn--black" type="submit">Delete election</button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <div class="position-list">
                            <h4>Positions</h4>
                            <?php $electionPositions = $positionsByElection[$electionId] ?? []; ?>
                            <?php if ($electionPositions === []): ?>
                                <p>No positions added.</p>
                            <?php else: ?>
                                <ul>
                                    <?php foreach ($electionPositions as $position): ?>
                                        <li>
                                            <?= sanitize($position['title']) ?>
                                            (maximum <?= (int) $position['max_votes'] ?> votes)
                                            <?php if ($election['status'] === 'draft'): ?>
                                                <a href="<?= BASE_URL ?>/admin/manage_elections.php?edit_position=<?= (int) $position['id'] ?>">
                                                    Edit
                                                </a>
                                                <form class="inline-form" method="post"
                                                    action="<?= BASE_URL ?>/admin/manage_elections.php">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_position">
                                                    <input type="hidden" name="position_id" value="<?= (int) $position['id'] ?>">
                                                    <button class="text-button" type="submit">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<script src="<?= BASE_URL ?>/assets/js/election-wizard.js" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>