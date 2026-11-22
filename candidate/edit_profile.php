<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('candidate');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';

function candidate_profile_redirect(int $candidateId): void
{
    redirect(BASE_URL . '/candidate/edit_profile.php?candidate_id=' . $candidateId);
}

function candidate_profile_owned_draft(PDO $pdo, int $candidateId, int $userId)
{
    $query = $pdo->prepare(
        "SELECT c.id, c.manifesto, c.photo_path, c.status,
                e.title AS election_title, e.status AS election_status,
                p.title AS position_title
         FROM candidates c
         INNER JOIN elections e ON e.id = c.election_id
         INNER JOIN positions p ON p.id = c.position_id
         WHERE c.id = :candidate_id
           AND c.user_id = :user_id
           AND e.status = 'draft'
           AND c.status IN ('pending', 'approved')
         LIMIT 1"
    );
    $query->execute([
        'candidate_id' => $candidateId,
        'user_id' => $userId,
    ]);

    return $query->fetch();
}

$userId = (int) current_user()['id'];

if (is_post()) {
    verify_csrf();

    $candidateId = isset($_POST['candidate_id']) && is_string($_POST['candidate_id'])
        ? filter_var($_POST['candidate_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $manifesto = isset($_POST['manifesto']) && is_string($_POST['manifesto'])
        ? trim($_POST['manifesto'])
        : '';

    if ($candidateId === false || strlen($manifesto) > 65535) {
        set_flash('error', 'Enter a valid manifesto and candidate application.');
        redirect(BASE_URL . '/candidate/dashboard.php');
    }

    $application = candidate_profile_owned_draft($pdo, (int) $candidateId, $userId);
    if (!$application) {
        set_flash('error', 'This profile can no longer be edited.');
        redirect(BASE_URL . '/candidate/dashboard.php');
    }

    $newPhotoPath = null;
    $oldPhotoPath = $application['photo_path'];
    $upload = $_FILES['photo'] ?? null;

    if (is_array($upload)) {
        $uploadError = $upload['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($uploadError !== UPLOAD_ERR_NO_FILE) {
            if ($uploadError !== UPLOAD_ERR_OK) {
                set_flash('error', 'The photo upload failed. Please try again.');
                candidate_profile_redirect((int) $candidateId);
            }

            $temporaryPath = $upload['tmp_name'] ?? '';
            if (
                !is_string($temporaryPath)
                || !is_uploaded_file($temporaryPath)
                || !isset($upload['size'])
                || !is_int($upload['size'])
                || $upload['size'] < 1
                || $upload['size'] > 2 * 1024 * 1024
            ) {
                set_flash('error', 'Choose a JPG or PNG image no larger than 2 MB.');
                candidate_profile_redirect((int) $candidateId);
            }

            if (!class_exists('finfo')) {
                throw new RuntimeException('The PHP fileinfo extension is required for photo uploads.');
            }

            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $fileInfo->file($temporaryPath);
            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
            ];
            $actualSize = filesize($temporaryPath);

            if (
                !isset($extensions[$mimeType])
                || $actualSize === false
                || $actualSize < 1
                || $actualSize > 2 * 1024 * 1024
            ) {
                set_flash('error', 'Choose a valid JPG or PNG image no larger than 2 MB.');
                candidate_profile_redirect((int) $candidateId);
            }

            $uploadDirectory = __DIR__ . '/../assets/img/candidates';
            if (
                !is_dir($uploadDirectory)
                && !mkdir($uploadDirectory, 0755, true)
                && !is_dir($uploadDirectory)
            ) {
                throw new RuntimeException('Unable to create the candidate photo directory.');
            }

            $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
            $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
            if (!move_uploaded_file($temporaryPath, $destination)) {
                throw new RuntimeException('Unable to save the uploaded candidate photo.');
            }

            $newPhotoPath = 'assets/img/candidates/' . $fileName;
        }
    } elseif ($upload !== null) {
        set_flash('error', 'Invalid photo upload.');
        candidate_profile_redirect((int) $candidateId);
    }

    $photoPath = $newPhotoPath ?? $oldPhotoPath;
    try {
        $updateProfile = $pdo->prepare(
            'UPDATE candidates
             SET manifesto = :manifesto, photo_path = :photo_path
             WHERE id = :candidate_id AND user_id = :user_id'
        );
        $updateProfile->execute([
            'manifesto' => $manifesto === '' ? null : $manifesto,
            'photo_path' => $photoPath,
            'candidate_id' => $candidateId,
            'user_id' => $userId,
        ]);
    } catch (PDOException $exception) {
        if ($newPhotoPath !== null) {
            $newPhotoFile = __DIR__ . '/../' . $newPhotoPath;
            if (is_file($newPhotoFile) && !unlink($newPhotoFile)) {
                error_log('Unable to remove an unreferenced candidate photo after a database error.');
            }
        }
        throw $exception;
    }

    if ($newPhotoPath !== null && is_string($oldPhotoPath)
        && preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png)\z~', $oldPhotoPath) === 1
    ) {
        $oldPhotoFile = __DIR__ . '/../' . $oldPhotoPath;
        if (is_file($oldPhotoFile) && !unlink($oldPhotoFile)) {
            error_log('Unable to remove the replaced candidate photo.');
        }
    }

    set_flash('success', 'Candidate profile updated.');
    candidate_profile_redirect((int) $candidateId);
}

