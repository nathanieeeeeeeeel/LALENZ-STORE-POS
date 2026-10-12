<?php

function ensureActionLogTable(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS action_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            actor_id INT NULL,
            actor_name VARCHAR(150) NOT NULL,
            action VARCHAR(64) NOT NULL,
            entity_type VARCHAR(32) NOT NULL,
            entity_id INT NULL,
            entity_label VARCHAR(255) NULL,
            outcome ENUM('success', 'failure') NOT NULL,
            details_json TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_action_logs_created_at (created_at),
            INDEX idx_action_logs_actor_id (actor_id),
            INDEX idx_action_logs_entity (entity_type, entity_id),
            INDEX idx_action_logs_outcome (outcome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $initialized = true;
}

function appLogAction(
    PDO $pdo,
    int $actorId,
    string $action,
    string $entityType,
    ?int $entityId,
    ?string $entityLabel,
    string $outcome,
    array $details = []
): void {
    ensureActionLogTable($pdo);

    if (!in_array($outcome, ['success', 'failure'], true)) {
        throw new InvalidArgumentException('Invalid action log outcome.');
    }

    $actorName = trim((string) ($_SESSION['full_name'] ?? ''));
    if ($actorName === '') {
        $actorName = trim((string) ($_SESSION['username'] ?? ''));
    }
    if ($actorName === '') {
        $actorName = 'User #' . $actorId;
    }

    $statement = $pdo->prepare("
        INSERT INTO action_logs (
            actor_id,
            actor_name,
            action,
            entity_type,
            entity_id,
            entity_label,
            outcome,
            details_json,
            ip_address
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $statement->execute([
        $actorId > 0 ? $actorId : null,
        mb_substr($actorName, 0, 150),
        $action,
        $entityType,
        $entityId,
        $entityLabel !== null ? mb_substr($entityLabel, 0, 255) : null,
        $outcome,
        $details
            ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            : null,
        isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
    ]);
}
