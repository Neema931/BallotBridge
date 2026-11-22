<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../config/database.php';

function audit_logs_parse_date($value): ?DateTimeImmutable
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        return null;
    }

    return $date;
}

function audit_logs_category_sql(string $category): ?string
{
    return [
        'election_state' => "a.action IN (
            'election.open', 'election.paused', 'election.resumed', 'election.closed',
            'election.auto_opened', 'election.auto_closed', 'election.deleted'
        )",
        'candidate_edits' => "(a.action LIKE 'candidate.%' OR a.action LIKE 'position.%' OR a.action LIKE 'ballot.%')",
        'voter_roll' => "a.action LIKE 'voter.%'",
        'security_alerts' => "(a.action LIKE 'security.%' OR a.action LIKE 'auth.%' OR a.action LIKE 'login.%')",
    ][$category] ?? null;
}

function audit_logs_action_badge_class(string $action): string
{
    if (preg_match('/(?:deleted|closed|paused|resumed|\\.open|reset|rejected|failed|throttled|blocked)/i', $action) === 1) {
        return 'audit-action--critical';
    }
    if (preg_match('/(?:created|assigned|approved)/i', $action) === 1) {
        return 'audit-action--addition';
    }

    return 'audit-action--edit';
}

function audit_logs_action_label(string $action): string
{
    return ucwords(str_replace(['.', '_', '-'], ' ', $action));
}

