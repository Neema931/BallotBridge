<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('candidate');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_scheduler.php';

activate_due_auto_elections($pdo);

$userId = (int) current_user()['id'];
$profileQuery = $pdo->prepare(
    "SELECT full_name, email, reg_number, status
     FROM users
     WHERE id = :user_id AND role = 'candidate'"
);
$profileQuery->execute(['user_id' => $userId]);
$profile = $profileQuery->fetch();

if (!$profile) {
    throw new RuntimeException('Candidate account was not found.');
}

$applicationsQuery = $pdo->prepare(
    'SELECT c.id, c.manifesto, c.photo_path, c.status AS application_status,
            e.id AS election_id, e.title AS election_title, e.status AS election_status,
            p.title AS position_title
     FROM candidates c
     INNER JOIN elections e ON e.id = c.election_id
     INNER JOIN positions p ON p.id = c.position_id
     WHERE c.user_id = :user_id
     ORDER BY e.start_datetime DESC, p.title'
);
$applicationsQuery->execute(['user_id' => $userId]);
$applications = $applicationsQuery->fetchAll();

$pageTitle = 'Candidate dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>Candidate dashboard</h1>
                <p>Welcome, <?= sanitize($profile['full_name']) ?>.</p>
            </div>
        </header>

        <section class="card candidate-profile-summary" aria-labelledby="candidate-profile-title">
            <div class="candidate-profile-summary__details">
                <h2 id="candidate-profile-title">Profile summary</h2>
                <p><strong>Name:</strong> <?= sanitize($profile['full_name']) ?></p>
                <p><strong>Email:</strong> <?= sanitize($profile['email']) ?></p>
                <p>
                    <strong>Account status:</strong>
                    <span class="badge badge--<?= sanitize($profile['status']) ?>">
                        <?= sanitize(ucfirst($profile['status'])) ?>
                    </span>
                </p>
            </div>
        </section>

        <section class="candidate-applications" aria-labelledby="candidate-applications-title">
            <div class="candidate-applications__header">
                <div>
                    <h2 id="candidate-applications-title">Election applications</h2>
                    <p>Application approval and election details are shown for each position.</p>
                </div>
            </div>

            <?php if ($applications === []): ?>
                <div class="empty-state" role="status">
                    <h3>No election applications</h3>
                    <p>No election application has been created for your account yet.</p>
                </div>
            <?php else: ?>
                <div class="grid grid--2">
                    <?php foreach ($applications as $application): ?>
                        <article class="card candidate-application">
                            <?php if (
                                is_string($application['photo_path'])
                                && preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png)\z~', $application['photo_path']) === 1
                            ): ?>
                                <img class="candidate-application__photo"
                                    src="<?= BASE_URL ?>/<?= sanitize($application['photo_path']) ?>"
                                    alt="Profile photo of <?= sanitize($profile['full_name']) ?>">
                            <?php endif; ?>
                            <h3><?= sanitize($application['election_title']) ?></h3>
                            <p><strong>Position:</strong> <?= sanitize($application['position_title']) ?></p>
                            <p>
                                <strong>Election status:</strong>
                                <span class="badge badge--<?= sanitize($application['election_status']) ?>">
                                    <?= sanitize(ucfirst($application['election_status'])) ?>
                                </span>
                            </p>
                            <p>
                                <strong>Application status:</strong>
                                <span class="badge badge--<?= sanitize($application['application_status']) ?>">
                                    <?= sanitize(ucfirst($application['application_status'])) ?>
                                </span>
                            </p>
                            <div class="candidate-application__manifesto">
                                <strong>Manifesto</strong>
                                <p><?= $application['manifesto'] !== null && $application['manifesto'] !== ''
                                    ? nl2br(sanitize($application['manifesto']))
                                    : 'No manifesto added yet.' ?></p>
                            </div>
                            <?php if (
                                $application['election_status'] === 'draft'
                                && $application['application_status'] !== 'rejected'
                            ): ?>
                                <a class="btn btn--outline"
                                    href="<?= BASE_URL ?>/candidate/edit_profile.php?candidate_id=<?= (int) $application['id'] ?>">
                                    Edit profile
                                </a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>