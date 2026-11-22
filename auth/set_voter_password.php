<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

header('Cache-Control: no-store, private');

$token = isset($_POST['token']) && is_string($_POST['token'])
    ? $_POST['token']
    : (isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '');
$tokenIsValid = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
$tokenRecord = null;
if ($tokenIsValid) {
    $tokenQuery = $pdo->prepare(
        'SELECT t.id, t.user_id
         FROM voter_password_tokens t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = :token_hash
           AND t.used_at IS NULL
           AND t.expires_at > UTC_TIMESTAMP()
           AND u.role = \'voter\'
           AND u.status IN (\'pending\', \'active\')
         LIMIT 1'
    );
    $tokenQuery->execute(['token_hash' => hash('sha256', $token)]);
    $tokenRecord = $tokenQuery->fetch() ?: null;
}

$passwordError = null;
if (is_post()) {
    verify_csrf();
    $password = isset($_POST['password']) && is_string($_POST['password'])
        ? $_POST['password']
        : '';
    $confirmation = isset($_POST['password_confirmation']) && is_string($_POST['password_confirmation'])
        ? $_POST['password_confirmation']
        : '';
    if (!$tokenRecord) {
        $passwordError = 'This password link is invalid, expired, or already used. Request a new one from the administrator.';
    } elseif (strlen($password) < 8 || strlen($password) > 72) {
        $passwordError = 'Choose a password between 8 and 72 bytes.';
    } elseif (!hash_equals($password, $confirmation)) {
        $passwordError = 'The password confirmation does not match.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('Unable to secure the voter password.');
        }

        $pdo->beginTransaction();
        $lockToken = $pdo->prepare(
            'SELECT id FROM voter_password_tokens
             WHERE id = :token_id AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
             FOR UPDATE'
        );
        $lockToken->execute(['token_id' => $tokenRecord['id']]);
        if (!$lockToken->fetchColumn()) {
            $pdo->rollBack();
            $passwordError = 'This password link is invalid, expired, or already used. Request a new one from the administrator.';
        } else {
            $updatePassword = $pdo->prepare(
                "UPDATE users SET password_hash = :password_hash
                 WHERE id = :user_id AND role = 'voter' AND status IN ('pending', 'active')"
            );
            $updatePassword->execute([
                'password_hash' => $passwordHash,
                'user_id' => $tokenRecord['user_id'],
            ]);
            if ($updatePassword->rowCount() !== 1) {
                $pdo->rollBack();
                $passwordError = 'This password link is invalid, expired, or already used. Request a new one from the administrator.';
            } else {
                $consumeToken = $pdo->prepare(
                    'UPDATE voter_password_tokens
                     SET used_at = UTC_TIMESTAMP()
                     WHERE id = :token_id AND used_at IS NULL'
                );
                $consumeToken->execute(['token_id' => $tokenRecord['id']]);
                log_security_event(
                    $pdo,
                    'security.voter_password_updated',
                    'A voter set or reset their password using a one-time email link.',
                    (int) $tokenRecord['user_id']
                );
                $pdo->commit();
                set_flash('success', 'Your password has been set. Sign in after your registration is approved.');
                redirect(BASE_URL . '/auth/login.php');
            }
        }
    }
}

$pageTitle = 'Set voter password';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <div class="form-card">
            <h1>Set your password</h1>
            <?php if ($passwordError !== null): ?>
                <div class="alert alert--error" role="alert"><?= sanitize($passwordError) ?></div>
            <?php endif; ?>
            <?php if ($tokenRecord): ?>
                <p>Choose a password for your Ballot Bridge voter account.</p>
                <form class="form" method="post" action="<?= BASE_URL ?>/auth/set_voter_password.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= sanitize($token) ?>">
                    <div class="form__group">
                        <label for="password">New password</label>
                        <input id="password" name="password" type="password" minlength="8" maxlength="72" required autocomplete="new-password">
                    </div>
                    <div class="form__group">
                        <label for="password_confirmation">Confirm password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" required autocomplete="new-password">
                    </div>
                    <button class="btn btn--primary" type="submit">Set password</button>
                </form>
            <?php else: ?>
                <p>Request a new password setup link from your election administrator.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
