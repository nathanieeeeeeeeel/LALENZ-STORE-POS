<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/system.php';
require_once __DIR__ . '/config/permissions.php';

requireAuth();
$userId = fetchUser();

$userStmt = $pdo->prepare('SELECT id, username, full_name, role, is_active FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([$userId]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

if ((int) $currentUser['is_active'] !== 1) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

requireAppPermission($pdo, $userId, 'admin.view');

$userStats = $pdo->query("
    SELECT
        COUNT(*) AS total_users,
        COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_users
    FROM users
")->fetch(PDO::FETCH_ASSOC);
$users = $pdo->query("
    SELECT id, username, full_name, role, is_active
    FROM users
    ORDER BY id DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$totalUsers = (int) ($userStats['total_users'] ?? 0);
$activeUsers = (int) ($userStats['active_users'] ?? 0);
$currentPage = basename($_SERVER['PHP_SELF']);
$username = $currentUser['username'] ?? 'Admin';
$fullName = $currentUser['full_name'] ?? $username;
$user_role = $currentUser['role'] ?? 'Admin';
$roleLabels = array_column(appRoles($pdo), 'display_name', 'role_key');

?>
<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel | <?= htmlspecialchars($systemName) ?></title>
    <meta name="description" content="Administrative overview of store accounts and settings">
    <link rel="icon" href="<?= htmlspecialchars($logo) ?>" type="image/x-icon">
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
                <h1 class="text-2xl font-black tracking-tight">Admin Panel</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Manage and review store administration.</p>
            </div>
        </header>

        <div class="space-y-6 p-4 sm:p-6 lg:p-8">
            <section class="grid gap-4 sm:grid-cols-2" aria-label="Account overview">
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Total Accounts</p>
                    <p class="mt-2 text-3xl font-black"><?= number_format($totalUsers) ?></p>
                </article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Active Accounts</p>
                    <p class="mt-2 text-3xl font-black text-green-700 dark:text-green-300"><?= number_format($activeUsers) ?></p>
                </article>
            </section>

            <section id="user-accounts" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-700 sm:px-7">
                    <div>
                        <h2 class="text-lg font-black">User Accounts</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Showing up to 50 most recently created accounts.</p>
                    </div>
                    <?php if (hasAppPermission($pdo, $userId, 'users.manage')): ?>
                        <a href="admin/users/index.php" class="rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-green-700">Manage Users</a>
                    <?php endif; ?>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th scope="col" class="px-5 py-3 sm:px-7">Name</th>
                                <th scope="col" class="px-5 py-3">Username</th>
                                <th scope="col" class="px-5 py-3">Role</th>
                                <th scope="col" class="px-5 py-3 sm:px-7">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <?php foreach ($users as $listedUser): ?>
                                <tr>
                                    <td class="px-5 py-4 font-semibold sm:px-7"><?= htmlspecialchars((string) ($listedUser['full_name'] ?: $listedUser['username'])) ?></td>
                                    <td class="px-5 py-4 text-slate-600 dark:text-slate-300"><?= htmlspecialchars((string) $listedUser['username']) ?></td>
                                    <td class="px-5 py-4 text-slate-600 dark:text-slate-300"><?= htmlspecialchars($roleLabels[strtolower((string) $listedUser['role'])] ?? ucfirst(strtolower((string) $listedUser['role']))) ?></td>
                                    <td class="px-5 py-4 sm:px-7">
                                        <?php if ((int) $listedUser['is_active'] === 1): ?>
                                            <span class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-bold text-green-800 dark:bg-green-900/40 dark:text-green-300">Active</span>
                                        <?php else: ?>
                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$users): ?>
                                <tr>
                                    <td colspan="4" class="px-5 py-8 text-center text-slate-500 dark:text-slate-400">No user accounts were found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
                <h2 class="text-lg font-black">Administration</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Configure store details, account security, and database backups from Settings.</p>
                <a href="settings.php" class="mt-4 inline-flex rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Open Settings</a>
                <a href="admin/action-logs.php" class="mt-4 ml-2 inline-flex rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-green-700">View Action Logs</a>
            </section>
        </div>
    </main>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
