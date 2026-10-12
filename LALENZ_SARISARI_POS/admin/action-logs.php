<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/system.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/action_log.php';

requireAuth();
$userId = fetchUser();
$userStmt = $pdo->prepare('SELECT id, username, full_name, role, is_active FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user || (int) $user['is_active'] !== 1) {
    session_unset();
    session_destroy();
    header('Location: ../login.php');
    exit;
}

requireAppPermission($pdo, $userId, 'admin.view');
ensureActionLogTable($pdo);

$entityType = trim((string) ($_GET['type'] ?? ''));
$outcome = trim((string) ($_GET['outcome'] ?? ''));
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $page !== false && $page !== null && $page > 0 ? $page : 1;
$perPage = 10;
$allowedTypes = ['product', 'category', 'sale', 'setting', 'expense'];
if (!in_array($entityType, $allowedTypes, true)) {
    $entityType = '';
}
if (!in_array($outcome, ['success', 'failure'], true)) {
    $outcome = '';
}

$conditions = [];
$parameters = [];
if ($entityType !== '') {
    $conditions[] = 'entity_type = ?';
    $parameters[] = $entityType;
}
if ($outcome !== '') {
    $conditions[] = 'outcome = ?';
    $parameters[] = $outcome;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM action_logs {$where}");
$countStmt->execute($parameters);
$totalLogs = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalLogs / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$logsStmt = $pdo->prepare("
    SELECT id, actor_name, action, entity_type, entity_id, entity_label,
        outcome, details_json, ip_address, created_at
    FROM action_logs
    {$where}
    ORDER BY id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$logsStmt->execute($parameters);
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

$currentPage = 'action-logs.php';
$username = $user['username'] ?? 'Admin';
$fullName = $user['full_name'] ?: $username;
$user_role = $user['role'] ?? 'Admin';
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filterQuery = http_build_query(array_filter([
    'type' => $entityType,
    'outcome' => $outcome,
], static fn ($value): bool => $value !== ''));

?>
<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action Logs | <?= $escape($systemName) ?></title>
    <link rel="icon" href="<?= $escape($logo) ?>" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
        (function () {
            const savedTheme = localStorage.getItem('lalenz_theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) document.documentElement.classList.add('dark');
        })();
    </script>
    <style>body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<main class="min-h-screen lg:ml-64">
    <header class="flex min-h-20 items-center border-b border-slate-200 bg-white px-4 py-4 sm:px-6 lg:px-8 dark:border-slate-700 dark:bg-slate-900">
        <div>
            <h1 class="text-2xl font-black">Action Logs</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Product, category, sales, expenses, and settings actions, including failed attempts.</p>
        </div>
    </header>
    <div class="space-y-6 p-4 sm:p-6 lg:p-8">
        <form method="GET" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
            <label class="text-sm font-semibold">Area
                <select name="type" class="mt-1 block h-10 rounded-lg border border-slate-300 bg-white px-3 dark:border-slate-600 dark:bg-slate-800">
                    <option value="">All areas</option>
                    <?php foreach ($allowedTypes as $type): ?>
                        <option value="<?= $escape($type) ?>" <?= $entityType === $type ? 'selected' : '' ?>><?= $escape(ucfirst($type)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="text-sm font-semibold">Outcome
                <select name="outcome" class="mt-1 block h-10 rounded-lg border border-slate-300 bg-white px-3 dark:border-slate-600 dark:bg-slate-800">
                    <option value="">All outcomes</option>
                    <option value="success" <?= $outcome === 'success' ? 'selected' : '' ?>>Success</option>
                    <option value="failure" <?= $outcome === 'failure' ? 'selected' : '' ?>>Failed attempt</option>
                </select>
            </label>
            <button class="h-10 rounded-lg bg-green-600 px-4 text-sm font-bold text-white hover:bg-green-700">Filter</button>
            <a href="action-logs.php" class="h-10 content-center rounded-lg border border-slate-300 px-4 text-sm font-bold dark:border-slate-600">Clear</a>
            <span class="ml-auto text-sm text-slate-500"><?= number_format($totalLogs) ?> log(s)</span>
        </form>
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1000px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-300">
                        <tr>
                            <th class="px-5 py-3">Timestamp</th><th class="px-5 py-3">User</th><th class="px-5 py-3">Action</th>
                            <th class="px-5 py-3">Target</th><th class="px-5 py-3">Outcome</th><th class="px-5 py-3">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $details = json_decode((string) ($log['details_json'] ?? ''), true);
                        $detailsText = is_array($details)
                            ? implode(' · ', array_map(
                                static fn ($key, $value): string => $key . ': ' . (is_scalar($value) ? (string) $value : json_encode($value)),
                                array_keys($details),
                                array_values($details)
                            ))
                            : '';
                        ?>
                        <tr class="align-top">
                            <td class="whitespace-nowrap px-5 py-4"><?= $escape($log['created_at']) ?></td>
                            <td class="px-5 py-4 font-semibold"><?= $escape($log['actor_name']) ?></td>
                            <td class="px-5 py-4"><?= $escape(ucwords(str_replace(['.', '_'], ' ', $log['action']))) ?></td>
                            <td class="px-5 py-4"><?= $escape(ucfirst($log['entity_type'])) ?><?= $log['entity_id'] !== null ? ' #' . (int) $log['entity_id'] : '' ?><?= $log['entity_label'] ? ' — ' . $escape($log['entity_label']) : '' ?></td>
                            <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-bold <?= $log['outcome'] === 'success' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' ?>"><?= $log['outcome'] === 'success' ? 'Success' : 'Failed' ?></span></td>
                            <td class="max-w-xl whitespace-normal px-5 py-4 text-slate-600 dark:text-slate-300"><?= $escape($detailsText) ?><?php if ($log['ip_address']): ?><div class="mt-1 text-xs text-slate-400">IP <?= $escape($log['ip_address']) ?></div><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$logs): ?><tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No action logs match these filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="flex items-center justify-between border-t border-slate-200 px-5 py-4 dark:border-slate-700" aria-label="Log pages">
                    <span class="text-sm text-slate-500">Page <?= $page ?> of <?= $totalPages ?></span>
                    <?php
                    $maxVisiblePages = 5;
                    $firstVisiblePage = max(1, $page - intdiv($maxVisiblePages, 2));
                    $lastVisiblePage = min($totalPages, $firstVisiblePage + $maxVisiblePages - 1);
                    $firstVisiblePage = max(1, $lastVisiblePage - $maxVisiblePages + 1);
                    ?>
                    <div class="flex items-center gap-2">
                        <?php if ($page > 1): ?>
                            <a aria-label="Previous page" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold dark:border-slate-600" href="?<?= $escape($filterQuery . ($filterQuery !== '' ? '&' : '') . 'page=' . ($page - 1)) ?>">Previous</a>
                        <?php else: ?>
                            <button type="button" aria-label="Previous page" disabled class="cursor-not-allowed rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold opacity-40 dark:border-slate-600">Previous</button>
                        <?php endif; ?>
                        <div class="flex items-center gap-1">
                            <?php for ($number = $firstVisiblePage; $number <= $lastVisiblePage; $number++): ?>
                                <a
                                    aria-label="Go to page <?= $number ?>"
                                    <?= $number === $page ? 'aria-current="page"' : '' ?>
                                    class="flex h-9 w-9 items-center justify-center rounded-lg border text-sm font-bold <?= $number === $page ? 'border-green-600 bg-green-600 text-white' : 'border-slate-300 text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800' ?>"
                                    href="?<?= $escape($filterQuery . ($filterQuery !== '' ? '&' : '') . 'page=' . $number) ?>"
                                ><?= $number ?></a>
                            <?php endfor; ?>
                        </div>
                        <?php if ($page < $totalPages): ?>
                            <a aria-label="Next page" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold dark:border-slate-600" href="?<?= $escape($filterQuery . ($filterQuery !== '' ? '&' : '') . 'page=' . ($page + 1)) ?>">Next</a>
                        <?php else: ?>
                            <button type="button" aria-label="Next page" disabled class="cursor-not-allowed rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold opacity-40 dark:border-slate-600">Next</button>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
