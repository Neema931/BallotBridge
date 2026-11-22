<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('candidate');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_scheduler.php';
require_once __DIR__ . '/../includes/candidate_profile_media.php';

activate_due_auto_elections($pdo);

function candidate_myprofile_redirect(?int $candidateId): void
{
    $url = BASE_URL . '/candidate/myprofile.php';
    if ($candidateId !== null) {
        $url .= '?candidate_id=' . $candidateId;
    }

    redirect($url);
}

function candidate_myprofile_fetch_application(PDO $pdo, int $candidateId, int $userId, bool $lock = false)
{
    $query = $pdo->prepare(
        'SELECT c.id, c.affiliation, c.campaign_slogan, c.manifesto, c.photo_path,
                c.status AS application_status, c.rejection_feedback,
                e.id AS election_id, e.title AS election_title, e.status AS election_status,
                e.start_datetime, e.end_datetime, e.timezone, e.nomination_deadline,
                p.title AS position_title, u.full_name
         FROM candidates c
         INNER JOIN elections e ON e.id = c.election_id
         INNER JOIN positions p ON p.id = c.position_id
         INNER JOIN users u ON u.id = c.user_id
         WHERE c.id = :candidate_id AND c.user_id = :user_id
         LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $query->execute([
        'candidate_id' => $candidateId,
        'user_id' => $userId,
    ]);

    return $query->fetch();
}

function candidate_myprofile_election_state(array $application): array
{
    $start = parse_stored_election_time($application['start_datetime'], $application['timezone']);
    $end = parse_stored_election_time($application['end_datetime'], $application['timezone']);
    if ($start === null || $end === null) {
        throw new RuntimeException('The election has an invalid stored schedule.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone($application['timezone']));
    if ($application['election_status'] === 'closed' || $now > $end) {
        return [
            'label' => 'Closed',
            'locked' => true,
            'message' => 'Public ballot details cannot be changed after voting closes. You may still update your email or password.',
        ];
    }
    if ($application['election_status'] === 'paused') {
        return [
            'label' => 'Paused',
            'locked' => true,
            'message' => 'Public ballot details are locked while voting is paused. You may still update your email or password.',
        ];
    }
    if ($application['election_status'] === 'open' && $now >= $start) {
        return [
            'label' => 'Live',
            'locked' => true,
            'message' => 'Public ballot details cannot be changed after voting begins. You may still update your email or password.',
        ];
    }

    if (is_string($application['nomination_deadline']) && $application['nomination_deadline'] !== '') {
        $nominationDeadline = parse_stored_election_time(
            $application['nomination_deadline'],
            $application['timezone']
        );
        if ($nominationDeadline === null) {
            throw new RuntimeException('The election has an invalid nomination deadline.');
        }
        if ($now >= $nominationDeadline) {
            return [
                'label' => 'Frozen',
                'locked' => true,
                'message' => 'The nomination freeze deadline has passed. You may still update your email or password.',
            ];
        }
    }
    if ($application['election_status'] === 'open') {
        return ['label' => 'Scheduled', 'locked' => false, 'message' => ''];
    }

    return ['label' => 'Draft', 'locked' => false, 'message' => ''];
}

$userId = (int) current_user()['id'];
$userQuery = $pdo->prepare(
    "SELECT id, full_name, email
     FROM users
     WHERE id = :user_id AND role = 'candidate' AND status = 'active'"
);
$userQuery->execute(['user_id' => $userId]);
$user = $userQuery->fetch();
if (!$user) {
    throw new RuntimeException('The active candidate account was not found.');
}

$applicationsQuery = $pdo->prepare(
    'SELECT c.id, c.status AS application_status, e.title AS election_title,
            e.status AS election_status, e.start_datetime, e.end_datetime, e.timezone,
            p.title AS position_title
     FROM candidates c
     INNER JOIN elections e ON e.id = c.election_id
     INNER JOIN positions p ON p.id = c.position_id
     WHERE c.user_id = :user_id
     ORDER BY CASE
         WHEN e.status = \'open\' THEN 0
         WHEN e.status = \'paused\' THEN 1
         WHEN e.status = \'draft\' THEN 2
         ELSE 3
     END, e.start_datetime, p.title'
);
$applicationsQuery->execute(['user_id' => $userId]);
$applications = $applicationsQuery->fetchAll();

$candidateId = null;
$requestedId = isset($_GET['candidate_id']) && is_string($_GET['candidate_id'])
    ? filter_var($_GET['candidate_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($requestedId !== false) {
    foreach ($applications as $candidateApplication) {
        if ((int) $candidateApplication['id'] === $requestedId) {
            $candidateId = (int) $candidateApplication['id'];
            break;
        }
    }
}
if ($candidateId === null && $applications !== []) {
    $candidateId = (int) $applications[0]['id'];
}

if (is_post()) {
    verify_csrf();

    $rawCandidateId = $_POST['candidate_id'] ?? '';
    $postedCandidateId = !is_string($rawCandidateId)
        ? false
        : ($rawCandidateId === ''
            ? null
            : filter_var($rawCandidateId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]));
    $emailInputValid = isset($_POST['email']) && is_string($_POST['email']);
    $email = $emailInputValid ? strtolower(trim($_POST['email'])) : '';
    $currentPassword = isset($_POST['current_password']) && is_string($_POST['current_password'])
        ? $_POST['current_password']
        : '';
    $newPassword = isset($_POST['new_password']) && is_string($_POST['new_password'])
        ? $_POST['new_password']
        : '';
    $confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
        ? $_POST['confirm_password']
        : '';
    $passwordLength = preg_match_all('/./us', $newPassword, $passwordCharacters);

    if (
        $postedCandidateId === false
        || !$emailInputValid
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || strlen($email) > 254
        || strlen($currentPassword) > 72
        || ($newPassword !== '' && (
            $passwordLength === false
            || $passwordLength < 8
            || strlen($newPassword) > 72
            || $newPassword !== $confirmPassword
        ))
        || ($newPassword === '' && $confirmPassword !== '')
    ) {
        set_flash('error', 'Check the email address and password fields, then try again.');
        candidate_myprofile_redirect(is_int($postedCandidateId) ? $postedCandidateId : null);
    }

    $postedCandidateId = is_int($postedCandidateId) ? $postedCandidateId : null;
    $candidate = $postedCandidateId === null
        ? false
        : candidate_myprofile_fetch_application($pdo, $postedCandidateId, $userId);
    if ($postedCandidateId !== null && !$candidate) {
        set_flash('error', 'That election application does not belong to this account.');
        candidate_myprofile_redirect(null);
    }

    $publicFieldNames = ['affiliation', 'campaign_slogan', 'manifesto'];
    $publicFieldsSubmitted = false;
    foreach ($publicFieldNames as $fieldName) {
        $publicFieldsSubmitted = $publicFieldsSubmitted || array_key_exists($fieldName, $_POST);
    }
    $photoUpload = $_FILES['photo'] ?? null;
    if ($photoUpload !== null && !is_array($photoUpload)) {
        set_flash('error', 'Choose a valid JPG or PNG headshot no larger than 2 MB.');
        candidate_myprofile_redirect($postedCandidateId);
    }
    $photoWasSubmitted = is_array($photoUpload)
        && ($photoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $publicFieldsSubmitted = $publicFieldsSubmitted || $photoWasSubmitted;
    $candidateState = $candidate ? candidate_myprofile_election_state($candidate) : null;

    if (
        ($candidate === false && $publicFieldsSubmitted)
        || ($candidateState !== null && $candidateState['locked'] && $publicFieldsSubmitted)
    ) {
        set_flash('error', 'Ballot profile changes are locked for this election.');
        candidate_myprofile_redirect($postedCandidateId);
    }

    $affiliation = null;
    $campaignSlogan = null;
    $manifesto = null;
    if ($candidate !== false && !$candidateState['locked']) {
        foreach ($publicFieldNames as $fieldName) {
            if (!isset($_POST[$fieldName]) || !is_string($_POST[$fieldName])) {
                set_flash('error', 'Submit all public ballot fields as text.');
                candidate_myprofile_redirect($postedCandidateId);
            }
        }
        $affiliationInput = trim($_POST['affiliation']);
        $campaignSloganInput = trim($_POST['campaign_slogan']);
        $manifestoInput = trim($_POST['manifesto']);
        if (
            strlen($affiliationInput) > 150
            || strlen($campaignSloganInput) > 200
            || strlen($manifestoInput) > 65535
        ) {
            set_flash('error', 'Check the length of the public ballot fields.');
            candidate_myprofile_redirect($postedCandidateId);
        }
        $affiliation = $affiliationInput === '' ? null : $affiliationInput;
        $campaignSlogan = $campaignSloganInput === '' ? null : $campaignSloganInput;
        $manifesto = $manifestoInput === '' ? null : $manifestoInput;
    }

    try {
        $newPhotoPath = candidate_profile_store_photo($photoWasSubmitted ? $photoUpload : null);
    } catch (InvalidArgumentException $exception) {
        set_flash('error', $exception->getMessage());
        candidate_myprofile_redirect($postedCandidateId);
    }

    $oldPhotoPath = null;
    $profileWasResubmitted = false;
    $passwordChanged = false;
    try {
        $pdo->beginTransaction();
        $lockedCandidate = $postedCandidateId === null
            ? false
            : candidate_myprofile_fetch_application($pdo, $postedCandidateId, $userId, true);
        if ($postedCandidateId !== null && !$lockedCandidate) {
            $pdo->rollBack();
            candidate_profile_delete_photo($newPhotoPath);
            set_flash('error', 'That election application is no longer available.');
            candidate_myprofile_redirect(null);
        }

        if ($lockedCandidate) {
            $lockedState = candidate_myprofile_election_state($lockedCandidate);
            if ($lockedState['locked'] && $publicFieldsSubmitted) {
                $pdo->rollBack();
                candidate_profile_delete_photo($newPhotoPath);
                set_flash('error', 'Ballot profile changes are locked for this election.');
                candidate_myprofile_redirect($postedCandidateId);
            }
        }

        $lockedUserQuery = $pdo->prepare(
            "SELECT email, password_hash
             FROM users
             WHERE id = :user_id AND role = 'candidate' AND status = 'active'
             FOR UPDATE"
        );
        $lockedUserQuery->execute(['user_id' => $userId]);
        $lockedUser = $lockedUserQuery->fetch();
        if (!$lockedUser) {
            throw new RuntimeException('The active candidate account was not found.');
        }

        $credentialsChanged = strcasecmp($email, $lockedUser['email']) !== 0 || $newPassword !== '';
        if (
            $credentialsChanged
            && (
                $currentPassword === ''
                || strlen($currentPassword) > 72
                || !password_verify($currentPassword, $lockedUser['password_hash'])
            )
        ) {
            $pdo->rollBack();
            candidate_profile_delete_photo($newPhotoPath);
            set_flash('error', 'Enter your current password to change account security settings.');
            candidate_myprofile_redirect($postedCandidateId);
        }

        $emailCheck = $pdo->prepare(
            'SELECT id FROM users WHERE email = :email AND id <> :user_id LIMIT 1'
        );
        $emailCheck->execute(['email' => $email, 'user_id' => $userId]);
        if ($emailCheck->fetchColumn() !== false) {
            $pdo->rollBack();
            candidate_profile_delete_photo($newPhotoPath);
            set_flash('error', 'That email address is already in use.');
            candidate_myprofile_redirect($postedCandidateId);
        }

        $passwordHash = $lockedUser['password_hash'];
        if ($newPassword !== '') {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Unable to hash the new password.');
            }
            $passwordChanged = true;
        }
        $saveUser = $pdo->prepare(
            "UPDATE users SET email = :email, password_hash = :password_hash
             WHERE id = :user_id AND role = 'candidate' AND status = 'active'"
        );
        $saveUser->execute([
            'email' => $email,
            'password_hash' => $passwordHash,
            'user_id' => $userId,
        ]);

        if ($lockedCandidate && !$lockedState['locked'] && $publicFieldsSubmitted) {
            $oldPhotoPath = $lockedCandidate['photo_path'];
            $photoPath = $newPhotoPath ?? $oldPhotoPath;
            $profileChanged = $affiliation !== $lockedCandidate['affiliation']
                || $campaignSlogan !== $lockedCandidate['campaign_slogan']
                || $manifesto !== $lockedCandidate['manifesto']
                || $photoPath !== $oldPhotoPath;
            if ($profileChanged) {
                $saveCandidate = $pdo->prepare(
                    'UPDATE candidates
                     SET affiliation = :affiliation, campaign_slogan = :campaign_slogan,
                         manifesto = :manifesto, photo_path = :photo_path,
                         rejection_feedback = NULL,
                         status = IF(status = \'rejected\', \'pending\', status)
                     WHERE id = :candidate_id AND user_id = :user_id'
                );
                $saveCandidate->execute([
                    'affiliation' => $affiliation,
                    'campaign_slogan' => $campaignSlogan,
                    'manifesto' => $manifesto,
                    'photo_path' => $photoPath,
                    'candidate_id' => $postedCandidateId,
                    'user_id' => $userId,
                ]);
                $profileWasResubmitted = $lockedCandidate['application_status'] === 'rejected';
            }
        }

        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        candidate_profile_delete_photo($newPhotoPath);
        $isDuplicateEmail = $exception->getCode() === '23000'
            && isset($exception->errorInfo[1])
            && (int) $exception->errorInfo[1] === 1062;
        if ($isDuplicateEmail) {
            set_flash('error', 'That email address is already in use.');
            candidate_myprofile_redirect($postedCandidateId);
        }
        throw $exception;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        candidate_myprofile_delete_photo($newPhotoPath);
        throw $exception;
    }

    if ($newPhotoPath !== null && $newPhotoPath !== $oldPhotoPath) {
        candidate_profile_delete_photo(is_string($oldPhotoPath) ? $oldPhotoPath : null);
    }
    if ($passwordChanged && !session_regenerate_id(true)) {
        throw new RuntimeException('Unable to renew the session after changing the password.');
    }

    set_flash(
        'success',
        $profileWasResubmitted
            ? 'Profile updated. Your application is pending administrator review again.'
            : 'Profile and account settings saved.'
    );
    candidate_myprofile_redirect($postedCandidateId);
}

$application = $candidateId === null
    ? false
    : candidate_myprofile_fetch_application($pdo, $candidateId, $userId);
$applicationState = $application
    ? candidate_myprofile_election_state($application)
    : ['label' => 'Not assigned', 'locked' => true];
$nominationDeadline = null;
if ($application && is_string($application['nomination_deadline']) && $application['nomination_deadline'] !== '') {
    $nominationDeadline = parse_stored_election_time(
        $application['nomination_deadline'],
        $application['timezone']
    );
    if ($nominationDeadline === null) {
        throw new RuntimeException('The election has an invalid nomination deadline.');
    }
}
$pageTitle = 'My profile';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section candidate-myprofile">
    <div class="container">
        <nav class="candidate-myprofile__breadcrumb" aria-label="Breadcrumb">
            <a href="<?= BASE_URL ?>/candidate/dashboard.php">Dashboard</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">My Profile</span>
        </nav>

        <header class="candidate-myprofile__header">
            <p class="eyebrow">Candidate workspace</p>
            <h1>My Profile</h1>
            <p>Manage how you appear on the ballot and keep your account details up to date.</p>
        </header>

        <?php if ($application && count($applications) > 1): ?>
            <nav class="candidate-myprofile__applications" aria-label="Election applications">
                <?php foreach ($applications as $candidateApplication): ?>
                    <a href="<?= BASE_URL ?>/candidate/myprofile.php?candidate_id=<?= (int) $candidateApplication['id'] ?>"
                        <?= (int) $application['id'] === (int) $candidateApplication['id'] ? 'aria-current="page"' : '' ?>>
                        <?= sanitize($candidateApplication['election_title'] . ' — ' . $candidateApplication['position_title']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php if ($application && $applicationState['locked']): ?>
            <div class="candidate-myprofile__state candidate-myprofile__state--locked" role="status">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <rect x="4" y="10" width="16" height="11" rx="2"></rect>
                    <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                </svg>
                <div>
                    <strong>Ballot profile locked — <?= sanitize($applicationState['label']) ?></strong>
                    <p><?= sanitize($applicationState['message']) ?></p>
                    <?php if (
                        $application['application_status'] === 'rejected'
                        && is_string($application['rejection_feedback'])
                        && $application['rejection_feedback'] !== ''
                    ): ?>
                        <p class="candidate-myprofile__rejection-feedback">
                            <strong>Administrator feedback:</strong>
                            <?= nl2br(sanitize($application['rejection_feedback'])) ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($application): ?>
            <div class="candidate-myprofile__state candidate-myprofile__state--editable" role="status">
                <span class="candidate-myprofile__state-indicator" aria-hidden="true"></span>
                <div>
                    <strong>Ballot profile editable — <?= sanitize($applicationState['label']) ?></strong>
                    <p><?= $application['application_status'] === 'rejected'
                        ? 'Your application is rejected. Saving a public profile change will return it to pending review.'
                        : 'Public ballot details can be changed until the nomination freeze deadline.' ?></p>
                    <?php if (
                        $application['application_status'] === 'rejected'
                        && is_string($application['rejection_feedback'])
                        && $application['rejection_feedback'] !== ''
                    ): ?>
                        <p class="candidate-myprofile__rejection-feedback">
                            <strong>Administrator feedback:</strong>
                            <?= nl2br(sanitize($application['rejection_feedback'])) ?>
                        </p>
                    <?php endif; ?>
                    <p>Nomination freeze:
                        <?= $nominationDeadline
                            ? sanitize($nominationDeadline->format('M j, Y g:i A T'))
                            : 'No separate deadline set' ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="candidate-myprofile__state" role="status">
                <div>
                    <strong>No election application assigned</strong>
                    <p>You can update your account settings now. Public ballot details will be available when you are assigned to a contest.</p>
                </div>
            </div>
        <?php endif; ?>

        <form class="candidate-myprofile__form" data-myprofile-form method="post" enctype="multipart/form-data"
            action="<?= BASE_URL ?>/candidate/myprofile.php<?= $application
                ? '?candidate_id=' . (int) $application['id']
                : '' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="candidate_id" value="<?= $application ? (int) $application['id'] : '' ?>">

            <section class="card candidate-myprofile__card" aria-labelledby="public-ballot-title">
                <header class="candidate-myprofile__card-header">
                    <span class="candidate-myprofile__card-number" aria-hidden="true">01</span>
                    <div>
                        <h2 id="public-ballot-title">Public Ballot Information</h2>
                        <p>This is the profile voters will see for your contest.</p>
                    </div>
                </header>

                <div class="candidate-myprofile__photo-field">
                    <div class="candidate-myprofile__photo-frame">
                        <?php if (
                            $application
                            && is_string($application['photo_path'])
                            && preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png)\z~', $application['photo_path']) === 1
                        ): ?>
                            <img src="<?= BASE_URL ?>/<?= sanitize($application['photo_path']) ?>"
                                alt="Current candidate headshot" data-headshot-preview>
                        <?php else: ?>
                            <span aria-hidden="true" data-headshot-placeholder>
                                <?= $application ? sanitize(strtoupper(substr($application['full_name'], 0, 1))) : '?' ?>
                            </span>
                            <img alt="" data-headshot-preview hidden>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="form__label" for="candidate-photo">Candidate headshot</label>
                        <input class="candidate-myprofile__file-input" id="candidate-photo"
                            name="photo" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                            data-headshot-input <?= !$application || $applicationState['locked'] ? 'disabled' : '' ?>>
                        <p class="form__hint">JPG or PNG, up to 2 MB. Your image is cropped to a circle on the ballot.</p>
                        <p class="form__error" role="alert" data-headshot-error hidden></p>
                    </div>
                </div>

                <div class="candidate-myprofile__fields">
                    <div class="form__group">
                        <label for="candidate-full-name">Registered full name</label>
                        <input id="candidate-full-name" type="text"
                            value="<?= sanitize($application ? $application['full_name'] : $user['full_name']) ?>"
                            readonly aria-readonly="true">
                    </div>
                    <div class="form__group">
                        <label for="candidate-position">Contested position</label>
                        <input id="candidate-position" type="text"
                            value="<?= sanitize($application ? $application['position_title'] : 'No position assigned') ?>"
                            readonly aria-readonly="true">
                    </div>
                    <div class="form__group">
                        <label for="candidate-affiliation">Party / slate affiliation</label>
                        <input id="candidate-affiliation" name="affiliation" type="text" maxlength="150"
                            value="<?= sanitize((string) ($application['affiliation'] ?? '')) ?>"
                            <?= !$application || $applicationState['locked'] ? 'disabled' : '' ?>>
                    </div>
                    <div class="form__group">
                        <label for="candidate-slogan">Campaign slogan</label>
                        <input id="candidate-slogan" name="campaign_slogan" type="text" maxlength="200"
                            value="<?= sanitize((string) ($application['campaign_slogan'] ?? '')) ?>"
                            <?= !$application || $applicationState['locked'] ? 'disabled' : '' ?>>
                    </div>
                    <div class="form__group candidate-myprofile__manifesto">
                        <label for="candidate-manifesto">Manifesto</label>
                        <textarea id="candidate-manifesto" name="manifesto" maxlength="65535"
                            data-manifesto-input aria-describedby="manifesto-counter"
                            <?= !$application || $applicationState['locked'] ? 'disabled' : '' ?>><?= sanitize((string) ($application['manifesto'] ?? '')) ?></textarea>
                        <div class="candidate-myprofile__manifesto-meta">
                            <span>Use clear, voter-friendly text. Line breaks are preserved on the ballot.</span>
                            <span id="manifesto-counter" data-manifesto-counter aria-live="polite">0 / 65,535 characters</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card candidate-myprofile__card" aria-labelledby="account-security-title">
                <header class="candidate-myprofile__card-header">
                    <span class="candidate-myprofile__card-number" aria-hidden="true">02</span>
                    <div>
                        <h2 id="account-security-title">Account &amp; Security</h2>
                        <p>Use your current password to confirm email or password changes.</p>
                    </div>
                </header>

                <div class="candidate-myprofile__fields">
                    <div class="form__group">
                        <label for="candidate-email">Contact email</label>
                        <input id="candidate-email" name="email" type="email" maxlength="254"
                            autocomplete="email" value="<?= sanitize($user['email']) ?>"
                            data-original-email="<?= sanitize(strtolower($user['email'])) ?>" required>
                    </div>
                    <div class="form__group">
                        <label for="current-password">Current password</label>
                        <input id="current-password" name="current_password" type="password"
                            autocomplete="current-password" maxlength="72" data-current-password>
                        <p class="form__hint">Required when changing your email or password.</p>
                    </div>
                    <div class="form__group">
                        <label for="new-password">New password <span>(optional)</span></label>
                        <input id="new-password" name="new_password" type="password"
                            autocomplete="new-password" minlength="8" maxlength="72" data-new-password>
                        <p class="form__hint">At least 8 characters and no more than 72 bytes.</p>
                    </div>
                    <div class="form__group">
                        <label for="confirm-password">Confirm new password</label>
                        <input id="confirm-password" name="confirm_password" type="password"
                            autocomplete="new-password" minlength="8" maxlength="72" data-confirm-password>
                        <p class="form__error" role="alert" data-password-match-error hidden>
                            New passwords must match.
                        </p>
                    </div>
                </div>
            </section>

            <div class="candidate-myprofile__action-bar">
                <a class="btn btn--outline" href="<?= BASE_URL ?>/candidate/dashboard.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Save Changes</button>
            </div>
        </form>
    </div>
</section>

<script src="<?= BASE_URL ?>/assets/js/candidate-myprofile.js?v=1" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
