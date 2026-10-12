<?php

function ensureProductAuditColumns(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $statement = $pdo->query("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'products'
            AND COLUMN_NAME IN ('created_at', 'updated_at', 'modified_by', 'description')
    ");
    $existingColumns = $statement->fetchAll(PDO::FETCH_COLUMN);

    $columns = [
        'created_at' => 'ALTER TABLE products ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'ALTER TABLE products ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'modified_by' => 'ALTER TABLE products ADD COLUMN modified_by INT NULL',
    ];

    foreach ($columns as $column => $sql) {
        if (!in_array($column, $existingColumns, true)) {
            $pdo->exec($sql);
        }
    }

    $descriptionColumn = $pdo->query("
        SELECT DATA_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'products'
            AND COLUMN_NAME = 'description'
    ")->fetchColumn();
    if (is_string($descriptionColumn) && !in_array(strtolower($descriptionColumn), ['text', 'mediumtext', 'longtext'], true)) {
        $pdo->exec('ALTER TABLE products MODIFY description TEXT NULL');
    }

    $initialized = true;
}
