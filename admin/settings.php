<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_time.php';
require_once __DIR__ . '/../includes/election_scheduler.php';
require_once __DIR__ . '/../includes/rich_text.php';

function election_settings_positive_int($value): int|false
{
    if (!is_string($value) && !is_int($value)) {
        return false;
    }

    $number = filter_var((string) $value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    return $number === false ? false : (int) $number;
}

function election_settings_has_ballot_activity(PDO $pdo, int $electionId): bool
{
    $query = $pdo->prepare(
        'SELECT
            EXISTS(SELECT 1 FROM ballot_submissions WHERE election_id = :submission_id)
            OR EXISTS(SELECT 1 FROM votes WHERE election_id = :vote_id)'
    );
    $query->execute(['submission_id' => $electionId, 'vote_id' => $electionId]);

    return (bool) $query->fetchColumn();
}

function election_settings_can_edit(array $election, bool $hasBallotActivity): bool
{
    if ($hasBallotActivity) {
        return false;
    }

    if ($election['status'] === 'draft') {
        return true;
    }

    return $election['status'] === 'open' && election_is_upcoming($election);
}

function election_settings_redirect(int $electionId): void
{
    redirect(BASE_URL . '/admin/settings.php?election_id=' . $electionId);
}

function election_settings_delete_candidate_photo(?string $path): void
{
    if (
        $path === null
        || preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $path) !== 1
    ) {
        return;
    }

    $fullPath = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($fullPath) && !unlink($fullPath)) {
        throw new RuntimeException('Unable to remove a candidate photo after deleting its election.');
    }
}

function election_settings_delete_logo(?string $path): void
{
    if (
        $path === null
        || preg_match('~\Aassets/img/elections/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $path) !== 1
    ) {
        return;
    }

    $fullPath = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($fullPath) && !unlink($fullPath)) {
        throw new RuntimeException('Unable to remove an election logo after deleting its election.');
    }
}

activate_due_auto_elections($pdo);

