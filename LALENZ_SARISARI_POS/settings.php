<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/action_log.php';

requireAuth();
$userId = fetchUser();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

require_once __DIR__ . '/config/system.php';
require_once __DIR__ . '/config/permissions.php';

$userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
ensureActionLogTable($pdo);
requireAppPermission($pdo, $userId, 'settings.view');
$canManageSettings = hasAppPermission($pdo, $userId, 'settings.manage');

$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$requestedPage = (string) ($_GET['page'] ?? 'general');
$page = in_array($requestedPage, ['general', 'backup', 'password'], true)
    ? $requestedPage
    : 'general';

if (empty($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['settings_csrf_token'];
$errorMessage = '';
$successMessage = $_SESSION['settings_flash'] ?? '';
unset($_SESSION['settings_flash']);
$systemNameValue = $systemName;
$receiptAddressValue = 'B15 L19, VILLA KARANGAHAN SUBD., SAN FELIPE, NAGA CITY';
$receiptAddressStmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'receipt_address'");
$receiptAddressStmt->execute();
$configuredReceiptAddress = $receiptAddressStmt->fetchColumn();
if (is_string($configuredReceiptAddress)) {
    $receiptAddressValue = $configuredReceiptAddress;
}
$receiptFooterValue = 'Thank you for your purchase! Please come again.';
$receiptFooterStmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'receipt_footer'");
$receiptFooterStmt->execute();
$configuredReceiptFooter = $receiptFooterStmt->fetchColumn();
if (is_string($configuredReceiptFooter)) {
    $receiptFooterValue = $configuredReceiptFooter;
}
$settingsLogAction = 'settings.action';
$settingsActionLogged = false;

function settingsTableSchemas(PDO $pdo): array
{
    $tables = [];
    $engineRows = $pdo->query("
        SELECT TABLE_NAME, ENGINE
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    $tableRows = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

    foreach ($tableRows as $tableRow) {
        $tableName = (string) $tableRow[0];
        $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';
        $columnRows = $pdo->query("SHOW COLUMNS FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);
        $columns = [];
        $insertableColumns = [];
        $columnDefinitions = [];

        foreach ($columnRows as $columnRow) {
            $columnName = (string) $columnRow['Field'];
            $columns[] = $columnName;
            $columnDefinitions[$columnName] = [
                'type' => $columnRow['Type'],
                'null' => $columnRow['Null'],
                'key' => $columnRow['Key'],
                'default' => $columnRow['Default'],
                'extra' => $columnRow['Extra'],
            ];
            if (stripos((string) ($columnRow['Extra'] ?? ''), 'GENERATED') === false) {
                $insertableColumns[] = $columnName;
            }
        }

        $tables[$tableName] = [
            'columns' => $columns,
            'column_definitions' => $columnDefinitions,
            'insertable_columns' => $insertableColumns,
            'engine' => $engineRows[$tableName] ?? null,
        ];
    }

    ksort($tables);
    return $tables;
}

function settingsJsonResponse(array $payload, int $statusCode = 200): void
{
    global $pdo, $userId, $settingsLogAction, $settingsActionLogged;
    $succeeded = ($payload['success'] ?? false) === true;
    if (!$settingsActionLogged) {
        appLogAction(
            $pdo,
            $userId,
            $settingsLogAction,
            'setting',
            $settingsLogAction === 'settings.password_changed' ? $userId : null,
            $settingsLogAction === 'settings.password_changed' ? 'User password' : 'System settings',
            $succeeded ? 'success' : 'failure',
            ['reason' => $succeeded ? 'Settings action completed.' : (string) ($payload['message'] ?? 'Settings action failed.')]
        );
        $settingsActionLogged = true;
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $settingsActionLogged = false;
    $settingsLogAction = [
        'change_password' => 'settings.password_changed',
        'reset_logo' => 'settings.logo_reset',
        'save_general' => 'settings.general_updated',
        'backup' => 'settings.backup_exported',
        'restore' => 'settings.backup_restored',
    ][$action] ?? 'settings.action';
    if ($action !== 'change_password') {
        if (!hasAppPermission($pdo, $userId, 'settings.manage')) {
            appLogAction($pdo, $userId, $settingsLogAction, 'setting', null, 'System settings', 'failure', [
                'reason' => 'Permission denied.',
            ]);
        }
        requireAppPermission($pdo, $userId, 'settings.manage');
    }
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        if ($action === 'change_password') {
            settingsJsonResponse([
                'success' => false,
                'message' => 'Your session could not be verified. Refresh the page and try again.',
            ], 403);
        }
        $errorMessage = 'Your session could not be verified. Refresh the page and try again.';
    } else {
        if ($action === 'change_password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                $errorMessage = 'Complete all password fields.';
            } elseif (!password_verify($currentPassword, (string) $user['password'])) {
                $errorMessage = 'Your current password is incorrect.';
            } elseif (strlen($newPassword) < 8) {
                $errorMessage = 'Your new password must be at least 8 characters long.';
            } elseif (strlen($newPassword) > 72) {
                $errorMessage = 'Your new password must be 72 bytes or fewer.';
            } elseif ($newPassword !== $confirmPassword) {
                $errorMessage = 'The new password and confirmation do not match.';
            } elseif (password_verify($newPassword, (string) $user['password'])) {
                $errorMessage = 'Choose a password different from your current password.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updatePassword = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                    $updatePassword->execute([$passwordHash, $userId]);
                    appLogAction($pdo, $userId, $settingsLogAction, 'setting', $userId, 'User password', 'success', [
                        'reason' => 'Password changed.',
                    ]);
                    $pdo->commit();
                    $settingsActionLogged = true;

                    settingsJsonResponse([
                        'success' => true,
                        'message' => 'Your password has been changed.',
                    ]);
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Unable to change user password: ' . $exception->getMessage());
                    settingsJsonResponse([
                        'success' => false,
                        'message' => 'Your password could not be changed. Check the application error log and try again.',
                    ], 500);
                }
            }
            if ($errorMessage !== '') {
                settingsJsonResponse([
                    'success' => false,
                    'message' => $errorMessage,
                ], 422);
            }
        } elseif ($action === 'reset_logo') {
            try {
                $pdo->beginTransaction();
                $logoSetting = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'system_logo'");
                $logoSetting->execute();
                $configuredLogo = $logoSetting->fetchColumn();
                $oldLogoFile = null;
                if (
                    is_string($configuredLogo)
                    && preg_match('#^/LALENZ_SARISARI_POS/assets/images/uploads/logo-[a-f0-9]{32}\.(png|jpg|webp)$#', $configuredLogo)
                ) {
                    $oldLogoFile = __DIR__ . substr($configuredLogo, strlen('/LALENZ_SARISARI_POS'));
                }

                $resetLogo = $pdo->prepare("
                    INSERT INTO app_settings (setting_key, setting_value)
                    VALUES ('system_logo', '__default__')
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                $resetLogo->execute();
                appLogAction($pdo, $userId, $settingsLogAction, 'setting', null, 'Store logo', 'success', [
                    'reason' => 'Logo reset to default.',
                ]);
                $pdo->commit();

                $flashMessage = 'Store logo reset to the default.';
                if ($oldLogoFile !== null && is_file($oldLogoFile) && !unlink($oldLogoFile)) {
                    error_log('Unable to remove the previous store logo: ' . $oldLogoFile);
                    $flashMessage = 'Store logo reset to the default, but the previous image file could not be removed. Check the application error log.';
                }
                $_SESSION['settings_flash'] = $flashMessage;
                header('Location: settings.php');
                exit;
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Unable to reset store logo: ' . $exception->getMessage());
                $errorMessage = 'The store logo could not be reset. Check the application error log and try again.';
            }
        } elseif ($action === 'save_general') {
            $systemNameValue = trim((string) ($_POST['system_name'] ?? ''));
            $postedReceiptAddress = $_POST['receipt_address'] ?? $receiptAddressValue;
            $postedReceiptFooter = $_POST['receipt_footer'] ?? $receiptFooterValue;
            if (!is_string($postedReceiptAddress) || !is_string($postedReceiptFooter)) {
                $errorMessage = 'Invalid receipt address or footer text.';
            } elseif (mb_strlen($systemNameValue) > 100) {
                $errorMessage = 'System name must be 100 characters or fewer.';
            } elseif (mb_strlen($postedReceiptAddress, 'UTF-8') > 500) {
                $errorMessage = 'Receipt address must be 500 characters or fewer.';
            } elseif (mb_strlen($postedReceiptFooter, 'UTF-8') > 500) {
                $errorMessage = 'Receipt footer text must be 500 characters or fewer.';
            } else {
                $receiptAddressValue = trim($postedReceiptAddress);
                $receiptFooterValue = trim($postedReceiptFooter);
                $logoUpload = $_FILES['system_logo'] ?? null;
                $newLogoPath = null;
                $uploadedLogoFile = null;

                if ($logoUpload && $logoUpload['error'] !== UPLOAD_ERR_NO_FILE) {
                    if ($logoUpload['error'] !== UPLOAD_ERR_OK) {
                        $errorMessage = $logoUpload['error'] === UPLOAD_ERR_INI_SIZE
                            || $logoUpload['error'] === UPLOAD_ERR_FORM_SIZE
                            ? 'The logo file exceeds the 3 MB upload limit.'
                            : 'The logo upload failed. Please choose the file again.';
                    } elseif (
                        !is_uploaded_file($logoUpload['tmp_name'])
                        || $logoUpload['size'] < 1
                        || $logoUpload['size'] > 3 * 1024 * 1024
                    ) {
                        $errorMessage = 'Choose a logo image smaller than 3 MB.';
                    } else {
                        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $fileInfo->file($logoUpload['tmp_name']);
                        $imageInfo = @getimagesize($logoUpload['tmp_name']);
                        $allowedLogoTypes = [
                            'image/png' => 'png',
                            'image/jpeg' => 'jpg',
                            'image/webp' => 'webp',
                        ];

                        if (
                            !is_string($mimeType)
                            || !isset($allowedLogoTypes[$mimeType])
                            || $imageInfo === false
                            || ($imageInfo['mime'] ?? null) !== $mimeType
                            || $imageInfo[0] > 4096
                            || $imageInfo[1] > 4096
                            || $imageInfo[0] * $imageInfo[1] > 16000000
                        ) {
                            $errorMessage = 'Choose a valid PNG, JPG, or WebP image up to 4096 × 4096 pixels.';
                        } else {
                            $uploadDirectory = __DIR__ . '/assets/images/uploads';
                            if (
                                !is_dir($uploadDirectory)
                                && !mkdir($uploadDirectory, 0755, true)
                                && !is_dir($uploadDirectory)
                            ) {
                                $errorMessage = 'The logo upload folder could not be created. Check the application folder permissions.';
                            } else {
                                $logoFilename = 'logo-' . bin2hex(random_bytes(16)) . '.' . $allowedLogoTypes[$mimeType];
                                $uploadedLogoFile = $uploadDirectory . '/' . $logoFilename;
                                $newLogoPath = '/LALENZ_SARISARI_POS/assets/images/uploads/' . $logoFilename;

                                if (!move_uploaded_file($logoUpload['tmp_name'], $uploadedLogoFile)) {
                                    $uploadedLogoFile = null;
                                    $newLogoPath = null;
                                    $errorMessage = 'The logo image could not be saved. Check the application folder permissions.';
                                }
                            }
                        }
                    }
                }

                if ($errorMessage === '') {
                    try {
                        $pdo->beginTransaction();
                        if ($systemNameValue === '') {
                            $deleteSetting = $pdo->prepare("DELETE FROM app_settings WHERE setting_key = 'system_name'");
                            $deleteSetting->execute();
                            $systemNameValue = 'LALENZ STORE POS';
                        } else {
                            $saveSetting = $pdo->prepare("
                                INSERT INTO app_settings (setting_key, setting_value)
                                VALUES ('system_name', ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                            ");
                            $saveSetting->execute([$systemNameValue]);
                        }

                        $saveReceiptAddress = $pdo->prepare("
                            INSERT INTO app_settings (setting_key, setting_value)
                            VALUES ('receipt_address', ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                        ");
                        $saveReceiptAddress->execute([$receiptAddressValue]);

                        $saveReceiptFooter = $pdo->prepare("
                            INSERT INTO app_settings (setting_key, setting_value)
                            VALUES ('receipt_footer', ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                        ");
                        $saveReceiptFooter->execute([$receiptFooterValue]);

                        if ($newLogoPath !== null) {
                            $saveLogo = $pdo->prepare("
                                INSERT INTO app_settings (setting_key, setting_value)
                                VALUES ('system_logo', ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                            ");
                            $saveLogo->execute([$newLogoPath]);
                        }
                        appLogAction($pdo, $userId, $settingsLogAction, 'setting', null, 'General settings', 'success', [
                            'system_name' => $systemNameValue,
                            'receipt_address_updated' => true,
                            'receipt_footer_updated' => true,
                            'logo_updated' => $newLogoPath !== null,
                        ]);
                        $pdo->commit();
                        $_SESSION['settings_flash'] = 'General settings saved.';
                        header('Location: settings.php');
                        exit;
                    } catch (PDOException $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        if ($uploadedLogoFile !== null && is_file($uploadedLogoFile)) {
                            unlink($uploadedLogoFile);
                        }
                        error_log('Unable to save general settings: ' . $exception->getMessage());
                        $errorMessage = 'Settings could not be saved. Check the application error log and try again.';
                    }
                }
            }
        } elseif ($action === 'backup') {
            $schemas = settingsTableSchemas($pdo);
            $backup = [
                'format' => 'lalenz-pos-backup',
                'version' => 1,
                'database' => $dbname,
                'created_at' => date(DATE_ATOM),
                'tables' => [],
            ];

            foreach ($schemas as $tableName => $schema) {
                $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';
                $rows = $pdo->query("SELECT * FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);
                $backup['tables'][$tableName] = [
                    'columns' => $schema['columns'],
                    'column_definitions' => $schema['column_definitions'],
                    'engine' => $schema['engine'],
                    'rows' => $rows,
                ];
            }

            try {
                $backupJson = json_encode(
                    $backup,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
            } catch (JsonException $exception) {
                error_log('Unable to encode database backup: ' . $exception->getMessage());
                $errorMessage = 'The database backup could not be generated. Check the application error log.';
            }

            if ($errorMessage === '') {
                appLogAction($pdo, $userId, $settingsLogAction, 'setting', null, 'Database backup', 'success', [
                    'reason' => 'Database backup exported.',
                    'table_count' => count($schemas),
                ]);
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="lalenz-pos-backup-' . date('Y-m-d-His') . '.json"');
                header('Content-Length: ' . strlen($backupJson));
                header('X-Content-Type-Options: nosniff');
                echo $backupJson;
                exit;
            }
        } elseif ($action === 'restore') {
            $upload = $_FILES['backup_file'] ?? null;
            if (($_POST['confirm_restore'] ?? '') !== '1') {
                $errorMessage = 'Confirm that you understand the restore will replace current data.';
            } elseif (!$upload || !isset($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
                $errorMessage = 'Select a valid backup file. If the file is too large, increase the PHP upload limit.';
            } elseif (!is_uploaded_file($upload['tmp_name']) || $upload['size'] < 1 || $upload['size'] > 52428800) {
                $errorMessage = 'Backup files must be smaller than 50 MB.';
            } else {
                $backupJson = file_get_contents($upload['tmp_name']);
                if ($backupJson === false) {
                    $backup = null;
                    $errorMessage = 'The selected backup file could not be read.';
                } else {
                    try {
                        $backup = json_decode($backupJson, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                    } catch (JsonException $exception) {
                        $backup = null;
                        $errorMessage = 'The selected file is not a valid LALENZ POS JSON backup.';
                    }
                }

                if ($errorMessage === '' && (
                    !is_array($backup)
                    || ($backup['format'] ?? null) !== 'lalenz-pos-backup'
                    || ($backup['version'] ?? null) !== 1
                    || ($backup['database'] ?? null) !== $dbname
                    || !isset($backup['tables'])
                    || !is_array($backup['tables'])
                )) {
                    $errorMessage = 'This backup is invalid or belongs to a different database.';
                }

                if ($errorMessage === '') {
                    $schemas = settingsTableSchemas($pdo);
                    $nonTransactionalTables = array_keys(array_filter(
                        $schemas,
                        static fn(array $schema): bool => strtoupper((string) $schema['engine']) !== 'INNODB'
                    ));
                    $backupTableNames = array_keys($backup['tables']);
                    $currentTableNames = array_keys($schemas);
                    sort($backupTableNames);
                    sort($currentTableNames);

                    if ($nonTransactionalTables) {
                        $errorMessage = 'Restore is unavailable because these tables do not support transactional rollback: ' . implode(', ', $nonTransactionalTables) . '.';
                    } elseif ($backupTableNames !== $currentTableNames) {
                        $errorMessage = 'The backup table list does not match this database version. Restore it to a matching version of the application.';
                    } else {
                        foreach ($schemas as $tableName => $schema) {
                            $tableBackup = $backup['tables'][$tableName] ?? null;
                            if (
                                !is_array($tableBackup)
                                || ($tableBackup['columns'] ?? null) !== $schema['columns']
                                || ($tableBackup['column_definitions'] ?? null) !== $schema['column_definitions']
                                || ($tableBackup['engine'] ?? null) !== $schema['engine']
                                || !isset($tableBackup['rows'])
                                || !is_array($tableBackup['rows'])
                            ) {
                                $errorMessage = 'The backup structure is invalid for table ' . $tableName . '.';
                                break;
                            }

                            foreach ($tableBackup['rows'] as $row) {
                                if (!is_array($row) || array_is_list($row)) {
                                    $errorMessage = 'A backup row is invalid in table ' . $tableName . '.';
                                    break 2;
                                }

                                $rowColumns = array_keys($row);
                                $expectedColumns = $schema['columns'];
                                sort($rowColumns);
                                sort($expectedColumns);
                                if ($rowColumns !== $expectedColumns) {
                                    $errorMessage = 'A backup row has an invalid column set in table ' . $tableName . '.';
                                    break 2;
                                }

                                foreach ($row as $value) {
                                    if (is_array($value) || is_object($value)) {
                                        $errorMessage = 'A backup value is invalid in table ' . $tableName . '.';
                                        break 3;
                                    }
                                }
                            }
                        }
                    }
                }

                if ($errorMessage === '') {
                    $foreignKeysDisabled = false;
                    $restoreSucceeded = false;
                    try {
                        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                        $foreignKeysDisabled = true;
                        $pdo->beginTransaction();

                        foreach ($schemas as $tableName => $schema) {
                            $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';
                            $pdo->exec("DELETE FROM {$quotedTable}");
                        }

                        foreach ($schemas as $tableName => $schema) {
                            $tableBackup = $backup['tables'][$tableName];
                            $columns = $schema['insertable_columns'];
                            if (!$columns || !$tableBackup['rows']) {
                                continue;
                            }

                            $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';
                            $quotedColumns = array_map(
                                static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
                                $columns
                            );
                            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                            $insert = $pdo->prepare(
                                "INSERT INTO {$quotedTable} (" . implode(', ', $quotedColumns) . ") VALUES ({$placeholders})"
                            );

                            foreach ($tableBackup['rows'] as $row) {
                                $values = array_map(static fn(string $column) => $row[$column], $columns);
                                $insert->execute($values);
                            }
                        }

                        appLogAction($pdo, $userId, $settingsLogAction, 'setting', null, 'Database restore', 'success', [
                            'reason' => 'Database backup restored.',
                            'table_count' => count($schemas),
                        ]);
                        $pdo->commit();
                        $restoreSucceeded = true;
                    } catch (PDOException $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Database restore failed: ' . $exception->getMessage());
                        $errorMessage = 'Restore failed and database changes were rolled back. Check the application error log.';
                    } finally {
                        if ($foreignKeysDisabled) {
                            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                        }
                    }

                    if ($restoreSucceeded) {
                        $_SESSION['settings_flash'] = 'Backup restored successfully. All current database table data was replaced.';
                        header('Location: settings.php?page=backup');
                        exit;
                    }
                }
            }
        } else {
            $errorMessage = 'The requested settings action is not valid.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '' && $action !== 'change_password') {
    appLogAction($pdo, $userId, $settingsLogAction, 'setting', null, $settingsLogAction === 'settings.logo_reset' ? 'Store logo' : 'System settings', 'failure', [
        'reason' => $errorMessage,
    ]);
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | <?= htmlspecialchars($systemName) ?></title>
    <meta name="description" content="Application settings and database backups">
    <link rel="icon" href="<?= htmlspecialchars($logo ?? '/LALENZ_SARISARI_POS/assets/images/logo.ico') ?>" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
        (function () {
            const savedTheme = localStorage.getItem('lalenz_theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <style>
        body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="min-h-screen lg:ml-64 dark:bg-slate-950">
    <header class="flex h-20 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8 dark:border-slate-700 dark:bg-slate-900">
        <div>
            <h1 class="text-2xl font-black tracking-tight">Settings</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Manage application settings, account security, and backups.</p>
        </div>
    </header>

    <div class="space-y-6 p-4 sm:p-6 lg:p-8">
        <nav class="flex flex-wrap gap-2" aria-label="Settings sections">
            <a href="settings.php" class="<?= $page === 'general' ? 'bg-green-600 text-white' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300' ?> rounded-xl px-4 py-2.5 text-sm font-bold">General</a>
            <a href="settings.php?page=password" class="<?= $page === 'password' ? 'bg-green-600 text-white' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300' ?> rounded-xl px-4 py-2.5 text-sm font-bold">Password</a>
            <a href="settings.php?page=backup" class="<?= $page === 'backup' ? 'bg-green-600 text-white' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300' ?> rounded-xl px-4 py-2.5 text-sm font-bold">Backup &amp; Restore</a>
        </nav>

        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">
                <?= htmlspecialchars($errorMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($successMessage !== ''): ?>
            <div role="status" class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-700 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300">
                <?= htmlspecialchars($successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($page === 'backup'): ?>
            <?php if ($canManageSettings): ?>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
                <h2 class="text-xl font-black">Database Backup</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500 dark:text-slate-400">
                    Download a JSON backup containing the data and column layout of all application database tables. Store the file somewhere secure; it contains private business records and user account data.
                </p>
                <form method="POST" class="mt-5">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="backup">
                    <button type="submit" class="rounded-xl bg-green-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-green-700">Download Database Backup</button>
                </form>
            </section>

            <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 shadow-sm dark:border-amber-800 dark:bg-amber-950/30 sm:p-7">
                <h2 class="text-xl font-black text-amber-900 dark:text-amber-200">Restore Database Backup</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-amber-800 dark:text-amber-300">
                    Restore replaces all current table data with data from the selected backup. Use a backup created for this database and matching application schema. Make a fresh backup first if you may need the current data.
                </p>
                <form method="POST" enctype="multipart/form-data" class="mt-5 space-y-4" onsubmit="return confirm('This will replace all current database table data. Continue with restore?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="restore">
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                        Backup file (JSON, maximum 50 MB)
                        <input type="file" name="backup_file" accept=".json,application/json" required class="mt-2 block w-full rounded-xl border border-amber-300 bg-white p-3 text-sm dark:border-amber-800 dark:bg-slate-900">
                    </label>
                    <label class="flex items-start gap-3 text-sm font-semibold text-amber-900 dark:text-amber-200">
                        <input type="checkbox" name="confirm_restore" value="1" required class="mt-1 accent-amber-600">
                        <span>I understand that restoring will replace the current database data and cannot be undone.</span>
                    </label>
                    <button type="submit" class="rounded-xl bg-amber-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-amber-800 dark:bg-amber-600 dark:hover:bg-amber-500">Restore Backup</button>
                </form>
            </section>
            <?php else: ?>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                    You have view-only access to settings and cannot create or restore backups.
                </section>
            <?php endif; ?>
        <?php elseif ($page === 'password'): ?>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
                <h2 class="text-xl font-black">Change Password</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500 dark:text-slate-400">
                    Update the password for your account. Your current password is required to confirm this change.
                </p>
                <div id="password-feedback" role="status" aria-live="polite" class="mt-4 hidden rounded-xl px-4 py-3 text-sm font-semibold"></div>
                <form id="password-form" action="settings.php?page=password" method="POST" class="mt-5 max-w-xl space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="change_password">
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                        Current Password
                        <span class="relative mt-2 block">
                            <input
                                id="current-password"
                                type="password"
                                name="current_password"
                                autocomplete="current-password"
                                required
                                class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 pr-12 text-slate-900 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                            >
                            <button type="button" data-toggle-password="current-password" aria-label="Show current password" class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 transition hover:text-green-600">
                                <svg data-eye-open aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z" />
                                    <circle cx="12" cy="12" r="2.5" stroke-width="1.8" />
                                </svg>
                                <svg data-eye-closed aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.1A10.8 10.8 0 0112 5c6 0 9.5 7 9.5 7a17 17 0 01-3.1 3.8M6.1 6.1C3.8 7.7 2.5 12 2.5 12s3.5 7 9.5 7c1.2 0 2.3-.2 3.3-.6" />
                                </svg>
                            </button>
                        </span>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                        New Password
                        <span class="relative mt-2 block">
                            <input
                                id="new-password"
                                type="password"
                                name="new_password"
                                autocomplete="new-password"
                                minlength="8"
                                required
                                class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 pr-12 text-slate-900 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                            >
                            <button type="button" data-toggle-password="new-password" aria-label="Show new password" class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 transition hover:text-green-600">
                                <svg data-eye-open aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z" />
                                    <circle cx="12" cy="12" r="2.5" stroke-width="1.8" />
                                </svg>
                                <svg data-eye-closed aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.1A10.8 10.8 0 0112 5c6 0 9.5 7 9.5 7a17 17 0 01-3.1 3.8M6.1 6.1C3.8 7.7 2.5 12 2.5 12s3.5 7 9.5 7c1.2 0 2.3-.2 3.3-.6" />
                                </svg>
                            </button>
                        </span>
                        <span class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">Use at least 8 characters (maximum 72 bytes).</span>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                        Confirm New Password
                        <span class="relative mt-2 block">
                            <input
                                id="confirm-password"
                                type="password"
                                name="confirm_password"
                                autocomplete="new-password"
                                minlength="8"
                                required
                                class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 pr-12 text-slate-900 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                            >
                            <button type="button" data-toggle-password="confirm-password" aria-label="Show password confirmation" class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 transition hover:text-green-600">
                                <svg data-eye-open aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z" />
                                    <circle cx="12" cy="12" r="2.5" stroke-width="1.8" />
                                </svg>
                                <svg data-eye-closed aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.1A10.8 10.8 0 0112 5c6 0 9.5 7 9.5 7a17 17 0 01-3.1 3.8M6.1 6.1C3.8 7.7 2.5 12 2.5 12s3.5 7 9.5 7c1.2 0 2.3-.2 3.3-.6" />
                                </svg>
                            </button>
                        </span>
                    </label>
                    <button id="password-submit" type="submit" class="rounded-xl bg-green-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-green-700">Update Password</button>
                </form>
            </section>
        <?php else: ?>
            <?php if ($canManageSettings): ?>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
                <h2 class="text-xl font-black">General Settings</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Update the name shown across the application. Leave it empty to use the default name.</p>
                <form method="POST" enctype="multipart/form-data" class="mt-5 max-w-2xl space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_general">
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                        System Name
                        <input
                            type="text"
                            name="system_name"
                            maxlength="100"
                            value="<?= htmlspecialchars($systemNameValue) ?>"
                            placeholder="LALENZ STORE POS"
                            class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-slate-900 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                        >
                        <span class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">If left empty, the name will be reset to LALENZ STORE POS.</span>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                        Receipt Address
                        <textarea
                            name="receipt_address"
                            maxlength="500"
                            rows="3"
                            class="mt-2 block w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                            placeholder="Store address"
                        ><?= htmlspecialchars($receiptAddressValue, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <span class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">This address appears below the store name on receipts. Leave empty to hide it. Maximum 500 characters.</span>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                        Receipt Footer
                        <textarea
                            name="receipt_footer"
                            maxlength="500"
                            rows="3"
                            class="mt-2 block w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                            placeholder="Thank you for your purchase! Please come again."
                        ><?= htmlspecialchars($receiptFooterValue, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <span class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">Leave empty to hide the receipt footer message. Maximum 500 characters.</span>
                    </label>
                    <div>
                        <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Store Logo</p>
                        <div class="mt-2 flex items-center gap-4">
                            <img src="<?= htmlspecialchars($logo) ?>" alt="Current store logo" class="h-16 w-16 rounded-xl border border-slate-200 bg-white object-contain p-2 dark:border-slate-700 dark:bg-slate-800">
                            <label class="min-w-0 flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                Choose a logo image
                                <input type="file" name="system_logo" accept="image/png,image/jpeg,image/webp" class="mt-2 block w-full rounded-xl border border-slate-200 bg-slate-50 p-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                            </label>
                        </div>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">PNG, JPG, or WebP; maximum 3 MB and 4096 × 4096 pixels. Leave empty to keep the current logo.</p>
                    </div>
                    <button type="submit" class="rounded-xl bg-green-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-green-700">Save General Settings</button>
                </form>
                <?php if ($logo !== '/LALENZ_SARISARI_POS/assets/images/logo.ico'): ?>
                    <form method="POST" class="mt-3" onsubmit="return confirm('Remove the current store logo and restore the default?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="reset_logo">
                        <button type="submit" class="rounded-xl border border-red-300 px-4 py-2 text-sm font-bold text-red-700 transition hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950">Reset to Default Logo</button>
                    </form>
                <?php endif; ?>
                <a href="settings.php?page=backup" class="mt-5 inline-flex rounded-xl bg-green-600 px-5 py-3 text-sm font-bold text-white hover:bg-green-700">Open Backup &amp; Restore</a>
            </section>
            <?php else: ?>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                    You have view-only access to application settings. You can still change your own password from the Password section.
                </section>
            <?php endif; ?>
            <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
                <h2 class="text-xl font-black">Project Documentation</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    View the external documentation repository for LALENS Sari-Sari POS.
                </p>
                <a
                    href="https://github.com/nathanieeeeeeeeel/LALENS-SARISARI-POS"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-4 inline-flex rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-blue-700"
                >
                    Open Project Documentation
                </a>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
<?php if ($page === 'password'): ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    $('#password-form').on('submit', function (event) {
        event.preventDefault();

        const form = $(this);
        const submitButton = $('#password-submit');
        const feedback = $('#password-feedback');
        const originalButtonText = submitButton.text();

        submitButton.prop('disabled', true).text('Updating...');
        feedback.addClass('hidden').removeClass(
            'border border-red-200 bg-red-50 text-red-700 border-green-200 bg-green-50 text-green-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300'
        );

        $.ajax({
            url: form.attr('action'),
            method: 'POST',
            data: form.serialize(),
            dataType: 'json'
        }).done(function (response) {
            feedback
                .text(response.message)
                .addClass(response.success
                    ? 'border border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300'
                    : 'border border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300')
                .removeClass('hidden')
                .attr('role', response.success ? 'status' : 'alert');

            if (response.success) {
                form[0].reset();
            }
        }).fail(function (xhr) {
            const message = xhr.responseJSON && xhr.responseJSON.message
                ? xhr.responseJSON.message
                : 'The password could not be changed. Check your connection and try again.';

            feedback
                .text(message)
                .addClass('border border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300')
                .removeClass('hidden')
                .attr('role', 'alert');
        }).always(function () {
            submitButton.prop('disabled', false).text(originalButtonText);
        });
    });
</script>
<?php endif; ?>
<script>
    document.querySelectorAll('[data-toggle-password]').forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const input = document.getElementById(toggle.dataset.togglePassword);
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            toggle.querySelector('[data-eye-open]').classList.toggle('hidden', isPassword);
            toggle.querySelector('[data-eye-closed]').classList.toggle('hidden', !isPassword);
            toggle.setAttribute(
                'aria-label',
                `${isPassword ? 'Hide' : 'Show'} ${toggle.dataset.togglePassword.replaceAll('-', ' ')}`
            );
        });
    });
</script>
</body>
</html>
