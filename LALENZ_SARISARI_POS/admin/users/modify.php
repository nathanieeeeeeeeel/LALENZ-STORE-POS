<?php

require_once __DIR__ . '/_bootstrap.php';
$adminPageTitle = 'Modify User';
$adminPageDescription = 'Update account details, change its role, or activate/deactivate it.';
$adminUsersNotice = '';
$adminUsersError = '';
$emailRequired = adminUserEmailRequired($pdo);
$nameMaxLength = adminUserColumnMaxLength($pdo, 'full_name', 100);
$usernameMaxLength = adminUserColumnMaxLength($pdo, 'username', 100);
$roles = appRoles($pdo);
$targetId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$targetId || $targetId < 1) {
    http_response_code(400);
    exit('A valid user ID is required.');
}

$targetUser = adminUserFind($pdo, (int) $targetId);
if (!$targetUser) {
    http_response_code(404);
    exit('User account not found.');
}

$values = [
    'full_name' => (string) ($targetUser['full_name'] ?? ''),
    'username' => (string) $targetUser['username'],
    'email' => (string) ($targetUser['email'] ?? ''),
    'role' => strtolower((string) $targetUser['role']),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($adminUsersCsrfToken, $postedToken)) {
        $adminUsersError = 'Your session could not be verified. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            $pdo->beginTransaction();
            $lockedUser = adminUserFind($pdo, (int) $targetId, true);
            if (!$lockedUser) {
                throw new RuntimeException('User account not found.');
            }

            if ($action === 'toggle_status') {
                $newActiveStatus = (string) ($_POST['is_active'] ?? '');
                if (!in_array($newActiveStatus, ['0', '1'], true)) {
                    throw new RuntimeException('Choose a valid account status.');
                }
                if (strtolower((string) $lockedUser['role']) === 'admin' && !$adminActorIsAdmin) {
                    throw new RuntimeException('Only an administrator can change an administrator account status.');
                }
                if ((int) $targetId === $adminUserId && $newActiveStatus === '0') {
                    throw new RuntimeException('You cannot deactivate your own account.');
                }

                $activeAdminIds = adminUserActiveAdmins($pdo, true);
                if (
                    (int) $lockedUser['is_active'] === 1
                    && strtolower((string) $lockedUser['role']) === 'admin'
                    && $newActiveStatus === '0'
                    && count($activeAdminIds) <= 1
                ) {
                    throw new RuntimeException('The last active administrator cannot be deactivated.');
                }

                $updateStatus = $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
                $updateStatus->execute([(int) $newActiveStatus, (int) $targetId]);
                $pdo->commit();
                $_SESSION['admin_users_flash'] = $newActiveStatus === '1'
                    ? 'User account activated.'
                    : 'User account deactivated.';
                header('Location: index.php');
                exit;
            }

            if ($action !== 'save') {
                throw new RuntimeException('The requested user action is not valid.');
            }
            if (strtolower((string) $lockedUser['role']) === 'admin' && !$adminActorIsAdmin) {
                throw new RuntimeException('Only an administrator can modify an administrator account.');
            }

            foreach (array_keys($values) as $field) {
                $values[$field] = trim((string) ($_POST[$field] ?? ''));
            }
            $newPassword = (string) ($_POST['password'] ?? '');

            if ($values['full_name'] === '' || $values['username'] === '' || ($emailRequired && $values['email'] === '')) {
                throw new RuntimeException('Name, username, and any database-required email address are required.');
            }
            if (mb_strlen($values['full_name'], 'UTF-8') > $nameMaxLength || mb_strlen($values['username'], 'UTF-8') > $usernameMaxLength) {
                throw new RuntimeException("Name must be {$nameMaxLength} characters or fewer and username must be {$usernameMaxLength} characters or fewer.");
            }
            if ($values['email'] !== '' && (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['email']) > 255)) {
                throw new RuntimeException('Enter a valid email address no longer than 255 characters.');
            }
            if (!adminUserRoleAllowed($pdo, $values['role'])) {
                throw new RuntimeException('Choose a valid account role.');
            }
            if ($values['role'] === 'admin' && !$adminActorIsAdmin) {
                throw new RuntimeException('Only an administrator can assign the administrator role.');
            }
            if ($newPassword !== '' && (strlen($newPassword) < 8 || strlen($newPassword) > 72)) {
                throw new RuntimeException('The new password must be at least 8 characters and no more than 72 bytes.');
            }
            if (
                (int) $targetId === $adminUserId
                && $values['role'] !== strtolower((string) $lockedUser['role'])
            ) {
                throw new RuntimeException('You cannot change your own administrator role.');
            }

            $activeAdminIds = adminUserActiveAdmins($pdo, true);
            if (
                (int) $lockedUser['is_active'] === 1
                && strtolower((string) $lockedUser['role']) === 'admin'
                && $values['role'] !== 'admin'
                && count($activeAdminIds) <= 1
            ) {
                throw new RuntimeException('The last active administrator cannot be changed to another role.');
            }

            if ($newPassword === '') {
                $update = $pdo->prepare('UPDATE users SET username = ?, email = ?, full_name = ?, role = ? WHERE id = ?');
                $update->execute([
                    $values['username'],
                    $values['email'] === '' ? null : $values['email'],
                    $values['full_name'],
                    $values['role'],
                    (int) $targetId,
                ]);
            } else {
                $update = $pdo->prepare('UPDATE users SET username = ?, email = ?, full_name = ?, role = ?, password = ? WHERE id = ?');
                $update->execute([
                    $values['username'],
                    $values['email'] === '' ? null : $values['email'],
                    $values['full_name'],
                    $values['role'],
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    (int) $targetId,
                ]);
            }
            $pdo->commit();
            $_SESSION['admin_users_flash'] = 'User account updated.';
            header('Location: index.php');
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
            error_log('Unable to update user account: ' . $exception->getMessage());
            $adminUsersError = (string) $exception->getCode() === '23000'
                ? 'That username or email is already in use, or a required account field is missing.'
                : 'The user account could not be updated. Check the application error log and try again.';
        }
    }
}

