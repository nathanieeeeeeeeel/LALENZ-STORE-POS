<?php

require_once __DIR__ . '/_bootstrap.php';
$adminPageTitle = 'Create User';
$adminPageDescription = 'Add a new user account to the store.';
$adminUsersNotice = '';
$adminUsersError = '';
$values = ['full_name' => '', 'username' => '', 'email' => '', 'role' => 'staff'];
$emailRequired = adminUserEmailRequired($pdo);
$nameMaxLength = adminUserColumnMaxLength($pdo, 'full_name', 100);
$usernameMaxLength = adminUserColumnMaxLength($pdo, 'username', 100);
$roles = appRoles($pdo);
$currentPage = '/admin/users/create.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($adminUsersCsrfToken, $postedToken)) {
        $adminUsersError = 'Your session could not be verified. Refresh the page and try again.';
    } else {
        foreach (array_keys($values) as $field) {
            $values[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        $password = (string) ($_POST['password'] ?? '');

        if ($values['full_name'] === '' || $values['username'] === '' || $password === '' || ($emailRequired && $values['email'] === '')) {
            $adminUsersError = 'Name, username, password, and any database-required email address are required.';
        } elseif (mb_strlen($values['full_name'], 'UTF-8') > $nameMaxLength || mb_strlen($values['username'], 'UTF-8') > $usernameMaxLength) {
            $adminUsersError = "Name must be {$nameMaxLength} characters or fewer and username must be {$usernameMaxLength} characters or fewer.";
        } elseif ($values['email'] !== '' && (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['email']) > 255)) {
            $adminUsersError = 'Enter a valid email address no longer than 255 characters.';
        } elseif (!adminUserRoleAllowed($pdo, $values['role'])) {
            $adminUsersError = 'Choose a valid account role.';
        } elseif ($values['role'] === 'admin' && !$adminActorIsAdmin) {
            $adminUsersError = 'Only an administrator can create another administrator account.';
        } elseif (strlen($password) < 8 || strlen($password) > 72) {
            $adminUsersError = 'The password must be at least 8 characters and no more than 72 bytes.';
        } else {
            try {
                $insert = $pdo->prepare('
                    INSERT INTO users (username, email, password, full_name, role, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ');
                $insert->execute([
                    $values['username'],
                    $values['email'] === '' ? null : $values['email'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $values['full_name'],
                    $values['role'],
                ]);
                $_SESSION['admin_users_flash'] = 'User account created.';
                header('Location: index.php');
                exit;
            } catch (PDOException $exception) {
                error_log('Unable to create user account: ' . $exception->getMessage());
                $adminUsersError = (string) $exception->getCode() === '23000'
                    ? 'That username or email is already in use, or a required account field is missing.'
                    : 'The user account could not be created. Check the application error log and try again.';
            }
        }
    }
}

require __DIR__ . '/_header.php';
?>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
            <h2 class="text-lg font-black">Account Details</h2>
            <form method="POST" class="mt-5 grid max-w-3xl gap-4 sm:grid-cols-2">
                <input type="hidden" name="csrf_token" value="<?= adminUserEscape($adminUsersCsrfToken) ?>">
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
                    <select name="role" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800">
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= adminUserEscape($role['role_key']) ?>" <?= $values['role'] === $role['role_key'] ? 'selected' : '' ?>><?= adminUserEscape($role['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-700 dark:text-slate-200 sm:col-span-2">Initial Password
                    <input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800">
                    <span class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">At least 8 characters, maximum 72 bytes.</span>
                </label>
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <button type="submit" class="rounded-xl bg-green-600 px-5 py-3 text-sm font-bold text-white hover:bg-green-700">Create User</button>
                    <a href="index.php" class="rounded-xl border border-slate-200 px-5 py-3 text-sm font-bold text-slate-700 dark:border-slate-700 dark:text-slate-200">Cancel</a>
                </div>
            </form>
        </section>
<?php require __DIR__ . '/_footer.php'; ?>
