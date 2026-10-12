<?php

function appPermissionDefinitions(): array
{
    return [
        'dashboard.view' => 'Dashboard',
        'pos.view' => 'Point of Sale',
        'products.view' => 'View products',
        'products.manage' => 'Manage products',
        'categories.view' => 'View categories',
        'categories.manage' => 'Manage categories',
        'sales.view' => 'View sales',
        'sales.manage' => 'Edit sales',
        'item_summary.view' => 'View item summary',
        'expenses.view' => 'View expenses',
        'expenses.manage' => 'Manage expenses',
        'reports.view' => 'View reports',
        'settings.view' => 'View settings',
        'settings.manage' => 'Manage settings',
        'admin.view' => 'View admin panel',
        'users.manage' => 'Manage users',
        'permissions.manage' => 'Manage permission policies',
    ];
}

function appPermissionRoleDefaults(): array
{
    $permissions = array_keys(appPermissionDefinitions());
    $defaults = [
        'admin' => array_fill_keys($permissions, 1),
        'manager' => array_fill_keys($permissions, 0),
        'staff' => array_fill_keys($permissions, 0),
    ];

    foreach ([
        'dashboard.view',
        'pos.view',
        'products.view',
        'categories.view',
        'sales.view',
        'item_summary.view',
        'expenses.view',
        'reports.view',
        'settings.view',
    ] as $permission) {
        $defaults['manager'][$permission] = 1;
        $defaults['staff'][$permission] = 1;
    }

    foreach (['products.manage', 'categories.manage', 'expenses.manage'] as $permission) {
        $defaults['manager'][$permission] = 1;
    }
    $defaults['manager']['settings.manage'] = 1;

    return $defaults;
}

function ensureAppPermissionTables(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $roleColumn = $pdo->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'
    ");
    $roleColumn->execute();
    $roleColumnType = $roleColumn->fetchColumn();
    if (is_string($roleColumnType) && (
        stripos($roleColumnType, 'enum(') === 0
        || (preg_match('/^varchar\((\d+)\)$/i', $roleColumnType, $matches) && (int) $matches[1] < 32)
    )) {
        $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(32) NOT NULL DEFAULT 'staff'");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_roles (
            role_key VARCHAR(32) NOT NULL PRIMARY KEY,
            display_name VARCHAR(64) NOT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_role_permissions (
            role_name VARCHAR(32) NOT NULL,
            permission_key VARCHAR(64) NOT NULL,
            is_allowed TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (role_name, permission_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_user_permissions (
            user_id INT NOT NULL,
            permission_key VARCHAR(64) NOT NULL,
            permission_value ENUM('allow', 'deny') NOT NULL,
            PRIMARY KEY (user_id, permission_key),
            INDEX idx_app_user_permissions_key (permission_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $insertRole = $pdo->prepare("
        INSERT IGNORE INTO app_roles (role_key, display_name, is_system)
        VALUES (?, ?, 1)
    ");
    foreach (['admin' => 'Admin', 'manager' => 'Manager', 'staff' => 'Staff'] as $role => $label) {
        $insertRole->execute([$role, $label]);
    }

    $insertDefault = $pdo->prepare("
        INSERT IGNORE INTO app_role_permissions (role_name, permission_key, is_allowed)
        VALUES (?, ?, ?)
    ");
    foreach (appPermissionRoleDefaults() as $role => $permissions) {
        foreach ($permissions as $permission => $isAllowed) {
            $insertDefault->execute([$role, $permission, $isAllowed]);
        }
    }

    $initialized = true;
}

function appRoles(PDO $pdo, bool $includeAdmin = true): array
{
    ensureAppPermissionTables($pdo);
    $query = 'SELECT role_key, display_name, is_system FROM app_roles';
    if (!$includeAdmin) {
        $query .= " WHERE role_key <> 'admin'";
    }
    $query .= ' ORDER BY CASE role_key WHEN \'admin\' THEN 0 WHEN \'manager\' THEN 1 WHEN \'staff\' THEN 2 ELSE 3 END, display_name';
    return $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
}

function appRoleLabel(PDO $pdo, string $role): string
{
    ensureAppPermissionTables($pdo);
    $statement = $pdo->prepare('SELECT display_name FROM app_roles WHERE role_key = ? LIMIT 1');
    $statement->execute([strtolower(trim($role))]);
    $label = $statement->fetchColumn();
    return is_string($label) ? $label : ucfirst($role);
}

function appUserRole(PDO $pdo, int $userId): ?string
{
    $statement = $pdo->prepare('SELECT role FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $statement->execute([$userId]);
    $role = $statement->fetchColumn();
    return is_string($role) ? strtolower(trim($role)) : null;
}

function hasAppPermission(PDO $pdo, int $userId, string $permission): bool
{
    static $userAccess = [];

    if (!isset(appPermissionDefinitions()[$permission])) {
        throw new InvalidArgumentException('Unknown application permission.');
    }

    ensureAppPermissionTables($pdo);
    if (!array_key_exists($userId, $userAccess)) {
        $role = appUserRole($pdo, $userId);
        $userAccess[$userId] = [
            'role' => $role,
            'overrides' => [],
            'role_permissions' => [],
        ];

        if ($role !== null && $role !== 'admin') {
            $userPermission = $pdo->prepare('
                SELECT permission_key, permission_value
                FROM app_user_permissions
                WHERE user_id = ?
            ');
            $userPermission->execute([$userId]);
            foreach ($userPermission->fetchAll(PDO::FETCH_KEY_PAIR) as $key => $value) {
                $userAccess[$userId]['overrides'][$key] = $value;
            }

            $rolePermission = $pdo->prepare('
                SELECT permission_key, is_allowed
                FROM app_role_permissions
                WHERE role_name = ?
            ');
            $rolePermission->execute([$role]);
            foreach ($rolePermission->fetchAll(PDO::FETCH_KEY_PAIR) as $key => $value) {
                $userAccess[$userId]['role_permissions'][$key] = (int) $value === 1;
            }
        }
    }

    $access = $userAccess[$userId];
    if ($access['role'] === 'admin') {
        return true;
    }
    if ($access['role'] === null) {
        return false;
    }

    $override = $access['overrides'][$permission] ?? null;
    if ($override === 'allow') {
        return true;
    }
    if ($override === 'deny') {
        return false;
    }
    return $access['role_permissions'][$permission] ?? false;
}

function requireAppPermission(PDO $pdo, int $userId, string $permission): void
{
    if (!hasAppPermission($pdo, $userId, $permission)) {
        http_response_code(403);
        exit('You do not have permission to access this feature.');
    }
}
