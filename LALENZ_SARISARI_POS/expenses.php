<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/system.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/action_log.php';

requireAuth();
$userId = fetchUser();

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
ensureActionLogTable($pdo);
requireAppPermission($pdo, $userId, 'expenses.view');
$canManageExpenses = hasAppPermission($pdo, $userId, 'expenses.manage');

$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$userRole = $user['role'] ?? 'Staff';
$categories = [
    'Inventory',
    'Utilities',
    'Rent',
    'Transportation',
    'Salaries',
    'Maintenance',
    'Supplies',
    'Other',
];

$pdo->exec("
    CREATE TABLE IF NOT EXISTS expenses (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        description TEXT NOT NULL,
        category VARCHAR(50) NOT NULL,
        amount DECIMAL(12, 2) NOT NULL,
        expense_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notes TEXT NULL,
        user_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_expenses_date (expense_date),
        INDEX idx_expenses_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$expenseDateColumn = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'expense_date'")->fetch(PDO::FETCH_ASSOC);
if ($expenseDateColumn && preg_match('/^date\b/i', (string) $expenseDateColumn['Type'])) {
    $pdo->exec('ALTER TABLE expenses MODIFY expense_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
}

$descriptionColumn = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'description'")->fetch(PDO::FETCH_ASSOC);
if ($descriptionColumn && !preg_match('/^(tiny|medium|long)?text\b/i', (string) $descriptionColumn['Type'])) {
    $pdo->exec('ALTER TABLE expenses MODIFY description TEXT NOT NULL');
}

if (empty($_SESSION['expenses_csrf_token'])) {
    $_SESSION['expenses_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['expenses_csrf_token'];
$errorMessage = '';
$successMessage = $_SESSION['expenses_flash'] ?? '';
unset($_SESSION['expenses_flash']);
$formValues = [
    'title' => '',
    'details' => '',
    'category' => 'Other',
    'amount' => '',
];

function validExpenseDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $actionLogAction = [
        'save' => 'expense.created',
        'update' => 'expense.updated',
        'delete' => 'expense.deleted',
    ][$action] ?? 'expense.action';
    $actionLogEntityId = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT);
    $actionLogEntityId = $actionLogEntityId !== false && $actionLogEntityId !== null && $actionLogEntityId > 0
        ? (int) $actionLogEntityId
        : null;
    $actionLogLabel = trim((string) ($_POST['title'] ?? '')) ?: null;
    $actionLogDetails = [];
    $actionLogRecorded = false;

    if (!hasAppPermission($pdo, $userId, 'expenses.manage')) {
        appLogAction($pdo, $userId, $actionLogAction, 'expense', $actionLogEntityId, $actionLogLabel, 'failure', [
            'reason' => 'Permission denied.',
        ]);
    }
    requireAppPermission($pdo, $userId, 'expenses.manage');
    foreach ($formValues as $field => $defaultValue) {
        $formValues[$field] = trim((string) ($_POST[$field] ?? $defaultValue));
    }

    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        $errorMessage = 'Your session could not be verified. Refresh the page and try again.';
    } else {
        if ($action === 'delete') {
            $expenseId = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$expenseId || $expenseId < 1) {
                $errorMessage = 'Select a valid expense to delete.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $findExpense = $pdo->prepare('SELECT description, category, amount FROM expenses WHERE id = ? FOR UPDATE');
                    $findExpense->execute([$expenseId]);
                    $expense = $findExpense->fetch(PDO::FETCH_ASSOC);
                    if (!$expense) {
                        throw new RuntimeException('Expense not found.');
                    }
                    $delete = $pdo->prepare('DELETE FROM expenses WHERE id = ?');
                    $delete->execute([$expenseId]);
                    appLogAction($pdo, $userId, $actionLogAction, 'expense', (int) $expenseId, (string) $expense['description'], 'success', [
                        'category' => $expense['category'],
                        'amount' => number_format((float) $expense['amount'], 2, '.', ''),
                    ]);
                    $pdo->commit();
                    $_SESSION['expenses_flash'] = 'Expense deleted.';
                    header('Location: expenses.php');
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errorMessage = $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'Unable to delete the expense. Please try again.';
                    $actionLogDetails['reason'] = $errorMessage;
                }
            }
        } elseif ($action === 'save' || $action === 'update') {
            $title = $formValues['title'];
            $details = $formValues['details'];
            $category = $formValues['category'];
            $amount = filter_var($formValues['amount'], FILTER_VALIDATE_FLOAT);
            $expenseDate = trim((string) ($_POST['expense_date'] ?? ''));
            $expenseId = $action === 'update'
                ? filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT)
                : null;

            if ($action === 'update' && (!$expenseId || $expenseId < 1)) {
                $errorMessage = 'Select a valid expense to update.';
            } elseif ($title === '' || mb_strlen($title) > 150) {
                $errorMessage = 'Enter a title between 1 and 150 characters.';
            } elseif (mb_strlen($details) > 2000) {
                $errorMessage = 'Details must be 2,000 characters or fewer.';
            } elseif (!in_array($category, $categories, true)) {
                $errorMessage = 'Select a valid expense category.';
            } elseif ($amount === false || !is_finite((float) $amount) || $amount <= 0 || $amount > 9999999999.99) {
                $errorMessage = 'Enter an amount greater than zero and within the supported limit.';
            } elseif ($action === 'update' && !validExpenseDate($expenseDate)) {
                $errorMessage = 'Enter a valid expense date.';
            } else {
                try {
                    $pdo->beginTransaction();
                    if ($action === 'update') {
                        $save = $pdo->prepare("
                            UPDATE expenses
                            SET description = ?, category = ?, amount = ?, expense_date = CONCAT(?, ' ', TIME(expense_date)), notes = ?
                            WHERE id = ?
                        ");
                        $save->execute([$title, $category, $amount, $expenseDate, $details ?: null, $expenseId]);
                        if ($save->rowCount() === 0) {
                            $checkExpense = $pdo->prepare('SELECT id FROM expenses WHERE id = ?');
                            $checkExpense->execute([$expenseId]);
                            if (!$checkExpense->fetchColumn()) {
                                throw new RuntimeException('Expense not found.');
                            }
                        }
                        $actionLogEntityId = (int) $expenseId;
                        $_SESSION['expenses_flash'] = 'Expense updated.';
                    } else {
                        $save = $pdo->prepare("
                            INSERT INTO expenses (description, category, amount, expense_date, notes, user_id)
                            VALUES (?, ?, ?, NOW(), ?, ?)
                        ");
                        $save->execute([$title, $category, $amount, $details ?: null, $userId]);
                        $actionLogEntityId = (int) $pdo->lastInsertId();
                        $_SESSION['expenses_flash'] = 'Expense recorded.';
                    }

                    $actionLogLabel = $title;
                    $actionLogDetails = [
                        'category' => $category,
                        'amount' => number_format((float) $amount, 2, '.', ''),
                    ];
                    appLogAction($pdo, $userId, $actionLogAction, 'expense', $actionLogEntityId, $actionLogLabel, 'success', $actionLogDetails);
                    $pdo->commit();
                    header('Location: expenses.php');
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errorMessage = $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'Unable to save the expense. Please try again.';
                    unset($_SESSION['expenses_flash']);
                    $actionLogDetails['reason'] = $errorMessage;
                }
            }
        } else {
            $errorMessage = 'The requested expense action is not valid.';
        }
    }

    if (!$actionLogRecorded && $errorMessage !== '') {
        $actionLogDetails['reason'] = $actionLogDetails['reason'] ?? $errorMessage;
        appLogAction($pdo, $userId, $actionLogAction, 'expense', $actionLogEntityId, $actionLogLabel, 'failure', $actionLogDetails);
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));
$categoryFilter = trim((string) ($_GET['category'] ?? ''));
$where = [];
$queryParams = [];

if ($search !== '') {
    $where[] = '(description LIKE ? OR notes LIKE ?)';
    $queryParams[] = '%' . $search . '%';
    $queryParams[] = '%' . $search . '%';
}
if ($categoryFilter !== '' && in_array($categoryFilter, $categories, true)) {
    $where[] = 'category = ?';
    $queryParams[] = $categoryFilter;
}
if ($startDate !== '' && validExpenseDate($startDate)) {
    $where[] = 'expense_date >= ?';
    $queryParams[] = $startDate;
}
if ($endDate !== '' && validExpenseDate($endDate)) {
    $where[] = 'expense_date <= ?';
    $queryParams[] = $endDate;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmtTotal = $pdo->prepare("
    SELECT COUNT(*) AS expense_count, COALESCE(SUM(amount), 0) AS total_amount
    FROM expenses
    $whereSql
");
$stmtTotal->execute($queryParams);
$summary = $stmtTotal->fetch(PDO::FETCH_ASSOC);
$expenseCount = (int) ($summary['expense_count'] ?? 0);
$totalExpenses = (float) ($summary['total_amount'] ?? 0);
$stmtExpenses = $pdo->prepare("
    SELECT
        id,
        description AS title,
        notes AS details,
        category,
        amount,
        DATE_FORMAT(expense_date, '%Y-%m-%d') AS expense_date,
        expense_date AS expense_datetime,
        created_at
    FROM expenses
    $whereSql
    ORDER BY expense_date DESC, id DESC
");
$stmtExpenses->execute($queryParams);
$expenses = $stmtExpenses->fetchAll(PDO::FETCH_ASSOC);

$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses | <?= htmlspecialchars($systemName) ?></title>
    <meta name="description" content="Record and review store expenses">
    <link rel="icon" href="<?= htmlspecialchars($logo ?? '/LALENZ_SARISARI_POS/assets/images/logo.ico') ?>" type="image/x-icon">
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
        @media print {
            .no-print { display: none !important; }
            main { margin-left: 0 !important; }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="min-h-screen lg:ml-64 dark:bg-slate-950">
    <header class="flex h-20 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8 dark:border-slate-700 dark:bg-slate-900">
        <div>
            <h1 class="text-2xl font-black tracking-tight">Expenses</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Record and track your store spending.</p>
        </div>
    </header>

    <div class="space-y-6 p-4 sm:p-6 lg:p-8">
        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">
                <?= htmlspecialchars($errorMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($successMessage !== ''): ?>
            <div role="status" class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-700 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300">
                <?= htmlspecialchars($successMessage) ?>
            </div>
        <?php endif; ?>

        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Filtered Expenses</p>
                <p class="mt-2 text-3xl font-black text-red-600 dark:text-red-400">₱<?= number_format($totalExpenses, 2) ?></p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Expense Records</p>
                <p class="mt-2 text-3xl font-black text-slate-800 dark:text-white"><?= number_format($expenseCount) ?></p>
            </div>
        </section>

        <?php if ($canManageExpenses): ?>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <h2 class="text-lg font-black">Record an Expense</h2>
            <form method="POST" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <label class="text-sm font-semibold text-slate-600 dark:text-slate-300">
                    Title
                    <input name="title" required maxlength="150" value="<?= htmlspecialchars($formValues['title']) ?>" placeholder="e.g. Electricity bill" class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-slate-900 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                </label>
                <label class="text-sm font-semibold text-slate-600 dark:text-slate-300 sm:col-span-2 xl:col-span-3">
                    Details <span class="font-normal text-slate-400">(optional)</span>
                    <textarea name="details" maxlength="2000" rows="4" placeholder="Add details about this expense" class="mt-1 w-full resize-y rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-slate-900 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"><?= htmlspecialchars($formValues['details']) ?></textarea>
                </label>
                <label class="text-sm font-semibold text-slate-600 dark:text-slate-300">
                    Category
                    <select name="category" required class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-slate-900 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category) ?>" <?= $formValues['category'] === $category ? 'selected' : '' ?>><?= htmlspecialchars($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-600 dark:text-slate-300">
                    Amount (₱)
                    <input name="amount" type="number" min="0.01" max="9999999999.99" step="0.01" required value="<?= htmlspecialchars($formValues['amount']) ?>" placeholder="0.00" class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-slate-900 outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                </label>
                <div class="sm:col-span-2 xl:col-span-3">
                    <button type="submit" class="h-11 rounded-xl bg-green-600 px-5 text-sm font-bold text-white transition hover:bg-green-700">Save Expense</button>
                </div>
            </form>
        </section>
        <?php endif; ?>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <div class="border-b border-slate-200 p-5 dark:border-slate-700">
                <h2 class="text-lg font-black">Expense History</h2>
                <form method="GET" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <input name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search titles or details" class="h-10 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800">
                    <select name="category" class="h-10 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>><?= htmlspecialchars($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" aria-label="Start date" class="h-10 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800">
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" aria-label="End date" class="h-10 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-green-500 dark:border-slate-700 dark:bg-slate-800">
                    <div class="flex gap-2">
                        <button type="submit" class="h-10 rounded-xl bg-slate-900 px-4 text-sm font-bold text-white hover:bg-slate-700 dark:bg-green-700 dark:hover:bg-green-600">Filter</button>
                        <a href="expenses.php" class="flex h-10 items-center rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">Reset</a>
                    </div>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[950px] text-left">
                    <thead class="bg-slate-50 dark:bg-slate-800/70">
                        <tr class="text-xs font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            <th class="px-5 py-4">Expense ID</th>
                            <th class="px-5 py-4">Date</th>
                            <th class="px-5 py-4">Title</th>
                            <th class="px-5 py-4">Details</th>
                            <th class="px-5 py-4">Category</th>
                            <th class="px-5 py-4 text-right">Amount</th>
                            <th class="px-5 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php if (!$expenses): ?>
                            <tr><td colspan="7" class="px-5 py-14 text-center text-sm text-slate-500 dark:text-slate-400">No expenses found. Add an expense or adjust your filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $expense): ?>
                                <?php
                                $expenseDetails = (string) ($expense['details'] ?? '');
                                $detailsPreview = preg_replace('/\s+/u', ' ', $expenseDetails) ?? $expenseDetails;
                                $hasLongDetails = mb_strlen($detailsPreview) > 100;
                                ?>
                                <tr class="text-sm hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                    <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-500 dark:text-slate-400">#<?= (int) $expense['id'] ?></td>
                                    <td class="whitespace-nowrap px-5 py-4 text-slate-500 dark:text-slate-400"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($expense['expense_datetime']))) ?></td>
                                    <td class="max-w-xs px-5 py-4 font-bold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($expense['title']) ?></td>
                                    <td class="max-w-md px-5 py-4 text-slate-500 dark:text-slate-400">
                                        <?php if ($expenseDetails !== ''): ?>
                                            <span><?= htmlspecialchars($hasLongDetails ? mb_substr($detailsPreview, 0, 100) . '…' : $detailsPreview) ?></span>
                                            <button
                                                type="button"
                                                data-view-expense-details="<?= htmlspecialchars(json_encode([
                                                    'title' => $expense['title'],
                                                    'details' => $expenseDetails,
                                                    'category' => $expense['category'],
                                                    'amount' => number_format((float) $expense['amount'], 2, '.', ''),
                                                    'date' => date('M j, Y g:i A', strtotime($expense['expense_datetime'])),
                                                ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
                                                class="mt-2 block text-xs font-bold text-green-700 underline decoration-green-300 underline-offset-2 hover:text-green-800 dark:text-green-300 dark:hover:text-green-200"
                                            >View details</button>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-slate-600 dark:text-slate-300"><?= htmlspecialchars($expense['category']) ?></td>
                                    <td class="whitespace-nowrap px-5 py-4 text-right font-black text-red-600 dark:text-red-400">₱<?= number_format((float) $expense['amount'], 2) ?></td>
                                    <td class="px-5 py-4">
                                        <?php if ($canManageExpenses): ?>
                                        <div class="flex justify-end gap-2">
                                            <button type="button" data-edit-expense="<?= htmlspecialchars(json_encode($expense, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-900/50">Edit</button>
                                            <form method="POST" onsubmit="return confirm('Delete this expense? This cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="expense_id" value="<?= (int) $expense['id'] ?>">
                                                <button type="submit" class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-bold text-red-700 hover:bg-red-100 dark:bg-red-900/30 dark:text-red-300 dark:hover:bg-red-900/50">Delete</button>
                                            </form>
                                        </div>
                                        <?php else: ?>
                                            <span class="block text-right text-xs text-slate-400">View only</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>

<div id="viewExpenseDetailsModal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="viewExpenseDetailsTitle">
    <div class="w-full max-w-xl rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
        <div class="mb-5 flex items-center justify-between gap-4">
            <div>
                <h2 id="viewExpenseDetailsTitle" class="text-xl font-black">Expense Details</h2>
                <p id="viewExpenseDetailsSubtitle" class="mt-1 text-sm text-slate-500 dark:text-slate-400"></p>
            </div>
            <button type="button" id="closeViewExpenseDetails" aria-label="Close expense details" class="rounded-lg px-3 py-1 text-2xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">&times;</button>
        </div>
        <dl class="mb-4 grid grid-cols-2 gap-3 text-sm">
            <div><dt class="font-semibold text-slate-500 dark:text-slate-400">Category</dt><dd id="viewExpenseCategory" class="mt-1 font-bold"></dd></div>
            <div><dt class="font-semibold text-slate-500 dark:text-slate-400">Amount</dt><dd id="viewExpenseAmount" class="mt-1 font-bold"></dd></div>
        </dl>
        <div id="viewExpenseDetailsText" class="max-h-[55vh] overflow-y-auto whitespace-pre-wrap break-words rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm leading-relaxed dark:border-slate-700 dark:bg-slate-800"></div>
        <div class="mt-5 flex justify-end">
            <button type="button" id="dismissViewExpenseDetails" class="h-10 rounded-xl bg-green-600 px-5 text-sm font-bold text-white hover:bg-green-700">Close</button>
        </div>
    </div>
</div>

<div id="editExpenseModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="editExpenseTitle">
    <div class="w-full max-w-xl rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
        <div class="mb-5 flex items-center justify-between">
            <h2 id="editExpenseTitle" class="text-xl font-black">Edit Expense</h2>
            <button type="button" id="closeEditExpense" aria-label="Close edit form" class="rounded-lg px-3 py-1 text-2xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">&times;</button>
        </div>
        <form method="POST" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="expense_id" id="editExpenseId">
            <label class="text-sm font-semibold text-slate-600 dark:text-slate-300 sm:col-span-2">Title
                <input name="title" id="editTitle" required maxlength="150" class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800">
            </label>
            <label class="text-sm font-semibold text-slate-600 dark:text-slate-300 sm:col-span-2">Details
                <textarea name="details" id="editDetails" maxlength="2000" rows="5" class="mt-1 w-full resize-y rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-800"></textarea>
            </label>
            <label class="text-sm font-semibold text-slate-600 dark:text-slate-300">Category
                <select name="category" id="editCategory" required class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800">
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-600 dark:text-slate-300">Amount (₱)
                <input name="amount" id="editAmount" type="number" min="0.01" max="9999999999.99" step="0.01" required class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800">
            </label>
            <label class="text-sm font-semibold text-slate-600 dark:text-slate-300">Expense Date
                <input name="expense_date" id="editDate" type="date" required class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800">
            </label>
            <div class="flex justify-end gap-2 sm:col-span-2">
                <button type="button" id="cancelEditExpense" class="h-10 rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-600 dark:border-slate-700 dark:text-slate-300">Cancel</button>
                <button type="submit" class="h-10 rounded-xl bg-green-600 px-5 text-sm font-bold text-white hover:bg-green-700">Update Expense</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const detailsModal = document.getElementById('viewExpenseDetailsModal');
    const modal = document.getElementById('editExpenseModal');
    const syncPageScroll = () => {
        const modalOpen = [detailsModal, modal].some(
            activeModal => !activeModal.classList.contains('hidden')
        );
        document.body.classList.toggle('overflow-hidden', modalOpen);
    };

    const closeDetailsModal = () => {
        detailsModal.classList.add('hidden');
        detailsModal.classList.remove('flex');
        syncPageScroll();
    };

    document.querySelectorAll('[data-view-expense-details]').forEach(button => {
        button.addEventListener('click', () => {
            const expense = JSON.parse(button.dataset.viewExpenseDetails);
            document.getElementById('viewExpenseDetailsTitle').textContent = expense.title;
            document.getElementById('viewExpenseDetailsSubtitle').textContent = expense.date;
            document.getElementById('viewExpenseCategory').textContent = expense.category;
            document.getElementById('viewExpenseAmount').textContent = `₱${expense.amount}`;
            document.getElementById('viewExpenseDetailsText').textContent = expense.details;
            detailsModal.classList.remove('hidden');
            detailsModal.classList.add('flex');
            syncPageScroll();
            document.getElementById('dismissViewExpenseDetails').focus();
        });
    });

    document.getElementById('closeViewExpenseDetails').addEventListener('click', closeDetailsModal);
    document.getElementById('dismissViewExpenseDetails').addEventListener('click', closeDetailsModal);
    detailsModal.addEventListener('click', event => {
        if (event.target === detailsModal) closeDetailsModal();
    });

    const closeModal = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        syncPageScroll();
    };

    document.querySelectorAll('[data-edit-expense]').forEach(button => {
        button.addEventListener('click', () => {
            const expense = JSON.parse(button.dataset.editExpense);
            document.getElementById('editExpenseId').value = expense.id;
            document.getElementById('editTitle').value = expense.title;
            document.getElementById('editDetails').value = expense.details || '';
            document.getElementById('editCategory').value = expense.category;
            document.getElementById('editAmount').value = expense.amount;
            document.getElementById('editDate').value = expense.expense_date;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            syncPageScroll();
            document.getElementById('editTitle').focus();
        });
    });

    document.getElementById('closeEditExpense').addEventListener('click', closeModal);
    document.getElementById('cancelEditExpense').addEventListener('click', closeModal);
    modal.addEventListener('click', event => {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeDetailsModal();
            closeModal();
        }
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