function audit_logs_pretty_json(?string $json): ?string
{
    if ($json === null) {
        return null;
    }

    return json_encode(
        json_decode($json, true, 512, JSON_THROW_ON_ERROR),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

function audit_logs_csv_cell(string $value): string
{
    return preg_match('/^[\s]*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
}

$filterError = null;
$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$search = substr($search, 0, 120);
$adminId = isset($_GET['admin_id']) && is_string($_GET['admin_id'])
    ? filter_var($_GET['admin_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$adminId = $adminId === false ? null : (int) $adminId;
$category = isset($_GET['category']) && is_string($_GET['category']) ? $_GET['category'] : '';
if (audit_logs_category_sql($category) === null) {
    $category = '';
}

$fromInput = isset($_GET['from']) && is_string($_GET['from']) ? $_GET['from'] : '';
$toInput = isset($_GET['to']) && is_string($_GET['to']) ? $_GET['to'] : '';
$fromDate = $fromInput === '' ? null : audit_logs_parse_date($fromInput);
$toDate = $toInput === '' ? null : audit_logs_parse_date($toInput);
if (($fromInput !== '' && $fromDate === null) || ($toInput !== '' && $toDate === null)) {
    $filterError = 'Enter valid dates to filter the audit log.';
    $fromDate = null;
    $toDate = null;
    $fromInput = '';
    $toInput = '';
} elseif ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
    $filterError = 'The start date must be on or before the end date.';
    $fromDate = null;
    $toDate = null;
    $fromInput = '';
    $toInput = '';
}

$where = [];
$parameters = [];
if ($search !== '') {
    $where[] = '(a.summary LIKE :search_summary OR a.action LIKE :search_action
        OR a.entity_type LIKE :search_entity OR CAST(a.entity_id AS CHAR) LIKE :search_id
        OR COALESCE(a.actor_name_snapshot, u.full_name) LIKE :search_name
        OR a.ip_address LIKE :search_ip)';
    $searchPattern = '%' . $search . '%';
    $parameters += [
        'search_summary' => $searchPattern,
        'search_action' => $searchPattern,
        'search_entity' => $searchPattern,
        'search_id' => $searchPattern,
        'search_name' => $searchPattern,
        'search_ip' => $searchPattern,
    ];
}
if ($adminId !== null) {
    $where[] = 'a.actor_user_id = :admin_id';
    $parameters['admin_id'] = $adminId;
}
if ($category !== '') {
    $where[] = audit_logs_category_sql($category);
}
if ($fromDate !== null) {
    $where[] = 'UNIX_TIMESTAMP(a.created_at) >= :from_epoch';
    $parameters['from_epoch'] = $fromDate->getTimestamp();
}
if ($toDate !== null) {
    $where[] = 'UNIX_TIMESTAMP(a.created_at) < :to_epoch';
    $parameters['to_epoch'] = $toDate->modify('+1 day')->getTimestamp();
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

$adminOptions = $pdo->query(
    "SELECT DISTINCT u.id, u.full_name
     FROM audit_logs a
     INNER JOIN users u ON u.id = a.actor_user_id
     WHERE u.role = 'admin'
     ORDER BY u.full_name, u.id"
)->fetchAll();

$countQuery = $pdo->prepare(
    'SELECT COUNT(*)
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.actor_user_id' . $whereSql
);
$countQuery->execute($parameters);
$totalRows = (int) $countQuery->fetchColumn();

$isCsvExport = isset($_GET['export']) && $_GET['export'] === 'csv';
$isPrintView = isset($_GET['print']) && $_GET['print'] === '1';
$pageSize = 50;
$page = isset($_GET['page']) && is_string($_GET['page'])
    ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$page = $page === false ? 1 : (int) $page;
$pageCount = max(1, (int) ceil($totalRows / $pageSize));
$page = min($page, $pageCount);
$offset = ($page - 1) * $pageSize;
$limitSql = $isCsvExport || $isPrintView ? '' : ' LIMIT :limit OFFSET :offset';
$auditQuery = $pdo->prepare(
    'SELECT a.id, a.actor_user_id, a.action, a.entity_type, a.entity_id, a.summary,
            a.ip_address, a.before_json, a.after_json, UNIX_TIMESTAMP(a.created_at) AS created_epoch,
            COALESCE(a.actor_name_snapshot, u.full_name) AS actor_name,
            COALESCE(a.actor_role_snapshot, u.role) AS actor_role
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.actor_user_id' . $whereSql . '
     ORDER BY a.id DESC' . $limitSql
);
foreach ($parameters as $name => $value) {
    $auditQuery->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
if (!$isCsvExport && !$isPrintView) {
    $auditQuery->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $auditQuery->bindValue(':offset', $offset, PDO::PARAM_INT);
}
$auditQuery->execute();

if ($isCsvExport) {
    $filename = 'ballot-bridge-audit-' . gmdate('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to create the audit report.');
    }
    fputcsv($output, [
        'Timestamp (UTC)',
        'Admin User',
        'Role',
        'Action',
        'Record',
        'Detailed Description',
        'IP Address',
        'Before JSON',
        'After JSON',
    ]);
    while ($row = $auditQuery->fetch()) {
        $actorName = $row['actor_name']
            ?? ($row['actor_user_id'] === null && str_starts_with($row['action'], 'election.auto_')
                ? 'Automated system'
                : (str_starts_with($row['action'], 'security.') ? 'Security event' : 'Deleted administrator'));
        $record = ucfirst(str_replace('_', ' ', $row['entity_type']))
            . ($row['entity_id'] === null ? '' : '#' . (int) $row['entity_id']);
        fputcsv($output, array_map('audit_logs_csv_cell', [
            gmdate('Y-m-d H:i:s', (int) $row['created_epoch']),
            $actorName,
            $row['actor_role'] ?? (in_array($actorName, ['Automated system', 'Security event'], true) ? 'System' : 'Admin'),
            audit_logs_action_label($row['action']),
            $record,
            $row['summary'],
            $row['ip_address'] ?? '',
            $row['before_json'] ?? '',
            $row['after_json'] ?? '',
        ]));
    }
    fclose($output);
    exit;
}

$auditLogs = $auditQuery->fetchAll();
$filterQuery = [
    'q' => $search,
    'admin_id' => $adminId,
    'category' => $category,
    'from' => $fromInput,
    'to' => $toInput,
];
$filterQuery = array_filter($filterQuery, static fn ($value): bool => $value !== null && $value !== '');
$csvUrl = BASE_URL . '/admin/audit_logs.php?' . http_build_query($filterQuery + ['export' => 'csv']);
$printUrl = BASE_URL . '/admin/audit_logs.php?' . http_build_query($filterQuery + ['print' => '1']);
$pageTitle = 'Audit logs';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section audit-ledger">
    <div class="container">
        <header class="dashboard__header audit-ledger__header">
            <div>
                <p class="audit-ledger__eyebrow">Forensic ledger</p>
                <h1>Audit logs</h1>
                <p>Review administrative actions and critical platform events.</p>
            </div>
            <div class="audit-ledger__export">
                <span>Export CSV / PDF Report</span>
                <a class="btn btn--outline" href="<?= sanitize($csvUrl) ?>">Download CSV</a>
                <a class="btn btn--primary" href="<?= sanitize($printUrl) ?>" target="_blank" rel="noopener">
                    Print / Save PDF
                </a>
            </div>
        </header>

        <aside class="audit-ledger__trust" aria-label="Audit log integrity">
            <span class="audit-ledger__trust-mark" aria-hidden="true">✓</span>
            <div>
                <strong>Read-only, append-only ledger</strong>
                <p>Database triggers reject updates and deletes to audit entries. Timestamps are shown in UTC.</p>
            </div>
        </aside>

        <?php if ($filterError !== null): ?>
            <div class="alert alert--error" role="alert"><?= sanitize($filterError) ?></div>
        <?php endif; ?>

        <form class="audit-filters" method="get" action="<?= BASE_URL ?>/admin/audit_logs.php">
            <div class="audit-filters__search">
                <label for="audit-search">Keyword search</label>
                <input id="audit-search" name="q" type="search" value="<?= sanitize($search) ?>"
                    placeholder="Search action, description, admin, IP">
            </div>
            <div>
                <label for="audit-admin">Admin user</label>
                <select id="audit-admin" name="admin_id">
                    <option value="">All administrators</option>
                    <?php foreach ($adminOptions as $adminOption): ?>
                        <option value="<?= (int) $adminOption['id'] ?>"
                            <?= $adminId === (int) $adminOption['id'] ? 'selected' : '' ?>>
                            <?= sanitize($adminOption['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="audit-category">Action category</label>
                <select id="audit-category" name="category">
                    <option value="">All categories</option>
                    <option value="election_state" <?= $category === 'election_state' ? 'selected' : '' ?>>Election State Changes</option>
                    <option value="candidate_edits" <?= $category === 'candidate_edits' ? 'selected' : '' ?>>Candidate Edits</option>
                    <option value="voter_roll" <?= $category === 'voter_roll' ? 'selected' : '' ?>>Voter Roll Updates</option>
                    <option value="security_alerts" <?= $category === 'security_alerts' ? 'selected' : '' ?>>Security Alerts</option>
                </select>
            </div>
            <div>
                <label for="audit-from">From (UTC)</label>
                <input id="audit-from" name="from" type="date" value="<?= sanitize($fromInput) ?>">
            </div>
            <div>
                <label for="audit-to">To (UTC)</label>
                <input id="audit-to" name="to" type="date" value="<?= sanitize($toInput) ?>">
            </div>
            <div class="audit-filters__actions">
                <button class="btn btn--primary" type="submit">Apply filters</button>
                <a class="btn btn--outline" href="<?= BASE_URL ?>/admin/audit_logs.php">Clear</a>
            </div>
        </form>

        <div class="audit-ledger__result-count" role="status">
            <?= number_format($totalRows) ?> <?= $totalRows === 1 ? 'entry' : 'entries' ?>
            <?= ($search !== '' || $adminId !== null || $category !== '' || $fromDate !== null || $toDate !== null)
                ? ' match these filters.'
                : ' recorded.' ?>
        </div>

        <div class="table-wrap audit-table-wrap">
            <table class="table audit-table">
                <thead>
                    <tr>
                        <th scope="col">Timestamp (UTC)</th>
                        <th scope="col">Admin User</th>
                        <th scope="col">Action</th>
                        <th scope="col">Detailed Description</th>
                        <th scope="col">IP Address</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($auditLogs === []): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state" role="status">
                                    No audit entries match the selected filters.
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($auditLogs as $auditLog): ?>
                            <?php
                            $actorName = $auditLog['actor_name']
                                ?? ($auditLog['actor_user_id'] === null && str_starts_with($auditLog['action'], 'election.auto_')
                                    ? 'Automated system'
                                    : (str_starts_with($auditLog['action'], 'security.') ? 'Security event' : 'Deleted administrator'));
                            $actorRole = $auditLog['actor_role']
                                ?? ($actorName === 'Automated system' ? 'System' : 'Admin');
                            $recordLabel = ucfirst(str_replace('_', ' ', $auditLog['entity_type']))
                                . ($auditLog['entity_id'] === null ? '' : ' #' . (int) $auditLog['entity_id']);
                            $beforeJson = audit_logs_pretty_json($auditLog['before_json']);
                            $afterJson = audit_logs_pretty_json($auditLog['after_json']);
                            ?>
                            <tr>
                                <td><time class="audit-timestamp" datetime="<?= gmdate('Y-m-d\TH:i:s\Z', (int) $auditLog['created_epoch']) ?>"><?= gmdate('Y-m-d H:i:s', (int) $auditLog['created_epoch']) ?></time></td>
                                <td>
                                    <strong><?= sanitize($actorName) ?></strong>
                                    <span class="audit-user-role"><?= sanitize(ucfirst($actorRole)) ?></span>
                                </td>
                                <td>
                                    <span class="audit-action <?= audit_logs_action_badge_class($auditLog['action']) ?>">
                                        <?= sanitize(audit_logs_action_label($auditLog['action'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <p class="audit-description"><?= sanitize($auditLog['summary']) ?></p>
                                    <span class="audit-record"><?= sanitize($recordLabel) ?></span>
                                </td>
                                <td><code class="audit-ip"><?= sanitize($auditLog['ip_address'] ?? '—') ?></code></td>
                                <td>
                                    <details class="audit-details">
                                        <summary>View Details</summary>
                                        <div class="audit-details__content">
                                            <p><strong>Record:</strong> <?= sanitize($recordLabel) ?></p>
                                            <div class="audit-diff">
                                                <section>
                                                    <h3>Before</h3>
                                                    <?php if ($beforeJson === null): ?>
                                                        <p class="audit-diff__empty">No before-state was captured for this entry.</p>
                                                    <?php else: ?>
                                                        <pre><?= sanitize($beforeJson) ?></pre>
                                                    <?php endif; ?>
                                                </section>
                                                <section>
                                                    <h3>After</h3>
                                                    <?php if ($afterJson === null): ?>
                                                        <p class="audit-diff__empty">No after-state was captured for this entry.</p>
                                                    <?php else: ?>
                                                        <pre><?= sanitize($afterJson) ?></pre>
                                                    <?php endif; ?>
                                                </section>
                                            </div>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!$isPrintView && !$isCsvExport && $pageCount > 1): ?>
            <nav class="audit-pagination" aria-label="Audit log pages">
                <span>Page <?= $page ?> of <?= $pageCount ?></span>
                <div>
                    <?php if ($page > 1): ?>
                        <a class="btn btn--outline" href="<?= BASE_URL ?>/admin/audit_logs.php?<?= sanitize(http_build_query($filterQuery + ['page' => $page - 1])) ?>">Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $pageCount): ?>
                        <a class="btn btn--outline" href="<?= BASE_URL ?>/admin/audit_logs.php?<?= sanitize(http_build_query($filterQuery + ['page' => $page + 1])) ?>">Next</a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    </div>
</section>

<?php if ($isPrintView): ?>
    <script>window.addEventListener("load", () => window.print());</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
