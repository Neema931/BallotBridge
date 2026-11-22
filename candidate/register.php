<?php

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_scheduler.php';
require_once __DIR__ . '/../includes/candidate_profile_media.php';

function candidate_registration_is_open(array $election, ?DateTimeImmutable $now = null): bool
{
    $timezone = $election['timezone'];
    $start = parse_stored_election_time($election['start_datetime'], $timezone);
    $end = parse_stored_election_time($election['end_datetime'], $timezone);
    if ($start === null || $end === null) {
        throw new RuntimeException('An election has an invalid stored schedule.');
    }

    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone($timezone));
    if ($election['status'] === 'open' && !election_is_upcoming($election, $now)) {
        return false;
    }
    if (!in_array($election['status'], ['draft', 'open'], true)) {
        return false;
    }

    if (is_string($election['nomination_deadline']) && $election['nomination_deadline'] !== '') {
        $nominationDeadline = parse_stored_election_time(
            $election['nomination_deadline'],
            $timezone
        );
        if ($nominationDeadline === null) {
            throw new RuntimeException('An election has an invalid nomination deadline.');
        }
        if ($now >= $nominationDeadline) {
            return false;
        }
    }

    return true;
}

function candidate_registration_redirect(): void
{
    redirect(BASE_URL . '/candidate/register.php');
}

activate_due_auto_elections($pdo);