$requestedCandidateId = isset($_GET['candidate_id']) && is_string($_GET['candidate_id'])
    ? filter_var($_GET['candidate_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$application = $requestedCandidateId === false
    ? false
    : candidate_profile_owned_draft($pdo, (int) $requestedCandidateId, $userId);

$applicationsQuery = $pdo->prepare(
    "SELECT c.id, c.status, e.title AS election_title, p.title AS position_title
     FROM candidates c
     INNER JOIN elections e ON e.id = c.election_id
     INNER JOIN positions p ON p.id = c.position_id
     WHERE c.user_id = :user_id
       AND e.status = 'draft'
       AND c.status IN ('pending', 'approved')
     ORDER BY e.start_datetime DESC, p.title"
);
$applicationsQuery->execute(['user_id' => $userId]);
$editableApplications = $applicationsQuery->fetchAll();

$pageTitle = 'Edit candidate profile';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <div class="form-card">
            <header class="form-card__header">
                <h1>Edit candidate profile</h1>
                <p>Update your manifesto and optional JPG or PNG profile photo.</p>
            </header>

            <?php if ($editableApplications === []): ?>
                <div class="alert alert--info" role="status">
                    There is no editable election application for your account yet.
                </div>
                <a class="btn btn--outline" href="<?= BASE_URL ?>/candidate/dashboard.php">
                    Return to dashboard
                </a>
            <?php elseif (!$application): ?>
                <p>Select an election application to edit:</p>
                <ul class="candidate-edit-list">
                    <?php foreach ($editableApplications as $editableApplication): ?>
                        <li>
                            <a href="<?= BASE_URL ?>/candidate/edit_profile.php?candidate_id=<?= (int) $editableApplication['id'] ?>">
                                <?= sanitize($editableApplication['election_title']) ?>
                                — <?= sanitize($editableApplication['position_title']) ?>
                            </a>
                            (<?= sanitize(ucfirst($editableApplication['status'])) ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="alert alert--info" role="status">
                    Editing: <?= sanitize($application['election_title']) ?> —
                    <?= sanitize($application['position_title']) ?>.
                    Profile changes are disabled when the election opens.
                </div>

                <?php if (
                    is_string($application['photo_path'])
                    && preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png)\z~', $application['photo_path']) === 1
                ): ?>
                    <img class="candidate-edit-photo"
                        src="<?= BASE_URL ?>/<?= sanitize($application['photo_path']) ?>"
                        alt="Current candidate profile photo">
                <?php endif; ?>

                <form class="form" data-loading-state method="post" enctype="multipart/form-data"
                    action="<?= BASE_URL ?>/candidate/edit_profile.php?candidate_id=<?= (int) $application['id'] ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="candidate_id" value="<?= (int) $application['id'] ?>">

                    <div class="form__group">
                        <label for="manifesto">Manifesto</label>
                        <textarea id="manifesto" name="manifesto" maxlength="65535"><?= sanitize((string) $application['manifesto']) ?></textarea>
                    </div>

                    <div class="form__group">
                        <label for="photo">Profile photo</label>
                        <input id="photo" name="photo" type="file"
                            accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                        <p class="form__hint">JPG or PNG, up to 2 MB.</p>
                    </div>

                    <div class="form-card__actions">
                        <button class="btn btn--primary" type="submit">Save profile</button>
                        <a href="<?= BASE_URL ?>/candidate/dashboard.php">Cancel</a>
                    </div>
                    <p class="loading-state" role="status" aria-live="polite"
                        data-loading-indicator hidden>
                        <span class="loading-state__spinner" aria-hidden="true"></span>
                        Saving your profile…
                    </p>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>