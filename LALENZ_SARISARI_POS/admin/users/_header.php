<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= adminUserEscape($adminPageTitle) ?> | <?= adminUserEscape($systemName) ?></title>
    <link rel="icon" href="<?= adminUserEscape($logo) ?>" type="image/x-icon">
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
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<main class="min-h-screen lg:ml-64 dark:bg-slate-950">
    <header class="flex min-h-20 items-center justify-between border-b border-slate-200 bg-white px-4 py-4 sm:px-6 lg:px-8 dark:border-slate-700 dark:bg-slate-900">
        <div>
            <h1 class="text-2xl font-black tracking-tight"><?= adminUserEscape($adminPageTitle) ?></h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= adminUserEscape($adminPageDescription) ?></p>
        </div>
        <a href="index.php" class="shrink-0 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Manage Users</a>
    </header>
    <div class="space-y-6 p-4 sm:p-6 lg:p-8">
        <nav class="flex flex-wrap gap-2 text-sm font-bold" aria-label="Admin navigation">
            <a href="../../admin.php" class="rounded-xl bg-white px-4 py-2.5 text-slate-600 dark:bg-slate-900 dark:text-slate-300">Admin Panel</a>
            <a href="index.php" class="<?= $currentPage == '/admin/users/index.php' ? 'rounded-xl bg-green-600 px-4 py-2.5 text-white' : 'rounded-xl bg-white px-4 py-2.5 text-slate-600 dark:bg-slate-900 dark:text-slate-300' ?>">Users</a>
            <a href="create.php" class="<?= $currentPage == '/admin/users/create.php' ? 'rounded-xl bg-green-600 px-4 py-2.5 text-white' : 'rounded-xl bg-white px-4 py-2.5 text-slate-600 dark:bg-slate-900 dark:text-slate-300' ?>">Create User</a>
            <?php if (hasAppPermission($pdo, $adminUserId, 'permissions.manage')): ?>
                <a href="roles.php" class="rounded-xl bg-white px-4 py-2.5 text-slate-600 dark:bg-slate-900 dark:text-slate-300">Roles</a>
                <a href="permissions.php" class="rounded-xl bg-white px-4 py-2.5 text-slate-600 dark:bg-slate-900 dark:text-slate-300">Permissions</a>
            <?php endif; ?>
        </nav>
        <?php if ($adminUsersNotice !== ''): ?>
            <div role="status" class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300"><?= adminUserEscape($adminUsersNotice) ?></div>
        <?php endif; ?>
        <?php if ($adminUsersError !== ''): ?>
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300"><?= adminUserEscape($adminUsersError) ?></div>
        <?php endif; ?>
