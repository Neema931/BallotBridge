<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';

if (is_post()) {
    verify_csrf();

    $accountDecision = isset($_POST['account_decision']) && is_string($_POST['account_decision'])
        ? $_POST['account_decision']
        : '';
    if ($accountDecision !== '') {
        $candidateUserId = isset($_POST['candidate_user_id']) && is_string($_POST['candidate_user_id'])
            ? filter_var($_POST['candidate_user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $accountStatus = ['approve' => 'active', 'reject' => 'suspended'][$accountDecision] ?? null;

        if ($candidateUserId === false || $accountStatus === null) {
            set_flash('error', 'Invalid candidate account action.');
            redirect(BASE_URL . '/admin/manage_candidates.php');
        }

        $accountUpdate = $pdo->prepare(
            "UPDATE users
             SET status = :status
             WHERE id = :user_id
               AND role = 'candidate'
               AND status = 'pending'
               AND NOT EXISTS (
                   SELECT 1 FROM candidates c
                   WHERE c.user_id = users.id AND c.status = 'pending'
               )"
        );
        $accountUpdate->execute([
            'status' => $accountStatus,
            'user_id' => $candidateUserId,
        ]);

        if ($accountUpdate->rowCount() !== 1) {
            set_flash('error', 'Pending candidate account not found or has a pending election application.');
            redirect(BASE_URL . '/admin/manage_candidates.php');
        }

        set_flash('success', 'Candidate account ' . $accountStatus . '.');
        redirect(BASE_URL . '/admin/manage_candidates.php');
    }

    $candidateId = isset($_POST['candidate_id']) && is_string($_POST['candidate_id'])
        ? filter_var($_POST['candidate_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $decision = isset($_POST['decision']) && is_string($_POST['decision'])
        ? $_POST['decision']
        : '';
    $newStatus = ['approve' => 'approved', 'reject' => 'rejected'][$decision] ?? null;

    if ($candidateId === false || $newStatus === null) {
        set_flash('error', 'Invalid candidate action.');
        redirect(BASE_URL . '/admin/manage_candidates.php');
    }

    $pdo->beginTransaction();
    $candidateQuery = $pdo->prepare(
        'SELECT c.user_id, c.status, e.status AS election_status
         FROM candidates c
         INNER JOIN elections e ON e.id = c.election_id
         WHERE c.id = :candidate_id
         FOR UPDATE'
    );
    $candidateQuery->execute(['candidate_id' => $candidateId]);
    $candidate = $candidateQuery->fetch();

    if (
        !$candidate
        || $candidate['status'] !== 'pending'
        || $candidate['election_status'] !== 'draft'
    ) {
        $pdo->rollBack();
        set_flash('error', 'Only pending candidates in draft elections can be reviewed.');
        redirect(BASE_URL . '/admin/manage_candidates.php');
    }

    $updateCandidate = $pdo->prepare(
        'UPDATE candidates SET status = :status WHERE id = :candidate_id'
    );
    $updateCandidate->execute([
        'status' => $newStatus,
        'candidate_id' => $candidateId,
    ]);

    if ($newStatus === 'approved') {
        $activateUser = $pdo->prepare(
            "UPDATE users SET status = 'active'
             WHERE id = :user_id AND role = 'candidate' AND status = 'pending'"
        );
        $activateUser->execute(['user_id' => $candidate['user_id']]);
    }

    $pdo->commit();
    set_flash('success', 'Candidate ' . $newStatus . '.');
    redirect(BASE_URL . '/admin/manage_candidates.php');
}

$statusFilter = isset($_GET['status']) && is_string($_GET['status'])
    ? $_GET['status']
    : 'all';
if (!in_array($statusFilter, ['all', 'pending', 'approved', 'rejected'], true)) {
    $statusFilter = 'all';
}

$electionFilter = isset($_GET['election_id']) && is_string($_GET['election_id'])
    ? filter_var($_GET['election_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

$conditions = [];
$parameters = [];
if ($statusFilter !== 'all') {
    $conditions[] = 'c.status = :candidate_status';
    $parameters['candidate_status'] = $statusFilter;
}
if ($electionFilter !== false) {
    $conditions[] = 'c.election_id = :election_id';
    $parameters['election_id'] = $electionFilter;
}

$candidateSql = '
    SELECT c.id, c.user_id, c.election_id, c.position_id, c.manifesto, c.photo_path,
           c.status, u.full_name, u.email, u.status AS account_status,
           e.title AS election_title, e.status AS election_status,
           p.title AS position_title
    FROM candidates c
    INNER JOIN users u ON u.id = c.user_id
    INNER JOIN elections e ON e.id = c.election_id
    INNER JOIN positions p ON p.id = c.position_id
';
if ($conditions !== []) {
    $candidateSql .= ' WHERE ' . implode(' AND ', $conditions);
}
$candidateSql .= ' ORDER BY c.status = \'pending\' DESC, e.start_datetime DESC, u.full_name';

$candidateQuery = $pdo->prepare($candidateSql);
$candidateQuery->execute($parameters);
$candidates = $candidateQuery->fetchAll();
$pendingAccountQuery = $pdo->prepare(
    "SELECT u.id, u.full_name, u.email, u.reg_number, u.created_at
     FROM users u
     WHERE u.role = 'candidate'
       AND u.status = 'pending'
       AND NOT EXISTS (
           SELECT 1 FROM candidates c
           WHERE c.user_id = u.id AND c.status = 'pending'
       )
     ORDER BY u.created_at, u.full_name"
);
$pendingAccountQuery->execute();
$pendingCandidateAccounts = $pendingAccountQuery->fetchAll();
$electionsQuery = $pdo->prepare('SELECT id, title FROM elections ORDER BY start_datetime DESC');
$electionsQuery->execute();
$elections = $electionsQuery->fetchAll();

$pageTitle = 'Manage candidates';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>Manage candidates</h1>
                <p>Review candidate applications for draft elections.</p>
            </div>
        </header>

        <section class="candidate-accounts" aria-labelledby="candidate-accounts-title">
            <h2 id="candidate-accounts-title">Pending candidate accounts</h2>
            <p>These candidates have registered but have not yet applied to an election position.</p>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Candidate</th>
                            <th scope="col">Registration number</th>
                            <th scope="col">Registered</th>
                            <th scope="col">Decision</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($pendingCandidateAccounts === []): ?>
                            <tr><td colspan="4">
                                <div class="empty-state" role="status">No pending candidate accounts.</div>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($pendingCandidateAccounts as $account): ?>
                                <tr>
                                    <td>
                                        <strong><?= sanitize($account['full_name']) ?></strong><br>
                                        <?= sanitize($account['email']) ?>
                                    </td>
                                    <td><?= sanitize((string) $account['reg_number']) ?></td>
                                    <td><?= sanitize($account['created_at']) ?></td>
                                    <td>
                                        <div class="admin-actions">
                                            <?php foreach (['approve' => 'Approve account', 'reject' => 'Reject account'] as $decision => $label): ?>
                                                <form method="post" action="<?= BASE_URL ?>/admin/manage_candidates.php">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="candidate_user_id" value="<?= (int) $account['id'] ?>">
                                                    <button class="btn <?= $decision === 'approve' ? 'btn--primary' : 'btn--black' ?>"
                                                        type="submit" name="account_decision" value="<?= sanitize($decision) ?>">
                                                        <?= sanitize($label) ?>
                                                    </button>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <h2 class="candidate-list-title">Election applications</h2>
        <form class="form candidate-filters" method="get" action="<?= BASE_URL ?>/admin/manage_candidates.php">
            <div class="form__group">
                <label for="status">Candidate status</label>
                <select id="status" name="status">
                    <?php foreach (['all' => 'All statuses', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label): ?>
                        <option value="<?= sanitize($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>>
                            <?= sanitize($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form__group">
                <label for="election_id">Election</label>
                <select id="election_id" name="election_id">
                    <option value="">All elections</option>
                    <?php foreach ($elections as $election): ?>
                        <option value="<?= (int) $election['id'] ?>"
                            <?= $electionFilter === (int) $election['id'] ? 'selected' : '' ?>>
                            <?= sanitize($election['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn--primary" type="submit">Filter candidates</button>
        </form>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Candidate</th>
                        <th scope="col">Election / position</th>
                        <th scope="col">Status</th>
                        <th scope="col">Manifesto</th>
                        <th scope="col">Review</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($candidates === []): ?>
                        <tr><td colspan="5">
                            <div class="empty-state" role="status">No candidates match these filters.</div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($candidates as $candidate): ?>
                            <tr>
                                <td>
                                    <strong><?= sanitize($candidate['full_name']) ?></strong><br>
                                    <?= sanitize($candidate['email']) ?>
                                </td>
                                <td>
                                    <?= sanitize($candidate['election_title']) ?><br>
                                    <?= sanitize($candidate['position_title']) ?>
                                </td>
                                <td>
                                    <span class="badge badge--<?= sanitize($candidate['status']) ?>">
                                        <?= sanitize(ucfirst($candidate['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $candidate['manifesto'] !== null && $candidate['manifesto'] !== ''
                                        ? sanitize($candidate['manifesto'])
                                        : 'Not provided' ?>
                                </td>
                                <td>
                                    <?php if ($candidate['status'] === 'pending' && $candidate['election_status'] === 'draft'): ?>
                                        <div class="admin-actions">
                                            <form method="post" action="<?= BASE_URL ?>/admin/manage_candidates.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="candidate_id" value="<?= (int) $candidate['id'] ?>">
                                                <button class="btn btn--primary" type="submit" name="decision" value="approve">
                                                    Approve
                                                </button>
                                            </form>
                                            <form method="post" action="<?= BASE_URL ?>/admin/manage_candidates.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="candidate_id" value="<?= (int) $candidate['id'] ?>">
                                                <button class="btn btn--black" type="submit" name="decision" value="reject">
                                                    Reject
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        Not available
                                    <?php endif; ?>
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