$adminUsersNotice = '';
require __DIR__ . '/_header.php';
?>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
            <h2 class="text-lg font-black">Account Details</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= adminUserEscape($targetUser['username']) ?><?= (int) $targetId === $adminUserId ? ' (your account)' : '' ?></p>
            <form method="POST" class="mt-5 grid max-w-3xl gap-4 sm:grid-cols-2">
                <input type="hidden" name="csrf_token" value="<?= adminUserEscape($adminUsersCsrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <label class="text-sm font-semibold text-slate-700 dark:text-slate-200">Full Name
                    <input name="full_name" value="<?= adminUserEscape($values['full_name']) ?>" maxlength="<?= $nameMaxLength ?>" required class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800">
                </label>
                <label class="text-sm font-semibold text-slate-700 dark:text-slate-200">Username
                    <input name="username" value="<?= adminUserEscape($values['username']) ?>" maxlength="<?= $usernameMaxLength ?>" autocomplete="username" required class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800">
                </label>
                <label class="text-sm font-semibold text-slate-700 dark:text-slate-200">Email<?= $emailRequired ? '' : ' (optional)' ?>
                    <input type="email" name="email" value="<?= adminUserEscape($values['email']) ?>" maxlength="255" autocomplete="email" <?= $emailRequired ? 'required' : '' ?> class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800">
                </label>
                <label class="text-sm font-semibold text-slate-700 dark:text-slate-200">Role
                    <select name="role" <?= (int) $targetId === $adminUserId ? 'disabled' : '' ?> class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-green-500 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800">
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= adminUserEscape($role['role_key']) ?>" <?= $values['role'] === $role['role_key'] ? 'selected' : '' ?>><?= adminUserEscape($role['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ((int) $targetId === $adminUserId): ?>
                        <input type="hidden" name="role" value="<?= adminUserEscape($values['role']) ?>">
                        <span class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">You cannot change your own administrator role.</span>
                    <?php endif; ?>
                </label>
                <label class="text-sm font-semibold text-slate-700 dark:text-slate-200 sm:col-span-2">Set New Password (optional)
                    <input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800">
                    <span class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">Leave empty to keep the current password; otherwise use at least 8 characters (maximum 72 bytes).</span>
                </label>
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <button type="submit" class="rounded-xl bg-green-600 px-5 py-3 text-sm font-bold text-white hover:bg-green-700">Save Changes</button>
                    <a href="index.php" class="rounded-xl border border-slate-200 px-5 py-3 text-sm font-bold text-slate-700 dark:border-slate-700 dark:text-slate-200">Cancel</a>
                </div>
            </form>
        </section>
<?php require __DIR__ . '/_footer.php'; ?>