if (is_post()) {
    verify_csrf();

    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $electionId = election_settings_positive_int($_POST['election_id'] ?? '');
    if ($electionId === false) {
        set_flash('error', 'Choose a valid election.');
        redirect(BASE_URL . '/admin/settings.php');
    }

    if ($action === 'save_settings') {
        $title = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
        $descriptionValid = isset($_POST['description']) && is_string($_POST['description']);
        $supportEmailValid = isset($_POST['support_email']) && is_string($_POST['support_email']);
        $rawDescription = isset($_POST['description']) && is_string($_POST['description'])
            ? trim($_POST['description'])
            : '';
        $supportEmail = isset($_POST['support_email']) && is_string($_POST['support_email'])
            ? trim($_POST['support_email'])
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
        $rawMethods = $_POST['verification_methods'] ?? [];
        $allowedMethods = ['email_token', 'voter_pin'];
        $methodsValid = is_array($rawMethods);
        if ($methodsValid) {
            foreach ($rawMethods as $method) {
                if (!is_string($method) || !in_array($method, $allowedMethods, true)) {
                    $methodsValid = false;
                    break;
                }
            }
        }
        $verificationMethods = $methodsValid
            ? array_values(array_intersect($allowedMethods, array_unique($rawMethods)))
            : [];
        $allowAbstentionsRaw = $_POST['allow_abstentions'] ?? null;
        $allowAbstentionsValid = is_string($allowAbstentionsRaw)
            && in_array($allowAbstentionsRaw, ['0', '1'], true);
        $allowAbstentions = $allowAbstentionsRaw === '1';
        $description = sanitize_rich_text($rawDescription);

        if (
            $title === ''
            || strlen($title) > 200
            || strlen($rawDescription) > 100000
            || strlen($description) > 100000
            || !$descriptionValid
            || !$supportEmailValid
            || !$allowAbstentionsValid
            || ($supportEmail !== '' && (
                strlen($supportEmail) > 254
                || filter_var($supportEmail, FILTER_VALIDATE_EMAIL) === false
            ))
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
            || !$methodsValid
        ) {
            set_flash('error', 'Check the title, instructions, support email, schedule, nomination deadline, timezone, voting method, and verification settings.');
            election_settings_redirect($electionId);
        }

        $description = $description === '' ? null : $description;
        $supportEmail = $supportEmail === '' ? null : $supportEmail;
        $startValue = $startDate->format('Y-m-d H:i:s');
        $endValue = $endDate->format('Y-m-d H:i:s');
        $nominationValue = $nominationDeadline?->format('Y-m-d H:i:s');
        $methodsValue = implode(',', $verificationMethods);

        try {
            $pdo->beginTransaction();
            $electionQuery = $pdo->prepare(
                'SELECT id, title, description, support_email, start_datetime, end_datetime,
                        timezone, nomination_deadline, status, voting_system,
                        verification_methods, allow_abstentions
                 FROM elections WHERE id = :election_id FOR UPDATE'
            );
            $electionQuery->execute(['election_id' => $electionId]);
            $election = $electionQuery->fetch();
            if (
                !$election
                || !election_settings_can_edit(
                    $election,
                    election_settings_has_ballot_activity($pdo, $electionId)
                )
            ) {
                $pdo->rollBack();
                set_flash('error', 'Election settings are locked after voting begins.');
                election_settings_redirect($electionId);
            }

            $existingMethods = array_values(array_filter(explode(',', $election['verification_methods'])));
            $newValues = [
                'title' => $title,
                'description' => $description,
                'support_email' => $supportEmail,
                'start_datetime' => $startValue,
                'end_datetime' => $endValue,
                'nomination_deadline' => $nominationValue,
                'timezone' => $timezone,
                'voting_system' => $votingSystem,
                'verification_methods' => $methodsValue,
                'allow_abstentions' => $allowAbstentions ? 1 : 0,
            ];
            $oldValues = [
                'title' => $election['title'],
                'description' => $election['description'],
                'support_email' => $election['support_email'],
                'start_datetime' => $election['start_datetime'],
                'end_datetime' => $election['end_datetime'],
                'nomination_deadline' => $election['nomination_deadline'],
                'timezone' => $election['timezone'],
                'voting_system' => $election['voting_system'],
                'verification_methods' => implode(',', $existingMethods),
                'allow_abstentions' => (int) $election['allow_abstentions'],
            ];
            $changedFields = [];
            foreach ($newValues as $field => $value) {
                if ($value !== $oldValues[$field]) {
                    $changedFields[] = $field;
                }
            }

            if ($changedFields !== []) {
                $save = $pdo->prepare(
                    'UPDATE elections
                     SET title = :title, description = :description, support_email = :support_email,
                         start_datetime = :start_datetime, end_datetime = :end_datetime,
                         nomination_deadline = :nomination_deadline, timezone = :timezone,
                         voting_system = :voting_system,
                         verification_methods = :verification_methods, allow_abstentions = :allow_abstentions
                     WHERE id = :election_id'
                );
                $save->execute($newValues + ['election_id' => $electionId]);
                $fieldLabels = [
                    'title' => 'election title updated',
                    'description' => 'voter instructions updated',
                    'support_email' => 'support email updated',
                    'start_datetime' => 'poll opening time',
                    'end_datetime' => 'poll closing time',
                    'nomination_deadline' => 'nomination freeze deadline',
                    'timezone' => 'timezone updated',
                    'voting_system' => 'voting system updated',
                    'verification_methods' => 'verification methods updated',
                    'allow_abstentions' => 'abstention rule updated',
                ];
                $changeDescriptions = [];
                foreach ($changedFields as $field) {
                    if (in_array($field, ['start_datetime', 'end_datetime', 'nomination_deadline'], true)) {
                        $changeDescriptions[] = $fieldLabels[$field] . ' updated';
                    } else {
                        $changeDescriptions[] = $fieldLabels[$field] ?? ($field . ' updated');
                    }
                }
                $auditSummary = 'Updated election settings: ' . implode('; ', $changeDescriptions) . '.';
                if (strlen($auditSummary) > 255) {
                    $auditSummary = substr($auditSummary, 0, 252) . '...';
                }
                log_admin_action(
                    $pdo,
                    'election.settings_updated',
                    'election',
                    $electionId,
                    $auditSummary,
                    array_intersect_key($oldValues, array_flip($changedFields)),
                    array_intersect_key($newValues, array_flip($changedFields))
                );
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        set_flash(
            $changedFields === [] ? 'info' : 'success',
            $changedFields === [] ? 'No settings were changed.' : 'Election settings saved and recorded in Audit logs.'
        );
        election_settings_redirect($electionId);
    }

    if (in_array($action, ['launch_election', 'pause_election', 'resume_election', 'close_election'], true)) {
        if (($_POST['confirmation'] ?? '') !== 'yes') {
            set_flash('error', 'Confirm the election lifecycle action before submitting.');
            election_settings_redirect($electionId);
        }

        $pdo->beginTransaction();
        $electionQuery = $pdo->prepare(
            'SELECT id, title, status, start_datetime, end_datetime, timezone
             FROM elections WHERE id = :election_id FOR UPDATE'
        );
        $electionQuery->execute(['election_id' => $electionId]);
        $election = $electionQuery->fetch();
        $hasBallotActivity = $election && election_settings_has_ballot_activity($pdo, $electionId);
        $valid = false;
        $newStatus = '';

        if (
            $election
            && $action === 'launch_election'
            && $election['status'] === 'draft'
            && !$hasBallotActivity
            && election_has_not_closed($election)
        ) {
            $positionCount = $pdo->prepare('SELECT COUNT(*) FROM positions WHERE election_id = :election_id');
            $positionCount->execute(['election_id' => $electionId]);
            $valid = (int) $positionCount->fetchColumn() > 0;
            $newStatus = 'open';
        } elseif (
            $election
            && $action === 'pause_election'
            && $election['status'] === 'open'
            && election_is_within_schedule($election)
        ) {
            $valid = true;
            $newStatus = 'paused';
        } elseif (
            $election
            && $action === 'resume_election'
            && $election['status'] === 'paused'
            && election_has_not_closed($election)
        ) {
            $valid = true;
            $newStatus = 'open';
        } elseif (
            $election
            && $action === 'close_election'
            && in_array($election['status'], ['open', 'paused'], true)
        ) {
            $valid = true;
            $newStatus = 'closed';
        }

        if (!$valid) {
            $pdo->rollBack();
            set_flash('error', 'The election state or ballot content does not allow that action.');
            election_settings_redirect($electionId);
        }

        if ($newStatus === 'closed') {
            $eligibleVoterQuery = $pdo->prepare(
                "SELECT COUNT(*) FROM users WHERE role = 'voter' AND status = 'active'"
            );
            $eligibleVoterQuery->execute();
            $eligibleVotersAtClose = (int) $eligibleVoterQuery->fetchColumn();
            $update = $pdo->prepare(
                'UPDATE elections
                 SET status = :status, closed_at = UTC_TIMESTAMP(),
                     eligible_voter_count = :eligible_voter_count
                 WHERE id = :election_id'
            );
            $update->execute([
                'status' => $newStatus,
                'eligible_voter_count' => $eligibleVotersAtClose,
                'election_id' => $electionId,
            ]);
        } else {
            $update = $pdo->prepare('UPDATE elections SET status = :status WHERE id = :election_id');
            $update->execute(['status' => $newStatus, 'election_id' => $electionId]);
        }
        $auditAfterStatus = ['status' => $newStatus];
        if ($newStatus === 'closed') {
            $auditAfterStatus['eligible_voter_count'] = $eligibleVotersAtClose;
        }
        $auditAction = [
            'launch_election' => 'election.open',
            'pause_election' => 'election.paused',
            'resume_election' => 'election.resumed',
            'close_election' => 'election.closed',
        ][$action];
        $auditSummary = [
            'launch_election' => 'Launched election from Settings.',
            'pause_election' => 'Paused voting from Settings.',
            'resume_election' => 'Resumed voting from Settings.',
            'close_election' => 'Closed polls early from Settings.',
        ][$action];
        log_admin_action(
            $pdo,
            $auditAction,
            'election',
            $electionId,
            $auditSummary,
            ['status' => $election['status']],
            $auditAfterStatus
        );
        $pdo->commit();

        set_flash('success', $auditSummary);
        election_settings_redirect($electionId);
    }

    if ($action === 'reset_test_votes') {
        if (($_POST['confirmation_phrase'] ?? '') !== 'RESET') {
            set_flash('error', 'Type RESET in the confirmation dialog to clear test ballots.');
            election_settings_redirect($electionId);
        }

        $pdo->beginTransaction();
        $electionQuery = $pdo->prepare(
            'SELECT status FROM elections WHERE id = :election_id FOR UPDATE'
        );
        $electionQuery->execute(['election_id' => $electionId]);
        $election = $electionQuery->fetch();
        if (!$election || $election['status'] !== 'draft') {
            $pdo->rollBack();
            set_flash('error', 'Test ballots can only be reset while the election is in Draft.');
            election_settings_redirect($electionId);
        }

        $voteCountQuery = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE election_id = :election_id');
        $voteCountQuery->execute(['election_id' => $electionId]);
        $voteCount = (int) $voteCountQuery->fetchColumn();
        $submissionCountQuery = $pdo->prepare(
            'SELECT COUNT(*) FROM ballot_submissions WHERE election_id = :election_id'
        );
        $submissionCountQuery->execute(['election_id' => $electionId]);
        $submissionCount = (int) $submissionCountQuery->fetchColumn();
        if ($voteCount === 0 && $submissionCount === 0) {
            $pdo->rollBack();
            set_flash('info', 'This draft election has no test ballots to reset.');
            election_settings_redirect($electionId);
        }

        $deleteVotes = $pdo->prepare('DELETE FROM votes WHERE election_id = :election_id');
        $deleteVotes->execute(['election_id' => $electionId]);
        $deleteSubmissions = $pdo->prepare(
            'DELETE FROM ballot_submissions WHERE election_id = :election_id'
        );
        $deleteSubmissions->execute(['election_id' => $electionId]);
        log_admin_action(
            $pdo,
            'election.test_votes_reset',
            'election',
            $electionId,
            'Reset ' . $voteCount . ' test votes and ' . $submissionCount . ' ballot submissions.',
            ['votes' => $voteCount, 'ballot_submissions' => $submissionCount],
            ['votes' => 0, 'ballot_submissions' => 0]
        );
        $pdo->commit();
        set_flash('success', 'Draft test votes and ballot submissions were reset.');
        election_settings_redirect($electionId);
    }

    if ($action === 'delete_election') {
        $confirmationPhrase = isset($_POST['confirmation_phrase']) && is_string($_POST['confirmation_phrase'])
            ? $_POST['confirmation_phrase']
            : '';
        $pdo->beginTransaction();
        $electionQuery = $pdo->prepare(
            'SELECT id, title, status, organization_logo_path
             FROM elections WHERE id = :election_id FOR UPDATE'
        );
        $electionQuery->execute(['election_id' => $electionId]);
        $election = $electionQuery->fetch();
        if (
            !$election
            || in_array($election['status'], ['open', 'paused'], true)
            || $confirmationPhrase !== $election['title']
            || election_settings_has_ballot_activity($pdo, $electionId)
        ) {
            $pdo->rollBack();
            set_flash('error', 'The election cannot be deleted. Close it, clear draft test ballots, and confirm its exact title.');
            election_settings_redirect($electionId);
        }

        $photoQuery = $pdo->prepare(
            'SELECT photo_path FROM candidates WHERE election_id = :election_id'
        );
        $photoQuery->execute(['election_id' => $electionId]);
        $candidatePhotos = $photoQuery->fetchAll(PDO::FETCH_COLUMN);
        $delete = $pdo->prepare('DELETE FROM elections WHERE id = :election_id');
        $delete->execute(['election_id' => $electionId]);
        log_admin_action(
            $pdo,
            'election.deleted',
            'election',
            $electionId,
            'Deleted election from Settings.',
            ['title' => $election['title'], 'status' => $election['status']],
            null
        );
        $pdo->commit();

        election_settings_delete_logo($election['organization_logo_path']);
        foreach ($candidatePhotos as $photoPath) {
            election_settings_delete_candidate_photo(is_string($photoPath) ? $photoPath : null);
        }
        set_flash('success', 'Election deleted.');
        redirect(BASE_URL . '/admin/settings.php');
    }

    set_flash('error', 'The requested Settings action is unavailable.');
    election_settings_redirect($electionId);
}

$elections = $pdo->query(
    'SELECT id, title, description, support_email, start_datetime, end_datetime, timezone,
            nomination_deadline,
            status, voting_system, verification_methods, allow_abstentions, organization_logo_path
     FROM elections ORDER BY id DESC'
)->fetchAll();
$requestedElectionId = election_settings_positive_int($_GET['election_id'] ?? '');
$selectedElection = null;
if ($requestedElectionId !== false) {
    foreach ($elections as $electionRow) {
        if ((int) $electionRow['id'] === $requestedElectionId) {
            $selectedElection = $electionRow;
            break;
        }
    }
}
if ($selectedElection === null && $elections !== []) {
    foreach ($elections as $electionRow) {
        if ($electionRow['status'] === 'open' && election_is_within_schedule($electionRow)) {
            $selectedElection = $electionRow;
            break;
        }
    }
    if ($selectedElection === null) {
        foreach ($elections as $electionRow) {
            if ($electionRow['status'] === 'open' && election_is_upcoming($electionRow)) {
                $selectedElection = $electionRow;
                break;
            }
        }
    }
    if ($selectedElection === null) {
        foreach ($elections as $electionRow) {
            if ($electionRow['status'] === 'draft') {
                $selectedElection = $electionRow;
                break;
            }
        }
    }
    $selectedElection = $selectedElection ?? $elections[0];
}

$hasBallotActivity = $selectedElection !== null
    && election_settings_has_ballot_activity($pdo, (int) $selectedElection['id']);
$canEditSettings = $selectedElection !== null
    && election_settings_can_edit($selectedElection, $hasBallotActivity);
$isLive = $selectedElection !== null
    && $selectedElection['status'] === 'open'
    && election_is_within_schedule($selectedElection);
$statusLabels = [
    'draft' => 'Draft',
    'paused' => 'Paused',
    'closed' => 'Closed',
    'open' => $isLive
        ? 'Live'
        : ($selectedElection !== null && election_is_upcoming($selectedElection)
            ? 'Scheduled'
            : 'Outside poll window'),
];
$positionCount = 0;
$localStart = null;
$localEnd = null;
$localNominationDeadline = null;
$enabledMethods = [];
if ($selectedElection !== null) {
    $positionCountQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM positions WHERE election_id = :election_id'
    );
    $positionCountQuery->execute(['election_id' => $selectedElection['id']]);
    $positionCount = (int) $positionCountQuery->fetchColumn();
    $localStart = parse_stored_election_time(
        $selectedElection['start_datetime'],
        $selectedElection['timezone']
    );
    $localEnd = parse_stored_election_time(
        $selectedElection['end_datetime'],
        $selectedElection['timezone']
    );
    if (is_string($selectedElection['nomination_deadline']) && $selectedElection['nomination_deadline'] !== '') {
        $localNominationDeadline = parse_stored_election_time(
            $selectedElection['nomination_deadline'],
            $selectedElection['timezone']
        );
    }
    $enabledMethods = array_values(array_filter(explode(',', $selectedElection['verification_methods'])));
}
$timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
if (!in_array('UTC', $timezones, true)) {
    $timezones[] = 'UTC';
}

$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="container election-settings">
    <header class="election-settings__header">
        <div>
            <p class="eyebrow">Election controls</p>
            <h1>Settings</h1>
            <p>Update election details and rules. Settings lock when voting begins.</p>
        </div>
        <div class="election-settings__appearance">
            <label class="theme-setting" for="admin-dark-theme">
                <input id="admin-dark-theme" type="checkbox" data-admin-theme-toggle>
                <span>Use dark theme</span>
            </label>
            <span class="form__hint" role="status" aria-live="polite" data-theme-status>Light theme is active.</span>
        </div>
    </header>

    <?php if ($elections === []): ?>
        <section class="card election-settings__empty">
            <h2>No elections to configure</h2>
            <p>Create an election before changing its settings.</p>
            <a class="btn btn--primary" href="<?= BASE_URL ?>/admin/manage_elections.php">Manage elections</a>
        </section>
    <?php else: ?>
        <form class="election-settings__picker" method="get" action="<?= BASE_URL ?>/admin/settings.php">
            <label for="settings-election-picker">Selected election</label>
            <select id="settings-election-picker" name="election_id" onchange="this.form.submit()">
                <?php foreach ($elections as $electionOption): ?>
                    <option value="<?= (int) $electionOption['id'] ?>"
                        <?= (int) $selectedElection['id'] === (int) $electionOption['id'] ? 'selected' : '' ?>>
                        <?= sanitize($electionOption['title']) ?> — <?= sanitize(ucfirst($electionOption['status'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn--outline" type="submit">Open election</button></noscript>
        </form>

        <section class="election-settings__summary" aria-label="Selected election status">
            <div>
                <h2><?= sanitize($selectedElection['title']) ?></h2>
                <p>
                    <span class="badge badge--<?= sanitize($selectedElection['status']) ?>">
                        <?= sanitize($statusLabels[$selectedElection['status']] ?? ucfirst($selectedElection['status'])) ?>
                    </span>
                    <?php if ($hasBallotActivity): ?>
                        <span class="election-settings__lock-note">Settings locked: ballot activity has been recorded.</span>
                    <?php elseif (!$canEditSettings): ?>
                        <span class="election-settings__lock-note">Settings are read-only after voting begins.</span>
                    <?php endif; ?>
                </p>
            </div>
            <a class="btn btn--outline" href="<?= BASE_URL ?>/admin/manage_elections.php?edit=<?= (int) $selectedElection['id'] ?>">
                Election setup
            </a>
        </section>

        <div class="election-settings__layout">
            <nav class="settings-anchor-menu" aria-label="Election settings sections">
                <a href="#general-information">General information</a>
                <a href="#schedule-status">Schedule &amp; status</a>
                <a href="#voting-rules">Voting &amp; authentication</a>
                <a href="#danger-zone">Danger zone</a>
            </nav>

            <div class="election-settings__content">
                <form id="election-settings-form" method="post" action="<?= BASE_URL ?>/admin/settings.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
                </form>

                <section class="card election-settings-card" id="general-information"
                    aria-labelledby="general-information-title">
                    <header class="election-settings-card__header">
                        <div>
                            <span class="election-settings-card__number">01</span>
                            <h2 id="general-information-title">General Information</h2>
                        </div>
                    </header>
                    <div class="election-settings-card__fields">
                        <div class="form__group">
                            <label for="settings-title">Election title</label>
                            <input id="settings-title" form="election-settings-form" name="title"
                                maxlength="200" value="<?= sanitize($selectedElection['title']) ?>"
                                data-setting-field <?= $canEditSettings ? '' : 'disabled' ?> required>
                        </div>
                        <div class="form__group">
                            <label for="settings-description">Voter instructions</label>
                            <textarea id="settings-description" form="election-settings-form"
                                name="description" rows="6" maxlength="100000" data-setting-field
                                <?= $canEditSettings ? '' : 'disabled' ?>><?= sanitize((string) ($selectedElection['description'] ?? '')) ?></textarea>
                            <p class="form__hint">Basic formatting is supported; unsafe markup is removed when saved.</p>
                        </div>
                        <div class="form__group">
                            <label for="settings-support-email">Support contact email</label>
                            <input id="settings-support-email" form="election-settings-form" name="support_email"
                                type="email" maxlength="254" autocomplete="email"
                                value="<?= sanitize((string) ($selectedElection['support_email'] ?? '')) ?>"
                                data-setting-field <?= $canEditSettings ? '' : 'disabled' ?>>
                            <p class="form__hint">Optional. Voters can use this address if they need help.</p>
                        </div>
                    </div>
                </section>

                <section class="card election-settings-card" id="schedule-status"
                    aria-labelledby="schedule-status-title">
                    <header class="election-settings-card__header">
                        <div>
                            <span class="election-settings-card__number">02</span>
                            <h2 id="schedule-status-title">Schedule &amp; Status Control</h2>
                        </div>
                    </header>
                    <div class="election-settings-card__fields election-settings-card__fields--schedule">
                        <div class="form__group">
                            <label for="settings-start">Poll opening</label>
                            <input id="settings-start" form="election-settings-form" name="start_datetime"
                                type="datetime-local" value="<?= $localStart ? sanitize($localStart->format('Y-m-d\TH:i')) : '' ?>"
                                data-setting-field <?= $canEditSettings ? '' : 'disabled' ?> required>
                        </div>
                        <div class="form__group">
                            <label for="settings-end">Poll closing</label>
                            <input id="settings-end" form="election-settings-form" name="end_datetime"
                                type="datetime-local" value="<?= $localEnd ? sanitize($localEnd->format('Y-m-d\TH:i')) : '' ?>"
                                data-setting-field <?= $canEditSettings ? '' : 'disabled' ?> required>
                        </div>
                        <div class="form__group">
                            <label for="settings-nomination-deadline">Nomination freeze deadline (optional)</label>
                            <input id="settings-nomination-deadline" form="election-settings-form"
                                name="nomination_deadline" type="datetime-local"
                                value="<?= $localNominationDeadline
                                    ? sanitize($localNominationDeadline->format('Y-m-d\TH:i'))
                                    : '' ?>"
                                data-setting-field <?= $canEditSettings ? '' : 'disabled' ?>>
                            <p class="form__hint">Must be on or before poll opening; leave blank if nominations do not have a separate deadline.</p>
                        </div>
                        <div class="form__group">
                            <label for="settings-timezone">Timezone</label>
                            <select id="settings-timezone" form="election-settings-form" name="timezone"
                                data-setting-field <?= $canEditSettings ? '' : 'disabled' ?> required>
                                <?php foreach ($timezones as $timezone): ?>
                                    <option value="<?= sanitize($timezone) ?>"
                                        <?= $timezone === $selectedElection['timezone'] ? 'selected' : '' ?>>
                                        <?= sanitize(str_replace('_', ' ', $timezone)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <p class="form__hint election-settings-card__timezone-hint">
                        Times are interpreted in the selected timezone.
                    </p>
                    <div class="election-lifecycle">
                        <div>
                            <h3>Election lifecycle</h3>
                            <p>Every status change is recorded in Audit logs.</p>
                        </div>
                        <div class="election-lifecycle__actions">
                            <?php if ($selectedElection['status'] === 'draft'): ?>
                                <form method="post" action="<?= BASE_URL ?>/admin/settings.php" data-confirm-form
                                    data-confirm-title="Launch this election?"
                                    data-confirm-message="This makes the election available according to its schedule.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="launch_election">
                                    <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
                                    <input type="hidden" name="confirmation" value="">
                                    <button class="btn btn--primary" type="submit"
                                        <?= $positionCount === 0
                                            || $hasBallotActivity
                                            || !election_has_not_closed($selectedElection)
                                            ? 'disabled'
                                            : '' ?>>
                                        Launch Election
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($isLive): ?>
                                <form method="post" action="<?= BASE_URL ?>/admin/settings.php" data-confirm-form
                                    data-confirm-title="Pause voting?"
                                    data-confirm-message="No new ballots will be accepted until voting resumes.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="pause_election">
                                    <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
                                    <input type="hidden" name="confirmation" value="">
                                    <button class="btn btn--outline" type="submit">Pause Voting</button>
                                </form>
                            <?php elseif ($selectedElection['status'] === 'paused'): ?>
                                <form method="post" action="<?= BASE_URL ?>/admin/settings.php" data-confirm-form
                                    data-confirm-title="Resume voting?"
                                    data-confirm-message="Voters will be able to submit ballots again.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="resume_election">
                                    <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
                                    <input type="hidden" name="confirmation" value="">
                                    <button class="btn btn--primary" type="submit"
                                        <?= election_has_not_closed($selectedElection) ? '' : 'disabled' ?>>
                                        Resume Voting
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if (in_array($selectedElection['status'], ['open', 'paused'], true)): ?>
                                <form method="post" action="<?= BASE_URL ?>/admin/settings.php" data-confirm-form
                                    data-confirm-title="Close polls early?"
                                    data-confirm-message="Closing the polls is permanent. Voters will not be able to submit ballots afterward.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="close_election">
                                    <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
                                    <input type="hidden" name="confirmation" value="">
                                    <button class="btn btn--black" type="submit">Close Polls Early</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if ($selectedElection['status'] === 'draft' && $positionCount === 0): ?>
                            <p class="form__hint" role="status">
                                Add at least one contest in Manage Candidates before launching this election.
                            </p>
                        <?php elseif (
                            $selectedElection['status'] === 'draft'
                            && !election_has_not_closed($selectedElection)
                        ): ?>
                            <p class="form__hint" role="status">
                                The scheduled closing time has passed. Update the schedule before launching.
                            </p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="card election-settings-card" id="voting-rules"
                    aria-labelledby="voting-rules-title">
                    <header class="election-settings-card__header">
                        <div>
                            <span class="election-settings-card__number">03</span>
                            <h2 id="voting-rules-title">Voting &amp; Authentication Rules</h2>
                        </div>
                        <?php if (!$canEditSettings): ?>
                            <span class="election-settings-lock">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <rect x="4" y="10" width="16" height="11" rx="2"></rect>
                                    <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                                </svg>
                                Locked
                            </span>
                        <?php endif; ?>
                    </header>
                    <?php if (!$canEditSettings): ?>
                        <p class="alert alert--info" role="status">
                            Voting rules become read-only when the election is live or ballot activity exists.
                        </p>
                    <?php endif; ?>
                    <fieldset class="election-settings-card__fieldset" <?= $canEditSettings ? '' : 'disabled' ?>>
                        <legend class="sr-only">Voting and authentication rules</legend>
                        <div class="form__group">
                            <span class="form__label">Voting method</span>
                            <div class="settings-choice-grid">
                                <?php
                                $votingSystems = [
                                    'fptp' => ['First-Past-the-Post', 'Select one option per contest.'],
                                    'multiple_selection' => ['Multiple Selection', 'Select up to each contest’s configured limit.'],
                                    'ranked_choice' => ['Ranked Choice', 'Rank options; results use instant-runoff voting.'],
                                ];
                                ?>
                                <?php foreach ($votingSystems as $value => [$label, $description]): ?>
                                    <label class="settings-choice-card">
                                        <input type="radio" form="election-settings-form" name="voting_system"
                                            value="<?= sanitize($value) ?>"
                                            <?= $selectedElection['voting_system'] === $value ? 'checked' : '' ?>
                                            data-setting-field>
                                        <span>
                                            <strong><?= sanitize($label) ?></strong>
                                            <small><?= sanitize($description) ?></small>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="settings-rule-row">
                            <div>
                                <strong>Allow voter abstentions</strong>
                                <p class="form__hint">Voters may leave individual contests unanswered.</p>
                            </div>
                            <label class="settings-toggle" for="settings-abstentions">
                                <span class="sr-only">Allow voter abstentions</span>
                                <input type="hidden" form="election-settings-form" name="allow_abstentions" value="0">
                                <input id="settings-abstentions" type="checkbox" form="election-settings-form"
                                    name="allow_abstentions" value="1"
                                    <?= (bool) $selectedElection['allow_abstentions'] ? 'checked' : '' ?>
                                    data-setting-field <?= $canEditSettings ? '' : 'disabled' ?>>
                                <span aria-hidden="true"></span>
                            </label>
                        </div>
                        <fieldset class="form__group settings-verification">
                            <legend class="form__label">Voter verification</legend>
                            <label class="settings-check">
                                <input type="checkbox" form="election-settings-form"
                                    name="verification_methods[]" value="email_token"
                                    <?= in_array('email_token', $enabledMethods, true) ? 'checked' : '' ?>
                                    data-setting-field <?= $canEditSettings ? '' : 'disabled' ?>>
                                <span>
                                    <strong>Email token</strong>
                                    <small>Require a one-time email code before ballot submission.</small>
                                </span>
                            </label>
                            <label class="settings-check">
                                <input type="checkbox" form="election-settings-form"
                                    name="verification_methods[]" value="voter_pin"
                                    <?= in_array('voter_pin', $enabledMethods, true) ? 'checked' : '' ?>
                                    data-setting-field <?= $canEditSettings ? '' : 'disabled' ?>>
                                <span>
                                    <strong>Voter ID + PIN</strong>
                                    <small>Verify the registration number and account password.</small>
                                </span>
                            </label>
                            <p class="form__hint">SMS OTP is unavailable until an SMS provider is configured.</p>
                        </fieldset>
                    </fieldset>
                </section>

                <div class="election-settings__save-bar" data-save-bar hidden>
                    <span role="status" aria-live="polite">Unsaved changes</span>
                    <button class="btn btn--primary" type="submit" form="election-settings-form">
                        Save Changes
                    </button>
                </div>
                <noscript>
                    <?php if ($canEditSettings): ?>
                        <button class="btn btn--primary election-settings__noscript-save"
                            type="submit" form="election-settings-form">Save Changes</button>
                    <?php endif; ?>
                </noscript>

                <section class="card election-settings-danger" id="danger-zone"
                    aria-labelledby="danger-zone-title">
                    <header class="election-settings-card__header">
                        <div>
                            <span class="election-settings-card__number">04</span>
                            <h2 id="danger-zone-title">Danger Zone</h2>
                        </div>
                    </header>
                    <div class="election-settings-danger__item">
                        <div>
                            <h3>Reset Test Votes</h3>
                            <p>Delete test votes and submission markers. This action is available only in Draft.</p>
                        </div>
                        <form method="post" action="<?= BASE_URL ?>/admin/settings.php" data-confirm-form
                            data-confirm-title="Reset draft test votes?"
                            data-confirm-message="This permanently removes all votes and ballot submissions for this draft election."
                            data-confirm-value="RESET"
                            data-confirm-prompt="Type RESET to continue.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reset_test_votes">
                            <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
                            <input type="hidden" name="confirmation_phrase" value="">
                            <button class="btn btn--danger" type="submit"
                                <?= $selectedElection['status'] !== 'draft' || !$hasBallotActivity ? 'disabled' : '' ?>>
                                Reset Test Votes
                            </button>
                        </form>
                    </div>
                    <div class="election-settings-danger__item">
                        <div>
                            <h3>Delete Election</h3>
                            <p>Delete this election and its configuration. Elections with submitted ballots cannot be deleted.</p>
                        </div>
                        <form method="post" action="<?= BASE_URL ?>/admin/settings.php" data-confirm-form
                            data-confirm-title="Delete this election?"
                            data-confirm-message="This permanently deletes the election, its ballot configuration, and its uploaded images."
                            data-confirm-value="<?= sanitize($selectedElection['title']) ?>"
                            data-confirm-prompt="Type the exact election title to continue.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_election">
                            <input type="hidden" name="election_id" value="<?= (int) $selectedElection['id'] ?>">
                            <input type="hidden" name="confirmation_phrase" value="">
                            <button class="btn btn--danger" type="submit"
                                <?= in_array($selectedElection['status'], ['open', 'paused'], true) || $hasBallotActivity ? 'disabled' : '' ?>>
                                Delete Election
                            </button>
                        </form>
                    </div>
                    <?php if ($hasBallotActivity && $selectedElection['status'] !== 'draft'): ?>
                        <p class="election-settings-danger__note">
                            Ballot activity prevents deletion. Reset is restricted to Draft elections.
                        </p>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    <?php endif; ?>
</section>

<dialog class="settings-confirm-dialog" data-settings-confirm-dialog
    aria-labelledby="settings-confirm-title" aria-describedby="settings-confirm-message">
    <div class="settings-confirm-dialog__content">
        <div class="settings-confirm-dialog__header">
            <h2 id="settings-confirm-title" data-confirm-heading>Confirm action</h2>
            <button type="button" class="btn btn--outline" data-confirm-cancel>Cancel</button>
        </div>
        <p id="settings-confirm-message" data-confirm-message></p>
        <div class="form__group" data-confirm-input-wrap hidden>
            <label for="settings-confirm-input" data-confirm-prompt></label>
            <input id="settings-confirm-input" type="text" autocomplete="off" data-confirm-input>
        </div>
        <div class="settings-confirm-dialog__actions">
            <button class="btn btn--black" type="button" data-confirm-cancel>Go back</button>
            <button class="btn btn--danger" type="button" data-confirm-accept>Confirm</button>
        </div>
    </div>
</dialog>
<noscript><p>JavaScript is required to confirm election lifecycle and danger-zone actions.</p></noscript>
<script src="<?= BASE_URL ?>/assets/js/election-settings.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
