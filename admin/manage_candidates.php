<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_time.php';
require_once __DIR__ . '/../includes/election_scheduler.php';

function ballot_builder_positive_int($value): int|false
{
    if (!is_string($value) && !is_int($value)) {
        return false;
    }

    $number = filter_var((string) $value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    return $number === false ? false : (int) $number;
}

function ballot_builder_election_is_locked(array $election): bool
{
    if (in_array($election['status'], ['paused', 'closed'], true)) {
        return true;
    }

    if ($election['status'] === 'open') {
        return !election_is_upcoming($election);
    }

    return $election['status'] !== 'draft';
}

function ballot_builder_has_ballot_activity(PDO $pdo, int $electionId, int $positionId): bool
{
    $activityQuery = $pdo->prepare(
        'SELECT
            EXISTS(SELECT 1 FROM ballot_submissions
                   WHERE election_id = :submission_election AND position_id = :submission_position)
            OR EXISTS(SELECT 1 FROM votes
                      WHERE election_id = :vote_election AND position_id = :vote_position)'
    );
    $activityQuery->execute([
        'submission_election' => $electionId,
        'submission_position' => $positionId,
        'vote_election' => $electionId,
        'vote_position' => $positionId,
    ]);

    return (bool) $activityQuery->fetchColumn();
}

function ballot_builder_store_photo($upload): string|false|null
{
    if (!is_array($upload)) {
        return null;
    }

    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $temporaryPath = $upload['tmp_name'] ?? '';
    $size = $upload['size'] ?? 0;
    if (
        !class_exists('finfo')
        || $error !== UPLOAD_ERR_OK
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
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    $actualSize = filesize($temporaryPath);
    if (
        !isset($extensions[$mimeType])
        || $actualSize === false
        || $actualSize < 1
        || $actualSize > 2 * 1024 * 1024
    ) {
        return false;
    }

    $directory = __DIR__ . '/../assets/img/candidates';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the candidate photo directory.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($temporaryPath, $directory . DIRECTORY_SEPARATOR . $fileName)) {
        throw new RuntimeException('Unable to save the candidate photo.');
    }

    return 'assets/img/candidates/' . $fileName;
}

function ballot_builder_delete_photo(?string $path): void
{
    if (
        $path === null
        || preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $path) !== 1
    ) {
        return;
    }

    $fullPath = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($fullPath) && !unlink($fullPath)) {
        throw new RuntimeException('Unable to remove a replaced candidate photo.');
    }
}

function ballot_builder_redirect(int $electionId, ?string $tab = null): void
{
    $url = BASE_URL . '/admin/manage_candidates.php?election_id=' . $electionId;
    if (in_array($tab, ['pending', 'approved', 'rejected'], true)) {
        $url .= '&tab=' . $tab;
    }

    redirect($url);
}

activate_due_auto_elections($pdo);

