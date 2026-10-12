<?php

require_once __DIR__ . '/_bootstrap.php';
$adminPageTitle = 'Manage Users';
$adminPageDescription = 'Create, update, activate, deactivate, and permanently delete user accounts.';
$adminUsersNotice = (string) ($_SESSION['admin_users_flash'] ?? '');
unset($_SESSION['admin_users_flash']);
$adminUsersError = '';

$currentPage = '/admin/users/index.php';

$users = $pdo->query('SELECT id, username, email, full_name, role, is_active FROM users ORDER BY id DESC')
    ->fetchAll(PDO::FETCH_ASSOC);
$roleLabels = array_column(appRoles($pdo), 'display_name', 'role_key');
$activeAdminCount = count(adminUserActiveAdmins($pdo));

require __DIR__ . '/_header.php';
?>
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-700 sm:px-7">
                <div>
                    <h2 class="text-lg font-black">User Accounts</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= number_format(count($users)) ?> account(s)</p>
                </div>
                <a href="create.php" class="rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-green-700">Create User</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-300">
                        <tr>
                            <th scope="col" class="px-5 py-3 sm:px-7">Name</th>
                            <th scope="col" class="px-5 py-3">Username</th>
                            <th scope="col" class="px-5 py-3">Email</th>
                            <th scope="col" class="px-5 py-3">Role</th>
                            <th scope="col" class="px-5 py-3">Status</th>
                            <th scope="col" class="px-5 py-3 sm:px-7">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <?php foreach ($users as $listedUser): ?>
                            <?php
                            $listedId = (int) $listedUser['id'];
                            $isSelf = $listedId === $adminUserId;
                            $isActive = (int) $listedUser['is_active'] === 1;
                            $isAdmin = strtolower((string) $listedUser['role']) === 'admin';
                            ?>
                            <tr>
                                <td class="px-5 py-4 font-semibold sm:px-7"><?= adminUserEscape((string) ($listedUser['full_name'] ?: $listedUser['username'])) ?></td>
                                <td class="px-5 py-4 text-slate-600 dark:text-slate-300"><?= adminUserEscape((string) $listedUser['username']) ?></td>
                                <td class="px-5 py-4 text-slate-600 dark:text-slate-300"><?= adminUserEscape((string) ($listedUser['email'] ?? '')) ?></td>
                                <td class="px-5 py-4 text-slate-600 dark:text-slate-300"><?= adminUserEscape($roleLabels[strtolower((string) $listedUser['role'])] ?? ucfirst(strtolower((string) $listedUser['role']))) ?></td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-bold <?= $isActive ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                                </td>
                                <td class="px-5 py-4 sm:px-7">
                                    <div class="flex items-center gap-2">
                                        <a href="modify.php?id=<?= $listedId ?>" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Modify</a>
                                        <?php if (!$isSelf && !($isActive && $isAdmin && $activeAdminCount <= 1)): ?>
                                            <form method="POST" action="modify.php?id=<?= $listedId ?>">
                                                <input type="hidden" name="csrf_token" value="<?= adminUserEscape($adminUsersCsrfToken) ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="is_active" value="<?= $isActive ? '0' : '1' ?>">
                                                <button type="submit" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"><?= $isActive ? 'Deactivate' : 'Activate' ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!$isSelf && !($isActive && $isAdmin && $activeAdminCount <= 1)): ?>
                                            <a href="delete.php?id=<?= $listedId ?>" class="rounded-lg border border-red-200 px-3 py-2 text-xs font-bold text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/40">Delete</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$users): ?>
                            <tr><td colspan="6" class="px-5 py-8 text-center text-slate-500 dark:text-slate-400">No user accounts were found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
<?php require __DIR__ . '/_footer.php'; ?>
