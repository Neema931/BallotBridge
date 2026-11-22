<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';

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

function election_manager_datetime($value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d\TH:i') !== $value
    ) {
        return null;
    }

    return $date->format('Y-m-d H:i:s');
}

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
        $title = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
        $description = isset($_POST['description']) && is_string($_POST['description'])
            ? trim($_POST['description'])
            : '';
        $startDate = election_manager_datetime($_POST['start_datetime'] ?? null);
        $endDate = election_manager_datetime($_POST['end_datetime'] ?? null);

        if (
            ($rawId !== '' && $electionId === false)
            || $title === ''
            || strlen($title) > 200
            || strlen($description) > 65535
            || $startDate === null
            || $endDate === null
            || $startDate >= $endDate
        ) {
            set_flash('error', 'Enter a title and a valid election schedule.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        if ($electionId !== null) {
            $existingQuery = $pdo->prepare(
                'SELECT status FROM elections WHERE id = :election_id'
            );
            $existingQuery->execute(['election_id' => $electionId]);
            $existing = $existingQuery->fetch();

            if (!$existing || $existing['status'] !== 'draft') {
                set_flash('error', 'Only draft elections can be edited.');
                redirect(BASE_URL . '/admin/manage_elections.php');
            }

            $saveElection = $pdo->prepare(
                'UPDATE elections
                 SET title = :title, description = :description,
                     start_datetime = :start_datetime, end_datetime = :end_datetime
                 WHERE id = :election_id'
            );
            $saveElection->execute([
                'title' => $title,
                'description' => $description === '' ? null : $description,
                'start_datetime' => $startDate,
                'end_datetime' => $endDate,
                'election_id' => $electionId,
            ]);
            set_flash('success', 'Election updated.');
        } else {
            $saveElection = $pdo->prepare(
                'INSERT INTO elections
                    (title, description, start_datetime, end_datetime, created_by)
                 VALUES
                    (:title, :description, :start_datetime, :end_datetime, :created_by)'
            );
            $saveElection->execute([
                'title' => $title,
                'description' => $description === '' ? null : $description,
                'start_datetime' => $startDate,
                'end_datetime' => $endDate,
                'created_by' => (int) current_user()['id'],
            ]);
            set_flash('success', 'Election created.');
        }

        redirect(BASE_URL . '/admin/manage_elections.php');
    }

    if ($action === 'delete_election') {
        $electionId = election_manager_positive_int($_POST['election_id'] ?? null);
        if ($electionId === false) {
            set_flash('error', 'Invalid election.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $electionQuery = $pdo->prepare(
            'SELECT status FROM elections WHERE id = :election_id'
        );
        $electionQuery->execute(['election_id' => $electionId]);
        $election = $electionQuery->fetch();

        if (!$election) {
            set_flash('error', 'Election not found.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }
        if ($election['status'] === 'open') {
            set_flash('error', 'Close an open election before deleting it.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $voteCountQuery = $pdo->prepare(
            'SELECT COUNT(*) FROM votes WHERE election_id = :election_id'
        );
        $voteCountQuery->execute(['election_id' => $electionId]);
        if ((int) $voteCountQuery->fetchColumn() > 0) {
            set_flash('error', 'An election with recorded votes cannot be deleted.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $deleteElection = $pdo->prepare('DELETE FROM elections WHERE id = :election_id');
        $deleteElection->execute(['election_id' => $electionId]);
        set_flash('success', 'Election deleted.');
        redirect(BASE_URL . '/admin/manage_elections.php');
    }

    if ($action === 'open_election' || $action === 'close_election') {
        $electionId = election_manager_positive_int($_POST['election_id'] ?? null);
        if ($electionId === false) {
            set_flash('error', 'Invalid election.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $electionQuery = $pdo->prepare(
            'SELECT status FROM elections WHERE id = :election_id'
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
        } else {
            if ($election['status'] !== 'open') {
                set_flash('error', 'Only open elections can be closed.');
                redirect(BASE_URL . '/admin/manage_elections.php');
            }
            $newStatus = 'closed';
        }

        $changeStatus = $pdo->prepare(
            'UPDATE elections SET status = :status WHERE id = :election_id'
        );
        $changeStatus->execute([
            'status' => $newStatus,
            'election_id' => $electionId,
        ]);
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
                'SELECT id FROM positions
                 WHERE id = :position_id AND election_id = :election_id'
            );
            $positionQuery->execute([
                'position_id' => $positionId,
                'election_id' => $electionId,
            ]);
            if ($positionQuery->fetchColumn() === false) {
                set_flash('error', 'Position not found in the selected election.');
                redirect(BASE_URL . '/admin/manage_elections.php');
            }

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
            set_flash('success', 'Position updated.');
        } else {
            $savePosition = $pdo->prepare(
                'INSERT INTO positions (election_id, title, max_votes)
                 VALUES (:election_id, :title, :max_votes)'
            );
            $savePosition->execute([
                'election_id' => $electionId,
                'title' => $title,
                'max_votes' => $maxVotes,
            ]);
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
            'SELECT p.election_id, e.status AS election_status
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

        $voteCountQuery = $pdo->prepare(
            'SELECT COUNT(*) FROM votes WHERE position_id = :position_id'
        );
        $voteCountQuery->execute(['position_id' => $positionId]);
        if ((int) $voteCountQuery->fetchColumn() > 0) {
            set_flash('error', 'A position with recorded votes cannot be deleted.');
            redirect(BASE_URL . '/admin/manage_elections.php');
        }

        $deletePosition = $pdo->prepare('DELETE FROM positions WHERE id = :position_id');
        $deletePosition->execute(['position_id' => $positionId]);
        set_flash('success', 'Position deleted.');
        redirect(BASE_URL . '/admin/manage_elections.php');
    }

    set_flash('error', 'Unknown election management action.');
    redirect(BASE_URL . '/admin/manage_elections.php');
}

$electionsQuery = $pdo->prepare(
    'SELECT id, title, description, start_datetime, end_datetime, status
     FROM elections ORDER BY id DESC'
);
$electionsQuery->execute();
$elections = $electionsQuery->fetchAll();
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
        "SELECT id, title, description, start_datetime, end_datetime
         FROM elections WHERE id = :election_id AND status = 'draft'"
    );
    $editElectionQuery->execute(['election_id' => $editElectionId]);
    $editElection = $editElectionQuery->fetch();
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
$pageTitle = 'Manage elections';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>Manage elections</h1>
                <p>Create elections and positions, then open or close voting.</p>
            </div>
        </header>

        <div class="grid grid--2 election-editors">
            <section class="form-card" aria-labelledby="election-form-title">
                <header class="form-card__header">
                    <h2 id="election-form-title"><?= $editElection ? 'Edit election' : 'Create election' ?></h2>
                </header>
                <form class="form" method="post" action="<?= BASE_URL ?>/admin/manage_elections.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_election">
                    <input type="hidden" name="election_id" value="<?= $editElection ? (int) $editElection['id'] : '' ?>">

                    <div class="form__group">
                        <label for="title">Title</label>
                        <input id="title" name="title" type="text" maxlength="200"
                            value="<?= $editElection ? sanitize($editElection['title']) : '' ?>" required>
                    </div>
                    <div class="form__group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" maxlength="65535"><?= $editElection
                            ? sanitize((string) $editElection['description'])
                            : '' ?></textarea>
                    </div>
                    <div class="form__group">
                        <label for="start_datetime">Starts</label>
                        <input id="start_datetime" name="start_datetime" type="datetime-local"
                            value="<?= $editElection
                                ? sanitize(str_replace(' ', 'T', substr($editElection['start_datetime'], 0, 16)))
                                : '' ?>"
                            required>
                    </div>
                    <div class="form__group">
                        <label for="end_datetime">Ends</label>
                        <input id="end_datetime" name="end_datetime" type="datetime-local"
                            value="<?= $editElection
                                ? sanitize(str_replace(' ', 'T', substr($editElection['end_datetime'], 0, 16)))
                                : '' ?>"
                            required>
                    </div>
                    <div class="form-card__actions">
                        <button class="btn btn--primary" type="submit">
                            <?= $editElection ? 'Save election' : 'Create election' ?>
                        </button>
                        <?php if ($editElection): ?>
                            <a href="<?= BASE_URL ?>/admin/manage_elections.php">Cancel edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="form-card" aria-labelledby="position-form-title">
                <header class="form-card__header">
                    <h2 id="position-form-title"><?= $editPosition ? 'Edit position' : 'Add a position' ?></h2>
                </header>
                <?php if ($draftElections === []): ?>
                    <p>Create a draft election before adding positions.</p>
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
                        </div>
                        <div class="form-card__actions">
                            <button class="btn btn--primary" type="submit">
                                <?= $editPosition ? 'Save position' : 'Add position' ?>
                            </button>
                            <?php if ($editPosition): ?>
                                <a href="<?= BASE_URL ?>/admin/manage_elections.php">Cancel edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        </div>

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
                    <article class="card election-card">
                        <div class="election-card__header">
                            <div>
                                <h3><?= sanitize($election['title']) ?></h3>
                                <p><?= sanitize($election['start_datetime']) ?> to
                                    <?= sanitize($election['end_datetime']) ?></p>
                            </div>
                            <span class="badge badge--<?= sanitize($election['status']) ?>">
                                <?= sanitize(ucfirst($election['status'])) ?>
                            </span>
                        </div>
                        <?php if ($election['description'] !== null && $election['description'] !== ''): ?>
                            <p><?= nl2br(sanitize($election['description'])) ?></p>
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
                                <form method="post" action="<?= BASE_URL ?>/admin/manage_elections.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="close_election">
                                    <input type="hidden" name="election_id" value="<?= $electionId ?>">
                                    <button class="btn btn--black" type="submit">Close election</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($election['status'] !== 'open'): ?>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>