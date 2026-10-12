<?php

require_once __DIR__ . '/_bootstrap.php';
requireAppPermission($pdo, $adminUserId, 'permissions.manage');

$adminPageTitle = 'Role Management';
$adminPageDescription = 'Create roles, update role names, and manage their default access.';
$adminUsersNotice = (string) ($_SESSION['admin_users_flash'] ?? '');
unset($_SESSION['admin_users_flash']);
$adminUsersError = '';

function adminRoleKeyFromName(string $name): string
{
    $key = strtolower(trim($name));
    $key = preg_replace('/[^a-z0-9]+/', '-', $key) ?? '';
    return trim(substr($key, 0, 32), '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($adminUsersCsrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $adminUsersError = 'Your session could not be verified. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $name = trim((string) ($_POST['display_name'] ?? ''));
                $roleKey = adminRoleKeyFromName($name);
                if ($name === '' || mb_strlen($name, 'UTF-8') > 64) {
                    throw new RuntimeException('Enter a role name no longer than 64 characters.');
                }
                if ($roleKey === '') {
                    throw new RuntimeException('Role names must include English letters or numbers.');
                }
                if (in_array($roleKey, ['admin', 'manager', 'staff'], true)) {
                    throw new RuntimeException('That name is reserved for a built-in role.');
                }

                $pdo->beginTransaction();
                $insertRole = $pdo->prepare('INSERT INTO app_roles (role_key, display_name, is_system) VALUES (?, ?, 0)');
                $insertRole->execute([$roleKey, $name]);
                $insertPermission = $pdo->prepare('
                    INSERT INTO app_role_permissions (role_name, permission_key, is_allowed)
                    VALUES (?, ?, 0)
                ');
                foreach (array_keys(appPermissionDefinitions()) as $permission) {
                    $insertPermission->execute([$roleKey, $permission]);
                }
                $pdo->commit();
                $_SESSION['admin_users_flash'] = 'Role created. Configure its defaults in Permissions.';
                header('Location: roles.php');
                exit;
            }

            $roleKey = strtolower(trim((string) ($_POST['role_key'] ?? '')));
            $roleStmt = $pdo->prepare('SELECT role_key, display_name FROM app_roles WHERE role_key = ? LIMIT 1');
            $roleStmt->execute([$roleKey]);
            $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
            if (!$role) {
                throw new RuntimeException('The selected role no longer exists.');
            }

            if ($action === 'rename') {
                $name = trim((string) ($_POST['display_name'] ?? ''));
                if ($name === '' || mb_strlen($name, 'UTF-8') > 64) {
                    throw new RuntimeException('Enter a role name no longer than 64 characters.');
                }
                $update = $pdo->prepare('UPDATE app_roles SET display_name = ? WHERE role_key = ?');
                $update->execute([$name, $roleKey]);
                $_SESSION['admin_users_flash'] = 'Role name updated.';
                header('Location: roles.php');
                exit;
            }

            if ($action !== 'delete') {
                throw new RuntimeException('The requested role action is not valid.');
            }
            if (in_array($roleKey, ['admin', 'staff'], true)) {
                throw new RuntimeException('The Admin role and the Staff fallback role cannot be deleted.');
            }

            $pdo->beginTransaction();
            $lockRole = $pdo->prepare('SELECT role_key FROM app_roles WHERE role_key = ? FOR UPDATE');
            $lockRole->execute([$roleKey]);
            if (!$lockRole->fetchColumn()) {
                throw new RuntimeException('The selected role no longer exists.');
            }
            $reassignUsers = $pdo->prepare('UPDATE users SET role = ? WHERE LOWER(role) = ?');
            $reassignUsers->execute(['staff', $roleKey]);
            $deletePermissions = $pdo->prepare('DELETE FROM app_role_permissions WHERE role_name = ?');
            $deletePermissions->execute([$roleKey]);
            $deleteRole = $pdo->prepare('DELETE FROM app_roles WHERE role_key = ?');
            $deleteRole->execute([$roleKey]);
            $pdo->commit();
            $_SESSION['admin_users_flash'] = 'Role deleted. Assigned users were moved to ' . appRoleLabel($pdo, 'staff') . '.';
            header('Location: roles.php');
            exit;
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $adminUsersError = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Unable to manage roles: ' . $exception->getMessage());
            $adminUsersError = (string) $exception->getCode() === '23000'
                ? 'That role name already exists.'
                : 'The role could not be saved. Check the application error log and try again.';
        }
    }
}

$roles = appRoles($pdo);
$roleCounts = $pdo->query('SELECT LOWER(role) AS role_key, COUNT(*) AS user_count FROM users GROUP BY LOWER(role)')
    ->fetchAll(PDO::FETCH_KEY_PAIR);

require __DIR__ . '/_header.php';
?>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
            <h2 class="text-lg font-black">Create Role</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">New roles start with no permissions. Set their default access in Permissions.</p>
            <form method="POST" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <input type="hidden" name="csrf_token" value="<?= adminUserEscape($adminUsersCsrfToken) ?>">
                <input type="hidden" name="action" value="create">
                <label class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200">Role name
                    <input name="display_name" maxlength="64" required class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800">
                </label>
                <button type="submit" class="h-11 rounded-xl bg-green-600 px-5 text-sm font-bold text-white hover:bg-green-700">Create Role</button>
            </form>
        </section>

        <section class="space-y-4" aria-label="Existing roles">
            <?php foreach ($roles as $role): ?>
                <?php $roleKey = (string) $role['role_key']; ?>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <form method="POST" class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-end">
                            <input type="hidden" name="csrf_token" value="<?= adminUserEscape($adminUsersCsrfToken) ?>">
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="role_key" value="<?= adminUserEscape($roleKey) ?>">
                            <label class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                <?= adminUserEscape(ucfirst($roleKey)) ?><?= (int) $role['is_system'] === 1 ? ' · Built-in' : '' ?>
                                <input name="display_name" value="<?= adminUserEscape($role['display_name']) ?>" maxlength="64" required class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800">
                            </label>
                            <span class="pb-3 text-sm text-slate-500 dark:text-slate-400"><?= (int) ($roleCounts[$roleKey] ?? 0) ?> assigned users</span>
                            <button type="submit" class="h-11 rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Save Name</button>
                        </form>
                        <div class="flex gap-2">
                            <a href="permissions.php?role=<?= rawurlencode($roleKey) ?>" class="inline-flex h-11 items-center rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Permissions</a>
                            <?php if (!in_array($roleKey, ['admin', 'staff'], true)): ?>
                                <form method="POST" onsubmit="return confirm('Delete this role? Assigned users will be moved to the default fallback role.');">
                                    <input type="hidden" name="csrf_token" value="<?= adminUserEscape($adminUsersCsrfToken) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="role_key" value="<?= adminUserEscape($roleKey) ?>">
                                    <button type="submit" class="h-11 rounded-xl bg-red-50 px-4 text-sm font-bold text-red-700 hover:bg-red-100 dark:bg-red-950/40 dark:text-red-300">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
<?php require __DIR__ . '/_footer.php'; ?>
