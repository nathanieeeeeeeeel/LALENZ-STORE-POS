<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/system.php';
require_once __DIR__ . '/../../config/permissions.php';

requireAuth();
$adminUserId = fetchUser();
$adminUserStmt = $pdo->prepare('SELECT id, username, full_name, role, is_active FROM users WHERE id = ? LIMIT 1');
$adminUserStmt->execute([$adminUserId]);
$adminCurrentUser = $adminUserStmt->fetch(PDO::FETCH_ASSOC);

if (!$adminCurrentUser) {
    session_unset();
    session_destroy();
    header('Location: ../../login.php');
    exit;
}

if ((int) $adminCurrentUser['is_active'] !== 1) {
    session_unset();
    session_destroy();
    header('Location: ../../login.php');
    exit;
}

if (empty($_SESSION['admin_users_csrf_token'])) {
    $_SESSION['admin_users_csrf_token'] = bin2hex(random_bytes(32));
}
$adminUsersCsrfToken = $_SESSION['admin_users_csrf_token'];
$currentPage = 'admin.php';
$username = (string) ($adminCurrentUser['username'] ?? 'Admin');
$fullName = (string) ($adminCurrentUser['full_name'] ?: $username);
$user_role = (string) ($adminCurrentUser['role'] ?? 'Admin');
$adminActorIsAdmin = strtolower(trim($user_role)) === 'admin';
ensureAppPermissionTables($pdo);
requireAppPermission($pdo, $adminUserId, 'users.manage');

function adminUserEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminUserFind(PDO $pdo, int $id, bool $lock = false): ?array
{
    $query = 'SELECT id, username, email, full_name, role, is_active FROM users WHERE id = ? LIMIT 1';
    if ($lock) {
        $query .= ' FOR UPDATE';
    }
    $statement = $pdo->prepare($query);
    $statement->execute([$id]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function adminUserActiveAdmins(PDO $pdo, bool $lock = false): array
{
    $query = "SELECT id FROM users WHERE LOWER(role) = 'admin' AND is_active = 1";
    if ($lock) {
        $query .= ' FOR UPDATE';
    }
    return $pdo->query($query)->fetchAll(PDO::FETCH_COLUMN);
}

function adminUserRoleAllowed(PDO $pdo, string $role): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM app_roles WHERE role_key = ? LIMIT 1');
    $statement->execute([strtolower(trim($role))]);
    return (bool) $statement->fetchColumn();
}

function adminUserEmailRequired(PDO $pdo): bool
{
    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch(PDO::FETCH_ASSOC);
    return $column && $column['Null'] === 'NO' && $column['Default'] === null;
}

function adminUserColumnMaxLength(PDO $pdo, string $name, int $fallback): int
{
    if (!in_array($name, ['username', 'full_name'], true)) {
        throw new InvalidArgumentException('Invalid user column.');
    }

    $column = $pdo->query("SHOW COLUMNS FROM users LIKE '{$name}'")->fetch(PDO::FETCH_ASSOC);
    if ($column && preg_match('/\((\d+)\)/', (string) $column['Type'], $matches)) {
        return (int) $matches[1];
    }
    return $fallback;
}

function adminUserDetachHistory(PDO $pdo, string $table, int $userId): void
{
    if (!in_array($table, ['sales', 'expenses'], true)) {
        throw new InvalidArgumentException('Invalid user history table.');
    }

    $tableExists = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'
    ");
    $tableExists->execute([$table]);
    if ((int) $tableExists->fetchColumn() === 0) {
        return;
    }

    $quotedTable = '`' . $table . '`';
    $column = $pdo->query("SHOW COLUMNS FROM {$quotedTable} LIKE 'user_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        return;
    }

    $checkReferences = $pdo->prepare("SELECT COUNT(*) FROM {$quotedTable} WHERE user_id = ?");
    $checkReferences->execute([$userId]);
    if ((int) $checkReferences->fetchColumn() === 0) {
        return;
    }

    if (($column['Null'] ?? 'NO') !== 'YES') {
        throw new RuntimeException("This account has {$table} history that cannot be detached. Deactivate the account instead.");
    }

    $detachReferences = $pdo->prepare("UPDATE {$quotedTable} SET user_id = NULL WHERE user_id = ?");
    $detachReferences->execute([$userId]);
}