if (is_post()) {
    verify_csrf();

    $fullName = isset($_POST['full_name']) && is_string($_POST['full_name'])
        ? trim($_POST['full_name'])
        : '';
    $email = isset($_POST['email']) && is_string($_POST['email'])
        ? strtolower(trim($_POST['email']))
        : '';
    $regNumber = isset($_POST['reg_number']) && is_string($_POST['reg_number'])
        ? trim($_POST['reg_number'])
        : '';
    $positionId = isset($_POST['position_id']) && is_string($_POST['position_id'])
        ? filter_var($_POST['position_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $affiliation = isset($_POST['affiliation']) && is_string($_POST['affiliation'])
        ? trim($_POST['affiliation'])
        : '';
    $campaignSlogan = isset($_POST['campaign_slogan']) && is_string($_POST['campaign_slogan'])
        ? trim($_POST['campaign_slogan'])
        : '';
    $manifesto = isset($_POST['manifesto']) && is_string($_POST['manifesto'])
        ? trim($_POST['manifesto'])
        : '';
    $password = isset($_POST['password']) && is_string($_POST['password'])
        ? $_POST['password']
        : '';
    $confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
        ? $_POST['confirm_password']
        : '';
    $eligibilityConfirmed = isset($_POST['eligibility_confirmation'])
        && is_string($_POST['eligibility_confirmation'])
        && $_POST['eligibility_confirmation'] === '1';

    $nameLength = preg_match_all('/./us', $fullName, $nameCharacters);
    $affiliationLength = preg_match_all('/./us', $affiliation, $affiliationCharacters);
    $sloganLength = preg_match_all('/./us', $campaignSlogan, $sloganCharacters);
    $passwordLength = preg_match_all('/./us', $password, $passwordCharacters);
    if (
        $nameLength === false
        || $nameLength < 1
        || $nameLength > 150
        || $affiliationLength === false
        || $affiliationLength > 150
        || $sloganLength === false
        || $sloganLength > 200
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || strlen($email) > 254
        || $regNumber === ''
        || strlen($regNumber) > 100
        || $positionId === false
        || strlen($manifesto) > 65535
        || $passwordLength === false
        || $passwordLength < 8
        || strlen($password) > 72
        || $password !== $confirmPassword
        || !$eligibilityConfirmed
    ) {
        set_flash('error', 'Check the application details, password confirmation, and eligibility declaration.');
        candidate_registration_redirect();
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($passwordHash)) {
        throw new RuntimeException('Unable to hash the candidate password.');
    }

    $newPhotoPath = null;
    try {
        $pdo->beginTransaction();
        $positionQuery = $pdo->prepare(
            "SELECT p.id AS position_id, p.title AS position_title,
                    e.id AS election_id, e.title AS election_title, e.status,
                    e.start_datetime, e.end_datetime, e.timezone, e.nomination_deadline
             FROM positions p
             INNER JOIN elections e ON e.id = p.election_id
             WHERE p.id = :position_id AND p.contest_type = 'candidate'
             FOR UPDATE"
        );
        $positionQuery->execute(['position_id' => $positionId]);
        $selectedPosition = $positionQuery->fetch();
        if (!$selectedPosition || !candidate_registration_is_open($selectedPosition)) {
            $pdo->rollBack();
            set_flash('error', 'That position is no longer accepting candidate applications.');
            candidate_registration_redirect();
        }

        $duplicateQuery = $pdo->prepare(
            'SELECT id FROM users
             WHERE email = :email OR reg_number = :reg_number
             LIMIT 1'
        );
        $duplicateQuery->execute([
            'email' => $email,
            'reg_number' => $regNumber,
        ]);
        if ($duplicateQuery->fetchColumn() !== false) {
            $pdo->rollBack();
            set_flash('error', 'An account or application already exists for that email address or ID number.');
            candidate_registration_redirect();
        }

        $newPhotoPath = candidate_profile_store_photo($_FILES['photo'] ?? null);
        $insertUser = $pdo->prepare(
            "INSERT INTO users (full_name, email, reg_number, password_hash, role, status)
             VALUES (:full_name, :email, :reg_number, :password_hash, 'candidate', 'pending')"
        );
        $insertUser->execute([
            'full_name' => $fullName,
            'email' => $email,
            'reg_number' => $regNumber,
            'password_hash' => $passwordHash,
        ]);
        $userId = (int) $pdo->lastInsertId();

        $insertCandidate = $pdo->prepare(
            "INSERT INTO candidates
                (user_id, application_source, election_id, position_id, manifesto, campaign_slogan,
                 photo_path, affiliation, status)
             VALUES
                (:user_id, 'self_registration', :election_id, :position_id, :manifesto, :campaign_slogan,
                 :photo_path, :affiliation, 'pending')"
        );
        $insertCandidate->execute([
            'user_id' => $userId,
            'election_id' => $selectedPosition['election_id'],
            'position_id' => $selectedPosition['position_id'],
            'manifesto' => $manifesto === '' ? null : $manifesto,
            'campaign_slogan' => $campaignSlogan === '' ? null : $campaignSlogan,
            'photo_path' => $newPhotoPath,
            'affiliation' => $affiliation === '' ? null : $affiliation,
        ]);

        $pdo->commit();
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        candidate_profile_delete_photo($newPhotoPath);
        set_flash('error', $exception->getMessage());
        candidate_registration_redirect();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        candidate_profile_delete_photo($newPhotoPath);
        $isDuplicate = $exception->getCode() === '23000'
            && isset($exception->errorInfo[1])
            && (int) $exception->errorInfo[1] === 1062;
        if ($isDuplicate) {
            set_flash('error', 'An account or application already exists for that email address or ID number.');
            candidate_registration_redirect();
        }
        throw $exception;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        candidate_profile_delete_photo($newPhotoPath);
        throw $exception;
    }

    set_flash(
        'success',
        'Your candidacy application was submitted and is pending administrator approval. You can sign in after your application is reviewed.'
    );
    redirect(BASE_URL . '/candidate/login.php');
}

$positionsQuery = $pdo->query(
    "SELECT e.id AS election_id, e.title AS election_title, e.status,
            e.start_datetime, e.end_datetime, e.timezone, e.nomination_deadline,
            p.id AS position_id, p.title AS position_title
     FROM elections e
     INNER JOIN positions p ON p.election_id = e.id
     WHERE e.status IN ('draft', 'open') AND p.contest_type = 'candidate'
     ORDER BY e.start_datetime, e.id, p.id"
);
$positions = [];
$eligibleElectionTitles = [];
foreach ($positionsQuery->fetchAll() as $position) {
    if (!candidate_registration_is_open($position)) {
        continue;
    }

    $electionId = (int) $position['election_id'];
    $eligibleElectionTitles[$electionId] = $position['election_title'];
    $positions[] = $position;
}

$canApply = $positions !== [];
$pageTitle = 'Candidate registration';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section candidate-register">
    <div class="container">
        <header class="candidate-register__header">
            <p class="eyebrow"><?= sanitize(APP_NAME) ?> · Candidate nominations</p>
            <h1>Candidate Registration</h1>
            <?php if ($canApply): ?>
                <p>
                    Apply for <?= count($eligibleElectionTitles) === 1
                        ? sanitize(reset($eligibleElectionTitles))
                        : sanitize((string) count($eligibleElectionTitles)) . ' elections accepting nominations' ?>.
                </p>
            <?php else: ?>
                <p>Self-nominations open when an election is accepting applications.</p>
            <?php endif; ?>
        </header>

        <?php if (!$canApply): ?>
            <div class="candidate-register__state candidate-register__state--closed" role="status">
                <span class="candidate-register__state-icon" aria-hidden="true">!</span>
                <div>
                    <h2>Nominations Currently Closed</h2>
                    <p>There are no Draft or scheduled elections accepting candidate applications right now. Please check back when nominations open.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="candidate-register__state candidate-register__state--open" role="status">
                <span class="candidate-register__state-icon" aria-hidden="true">✓</span>
                <div>
                    <h2>Nominations are open</h2>
                    <p>
                        <?= count($eligibleElectionTitles) === 1
                            ? sanitize(reset($eligibleElectionTitles))
                            : 'Choose an election and position below.' ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <form class="card candidate-register__card" method="post" enctype="multipart/form-data"
            action="<?= BASE_URL ?>/candidate/register.php" data-validate data-candidate-register>
            <?= csrf_field() ?>
            <fieldset class="candidate-register__fieldset" <?= !$canApply ? 'disabled' : '' ?>>
                <section class="candidate-register__section" aria-labelledby="account-verification-title">
                    <header class="candidate-register__section-header">
                        <span class="candidate-register__section-number" aria-hidden="true">01</span>
                        <div>
                            <h2 id="account-verification-title">Account &amp; Identity Verification</h2>
                            <p>Use your official details. Your application will be reviewed by an administrator.</p>
                        </div>
                    </header>
                    <div class="candidate-register__fields">
                        <div class="form__group">
                            <label for="candidate-full-name">Full name</label>
                            <input id="candidate-full-name" name="full_name" type="text"
                                autocomplete="name" maxlength="150" required>
                        </div>
                        <div class="form__group">
                            <label for="candidate-reg-number">Voter / Student / Member ID Number</label>
                            <input id="candidate-reg-number" name="reg_number" type="text"
                                autocomplete="off" maxlength="100" required>
                            <p class="form__hint">Each ID number can be used for one account/application.</p>
                        </div>
                        <div class="form__group">
                            <label for="candidate-email">Email address</label>
                            <input id="candidate-email" name="email" type="email"
                                autocomplete="email" maxlength="254" required>
                        </div>
                        <div class="form__group">
                            <label for="candidate-password">Password</label>
                            <input id="candidate-password" name="password" type="password"
                                data-password autocomplete="new-password" minlength="8" maxlength="72" required>
                            <p class="form__hint">At least 8 characters and no more than 72 bytes.</p>
                        </div>
                        <div class="form__group">
                            <label for="candidate-confirm-password">Confirm password</label>
                            <input id="candidate-confirm-password" name="confirm_password" type="password"
                                data-password-confirm autocomplete="new-password" minlength="8" maxlength="72"
                                aria-describedby="candidate-password-match" required>
                            <p class="form__error" id="candidate-password-match" hidden>
                                Passwords must match.
                            </p>
                        </div>
                    </div>
                </section>

                <section class="candidate-register__section" aria-labelledby="campaign-profile-title">
                    <header class="candidate-register__section-header">
                        <span class="candidate-register__section-number" aria-hidden="true">02</span>
                        <div>
                            <h2 id="campaign-profile-title">Ballot &amp; Campaign Profile</h2>
                            <p>Choose an open contest and tell voters what you stand for.</p>
                        </div>
                    </header>
                    <div class="candidate-register__fields">
                        <div class="form__group">
                            <label for="candidate-position">Contested position</label>
                            <select id="candidate-position" name="position_id" required>
                                <option value="" selected disabled>Choose an election and position</option>
                                <?php foreach ($eligibleElectionTitles as $electionId => $electionTitle): ?>
                                    <optgroup label="<?= sanitize($electionTitle) ?>">
                                        <?php foreach ($positions as $position): ?>
                                            <?php if ((int) $position['election_id'] === $electionId): ?>
                                                <option value="<?= (int) $position['position_id'] ?>">
                                                    <?= sanitize($position['position_title']) ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form__group">
                            <label for="candidate-affiliation">Party / slate affiliation <span>(optional)</span></label>
                            <input id="candidate-affiliation" name="affiliation" type="text" maxlength="150">
                        </div>
                        <div class="candidate-register__photo-field">
                            <div class="candidate-register__photo-frame">
                                <span aria-hidden="true" data-registration-photo-placeholder>?</span>
                                <img alt="Headshot preview" data-registration-photo-preview hidden>
                            </div>
                            <div class="form__group">
                                <label for="candidate-photo">Headshot <span>(optional)</span></label>
                                <input id="candidate-photo" name="photo" type="file"
                                    accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                    data-registration-photo>
                                <p class="form__hint">JPG or PNG, up to 2 MB. The ballot crops your image into a circle.</p>
                                <p class="form__error" role="alert" data-registration-photo-error hidden></p>
                            </div>
                        </div>
                        <div class="form__group">
                            <label for="candidate-slogan">Campaign slogan <span>(optional)</span></label>
                            <input id="candidate-slogan" name="campaign_slogan" type="text" maxlength="200">
                        </div>
                        <div class="form__group candidate-register__manifesto">
                            <label for="candidate-manifesto">Manifesto <span>(optional)</span></label>
                            <textarea id="candidate-manifesto" name="manifesto" maxlength="65535"
                                data-registration-manifesto aria-describedby="candidate-manifesto-counter"></textarea>
                            <div class="candidate-register__manifesto-meta">
                                <span>Line breaks are preserved in your voter-facing profile.</span>
                                <span id="candidate-manifesto-counter" data-registration-manifesto-counter
                                    aria-live="polite">0 / 65,535 characters</span>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="candidate-register__submit">
                    <label class="candidate-register__eligibility">
                        <input type="checkbox" name="eligibility_confirmation" value="1" required>
                        <span>I certify that I am eligible to contest the selected position and that the information provided is accurate.</span>
                    </label>
                    <button class="btn btn--primary" type="submit" <?= !$canApply ? 'disabled' : '' ?>>
                        Submit Candidacy Application
                    </button>
                </div>
            </fieldset>
        </form>

        <p class="candidate-register__login">
            Already registered? <a href="<?= BASE_URL ?>/candidate/login.php">Sign in here</a>
        </p>
    </div>
</section>

<script src="<?= BASE_URL ?>/assets/js/validation.js" defer></script>
<script src="<?= BASE_URL ?>/assets/js/candidate-register.js?v=1" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
