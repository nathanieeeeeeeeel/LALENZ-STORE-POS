<?php

require_once __DIR__ . '/_bootstrap.php';
requireAppPermission($pdo, $adminUserId, 'permissions.manage');
$adminPageTitle = 'Manage Permissions';
$adminPageDescription = 'Set role defaults and per-user permission overrides.';
$adminUsersNotice = (string) ($_SESSION['admin_users_flash'] ?? '');
unset($_SESSION['admin_users_flash']);
$adminUsersError = '';
$definitions = appPermissionDefinitions();
$roles = appRoles($pdo, false);
$managedRoles = array_column($roles, 'role_key');
$roleLabels = array_column($roles, 'display_name', 'role_key');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($adminUsersCsrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $adminUsersError = 'Your session could not be verified. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_role') {
            $role = strtolower(trim((string) ($_POST['role'] ?? '')));
            if (!in_array($role, $managedRoles, true)) {
                $adminUsersError = 'Choose a valid non-administrator role to edit its defaults.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $save = $pdo->prepare("
                        INSERT INTO app_role_permissions (role_name, permission_key, is_allowed)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed)
                    ");
                    foreach ($definitions as $permission => $label) {
                        $save->execute([$role, $permission, isset($_POST['role_permissions'][$permission]) ? 1 : 0]);
                    }
                    $pdo->commit();
                    $_SESSION['admin_users_flash'] = ($roleLabels[$role] ?? ucfirst($role)) . ' role permissions saved.';
                    header('Location: permissions.php?role=' . rawurlencode($role));
                    exit;
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Unable to save role permissions: ' . $exception->getMessage());
                    $adminUsersError = 'Role permissions could not be saved. Check the application error log and try again.';
                }
            }
        } elseif ($action === 'save_user') {
            $targetUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$targetUserId || $targetUserId < 1) {
                $adminUsersError = 'Choose a valid user account.';
            } else {
                $targetStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
                $targetStmt->execute([$targetUserId]);
                $targetRole = $targetStmt->fetchColumn();

                if (!is_string($targetRole)) {
                    $adminUsersError = 'The selected user account no longer exists.';
                } elseif (strtolower($targetRole) === 'admin') {
                    $adminUsersError = 'Administrator accounts always have full access and cannot have permission overrides.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $deleteExisting = $pdo->prepare('DELETE FROM app_user_permissions WHERE user_id = ?');
                        $deleteExisting->execute([$targetUserId]);
                        $saveOverride = $pdo->prepare("
                            INSERT INTO app_user_permissions (user_id, permission_key, permission_value)
                            VALUES (?, ?, ?)
                        ");

                        foreach ($definitions as $permission => $label) {
                            $value = (string) ($_POST['user_permissions'][$permission] ?? 'inherit');
                            if (!in_array($value, ['inherit', 'allow', 'deny'], true)) {
                                throw new InvalidArgumentException('An invalid user permission value was submitted.');
                            }
                            if ($value !== 'inherit') {
                                $saveOverride->execute([$targetUserId, $permission, $value]);
                            }
                        }

                        $pdo->commit();
                        $_SESSION['admin_users_flash'] = 'User permission overrides saved.';
                        header('Location: permissions.php?user_id=' . (int) $targetUserId);
                        exit;
                    } catch (InvalidArgumentException $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $adminUsersError = $exception->getMessage();
                    } catch (PDOException $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Unable to save user permissions: ' . $exception->getMessage());
                        $adminUsersError = 'User permission overrides could not be saved. Check the application error log and try again.';
                    }
                }
            }
        } else {
            $adminUsersError = 'The requested permission action is not valid.';
        }
    }
}

$selectedRole = strtolower((string) ($_GET['role'] ?? ($managedRoles[0] ?? '')));
if (!in_array($selectedRole, $managedRoles, true)) {
    $selectedRole = $managedRoles[0] ?? '';
}
$selectedUserId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
$userRows = $pdo->query('SELECT id, username, full_name, role FROM users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);

$rolePermissions = [];
if ($selectedRole !== '') {
    $roleStmt = $pdo->prepare('SELECT permission_key, is_allowed FROM app_role_permissions WHERE role_name = ?');
    $roleStmt->execute([$selectedRole]);
    foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rolePermissions[$row['permission_key']] = (int) $row['is_allowed'] === 1;
    }
}

$selectedUser = null;
$userOverrides = [];
if ($selectedUserId && $selectedUserId > 0) {
    $selectedUserStmt = $pdo->prepare('SELECT id, username, full_name, role FROM users WHERE id = ? LIMIT 1');
    $selectedUserStmt->execute([$selectedUserId]);
    $selectedUser = $selectedUserStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($selectedUser && strtolower((string) $selectedUser['role']) !== 'admin') {
        $overrideStmt = $pdo->prepare('SELECT permission_key, permission_value FROM app_user_permissions WHERE user_id = ?');
        $overrideStmt->execute([$selectedUserId]);
        foreach ($overrideStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userOverrides[$row['permission_key']] = $row['permission_value'];
        }
    }
}

