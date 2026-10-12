<?php

function ensureSaleMetadataColumns(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $statement = $pdo->query("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'sales'
            AND COLUMN_NAME IN (
                'customer_name',
                'customer_phone',
                'customer_tin',
                'customer_address',
                'vat_reg_tin',
                'transaction_note'
            )
    ");
    $existingColumns = $statement->fetchAll(PDO::FETCH_COLUMN);

    $columns = [
        'customer_name' => 'ALTER TABLE sales ADD COLUMN customer_name VARCHAR(150) NULL',
        'customer_phone' => 'ALTER TABLE sales ADD COLUMN customer_phone VARCHAR(50) NULL',
        'customer_tin' => 'ALTER TABLE sales ADD COLUMN customer_tin VARCHAR(50) NULL',
        'customer_address' => 'ALTER TABLE sales ADD COLUMN customer_address VARCHAR(500) NULL',
        'vat_reg_tin' => 'ALTER TABLE sales ADD COLUMN vat_reg_tin VARCHAR(50) NULL',
        'transaction_note' => 'ALTER TABLE sales ADD COLUMN transaction_note TEXT NULL',
    ];

    foreach ($columns as $column => $sql) {
        if (!in_array($column, $existingColumns, true)) {
            $pdo->exec($sql);
        }
    }

    $initialized = true;
}