if (is_post()) {
    verify_csrf();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $rawElectionId = $_POST['election_id'] ?? '';
    $electionId = ballot_builder_positive_int($rawElectionId);

    if ($electionId === false) {
        set_flash('error', 'Choose a valid election before editing its ballot.');
        redirect(BASE_URL . '/admin/manage_candidates.php');
    }

    if ($action === 'save_position') {
        $rawPositionId = $_POST['position_id'] ?? '';
        $positionId = $rawPositionId === '' ? null : ballot_builder_positive_int($rawPositionId);
        $title = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
        $contestType = isset($_POST['contest_type']) && is_string($_POST['contest_type'])
            ? $_POST['contest_type']
            : '';
        $rawMaxVotes = $_POST['max_votes'] ?? '';
        $maxVotes = filter_var($rawMaxVotes, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        if (
            ($positionId !== null && $positionId === false)
            || $title === ''
            || strlen($title) > 150
            || !in_array($contestType, ['candidate', 'referendum'], true)
            || $maxVotes === false
        ) {
            set_flash('error', 'Enter a contest title, type, and valid maximum selection count.');
            ballot_builder_redirect($electionId);
        }

        $pdo->beginTransaction();
        $electionQuery = $pdo->prepare(
            'SELECT id, status, start_datetime, end_datetime, timezone
             FROM elections WHERE id = :id FOR UPDATE'
        );
        $electionQuery->execute(['id' => $electionId]);
        $election = $electionQuery->fetch();
        if (!$election || ballot_builder_election_is_locked($election)) {
            $pdo->rollBack();
            set_flash('error', 'This election is locked; its ballot cannot be edited.');
            ballot_builder_redirect($electionId);
        }

        if ($positionId !== null) {
            $positionQuery = $pdo->prepare(
                'SELECT title, contest_type, max_votes FROM positions
                 WHERE id = :id AND election_id = :election_id FOR UPDATE'
            );
            $positionQuery->execute(['id' => $positionId, 'election_id' => $electionId]);
            $existingPosition = $positionQuery->fetch();
            if (!$existingPosition) {
                $pdo->rollBack();
                set_flash('error', 'That contest no longer exists.');
                ballot_builder_redirect($electionId);
            }

            if (ballot_builder_has_ballot_activity($pdo, $electionId, $positionId)) {
                $pdo->rollBack();
                set_flash('error', 'A contest with submitted ballots cannot be changed.');
                ballot_builder_redirect($electionId);
            }

            if ($existingPosition['contest_type'] !== $contestType) {
                $countQuery = $pdo->prepare('SELECT COUNT(*) FROM candidates WHERE position_id = :id');
                $countQuery->execute(['id' => $positionId]);
                if ((int) $countQuery->fetchColumn() > 0) {
                    $pdo->rollBack();
                    set_flash('error', 'Remove the existing entries before changing a contest type.');
                    ballot_builder_redirect($electionId);
                }
            }

            $savePosition = $pdo->prepare(
                'UPDATE positions SET title = :title, contest_type = :contest_type, max_votes = :max_votes
                 WHERE id = :id AND election_id = :election_id'
            );
            $savePosition->execute([
                'title' => $title,
                'contest_type' => $contestType,
                'max_votes' => $maxVotes,
                'id' => $positionId,
                'election_id' => $electionId,
            ]);
            log_admin_action(
                $pdo,
                'ballot.position_updated',
                'position',
                $positionId,
                'Updated ballot contest.',
                [
                    'title' => $existingPosition['title'],
                    'contest_type' => $existingPosition['contest_type'],
                    'max_votes' => (int) $existingPosition['max_votes'],
                ],
                ['title' => $title, 'contest_type' => $contestType, 'max_votes' => (int) $maxVotes]
            );
        } else {
            $savePosition = $pdo->prepare(
                'INSERT INTO positions (election_id, title, contest_type, max_votes)
                 VALUES (:election_id, :title, :contest_type, :max_votes)'
            );
            $savePosition->execute([
                'election_id' => $electionId,
                'title' => $title,
                'contest_type' => $contestType,
                'max_votes' => $maxVotes,
            ]);
            $positionId = (int) $pdo->lastInsertId();
            log_admin_action(
                $pdo,
                'ballot.position_created',
                'position',
                $positionId,
                'Created ballot contest.',
                null,
                [
                    'election_id' => $electionId,
                    'title' => $title,
                    'contest_type' => $contestType,
                    'max_votes' => (int) $maxVotes,
                ]
            );
        }

        $pdo->commit();
        set_flash('success', 'Contest saved.');
        ballot_builder_redirect($electionId);
    }

    if ($action === 'delete_position' || $action === 'delete_entry' || $action === 'set_randomize') {
        $positionId = ballot_builder_positive_int($_POST['position_id'] ?? '');
        if ($positionId === false) {
            set_flash('error', 'Choose a valid ballot contest.');
            ballot_builder_redirect($electionId);
        }

        $pdo->beginTransaction();
        $electionQuery = $pdo->prepare(
            'SELECT id, status, start_datetime, end_datetime, timezone
             FROM elections WHERE id = :id FOR UPDATE'
        );
        $electionQuery->execute(['id' => $electionId]);
        $election = $electionQuery->fetch();
        $positionQuery = $pdo->prepare(
            'SELECT id, title, max_votes, randomize_candidates FROM positions
             WHERE id = :id AND election_id = :election_id FOR UPDATE'
        );
        $positionQuery->execute(['id' => $positionId, 'election_id' => $electionId]);
        $position = $positionQuery->fetch();
        if (!$election || !$position || ballot_builder_election_is_locked($election)) {
            $pdo->rollBack();
            set_flash('error', 'This contest is unavailable or its election is locked.');
            ballot_builder_redirect($electionId);
        }

        if (ballot_builder_has_ballot_activity($pdo, $electionId, $positionId)) {
            $pdo->rollBack();
            set_flash('error', 'A contest with submitted ballots cannot be changed or deleted.');
            ballot_builder_redirect($electionId);
        }

        if ($action === 'set_randomize') {
            $enabled = isset($_POST['randomize']) && $_POST['randomize'] === '1' ? 1 : 0;
            $update = $pdo->prepare('UPDATE positions SET randomize_candidates = :enabled WHERE id = :id');
            $update->execute(['enabled' => $enabled, 'id' => $positionId]);
            log_admin_action(
                $pdo,
                'ballot.order_updated',
                'position',
                $positionId,
                'Updated ballot entry ordering.',
                ['randomize_candidates' => (int) $position['randomize_candidates']],
                ['randomize_candidates' => $enabled]
            );
            $pdo->commit();
            ballot_builder_redirect($electionId);
        }

        if ($action === 'delete_position') {
            $photosQuery = $pdo->prepare('SELECT photo_path FROM candidates WHERE position_id = :id');
            $photosQuery->execute(['id' => $positionId]);
            $photos = $photosQuery->fetchAll(PDO::FETCH_COLUMN);
            $delete = $pdo->prepare('DELETE FROM positions WHERE id = :id AND election_id = :election_id');
            $delete->execute(['id' => $positionId, 'election_id' => $electionId]);
            log_admin_action(
                $pdo,
                'ballot.position_deleted',
                'position',
                $positionId,
                'Deleted ballot contest.',
                [
                    'election_id' => $electionId,
                    'title' => $position['title'],
                    'max_votes' => (int) $position['max_votes'],
                ],
                null
            );
            $pdo->commit();
            foreach ($photos as $photo) {
                ballot_builder_delete_photo(is_string($photo) ? $photo : null);
            }
            set_flash('success', 'Contest deleted.');
            ballot_builder_redirect($electionId);
        }

        $entryId = ballot_builder_positive_int($_POST['candidate_id'] ?? '');
        if ($entryId === false) {
            $pdo->rollBack();
            set_flash('error', 'Choose a valid candidate or referendum option.');
            ballot_builder_redirect($electionId);
        }
        $entryQuery = $pdo->prepare(
            'SELECT user_id, election_id, position_id, display_name, manifesto, affiliation, status, photo_path
             FROM candidates
             WHERE id = :id AND election_id = :election_id AND position_id = :position_id FOR UPDATE'
        );
        $entryQuery->execute([
            'id' => $entryId,
            'election_id' => $electionId,
            'position_id' => $positionId,
        ]);
        $entry = $entryQuery->fetch();
        if (!$entry) {
            $pdo->rollBack();
            set_flash('error', 'That ballot entry no longer exists.');
            ballot_builder_redirect($electionId);
        }
        $delete = $pdo->prepare('DELETE FROM candidates WHERE id = :id');
        $delete->execute(['id' => $entryId]);
        log_admin_action(
            $pdo,
            'ballot.entry_deleted',
            'candidate',
            $entryId,
            'Deleted ballot entry.',
            [
                'user_id' => $entry['user_id'] === null ? null : (int) $entry['user_id'],
                'election_id' => (int) $entry['election_id'],
                'position_id' => (int) $entry['position_id'],
                'display_name' => $entry['display_name'],
                'manifesto' => $entry['manifesto'],
                'affiliation' => $entry['affiliation'],
                'status' => $entry['status'],
            ],
            null
        );
        $pdo->commit();
        ballot_builder_delete_photo($entry['photo_path']);
        set_flash('success', 'Ballot entry removed.');
        ballot_builder_redirect($electionId);
    }

    if (in_array($action, ['save_candidate', 'save_option', 'review_candidate'], true)) {
        $positionId = ballot_builder_positive_int($_POST['position_id'] ?? '');
        $entryIdValue = $_POST['candidate_id'] ?? '';
        $entryId = $entryIdValue === '' ? null : ballot_builder_positive_int($entryIdValue);
        if ($positionId === false || ($entryId !== null && $entryId === false)) {
            set_flash('error', 'Choose a valid ballot contest and entry.');
            ballot_builder_redirect($electionId);
        }

        $pdo->beginTransaction();
        $electionQuery = $pdo->prepare(
            'SELECT id, status, start_datetime, end_datetime, timezone
             FROM elections WHERE id = :id FOR UPDATE'
        );
        $electionQuery->execute(['id' => $electionId]);
        $election = $electionQuery->fetch();
        $positionQuery = $pdo->prepare(
            'SELECT id, contest_type FROM positions
             WHERE id = :id AND election_id = :election_id FOR UPDATE'
        );
        $positionQuery->execute(['id' => $positionId, 'election_id' => $electionId]);
        $position = $positionQuery->fetch();
        if (!$election || !$position || ballot_builder_election_is_locked($election)) {
            $pdo->rollBack();
            set_flash('error', 'This election is locked or the selected contest is unavailable.');
            ballot_builder_redirect($electionId);
        }

        if (ballot_builder_has_ballot_activity($pdo, $electionId, $positionId)) {
            $pdo->rollBack();
            set_flash('error', 'A contest with submitted ballots cannot be changed.');
            ballot_builder_redirect($electionId);
        }

        if ($action === 'review_candidate') {
            $decision = isset($_POST['decision']) && is_string($_POST['decision']) ? $_POST['decision'] : '';
            $rejectionFeedback = isset($_POST['rejection_feedback']) && is_string($_POST['rejection_feedback'])
                ? trim($_POST['rejection_feedback'])
                : '';
            $requiresFeedback = in_array($decision, ['reject', 'disqualify'], true);
            $expectedCandidateStatus = $decision === 'disqualify' ? 'approved' : 'pending';
            if ($entryId === null || $position['contest_type'] !== 'candidate'
                || !in_array($decision, ['approve', 'reject', 'disqualify'], true)) {
                $pdo->rollBack();
                set_flash('error', 'That candidate review action is invalid.');
                ballot_builder_redirect($electionId);
            }
            $review = $pdo->prepare(
                'SELECT c.user_id, c.status, c.rejection_feedback,
                        u.status AS user_status, u.full_name, u.email, e.title AS election_title
                 FROM candidates c
                 INNER JOIN users u ON u.id = c.user_id
                 INNER JOIN elections e ON e.id = c.election_id
                 WHERE c.id = :id AND c.election_id = :election_id
                   AND c.position_id = :position_id FOR UPDATE'
            );
            $review->execute(['id' => $entryId, 'election_id' => $electionId, 'position_id' => $positionId]);
            $candidate = $review->fetch();
            if (
                !$candidate
                || $candidate['user_id'] === null
                || $candidate['status'] !== $expectedCandidateStatus
                || ($requiresFeedback && (
                    $rejectionFeedback === ''
                    || strlen($rejectionFeedback) > 2000
                ))
            ) {
                $pdo->rollBack();
                set_flash('error', 'The candidate status changed, or the requested decision requires feedback of at most 2,000 characters.');
                ballot_builder_redirect($electionId);
            }
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            $userStatus = $candidate['user_status'] === 'suspended' ? 'suspended' : 'active';
            $review->closeCursor();
            $feedbackValue = $requiresFeedback ? $rejectionFeedback : null;
            $updateCandidate = $pdo->prepare(
                'UPDATE candidates
                 SET status = :status, rejection_feedback = :rejection_feedback
                 WHERE id = :id'
            );
            $updateCandidate->execute([
                'status' => $status,
                'rejection_feedback' => $feedbackValue,
                'id' => $entryId,
            ]);
            $updateUser = $pdo->prepare(
                'UPDATE users SET status = :status WHERE id = :id AND role = \'candidate\''
            );
            $updateUser->execute(['status' => $userStatus, 'id' => $candidate['user_id']]);
            log_admin_action(
                $pdo,
                match ($decision) {
                    'approve' => 'candidate.approved',
                    'disqualify' => 'candidate.disqualified',
                    default => 'candidate.rejected',
                },
                'candidate',
                $entryId,
                match ($decision) {
                    'approve' => 'Approved candidate application.',
                    'disqualify' => 'Disqualified approved candidate.',
                    default => 'Rejected candidate application.',
                },
                [
                    'candidate_status' => $candidate['status'],
                    'user_status' => $candidate['user_status'],
                    'rejection_feedback' => $candidate['rejection_feedback'],
                ],
                [
                    'candidate_status' => $status,
                    'user_status' => $userStatus,
                    'rejection_feedback' => $feedbackValue,
                ]
            );
            $pdo->commit();
            $emailSent = send_candidate_review_email(
                $candidate['email'],
                $candidate['full_name'],
                $candidate['election_title'],
                $status,
                $feedbackValue ?? ''
            );
            set_flash(
                $emailSent ? 'success' : 'error',
                $emailSent
                    ? match ($decision) {
                        'approve' => 'Candidate approved and notified by email.',
                        'disqualify' => 'Candidate disqualified and notified by email.',
                        default => 'Candidate rejected and notified by email.',
                    }
                    : 'Candidate decision saved, but the notification email could not be sent. Please contact the candidate and check the mail transport.'
            );
            ballot_builder_redirect($electionId, $decision === 'approve' ? 'approved' : 'rejected');
        }

        $isOption = $action === 'save_option';
        if (($position['contest_type'] === 'referendum') !== $isOption) {
            $pdo->rollBack();
            set_flash('error', 'This entry type does not match the selected contest.');
            ballot_builder_redirect($electionId);
        }

        $manifesto = isset($_POST['manifesto']) && is_string($_POST['manifesto'])
            ? trim($_POST['manifesto'])
            : '';
        $campaignSlogan = isset($_POST['campaign_slogan']) && is_string($_POST['campaign_slogan'])
            ? trim($_POST['campaign_slogan'])
            : '';
        if (strlen($manifesto) > 65535 || strlen($campaignSlogan) > 200) {
            $pdo->rollBack();
            set_flash('error', 'The entry description or campaign slogan is too long.');
            ballot_builder_redirect($electionId);
        }

        if ($isOption) {
            $displayName = isset($_POST['display_name']) && is_string($_POST['display_name'])
                ? trim($_POST['display_name'])
                : '';
            if ($displayName === '' || strlen($displayName) > 150) {
                $pdo->rollBack();
                set_flash('error', 'Enter a referendum option no longer than 150 characters.');
                ballot_builder_redirect($electionId);
            }
            if ($entryId === null) {
                $saveEntry = $pdo->prepare(
                    'INSERT INTO candidates (user_id, display_name, election_id, position_id, manifesto, status)
                     VALUES (NULL, :display_name, :election_id, :position_id, :manifesto, \'approved\')'
                );
                $saveEntry->execute([
                    'display_name' => $displayName,
                    'election_id' => $electionId,
                    'position_id' => $positionId,
                    'manifesto' => $manifesto === '' ? null : $manifesto,
                ]);
                $entryId = (int) $pdo->lastInsertId();
                log_admin_action(
                    $pdo,
                    'ballot.option_created',
                    'candidate',
                    $entryId,
                    'Created referendum option.',
                    null,
                    ['display_name' => $displayName, 'manifesto' => $manifesto === '' ? null : $manifesto]
                );
            } else {
                $existingOptionQuery = $pdo->prepare(
                    'SELECT display_name, manifesto FROM candidates
                     WHERE id = :id AND user_id IS NULL AND election_id = :election_id
                       AND position_id = :position_id FOR UPDATE'
                );
                $existingOptionQuery->execute([
                    'id' => $entryId,
                    'election_id' => $electionId,
                    'position_id' => $positionId,
                ]);
                $existingOption = $existingOptionQuery->fetch();
                if (!$existingOption) {
                    $pdo->rollBack();
                    set_flash('error', 'That referendum option is unavailable.');
                    ballot_builder_redirect($electionId);
                }
                $saveEntry = $pdo->prepare(
                    'UPDATE candidates SET display_name = :display_name, manifesto = :manifesto
                     WHERE id = :id AND user_id IS NULL AND election_id = :election_id AND position_id = :position_id'
                );
                $saveEntry->execute([
                    'display_name' => $displayName,
                    'manifesto' => $manifesto === '' ? null : $manifesto,
                    'id' => $entryId,
                    'election_id' => $electionId,
                    'position_id' => $positionId,
                ]);
                log_admin_action(
                    $pdo,
                    'ballot.option_updated',
                    'candidate',
                    $entryId,
                    'Updated referendum option.',
                    ['display_name' => $existingOption['display_name'], 'manifesto' => $existingOption['manifesto']],
                    ['display_name' => $displayName, 'manifesto' => $manifesto === '' ? null : $manifesto]
                );
            }
        } else {
            if ($entryId === null) {
                $userId = ballot_builder_positive_int($_POST['user_id'] ?? '');
                $accountQuery = $pdo->prepare(
                    'SELECT id FROM users WHERE id = :id AND role = \'candidate\' AND status IN (\'pending\', \'active\')'
                );
                if ($userId === false) {
                    $pdo->rollBack();
                    set_flash('error', 'Select a registered candidate account that can be assigned.');
                    ballot_builder_redirect($electionId);
                }
                $accountQuery->execute(['id' => $userId]);
                if (!$accountQuery->fetch()) {
                    $pdo->rollBack();
                    set_flash('error', 'Select a registered candidate account that can be assigned.');
                    ballot_builder_redirect($electionId);
                }
                $duplicateQuery = $pdo->prepare(
                    'SELECT COUNT(*) FROM candidates WHERE user_id = :user_id AND election_id = :election_id'
                );
                $duplicateQuery->execute(['user_id' => $userId, 'election_id' => $electionId]);
                if ((int) $duplicateQuery->fetchColumn() > 0) {
                    $pdo->rollBack();
                    set_flash('error', 'That account is already assigned to this election.');
                    ballot_builder_redirect($electionId);
                }
                $affiliation = isset($_POST['affiliation']) && is_string($_POST['affiliation'])
                    ? trim($_POST['affiliation'])
                    : '';
                if (strlen($affiliation) > 150 || strlen($campaignSlogan) > 200) {
                    $pdo->rollBack();
                    set_flash('error', 'Affiliation or campaign slogan exceeds its allowed length.');
                    ballot_builder_redirect($electionId);
                }
                $newPhoto = ballot_builder_store_photo($_FILES['photo'] ?? null);
                if ($newPhoto === false) {
                    $pdo->rollBack();
                    set_flash('error', 'Choose a valid JPG or PNG photo under 2 MB.');
                    ballot_builder_redirect($electionId);
                }
                $saveEntry = $pdo->prepare(
                    'INSERT INTO candidates
                     (user_id, application_source, election_id, position_id, manifesto, campaign_slogan,
                      photo_path, affiliation, status)
                     VALUES (:user_id, \'admin\', :election_id, :position_id, :manifesto, :campaign_slogan,
                             :photo_path, :affiliation, \'pending\')'
                );
                $saveEntry->execute([
                    'user_id' => $userId,
                    'election_id' => $electionId,
                    'position_id' => $positionId,
                    'manifesto' => $manifesto === '' ? null : $manifesto,
                    'campaign_slogan' => $campaignSlogan === '' ? null : $campaignSlogan,
                    'photo_path' => $newPhoto,
                    'affiliation' => $affiliation === '' ? null : $affiliation,
                ]);
                $entryId = (int) $pdo->lastInsertId();
                log_admin_action(
                    $pdo,
                    'ballot.candidate_assigned',
                    'candidate',
                    $entryId,
                    'Assigned candidate account to contest.',
                    null,
                    [
                        'user_id' => $userId,
                        'election_id' => $electionId,
                        'position_id' => $positionId,
                        'manifesto' => $manifesto === '' ? null : $manifesto,
                        'campaign_slogan' => $campaignSlogan === '' ? null : $campaignSlogan,
                        'affiliation' => $affiliation === '' ? null : $affiliation,
                        'status' => 'pending',
                    ]
                );
            } else {
                $profileQuery = $pdo->prepare(
                    'SELECT photo_path, manifesto, campaign_slogan, affiliation FROM candidates
                     WHERE id = :id AND user_id IS NOT NULL AND election_id = :election_id
                       AND position_id = :position_id FOR UPDATE'
                );
                $profileQuery->execute([
                    'id' => $entryId,
                    'election_id' => $electionId,
                    'position_id' => $positionId,
                ]);
                $profile = $profileQuery->fetch();
                if (!$profile) {
                    $pdo->rollBack();
                    set_flash('error', 'That registered candidate profile is unavailable.');
                    ballot_builder_redirect($electionId);
                }
                $affiliation = isset($_POST['affiliation']) && is_string($_POST['affiliation'])
                    ? trim($_POST['affiliation'])
                    : '';
                if (strlen($affiliation) > 150 || strlen($campaignSlogan) > 200) {
                    $pdo->rollBack();
                    set_flash('error', 'Affiliation or campaign slogan exceeds its allowed length.');
                    ballot_builder_redirect($electionId);
                }
                $newPhoto = ballot_builder_store_photo($_FILES['photo'] ?? null);
                if ($newPhoto === false) {
                    $pdo->rollBack();
                    set_flash('error', 'Choose a valid JPG or PNG photo under 2 MB.');
                    ballot_builder_redirect($electionId);
                }
                $saveEntry = $pdo->prepare(
                    'UPDATE candidates SET manifesto = :manifesto, campaign_slogan = :campaign_slogan,
                        affiliation = :affiliation,
                        photo_path = COALESCE(:photo_path, photo_path)
                     WHERE id = :id AND election_id = :election_id AND position_id = :position_id'
                );
                $saveEntry->execute([
                    'manifesto' => $manifesto === '' ? null : $manifesto,
                    'campaign_slogan' => $campaignSlogan === '' ? null : $campaignSlogan,
                    'affiliation' => $affiliation === '' ? null : $affiliation,
                    'photo_path' => $newPhoto,
                    'id' => $entryId,
                    'election_id' => $electionId,
                    'position_id' => $positionId,
                ]);
                log_admin_action(
                    $pdo,
                    'ballot.candidate_updated',
                    'candidate',
                    $entryId,
                    'Updated candidate ballot profile.',
                    [
                        'manifesto' => $profile['manifesto'],
                        'campaign_slogan' => $profile['campaign_slogan'],
                        'affiliation' => $profile['affiliation'],
                        'photo_path' => $profile['photo_path'],
                    ],
                    [
                        'manifesto' => $manifesto === '' ? null : $manifesto,
                        'campaign_slogan' => $campaignSlogan === '' ? null : $campaignSlogan,
                        'affiliation' => $affiliation === '' ? null : $affiliation,
                        'photo_path' => $newPhoto ?? $profile['photo_path'],
                    ]
                );
            }
        }

        $pdo->commit();
        if (!$isOption && $entryId !== null && isset($newPhoto) && is_string($newPhoto)
            && isset($profile['photo_path'])) {
            ballot_builder_delete_photo($profile['photo_path']);
        }
        set_flash('success', $isOption ? 'Referendum option saved.' : 'Candidate profile saved.');
        ballot_builder_redirect($electionId);
    }

    set_flash('error', 'The requested ballot action is not available.');
    ballot_builder_redirect($electionId);
}

$elections = $pdo->query(
    'SELECT id, title, status, start_datetime, end_datetime, timezone, voting_system,
            verification_methods, allow_abstentions
     FROM elections ORDER BY id DESC'
)->fetchAll();
$requestedElectionId = ballot_builder_positive_int($_GET['election_id'] ?? '');
$selectedElection = null;
foreach ($elections as $electionRow) {
    if ($requestedElectionId !== false && (int) $electionRow['id'] === $requestedElectionId) {
        $selectedElection = $electionRow;
        break;
    }
}
if ($selectedElection === null && $elections !== []) {
    foreach ($elections as $electionRow) {
        if ($electionRow['status'] === 'open' && !ballot_builder_election_is_locked($electionRow)) {
            $selectedElection = $electionRow;
            break;
        }
    }
    $selectedElection = $selectedElection ?? $elections[0];
}

$positions = [];
$candidateAccounts = [];
$pendingCandidates = [];
$rejectedCandidates = [];
$selfNominationCount = 0;
$approvedBallotCount = 0;
if ($selectedElection !== null) {
    $positionQuery = $pdo->prepare(
        'SELECT id, title, contest_type, max_votes, randomize_candidates
         FROM positions WHERE election_id = :election_id ORDER BY id'
    );
    $positionQuery->execute(['election_id' => $selectedElection['id']]);
    $positions = $positionQuery->fetchAll();

    $entriesQuery = $pdo->prepare(
        'SELECT c.id, c.user_id, c.application_source, c.display_name, c.position_id,
                c.manifesto, c.campaign_slogan, c.rejection_feedback, c.photo_path,
                c.affiliation, c.status, u.full_name, u.email, u.reg_number
         FROM candidates c
         LEFT JOIN users u ON u.id = c.user_id
         WHERE c.election_id = :election_id
         ORDER BY c.position_id, COALESCE(u.full_name, c.display_name), c.id'
    );
    $entriesQuery->execute(['election_id' => $selectedElection['id']]);
    $entriesByPosition = [];
    foreach ($entriesQuery->fetchAll() as $entry) {
        $entriesByPosition[(int) $entry['position_id']][] = $entry;
    }
    foreach ($positions as &$positionRow) {
        $positionEntries = $entriesByPosition[(int) $positionRow['id']] ?? [];
        $approvedEntries = [];
        foreach ($positionEntries as $entry) {
            $entry['position_title'] = $positionRow['title'];
            if ($entry['user_id'] !== null && $entry['status'] === 'pending') {
                $pendingCandidates[] = $entry;
                if ($entry['application_source'] === 'self_registration') {
                    $selfNominationCount++;
                }
            } elseif ($entry['user_id'] !== null && $entry['status'] === 'rejected') {
                $rejectedCandidates[] = $entry;
            } else {
                $approvedEntries[] = $entry;
                $approvedBallotCount++;
            }
        }
        $positionRow['entries'] = $positionEntries;
        $positionRow['approved_entries'] = $approvedEntries;
    }
    unset($positionRow);

    $candidateAccounts = $pdo->query(
        'SELECT id, full_name, email FROM users
         WHERE role = \'candidate\' AND status IN (\'pending\', \'active\')
         ORDER BY full_name, id'
    )->fetchAll();
}

$activeTab = isset($_GET['tab']) && is_string($_GET['tab'])
    && in_array($_GET['tab'], ['pending', 'approved', 'rejected'], true)
    ? $_GET['tab']
    : ($pendingCandidates !== [] ? 'pending' : 'approved');
$isLocked = $selectedElection !== null && ballot_builder_election_is_locked($selectedElection);
$statusLabels = [
    'draft' => 'Draft',
    'open' => $selectedElection !== null && election_is_within_schedule($selectedElection) ? 'Live' : 'Scheduled',
    'paused' => 'Paused',
    'closed' => 'Closed',
];
$pageTitle = 'Manage candidates';
require __DIR__ . '/../includes/header.php';
?>
<section class="container ballot-builder">
    <header class="ballot-builder__header">
        <div>
            <p class="eyebrow">Election ballot</p>
            <h1>Manage Candidates</h1>
            <p>Build positions, candidate profiles, and referendum choices for voter ballots.</p>
        </div>
        <?php if ($elections !== []): ?>
            <form method="get" class="ballot-builder__election-picker">
                <label for="election-picker">Election</label>
                <select id="election-picker" name="election_id" onchange="this.form.submit()">
                    <?php foreach ($elections as $electionOption): ?>
                        <option value="<?= (int) $electionOption['id'] ?>"
                            <?= $selectedElection !== null && (int) $selectedElection['id'] === (int) $electionOption['id'] ? 'selected' : '' ?>>
                            <?= sanitize($electionOption['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="button button--outline" type="submit">Open election</button></noscript>
            </form>
        <?php endif; ?>
    </header>

    <?php if ($selectedElection === null): ?>
        <section class="card ballot-builder__empty">
            <div class="ballot-builder__empty-mark" aria-hidden="true">+</div>
            <h2>No elections to configure</h2>
            <p>Create an election first, then return here to build its ballot.</p>
            <a class="button button--primary" href="<?= BASE_URL ?>/admin/manage_elections.php">Manage elections</a>
        </section>
    <?php else: ?>
        <section class="card ballot-builder__election">
            <div>
                <p class="eyebrow">Selected election</p>
                <h2><?= sanitize($selectedElection['title']) ?></h2>
                <span class="badge badge--<?= sanitize($selectedElection['status']) ?>">
                    <?= sanitize($statusLabels[$selectedElection['status']] ?? ucfirst($selectedElection['status'])) ?>
                </span>
            </div>
            <div class="ballot-builder__actions">
                <button class="button button--outline" type="button" data-open-preview>Preview ballot</button>
                <button class="button button--primary" type="button" data-open-position
                    <?= $isLocked ? 'disabled' : '' ?>>+ Add Position</button>
            </div>
        </section>

        <?php if ($isLocked): ?>
            <aside class="alert alert--info ballot-builder__lock" role="status">
                <strong>Editing is locked.</strong>
                <?= $selectedElection['status'] === 'open' && election_is_within_schedule($selectedElection)
                    ? 'This election is live. Ballot changes are disabled to protect election integrity.'
                    : 'This election is paused or closed. Its ballot cannot be changed.' ?>
            </aside>
        <?php else: ?>
            <aside class="alert alert--info ballot-builder__lock" role="status">
                Add at least one candidate position to accept self-nominations. Applications open automatically for Draft
                and scheduled elections until poll opening, unless the optional nomination freeze deadline has passed.
                Candidate contests use registered candidate accounts. Referendum choices follow the election-wide
                <strong><?= sanitize(ucwords(str_replace('_', ' ', $selectedElection['voting_system']))) ?></strong>
                voting system.
            </aside>
        <?php endif; ?>

        <nav class="ballot-review-tabs" aria-label="Candidate status filters">
            <?php foreach ([
                'pending' => 'Pending Approval',
                'approved' => 'Approved Ballot',
                'rejected' => 'Rejected',
            ] as $tabKey => $tabLabel): ?>
                <?php
                $tabCount = match ($tabKey) {
                    'pending' => $selfNominationCount,
                    'approved' => $approvedBallotCount,
                    default => count($rejectedCandidates),
                };
                ?>
                <a class="ballot-review-tabs__link<?= $activeTab === $tabKey ? ' is-active' : '' ?>"
                    href="<?= BASE_URL ?>/admin/manage_candidates.php?election_id=<?= (int) $selectedElection['id'] ?>&amp;tab=<?= sanitize($tabKey) ?>"
                    <?= $activeTab === $tabKey ? 'aria-current="page"' : '' ?>>
                    <span><?= sanitize($tabLabel) ?></span>
                    <span class="ballot-review-tabs__count<?= $tabKey === 'pending' && $selfNominationCount > 0 ? ' ballot-review-tabs__count--pending' : '' ?>">
                        <?= $tabCount ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($activeTab === 'pending'): ?>
            <?php if ($pendingCandidates === []): ?>
                <section class="card ballot-builder__empty">
                    <div class="ballot-builder__empty-mark" aria-hidden="true">✓</div>
                    <h2>No candidates are awaiting approval</h2>
                    <p>New self-nominations and manually assigned candidate accounts will appear here for review.</p>
                </section>
            <?php else: ?>
                <section class="card candidate-review-queue" aria-labelledby="candidate-review-title">
                    <div class="candidate-review-queue__header">
                        <div>
                            <h2 id="candidate-review-title">Candidate vetting queue</h2>
                            <p><?= count($pendingCandidates) ?> application<?= count($pendingCandidates) === 1 ? '' : 's' ?> awaiting review</p>
                        </div>
                        <span class="badge badge--pending"><?= $selfNominationCount ?> new self-nomination<?= $selfNominationCount === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="candidate-review-table__scroll">
                        <table class="candidate-review-table">
                            <thead>
                                <tr>
                                    <th scope="col">Applicant</th>
                                    <th scope="col">ID number</th>
                                    <th scope="col">Position</th>
                                    <th scope="col">Party / slate</th>
                                    <th scope="col">Manifesto</th>
                                    <th scope="col">Review</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingCandidates as $entry): ?>
                                    <?php
                                    $entryName = (string) $entry['full_name'];
                                    $photoIsValid = is_string($entry['photo_path'])
                                        && preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $entry['photo_path']) === 1;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="candidate-review-table__applicant">
                                                <?php if ($photoIsValid): ?>
                                                    <img class="candidate-review-table__photo"
                                                        src="<?= BASE_URL ?>/<?= sanitize($entry['photo_path']) ?>"
                                                        alt="">
                                                <?php else: ?>
                                                    <span class="candidate-review-table__photo candidate-review-table__photo--empty"
                                                        aria-hidden="true"><?= sanitize(strtoupper(substr($entryName, 0, 1))) ?></span>
                                                <?php endif; ?>
                                                <span>
                                                    <strong><?= sanitize($entryName) ?></strong>
                                                    <small><?= $entry['application_source'] === 'self_registration' ? 'Self-nomination' : 'Admin-assigned' ?></small>
                                                </span>
                                            </div>
                                        </td>
                                        <td><?= sanitize((string) ($entry['reg_number'] ?? '—')) ?></td>
                                        <td><?= sanitize($entry['position_title']) ?></td>
                                        <td><?= sanitize((string) ($entry['affiliation'] ?: '—')) ?></td>
                                        <td>
                                            <?php if (is_string($entry['manifesto']) && trim($entry['manifesto']) !== ''): ?>
                                                <button class="button button--small button--outline" type="button"
                                                    data-read-manifesto
                                                    data-candidate-name="<?= sanitize($entryName) ?>"
                                                    data-position-title="<?= sanitize($entry['position_title']) ?>"
                                                    data-manifesto="<?= sanitize($entry['manifesto']) ?>">
                                                    Read Manifesto
                                                </button>
                                            <?php else: ?>
                                                <span class="form__hint">Not provided</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="candidate-review-table__actions">
                                                <form method="post">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="review_candidate">
                                                    <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
                                                    <input type="hidden" name="position_id" value="<?= (int) $entry['position_id'] ?>">
                                                    <input type="hidden" name="candidate_id" value="<?= (int) $entry['id'] ?>">
                                                    <input type="hidden" name="decision" value="approve">
                                                    <button class="button button--small candidate-review-button candidate-review-button--approve"
                                                        type="submit" <?= $isLocked ? 'disabled' : '' ?>
                                                        aria-label="Approve <?= sanitize($entryName) ?>">
                                                        <span aria-hidden="true">✓</span> Approve
                                                    </button>
                                                </form>
                                                <button class="button button--small candidate-review-button candidate-review-button--reject"
                                                    type="button" data-open-rejection
                                                    data-decision="reject"
                                                    data-entry-id="<?= (int) $entry['id'] ?>"
                                                    data-position-id="<?= (int) $entry['position_id'] ?>"
                                                    data-candidate-name="<?= sanitize($entryName) ?>"
                                                    <?= $isLocked ? 'disabled' : '' ?>>
                                                    <span aria-hidden="true">×</span> Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        <?php elseif ($activeTab === 'rejected'): ?>
            <?php if ($rejectedCandidates === []): ?>
                <section class="card ballot-builder__empty">
                    <div class="ballot-builder__empty-mark" aria-hidden="true">—</div>
                    <h2>No rejected applications</h2>
                    <p>Rejected candidate applications for this election will appear here.</p>
                </section>
            <?php else: ?>
                <section class="card candidate-review-queue" aria-labelledby="rejected-candidates-title">
                    <div class="candidate-review-queue__header">
                        <div>
                            <h2 id="rejected-candidates-title">Rejected applications</h2>
                            <p>Review the feedback sent to each applicant.</p>
                        </div>
                    </div>
                    <div class="candidate-review-table__scroll">
                        <table class="candidate-review-table">
                            <thead>
                                <tr>
                                    <th scope="col">Applicant</th>
                                    <th scope="col">ID number</th>
                                    <th scope="col">Position</th>
                                    <th scope="col">Party / slate</th>
                                    <th scope="col">Feedback</th>
                                    <th scope="col">Manifesto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rejectedCandidates as $entry): ?>
                                    <?php
                                    $entryName = (string) $entry['full_name'];
                                    $photoIsValid = is_string($entry['photo_path'])
                                        && preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $entry['photo_path']) === 1;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="candidate-review-table__applicant">
                                                <?php if ($photoIsValid): ?>
                                                    <img class="candidate-review-table__photo"
                                                        src="<?= BASE_URL ?>/<?= sanitize($entry['photo_path']) ?>"
                                                        alt="">
                                                <?php else: ?>
                                                    <span class="candidate-review-table__photo candidate-review-table__photo--empty"
                                                        aria-hidden="true"><?= sanitize(strtoupper(substr($entryName, 0, 1))) ?></span>
                                                <?php endif; ?>
                                                <strong><?= sanitize($entryName) ?></strong>
                                            </div>
                                        </td>
                                        <td><?= sanitize((string) ($entry['reg_number'] ?? '—')) ?></td>
                                        <td><?= sanitize($entry['position_title']) ?></td>
                                        <td><?= sanitize((string) ($entry['affiliation'] ?: '—')) ?></td>
                                        <td class="candidate-review-table__feedback">
                                            <?= nl2br(sanitize((string) ($entry['rejection_feedback'] ?? ''))) ?>
                                        </td>
                                        <td>
                                            <?php if (is_string($entry['manifesto']) && trim($entry['manifesto']) !== ''): ?>
                                                <button class="button button--small button--outline" type="button"
                                                    data-read-manifesto
                                                    data-candidate-name="<?= sanitize($entryName) ?>"
                                                    data-position-title="<?= sanitize($entry['position_title']) ?>"
                                                    data-manifesto="<?= sanitize($entry['manifesto']) ?>">
                                                    Read Manifesto
                                                </button>
                                            <?php else: ?>
                                                <span class="form__hint">Not provided</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        <?php elseif ($positions === []): ?>
            <section class="card ballot-builder__empty">
                <div class="ballot-builder__empty-mark" aria-hidden="true">01</div>
                <h2>Your ballot is ready to take shape</h2>
                <p>Add a position or referendum to start organizing this election.</p>
                <button class="button button--primary" type="button" data-open-position
                    <?= $isLocked ? 'disabled' : '' ?>>+ Add Position</button>
            </section>
        <?php else: ?>
            <div class="ballot-builder__positions">
                <?php foreach ($positions as $position): ?>
                    <?php $entries = $position['approved_entries']; ?>
                    <details class="ballot-position" open>
                        <summary class="ballot-position__summary">
                            <span class="ballot-position__heading">
                                <span class="ballot-position__kind"><?= $position['contest_type'] === 'referendum' ? 'Referendum' : 'Position' ?></span>
                                <strong><?= sanitize($position['title']) ?></strong>
                            </span>
                            <span class="badge badge--info">
                                <?= $selectedElection['voting_system'] === 'ranked_choice'
                            ? 'Rank choices'
                            : ($selectedElection['voting_system'] === 'fptp'
                                ? 'Vote for 1'
                                : 'Select up to ' . (int) $position['max_votes']) ?>
                            </span>
                        </summary>
                        <div class="ballot-position__body">
                            <div class="ballot-position__toolbar">
                                <form method="post" class="ballot-position__randomize">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_randomize">
                                    <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
                                    <input type="hidden" name="position_id" value="<?= (int) $position['id'] ?>">
                                    <input type="hidden" name="randomize" value="0">
                                    <label>
                                        <input type="checkbox" name="randomize" value="1"
                                            <?= (int) $position['randomize_candidates'] === 1 ? 'checked' : '' ?>
                                            <?= $isLocked ? 'disabled' : '' ?>
                                            onchange="this.form.querySelector('input[type=hidden][name=randomize]').value=this.checked?'1':'0';this.form.submit()">
                                        Randomize entry order
                                    </label>
                                </form>
                                <div class="ballot-position__controls">
                                    <button class="button button--small button--outline" type="button" data-edit-position
                                        data-position-id="<?= (int) $position['id'] ?>"
                                        data-title="<?= sanitize($position['title']) ?>"
                                        data-type="<?= sanitize($position['contest_type']) ?>"
                                        data-max-votes="<?= (int) $position['max_votes'] ?>"
                                        <?= $isLocked ? 'disabled' : '' ?>>Edit</button>
                                    <form method="post" onsubmit="return confirm('Delete this contest and its entries?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_position">
                                        <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
                                        <input type="hidden" name="position_id" value="<?= (int) $position['id'] ?>">
                                        <button class="button button--small button--outline" type="submit"
                                            <?= $isLocked ? 'disabled' : '' ?>>Delete</button>
                                    </form>
                                </div>
                            </div>
                            <?php if ($entries === []): ?>
                                <p class="ballot-position__empty">No entries have been added to this contest yet.</p>
                            <?php else: ?>
                                <div class="candidate-builder-grid">
                                    <?php foreach ($entries as $entry): ?>
                                        <?php
                                        $entryName = $entry['user_id'] === null
                                            ? (string) $entry['display_name']
                                            : (string) $entry['full_name'];
                                        $entryStatus = $entry['user_id'] === null ? 'approved' : $entry['status'];
                                        ?>
                                        <article class="candidate-builder-card">
                                            <?php if (is_string($entry['photo_path']) && $entry['photo_path'] !== ''): ?>
                                                <img class="candidate-builder-card__photo"
                                                    src="<?= BASE_URL ?>/<?= sanitize($entry['photo_path']) ?>"
                                                    alt="<?= sanitize($entryName) ?>">
                                            <?php else: ?>
                                                <div class="candidate-builder-card__photo candidate-builder-card__photo--empty"
                                                    aria-hidden="true"><?= sanitize(strtoupper(substr($entryName, 0, 1))) ?></div>
                                            <?php endif; ?>
                                            <div class="candidate-builder-card__content">
                                                <div class="candidate-builder-card__title">
                                                    <h3><?= sanitize($entryName) ?></h3>
                                                    <span class="badge badge--<?= sanitize($entryStatus) ?>">
                                                        <?= sanitize(ucfirst($entryStatus)) ?>
                                                    </span>
                                                </div>
                                                <?php if (is_string($entry['affiliation']) && $entry['affiliation'] !== ''): ?>
                                                    <p class="candidate-builder-card__affiliation"><?= sanitize($entry['affiliation']) ?></p>
                                                <?php elseif (is_string($entry['email']) && $entry['email'] !== ''): ?>
                                                    <p class="candidate-builder-card__affiliation"><?= sanitize($entry['email']) ?></p>
                                                <?php endif; ?>
                                                <?php if (is_string($entry['campaign_slogan']) && $entry['campaign_slogan'] !== ''): ?>
                                                    <p><?= sanitize($entry['campaign_slogan']) ?></p>
                                                <?php endif; ?>
                                                <?php if (is_string($entry['manifesto']) && $entry['manifesto'] !== ''): ?>
                                                    <p class="candidate-builder-card__manifesto"><?= sanitize($entry['manifesto']) ?></p>
                                                <?php endif; ?>
                                                <div class="candidate-builder-card__actions">
                                                    <button class="button button--small button--outline" type="button"
                                                        <?= $position['contest_type'] === 'referendum' ? 'data-edit-option' : 'data-edit-candidate' ?>
                                                        data-entry-id="<?= (int) $entry['id'] ?>"
                                                        data-position-id="<?= (int) $position['id'] ?>"
                                                        data-name="<?= sanitize($entryName) ?>"
                                                        data-affiliation="<?= sanitize((string) ($entry['affiliation'] ?? '')) ?>"
                                                        data-campaign-slogan="<?= sanitize((string) ($entry['campaign_slogan'] ?? '')) ?>"
                                                        data-manifesto="<?= sanitize((string) ($entry['manifesto'] ?? '')) ?>"
                                                        <?= $isLocked ? 'disabled' : '' ?>>Edit</button>
                                                        <?php if ($entry['user_id'] !== null): ?>
                                                            <button class="button button--small candidate-review-button candidate-review-button--reject"
                                                                type="button" data-open-rejection
                                                                data-decision="disqualify"
                                                                data-entry-id="<?= (int) $entry['id'] ?>"
                                                                data-position-id="<?= (int) $position['id'] ?>"
                                                                data-candidate-name="<?= sanitize($entryName) ?>"
                                                                <?= $isLocked ? 'disabled' : '' ?>>
                                                                Disqualify
                                                            </button>
                                                        <?php else: ?>
                                                            <form method="post" onsubmit="return confirm('Remove this ballot option?');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete_entry">
                                                                <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
                                                                <input type="hidden" name="position_id" value="<?= (int) $position['id'] ?>">
                                                                <input type="hidden" name="candidate_id" value="<?= (int) $entry['id'] ?>">
                                                                <button class="button button--small button--outline" type="submit"
                                                                    <?= $isLocked ? 'disabled' : '' ?>>Remove</button>
                                                            </form>
                                                        <?php endif; ?>
                                                </div>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <button class="button button--outline ballot-position__add" type="button"
                                <?= $position['contest_type'] === 'referendum' ? 'data-add-option' : 'data-add-candidate' ?>
                                data-position-id="<?= (int) $position['id'] ?>"
                                data-position-title="<?= sanitize($position['title']) ?>"
                                <?= $isLocked ? 'disabled' : '' ?>>
                                <?= $position['contest_type'] === 'referendum' ? '+ Add Referendum Option' : '+ Add Candidate Manually' ?>
                            </button>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($selectedElection !== null): ?>
<dialog class="ballot-builder__dialog" id="position-dialog" aria-labelledby="position-dialog-title">
    <form method="post" class="form-card ballot-builder__dialog-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_position">
        <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
        <input type="hidden" name="position_id" value="">
        <div class="ballot-builder__dialog-heading">
            <h2 id="position-dialog-title">Add a contest</h2>
            <button type="button" class="button button--small button--outline" data-close-dialog aria-label="Close">Close</button>
        </div>
        <label for="position-title">Position or referendum title</label>
        <input id="position-title" name="title" maxlength="150" required>
        <label for="position-type">Contest type</label>
        <select id="position-type" name="contest_type" required>
            <option value="candidate">Candidate position</option>
            <option value="referendum">Referendum</option>
        </select>
        <label for="position-max-votes">Maximum selections per voter</label>
        <input id="position-max-votes" name="max_votes" type="number" min="1" max="100" value="1" required>
        <div class="ballot-builder__dialog-actions">
            <button class="button button--primary" type="submit">Save Contest</button>
        </div>
    </form>
</dialog>

<dialog class="ballot-builder__dialog" id="entry-dialog" aria-labelledby="entry-dialog-title">
    <form method="post" enctype="multipart/form-data" class="form-card ballot-builder__dialog-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_candidate">
        <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
        <input type="hidden" name="position_id" value="">
        <input type="hidden" name="candidate_id" value="">
        <div class="ballot-builder__dialog-heading">
            <div>
                <p class="eyebrow" data-entry-subtitle>Registered candidate account</p>
                <h2 id="entry-dialog-title">Add candidate</h2>
            </div>
            <button type="button" class="button button--small button--outline" data-close-dialog aria-label="Close">Close</button>
        </div>
        <div data-account-field>
            <label for="candidate-user">Registered candidate</label>
            <select id="candidate-user" name="user_id">
                <option value="">Select candidate account</option>
                <?php foreach ($candidateAccounts as $account): ?>
                    <option value="<?= (int) $account['id'] ?>">
                        <?= sanitize($account['full_name'] . ' (' . $account['email'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form__hint">Candidate applications remain pending until an administrator approves them.</p>
        </div>
        <div data-candidate-fields>
            <label for="candidate-affiliation">Party or slate affiliation</label>
            <input id="candidate-affiliation" name="affiliation" maxlength="150">
            <label for="candidate-slogan">Campaign slogan</label>
            <input id="candidate-slogan" name="campaign_slogan" maxlength="200">
            <label for="candidate-photo">Profile photo (JPG or PNG; up to 2 MB)</label>
            <input id="candidate-photo" name="photo" type="file" accept="image/jpeg,image/png">
        </div>
        <label for="candidate-manifesto">Manifesto or description</label>
        <textarea id="candidate-manifesto" name="manifesto" rows="5" maxlength="65535"></textarea>
        <div class="ballot-builder__dialog-actions">
            <button class="button button--primary" type="submit">Save Candidate</button>
        </div>
    </form>
</dialog>

<dialog class="ballot-builder__dialog" id="option-dialog" aria-labelledby="option-dialog-title">
    <form method="post" class="form-card ballot-builder__dialog-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_option">
        <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
        <input type="hidden" name="position_id" value="">
        <input type="hidden" name="candidate_id" value="">
        <div class="ballot-builder__dialog-heading">
            <div>
                <p class="eyebrow">Referendum choice</p>
                <h2 id="option-dialog-title">Add option</h2>
            </div>
            <button type="button" class="button button--small button--outline" data-close-dialog aria-label="Close">Close</button>
        </div>
        <label for="option-name">Choice label</label>
        <input id="option-name" name="display_name" maxlength="150" required>
        <label for="option-description">Description (optional)</label>
        <textarea id="option-description" name="manifesto" rows="4" maxlength="65535"></textarea>
        <p class="form__hint">Voters will see choices using the election’s configured voting system.</p>
        <div class="ballot-builder__dialog-actions">
            <button class="button button--primary" type="submit">Save Option</button>
        </div>
    </form>
</dialog>

<dialog class="ballot-builder__dialog ballot-builder__dialog--preview" id="preview-dialog" aria-labelledby="preview-title">
    <div class="form-card ballot-builder__dialog-form">
        <div class="ballot-builder__dialog-heading">
            <div>
                <p class="eyebrow">Voter-facing preview</p>
                <h2 id="preview-title"><?= sanitize($selectedElection['title']) ?></h2>
            </div>
            <button type="button" class="button button--small button--outline" data-close-dialog>Close preview</button>
        </div>
        <p>Voting system: <strong><?= sanitize(ucwords(str_replace('_', ' ', $selectedElection['voting_system']))) ?></strong></p>
        <?php foreach ($positions as $position): ?>
            <section class="ballot-preview__contest">
                <h3><?= sanitize($position['title']) ?></h3>
                <p class="form__hint">
                    <?= $selectedElection['voting_system'] === 'ranked_choice'
                        ? 'Rank the choices in your preferred order.'
                        : ($selectedElection['voting_system'] === 'fptp'
                            ? 'Select one option.'
                            : 'Select up to ' . (int) $position['max_votes'] . ' option(s).') ?>
                </p>
                <?php if ($position['entries'] === []): ?>
                    <p class="ballot-position__empty">No approved options are available.</p>
                <?php else: ?>
                    <div class="ballot-preview__choices">
                        <?php
                        $previewEntries = $position['entries'];
                        if ((int) $position['randomize_candidates'] === 1) {
                            shuffle($previewEntries);
                        }
                        foreach ($previewEntries as $entry):
                        ?>
                            <?php
                            $previewName = $entry['user_id'] === null
                                ? (string) $entry['display_name']
                                : (string) $entry['full_name'];
                            if ($entry['user_id'] !== null && $entry['status'] !== 'approved') {
                                continue;
                            }
                            ?>
                            <label class="ballot-preview__choice">
                                <?php if ($selectedElection['voting_system'] === 'ranked_choice'): ?>
                                    <select disabled aria-label="Rank <?= sanitize($previewName) ?>">
                                        <option>Rank</option>
                                    </select>
                                <?php elseif ($selectedElection['voting_system'] === 'multiple_selection'): ?>
                                    <input type="checkbox" disabled aria-label="<?= sanitize($previewName) ?>">
                                <?php else: ?>
                                    <input type="radio" disabled aria-label="<?= sanitize($previewName) ?>">
                                <?php endif; ?>
                                <span>
                                    <?php if (
                                        $selectedElection['voting_system'] === 'fptp'
                                        && is_string($entry['photo_path'])
                                        && preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $entry['photo_path']) === 1
                                    ): ?>
                                        <img class="ballot-preview__photo" src="<?= BASE_URL ?>/<?= sanitize($entry['photo_path']) ?>"
                                            alt="<?= sanitize($previewName) ?>">
                                    <?php endif; ?>
                                    <strong><?= sanitize($previewName) ?></strong>
                                    <?php if (is_string($entry['affiliation']) && $entry['affiliation'] !== ''): ?>
                                        <small><?= sanitize($entry['affiliation']) ?></small>
                                    <?php endif; ?>
                                    <?php if (is_string($entry['campaign_slogan']) && $entry['campaign_slogan'] !== ''): ?>
                                        <small><?= sanitize($entry['campaign_slogan']) ?></small>
                                    <?php endif; ?>
                                    <?php if (
                                        $entry['manifesto'] !== null
                                        && $entry['manifesto'] !== ''
                                        && $selectedElection['voting_system'] !== 'ranked_choice'
                                    ): ?>
                                        <small><?= nl2br(sanitize($entry['manifesto'])) ?></small>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($selectedElection['allow_abstentions']): ?>
                    <label class="ballot-preview__choice">
                        <input type="<?= $selectedElection['voting_system'] === 'fptp' ? 'radio' : 'checkbox' ?>"
                            disabled aria-label="Abstain from <?= sanitize($position['title']) ?>">
                        <span><strong>Abstain from this contest</strong></span>
                    </label>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
</dialog>

<dialog class="ballot-builder__dialog" id="manifesto-dialog" aria-labelledby="manifesto-dialog-title">
    <div class="form-card ballot-builder__dialog-form">
        <div class="ballot-builder__dialog-heading">
            <div>
                <p class="eyebrow" data-manifesto-position></p>
                <h2 id="manifesto-dialog-title" data-manifesto-name>Candidate manifesto</h2>
            </div>
            <button type="button" class="button button--small button--outline" data-close-dialog>Close</button>
        </div>
        <div class="candidate-manifesto-dialog__content" data-manifesto-content></div>
    </div>
</dialog>

<dialog class="ballot-builder__dialog" id="rejection-dialog" aria-labelledby="rejection-dialog-title">
    <form method="post" class="form-card ballot-builder__dialog-form" data-rejection-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="review_candidate">
        <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
        <input type="hidden" name="position_id" value="">
        <input type="hidden" name="candidate_id" value="">
        <input type="hidden" name="decision" value="reject">
        <div class="ballot-builder__dialog-heading">
            <div>
                <p class="eyebrow">Candidate review</p>
                <h2 id="rejection-dialog-title" data-rejection-title>Reject application</h2>
            </div>
            <button type="button" class="button button--small button--outline" data-close-dialog>Cancel</button>
        </div>
        <p data-rejection-candidate></p>
        <label for="candidate-review-feedback">Feedback for the candidate <span>(required)</span></label>
        <textarea id="candidate-review-feedback" name="rejection_feedback" maxlength="2000"
            rows="5" placeholder="Explain what needs to be corrected" required
            data-rejection-feedback></textarea>
        <p class="form__hint">This feedback will be included in the decision email.</p>
        <div class="ballot-builder__dialog-actions">
            <button class="button button--primary" type="submit" data-rejection-submit>Confirm rejection</button>
        </div>
    </form>
</dialog>

<script src="<?= BASE_URL ?>/assets/js/ballot-builder.js" defer></script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