require __DIR__ . '/_header.php';
?>
        <div class="grid gap-6 xl:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
                <h2 class="text-lg font-black">Role Defaults</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">These permissions apply by default. A user-specific allow or deny overrides the role setting. Administrators always have full access.</p>
                <form method="GET" class="mt-4">
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">Role
                        <select name="role" onchange="this.form.submit()" <?= $managedRoles === [] ? 'disabled' : '' ?> class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800">
                            <?php foreach ($managedRoles as $role): ?>
                                <option value="<?= adminUserEscape($role) ?>" <?= $selectedRole === $role ? 'selected' : '' ?>><?= adminUserEscape($roleLabels[$role] ?? ucfirst($role)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
                <?php if ($selectedRole !== ''): ?>
                <form method="POST" class="mt-4 space-y-3">
                    <input type="hidden" name="csrf_token" value="<?= adminUserEscape($adminUsersCsrfToken) ?>">
                    <input type="hidden" name="action" value="save_role">
                    <input type="hidden" name="role" value="<?= adminUserEscape($selectedRole) ?>">
                    <?php foreach ($definitions as $permission => $label): ?>
                        <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-100 px-3 py-2.5 text-sm dark:border-slate-700">
                            <span class="font-medium"><?= adminUserEscape($label) ?></span>
                            <input type="checkbox" name="role_permissions[<?= adminUserEscape($permission) ?>]" value="1" <?= !empty($rolePermissions[$permission]) ? 'checked' : '' ?> class="h-4 w-4 accent-green-600">
                        </label>
                    <?php endforeach; ?>
                    <button type="submit" class="rounded-xl bg-green-600 px-5 py-3 text-sm font-bold text-white hover:bg-green-700">Save Role Defaults</button>
                </form>
                <?php else: ?>
                    <p class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">Create a non-administrator role to configure role defaults.</p>
                <?php endif; ?>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
                <h2 class="text-lg font-black">User Overrides</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">Choose Allow or Deny to override a user's role. Inherit follows the role defaults. Administrator accounts always bypass these overrides.</p>
                <form method="GET" class="mt-4">
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">User
                        <select name="user_id" onchange="this.form.submit()" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800">
                            <option value="">Choose a user</option>
                            <?php foreach ($userRows as $userRow): ?>
                                <option value="<?= (int) $userRow['id'] ?>" <?= (int) $selectedUserId === (int) $userRow['id'] ? 'selected' : '' ?>>
                                    <?= adminUserEscape((string) ($userRow['full_name'] ?: $userRow['username'])) ?> (<?= adminUserEscape($roleLabels[strtolower((string) $userRow['role'])] ?? ucfirst(strtolower((string) $userRow['role']))) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>

                <?php if ($selectedUser && strtolower((string) $selectedUser['role']) === 'admin'): ?>
                    <p class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">Administrator accounts have all permissions and cannot be restricted.</p>
                <?php elseif ($selectedUser): ?>
                    <form method="POST" class="mt-4 space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= adminUserEscape($adminUsersCsrfToken) ?>">
                        <input type="hidden" name="action" value="save_user">
                        <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id'] ?>">
                        <?php foreach ($definitions as $permission => $label): ?>
                            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-100 px-3 py-2.5 text-sm dark:border-slate-700">
                                <span class="font-medium"><?= adminUserEscape($label) ?></span>
                                <select name="user_permissions[<?= adminUserEscape($permission) ?>]" class="h-9 rounded-lg border border-slate-200 bg-slate-50 px-2 text-xs font-semibold dark:border-slate-700 dark:bg-slate-800">
                                    <option value="inherit" <?= ($userOverrides[$permission] ?? 'inherit') === 'inherit' ? 'selected' : '' ?>>Inherit</option>
                                    <option value="allow" <?= ($userOverrides[$permission] ?? '') === 'allow' ? 'selected' : '' ?>>Allow</option>
                                    <option value="deny" <?= ($userOverrides[$permission] ?? '') === 'deny' ? 'selected' : '' ?>>Deny</option>
                                </select>
                            </label>
                        <?php endforeach; ?>
                        <button type="submit" class="rounded-xl bg-green-600 px-5 py-3 text-sm font-bold text-white hover:bg-green-700">Save User Overrides</button>
                    </form>
                <?php else: ?>
                    <p class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">Select a user to view or edit their overrides.</p>
                <?php endif; ?>
            </section>
        </div>
<?php require __DIR__ . '/_footer.php'; ?>
