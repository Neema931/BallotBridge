<?php

require_once __DIR__ . '/auth.php';

/**
 * Record an administrator action in the current transaction.
 */
function log_admin_action(
    PDO $pdo,
    string $action,
    string $entityType,
    ?int $entityId,
    string $summary,
    ?array $before = null,
    ?array $after = null
): void {
    $actor = current_user();
    if ($actor === null || ($actor['role'] ?? null) !== 'admin') {
        throw new LogicException('Only an authenticated administrator can create audit entries.');
    }

    if (
        preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $action) !== 1
        || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $entityType) !== 1
        || $summary === ''
        || strlen($summary) > 255
    ) {
        throw new InvalidArgumentException('Invalid audit log entry.');
    }

    $actorQuery = $pdo->prepare('SELECT full_name, role FROM users WHERE id = :actor_id');
    $actorQuery->execute(['actor_id' => (int) $actor['id']]);
    $actorRecord = $actorQuery->fetch();
    if (!$actorRecord || $actorRecord['role'] !== 'admin') {
        throw new LogicException('The audit actor is not an active administrator record.');
    }

    $remoteAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
        ? $_SERVER['REMOTE_ADDR']
        : '';
    $ipAddress = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false
        ? $remoteAddress
        : null;
    $jsonOptions = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    $insert = $pdo->prepare(
        'INSERT INTO audit_logs
            (actor_user_id, actor_name_snapshot, actor_role_snapshot, action, entity_type, entity_id,
             summary, ip_address, before_json, after_json)
         VALUES
            (:actor_user_id, :actor_name_snapshot, :actor_role_snapshot, :action, :entity_type,
             :entity_id, :summary, :ip_address, :before_json, :after_json)'
    );
    $insert->execute([
        'actor_user_id' => (int) $actor['id'],
        'actor_name_snapshot' => $actorRecord['full_name'],
        'actor_role_snapshot' => $actorRecord['role'],
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'summary' => $summary,
        'ip_address' => $ipAddress,
        'before_json' => $before === null ? null : json_encode($before, $jsonOptions),
        'after_json' => $after === null ? null : json_encode($after, $jsonOptions),
    ]);
}

function log_security_event(
    PDO $pdo,
    string $action,
    string $summary,
    ?int $actorUserId = null,
    ?array $details = null
): void {
    if (
        preg_match('/^security\\.[a-z0-9_.-]{1,56}$/', $action) !== 1
        || $summary === ''
        || strlen($summary) > 255
    ) {
        throw new InvalidArgumentException('Invalid security audit entry.');
    }

    $remoteAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
        ? $_SERVER['REMOTE_ADDR']
        : '';
    $ipAddress = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false
        ? $remoteAddress
        : null;
    $actorName = null;
    $actorRole = null;
    if ($actorUserId !== null) {
        $actorQuery = $pdo->prepare('SELECT full_name, role FROM users WHERE id = :actor_id');
        $actorQuery->execute(['actor_id' => $actorUserId]);
        $actor = $actorQuery->fetch();
        if ($actor) {
            $actorName = $actor['full_name'];
            $actorRole = $actor['role'];
        }
    }

    $insert = $pdo->prepare(
        'INSERT INTO audit_logs
            (actor_user_id, actor_name_snapshot, actor_role_snapshot, action, entity_type,
             summary, ip_address, after_json)
         VALUES
            (:actor_user_id, :actor_name_snapshot, :actor_role_snapshot, :action, \'security\',
             :summary, :ip_address, :after_json)'
    );
    $insert->execute([
        'actor_user_id' => $actorUserId,
        'actor_name_snapshot' => $actorName,
        'actor_role_snapshot' => $actorRole,
        'action' => $action,
        'summary' => $summary,
        'ip_address' => $ipAddress,
        'after_json' => $details === null
            ? null
            : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
}
