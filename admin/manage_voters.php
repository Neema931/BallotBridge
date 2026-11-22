<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../config/database.php';

$readVoterDetails = static function (): ?array {
    $fullName = isset($_POST['full_name']) && is_string($_POST['full_name'])
        ? trim($_POST['full_name'])
        : '';
    $email = isset($_POST['email']) && is_string($_POST['email'])
        ? strtolower(trim($_POST['email']))
        : '';
    $regNumber = isset($_POST['reg_number']) && is_string($_POST['reg_number'])
        ? trim($_POST['reg_number'])
        : '';
    $phone = isset($_POST['phone']) && is_string($_POST['phone'])
        ? trim($_POST['phone'])
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
            redirect(BASE_URL . '/admin/manage_voters.php');
        }

        if ($action === 'add') {
            $password = isset($_POST['password']) && is_string($_POST['password'])
                ? $_POST['password']
                : '';
            if (strlen($password) < 8 || strlen($password) > 72) {
                set_flash('error', 'The initial password must be between 8 and 72 bytes.');
                redirect(BASE_URL . '/admin/manage_voters.php');
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
                $insertVoter->execute([
                    'user_id' => (int) $pdo->lastInsertId(),
                    'reg_number' => $details['reg_number'],
                    'phone' => $details['phone'],
                ]);
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
                    redirect(BASE_URL . '/admin/manage_voters.php');
                }

                throw $exception;
            }

            set_flash('success', 'Voter added. The account is pending approval.');
            redirect(BASE_URL . '/admin/manage_voters.php');
        }

        try {
            $pdo->beginTransaction();
            $existingVoter = $pdo->prepare(
                'SELECT v.id
                 FROM voters v
                 INNER JOIN users u ON u.id = v.user_id
                 WHERE v.user_id = :user_id AND u.role = \'voter\'
                 FOR UPDATE'
            );
            $existingVoter->execute(['user_id' => $userId]);
            if ($existingVoter->fetchColumn() === false) {
                $pdo->rollBack();
                set_flash('error', 'Voter account not found.');
                redirect(BASE_URL . '/admin/manage_voters.php');
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
                redirect(BASE_URL . '/admin/manage_voters.php');
            }

            throw $exception;
        }

        set_flash('success', 'Voter details updated.');
        redirect(BASE_URL . '/admin/manage_voters.php');
    }

    if ($action === 'review') {
        $userId = isset($_POST['user_id']) && is_string($_POST['user_id'])
            ? filter_var($_POST['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $decision = isset($_POST['decision']) && is_string($_POST['decision'])
            ? $_POST['decision']
            : '';
        $newStatus = ['approve' => 'active', 'reject' => 'rejected'][$decision] ?? null;

        if ($userId === false || $newStatus === null) {
            set_flash('error', 'Invalid voter review action.');
            redirect(BASE_URL . '/admin/manage_voters.php');
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
            redirect(BASE_URL . '/admin/manage_voters.php');
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
        $pdo->commit();

        $emailSent = send_voter_review_email($voter['email'], $voter['full_name'], $newStatus);
        $emailStatus = $emailSent ? 'sent' : 'failed';
        $saveEmailStatus = $pdo->prepare(
            'UPDATE voters SET review_email_status = :email_status WHERE user_id = :user_id'
        );
        $saveEmailStatus->execute([
            'email_status' => $emailStatus,
            'user_id' => $userId,
        ]);

        $decisionLabel = $decision === 'approve' ? 'approved' : 'rejected';
        if ($emailSent) {
            set_flash('success', 'Voter ' . $decisionLabel . ' and notification email sent.');
        } else {
            set_flash(
                'error',
                'Voter ' . $decisionLabel . ', but the notification email failed. Check mail settings and retry it.'
            );
        }
        redirect(BASE_URL . '/admin/manage_voters.php');
    }

    if ($action === 'retry_email') {
        $userId = isset($_POST['user_id']) && is_string($_POST['user_id'])
            ? filter_var($_POST['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($userId === false) {
            set_flash('error', 'Invalid voter notification action.');
            redirect(BASE_URL . '/admin/manage_voters.php');
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
            redirect(BASE_URL . '/admin/manage_voters.php');
        }

        $emailSent = send_voter_review_email($voter['email'], $voter['full_name'], $voter['status']);
        $saveEmailStatus = $pdo->prepare(
            'UPDATE voters SET review_email_status = :email_status WHERE user_id = :user_id'
        );
        $saveEmailStatus->execute([
            'email_status' => $emailSent ? 'sent' : 'failed',
            'user_id' => $userId,
        ]);

        set_flash(
            $emailSent ? 'success' : 'error',
            $emailSent
                ? 'Review email sent.'
                : 'The review email could not be sent. Check the mail settings and try again.'
        );
        redirect(BASE_URL . '/admin/manage_voters.php');
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
            redirect(BASE_URL . '/admin/manage_voters.php');
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
            redirect(BASE_URL . '/admin/manage_voters.php');
        }

        $updateVoter = $pdo->prepare(
            "UPDATE users SET status = :status WHERE id = :user_id AND role = 'voter'"
        );
        $updateVoter->execute([
            'status' => $newStatus,
            'user_id' => $userId,
        ]);
        set_flash('success', 'Voter account ' . $newStatus . '.');
        redirect(BASE_URL . '/admin/manage_voters.php');
    }

    set_flash('error', 'Unknown voter action.');
    redirect(BASE_URL . '/admin/manage_voters.php');
}

$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}

$voterSql = "
    SELECT u.id AS user_id, u.full_name, u.email, u.status, u.created_at,
           v.id AS voter_id, v.reg_number, v.phone, v.review_email_status
    FROM voters v
    INNER JOIN users u ON u.id = v.user_id
    WHERE u.role = 'voter'
";
$voterParameters = [];
if ($search !== '') {
    $voterSql .= ' AND (u.full_name LIKE :name_search OR v.reg_number LIKE :number_search)';
    $voterParameters = [
        'name_search' => '%' . $search . '%',
        'number_search' => '%' . $search . '%',
    ];
}
$voterSql .= " ORDER BY u.status = 'pending' DESC, u.created_at DESC, u.full_name";
$voterQuery = $pdo->prepare($voterSql);
$voterQuery->execute($voterParameters);
$voters = $voterQuery->fetchAll();

$pageTitle = 'Manage voters';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>Manage voters</h1>
                <p>Review registrations, maintain voter details, and manage account access.</p>
            </div>
        </header>

        <section class="card voter-add" aria-labelledby="add-voter-title">
            <h2 id="add-voter-title">Add a voter</h2>
            <p>New accounts remain pending until you approve them. Share the initial password securely.</p>
            <form class="form voter-create-form" method="post" action="<?= BASE_URL ?>/admin/manage_voters.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="form__group">
                    <label for="new_full_name">Full name</label>
                    <input id="new_full_name" name="full_name" type="text" maxlength="150" required>
                </div>
                <div class="form__group">
                    <label for="new_email">Email address</label>
                    <input id="new_email" name="email" type="email" maxlength="254" required>
                </div>
                <div class="form__group">
                    <label for="new_reg_number">Registration number</label>
                    <input id="new_reg_number" name="reg_number" type="text" maxlength="100" required>
                </div>
                <div class="form__group">
                    <label for="new_phone">Phone (optional)</label>
                    <input id="new_phone" name="phone" type="tel" maxlength="32">
                </div>
                <div class="form__group">
                    <label for="new_password">Initial password</label>
                    <input id="new_password" name="password" type="password" minlength="8" maxlength="72" required>
                </div>
                <button class="btn btn--primary" type="submit">Add pending voter</button>
            </form>
        </section>

        <form class="form voter-search" method="get" action="<?= BASE_URL ?>/admin/manage_voters.php">
            <div class="form__group">
                <label for="q">Search by name or registration number</label>
                <input id="q" name="q" type="search" maxlength="100" value="<?= sanitize($search) ?>">
            </div>
            <button class="btn btn--primary" type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a href="<?= BASE_URL ?>/admin/manage_voters.php">Clear search</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Voter</th>
                        <th scope="col">Registration number</th>
                        <th scope="col">Phone</th>
                        <th scope="col">Status</th>
                        <th scope="col">Review email</th>
                        <th scope="col">Registered</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($voters === []): ?>
                        <tr><td colspan="7">
                            <div class="empty-state" role="status">No voters found.</div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($voters as $voter): ?>
                            <tr>
                                <td>
                                    <strong><?= sanitize($voter['full_name']) ?></strong><br>
                                    <?= sanitize($voter['email']) ?>
                                </td>
                                <td><?= sanitize($voter['reg_number']) ?></td>
                                <td><?= sanitize((string) ($voter['phone'] ?? 'Not provided')) ?></td>
                                <td>
                                    <span class="badge badge--<?= sanitize($voter['status']) ?>">
                                        <?= sanitize(ucfirst($voter['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($voter['review_email_status'] === 'not_required'): ?>
                                        —
                                    <?php else: ?>
                                        <span class="badge badge--<?= sanitize($voter['review_email_status']) ?>">
                                            <?= sanitize(ucfirst($voter['review_email_status'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= sanitize($voter['created_at']) ?></td>
                                <td>
                                    <div class="admin-actions">
                                        <?php if ($voter['status'] === 'pending'): ?>
                                            <?php foreach (['approve' => 'Approve', 'reject' => 'Reject'] as $decision => $label): ?>
                                                <form method="post" action="<?= BASE_URL ?>/admin/manage_voters.php">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="review">
                                                    <input type="hidden" name="user_id" value="<?= (int) $voter['user_id'] ?>">
                                                    <button class="btn <?= $decision === 'approve' ? 'btn--primary' : 'btn--black' ?>"
                                                        type="submit" name="decision" value="<?= sanitize($decision) ?>">
                                                        <?= sanitize($label) ?>
                                                    </button>
                                                </form>
                                            <?php endforeach; ?>
                                        <?php elseif (in_array($voter['status'], ['active', 'suspended'], true)): ?>
                                            <form method="post" action="<?= BASE_URL ?>/admin/manage_voters.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="status">
                                                <input type="hidden" name="user_id" value="<?= (int) $voter['user_id'] ?>">
                                                <button class="btn <?= $voter['status'] === 'active' ? 'btn--black' : 'btn--primary' ?>"
                                                    type="submit" name="status"
                                                    value="<?= $voter['status'] === 'active' ? 'suspended' : 'active' ?>">
                                                    <?= $voter['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (
                                            in_array($voter['status'], ['active', 'rejected'], true)
                                            && in_array($voter['review_email_status'], ['pending', 'failed'], true)
                                        ): ?>
                                            <form method="post" action="<?= BASE_URL ?>/admin/manage_voters.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="retry_email">
                                                <input type="hidden" name="user_id" value="<?= (int) $voter['user_id'] ?>">
                                                <button class="btn btn--outline" type="submit">Retry email</button>
                                            </form>
                                        <?php endif; ?>

                                        <details class="voter-edit">
                                            <summary>Edit details</summary>
                                            <form class="form voter-edit-form" method="post"
                                                action="<?= BASE_URL ?>/admin/manage_voters.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_details">
                                                <input type="hidden" name="user_id" value="<?= (int) $voter['user_id'] ?>">
                                                <div class="form__group">
                                                    <label for="edit_name_<?= (int) $voter['user_id'] ?>">Full name</label>
                                                    <input id="edit_name_<?= (int) $voter['user_id'] ?>" name="full_name"
                                                        type="text" maxlength="150" value="<?= sanitize($voter['full_name']) ?>" required>
                                                </div>
                                                <div class="form__group">
                                                    <label for="edit_email_<?= (int) $voter['user_id'] ?>">Email address</label>
                                                    <input id="edit_email_<?= (int) $voter['user_id'] ?>" name="email"
                                                        type="email" maxlength="254" value="<?= sanitize($voter['email']) ?>" required>
                                                </div>
                                                <div class="form__group">
                                                    <label for="edit_reg_<?= (int) $voter['user_id'] ?>">Registration number</label>
                                                    <input id="edit_reg_<?= (int) $voter['user_id'] ?>" name="reg_number"
                                                        type="text" maxlength="100" value="<?= sanitize($voter['reg_number']) ?>" required>
                                                </div>
                                                <div class="form__group">
                                                    <label for="edit_phone_<?= (int) $voter['user_id'] ?>">Phone</label>
                                                    <input id="edit_phone_<?= (int) $voter['user_id'] ?>" name="phone"
                                                        type="tel" maxlength="32" value="<?= sanitize((string) ($voter['phone'] ?? '')) ?>">
                                                </div>
                                                <button class="btn btn--primary" type="submit">Save details</button>
                                            </form>
                                        </details>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
