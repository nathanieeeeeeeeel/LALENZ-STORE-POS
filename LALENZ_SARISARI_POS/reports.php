<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/system.php';
require_once __DIR__ . '/config/permissions.php';

$userId = fetchUser();

/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
requireAppPermission($pdo, $userId, 'reports.view');

// User information
$username = $user['username'] ?? 'User';
$fullName = $user['full_name'] ?? $username;
$user_role = $user['role'] ?? 'Staff';

/*
|--------------------------------------------------------------------------
| Report Filtering Setup
|--------------------------------------------------------------------------
*/

$reportView = ($_GET['report_view'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
$filterType = $_GET['filter'] ?? ($reportView === 'yearly' ? 'year' : 'month');
$startDate = $_GET['start_date'] ?? ($reportView === 'yearly' ? date('Y-01-01') : date('Y-m-d'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$dateCondition = "MONTH(s.created_at) = MONTH(NOW()) AND YEAR(s.created_at) = YEAR(NOW())";
$params = [];

switch ($filterType) {
    case 'today':
        $dateCondition = "DATE(s.created_at) = CURDATE()";
        break;
    case 'week':
        $dateCondition = "YEARWEEK(s.created_at, 1) = YEARWEEK(NOW(), 1)";
        break;
    case 'month':
        $dateCondition = "MONTH(s.created_at) = MONTH(NOW()) AND YEAR(s.created_at) = YEAR(NOW())";
        break;
    case 'year':
        $dateCondition = "YEAR(s.created_at) = YEAR(NOW())";
        break;
    case 'custom':
        if (!empty($startDate) && !empty($endDate)) {
            $dateCondition = "DATE(s.created_at) BETWEEN ? AND ?";
            $params = [$startDate, $endDate];
        }
        break;
    case 'all':
        $dateCondition = "1 = 1";
        break;
}

/*
|--------------------------------------------------------------------------
| Fetch Statistics & Summary
|--------------------------------------------------------------------------
*/

$sqlStats = "
    SELECT 
        COUNT(s.id) AS total_transactions,
        COALESCE(SUM(s.total_amount), 0) AS total_revenue
    FROM sales s
    WHERE $dateCondition
";
$stmtStats = $pdo->prepare($sqlStats);
$stmtStats->execute($params);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

$totalTransactions = (int) ($stats['total_transactions'] ?? 0);
$totalRevenue = (float) ($stats['total_revenue'] ?? 0);
$averageOrderValue = $totalTransactions > 0 ? $totalRevenue / $totalTransactions : 0;

$expenseTableExists = (bool) $pdo->query("
    SELECT 1
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND TABLE_TYPE = 'BASE TABLE'
")->fetchColumn();
$totalExpenses = 0.0;
$expenseRows = [];

if ($expenseTableExists) {
    $expenseDateCondition = 'MONTH(e.expense_date) = MONTH(NOW()) AND YEAR(e.expense_date) = YEAR(NOW())';
    $expenseParams = [];

    switch ($filterType) {
        case 'today':
            $expenseDateCondition = 'e.expense_date = CURDATE()';
            break;
        case 'week':
            $expenseDateCondition = 'YEARWEEK(e.expense_date, 1) = YEARWEEK(NOW(), 1)';
            break;
        case 'month':
            $expenseDateCondition = 'MONTH(e.expense_date) = MONTH(NOW()) AND YEAR(e.expense_date) = YEAR(NOW())';
            break;
        case 'year':
            $expenseDateCondition = 'YEAR(e.expense_date) = YEAR(NOW())';
            break;
        case 'custom':
            if (!empty($startDate) && !empty($endDate)) {
                $expenseDateCondition = 'e.expense_date BETWEEN ? AND ?';
                $expenseParams = [$startDate, $endDate];
            }
            break;
        case 'all':
            $expenseDateCondition = '1 = 1';
            break;
    }

    $stmtExpenseStats = $pdo->prepare("
        SELECT COALESCE(SUM(e.amount), 0)
        FROM expenses e
        WHERE $expenseDateCondition
    ");
    $stmtExpenseStats->execute($expenseParams);
    $totalExpenses = (float) $stmtExpenseStats->fetchColumn();

    $expensePeriodFormat = $reportView === 'yearly' ? '%Y-%m' : '%Y-%m-%d';
    $expenseLabelFormat = $reportView === 'yearly' ? '%M %Y' : '%b %e, %Y';
    $stmtExpenseRows = $pdo->prepare("
        SELECT
            DATE_FORMAT(e.expense_date, '$expensePeriodFormat') AS period_key,
            DATE_FORMAT(e.expense_date, '$expenseLabelFormat') AS period_label,
            COALESCE(SUM(e.amount), 0) AS total_expenses
        FROM expenses e
        WHERE $expenseDateCondition
        GROUP BY period_key
    ");
    $stmtExpenseRows->execute($expenseParams);
    $expenseRows = $stmtExpenseRows->fetchAll(PDO::FETCH_ASSOC);
}

$netRevenue = $totalRevenue - $totalExpenses;

if ($reportView === 'yearly') {
    $sqlReport = "
        SELECT 
            DATE_FORMAT(s.created_at, '%Y-%m') AS period_key,
            DATE_FORMAT(s.created_at, '%M %Y') AS period_label,
            COUNT(s.id) AS total_transactions,
            COALESCE(SUM(s.total_amount), 0) AS total_revenue,
            COALESCE(AVG(s.total_amount), 0) AS average_revenue
        FROM sales s
        WHERE $dateCondition
        GROUP BY YEAR(s.created_at), MONTH(s.created_at)
        ORDER BY YEAR(s.created_at) DESC, MONTH(s.created_at) DESC
    ";
} else {
    $sqlReport = "
        SELECT 
            DATE(s.created_at) AS period_key,
            DATE_FORMAT(s.created_at, '%b %e, %Y') AS period_label,
            COUNT(s.id) AS total_transactions,
            COALESCE(SUM(s.total_amount), 0) AS total_revenue,
            COALESCE(AVG(s.total_amount), 0) AS average_revenue
        FROM sales s
        WHERE $dateCondition
        GROUP BY DATE(s.created_at)
        ORDER BY DATE(s.created_at) DESC
    ";
}

$stmtReport = $pdo->prepare($sqlReport);
$stmtReport->execute($params);
$reportRows = $stmtReport->fetchAll(PDO::FETCH_ASSOC);

$reportRowsByPeriod = [];
foreach ($reportRows as $reportRow) {
    $reportRow['total_expenses'] = 0.0;
    $reportRowsByPeriod[$reportRow['period_key']] = $reportRow;
}
foreach ($expenseRows as $expenseRow) {
    $periodKey = $expenseRow['period_key'];
    if (!isset($reportRowsByPeriod[$periodKey])) {
        $reportRowsByPeriod[$periodKey] = [
            'period_key' => $periodKey,
            'period_label' => $expenseRow['period_label'],
            'total_transactions' => 0,
            'total_revenue' => 0.0,
            'average_revenue' => 0.0,
            'total_expenses' => 0.0,
        ];
    }
    $reportRowsByPeriod[$periodKey]['total_expenses'] = (float) $expenseRow['total_expenses'];
}
$reportRows = array_values($reportRowsByPeriod);
usort($reportRows, static fn(array $left, array $right): int => strcmp($right['period_key'], $left['period_key']));

?>

<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales Reports | <?= htmlspecialchars($systemName) ?></title>
<meta name="description" content="Generate and view store sales reports and analytics">
<link rel="icon" href="<?= htmlspecialchars($logo) ?>" type="image/x-icon" />
<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    brand: {
                        50: "#f0fdf4", 100: "#dcfce7", 200: "#bbf7d0", 300: "#86efac",
                        400: "#4ade80", 500: "#22c55e", 600: "#16a34a", 700: "#15803d",
                        800: "#166534", 900: "#14532d"
                    }
                }
            }
        }
    };
</script>
<script>
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
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: #f8fafc; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    @media print {
        body * { visibility: hidden; }
        #reportExportArea,
        #reportExportArea * { visibility: visible; }
        #reportExportArea {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            margin: 0;
            padding: 0;
        }
        #reportExportArea tr[hidden] { display: table-row !important; }
        #reportExportArea .no-print { display: none !important; }
        main { margin-left: 0 !important; }
        @page { size: landscape; margin: 12mm; }
    }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.2/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.4/jspdf.plugin.autotable.min.js"></script>
</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

<!-- SIDEBAR -->
<?php 
$currentPage = basename($_SERVER['PHP_SELF']);
@include("./includes/sidebar.php");
?>

<!-- MAIN -->
<main class="lg:ml-64 min-h-screen dark:bg-slate-950">

    <!-- HEADER -->
    <header class="flex h-20 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8 no-print dark:border-slate-700 dark:bg-slate-900">
            <div>
                <h1 class="text-2xl font-black tracking-tight">Sales Reports</h1>
                <p class="text-sm text-slate-500 mt-1">Analyze revenue, transactions, and store performance.</p>
            </div>
    </header>

    <!-- CONTENT -->
    <div class="p-6 lg:p-8">

        <!-- FILTER BAR -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-8 shadow-sm no-print">
            <form method="GET" action="reports.php" class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-bold text-slate-700 mr-2">Quick Filter:</span>
                    <a href="reports.php?filter=today&report_view=<?= urlencode($reportView) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold transition <?= $filterType === 'today' ? 'bg-green-600 text-white shadow-md shadow-green-600/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Today</a>
                    <a href="reports.php?filter=week&report_view=<?= urlencode($reportView) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold transition <?= $filterType === 'week' ? 'bg-green-600 text-white shadow-md shadow-green-600/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">This Week</a>
                    <a href="reports.php?filter=month&report_view=<?= urlencode($reportView) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold transition <?= $filterType === 'month' ? 'bg-green-600 text-white shadow-md shadow-green-600/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">This Month</a>
                    <a href="reports.php?filter=year&report_view=<?= urlencode($reportView) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold transition <?= $filterType === 'year' ? 'bg-green-600 text-white shadow-md shadow-green-600/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">This Year</a>
                    <a href="reports.php?filter=all&report_view=<?= urlencode($reportView) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold transition <?= $filterType === 'all' ? 'bg-green-600 text-white shadow-md shadow-green-600/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">All Time</a>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <label class="text-sm font-bold text-slate-700">Report View:</label>
                    <select name="report_view" class="h-10 px-3 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500">
                        <option value="monthly" <?= $reportView === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                        <option value="yearly" <?= $reportView === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                    </select>
                    <input type="hidden" name="filter" value="custom">
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="h-10 px-3 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500">
                    <span class="text-slate-400 text-sm">to</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="h-10 px-3 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500">
                    <button type="submit" class="h-10 px-4 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-sm font-bold transition">Apply</button>
                </div>
            </form>
        </div>

        <div class="flex flex-wrap items-center gap-2 mb-6 no-print" aria-label="Export report">
            <span class="text-sm font-bold text-slate-700 mr-1">Export:</span>
            <button type="button" data-export-format="word" class="h-10 px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold transition">Word</button>
            <button type="button" data-export-format="excel" class="h-10 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-bold transition">Excel</button>
            <button type="button" data-export-format="pdf" class="h-10 px-4 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-sm font-bold transition">PDF</button>
            <button type="button" data-export-format="png" class="h-10 px-4 bg-violet-600 hover:bg-violet-700 text-white rounded-xl text-sm font-bold transition">PNG</button>
            <button type="button" data-export-format="jpg" class="h-10 px-4 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-sm font-bold transition">JPG</button>
        </div>

        <div id="reportExportArea">
        <?php
        $chartRows = array_reverse($reportRows);
        $chartLabels = array_map(
            static fn(array $report): string => (string) ($report['period_label'] ?? 'N/A'),
            $chartRows
        );
        $chartRevenue = array_map(
            static fn(array $report): float => (float) ($report['total_revenue'] ?? 0),
            $chartRows
        );
        $chartTransactions = array_map(
            static fn(array $report): int => (int) ($report['total_transactions'] ?? 0),
            $chartRows
        );
        $chartExpenses = array_map(
            static fn(array $report): float => (float) ($report['total_expenses'] ?? 0),
            $chartRows
        );
        $chartNetRevenue = array_map(
            static fn(array $report): float => (float) ($report['total_revenue'] ?? 0) - (float) ($report['total_expenses'] ?? 0),
            $chartRows
        );
        $chartDailyData = [];
        $chartHourlyData = [];
        $chartToday = date('Y-m-d');

        if ($reportView === 'monthly') {
            $dailyBuckets = [];
            $stmtChartDailySales = $pdo->query("
                SELECT
                    DATE(s.created_at) AS period_key,
                    COUNT(s.id) AS total_transactions,
                    COALESCE(SUM(s.total_amount), 0) AS total_revenue
                FROM sales s
                WHERE s.created_at >= DATE_SUB(CURDATE(), INTERVAL 59 DAY)
                    AND s.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                GROUP BY DATE(s.created_at)
            ");
            foreach ($stmtChartDailySales->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dailyBuckets[$row['period_key']] = [
                    'period_key' => $row['period_key'],
                    'total_transactions' => (int) $row['total_transactions'],
                    'total_revenue' => (float) $row['total_revenue'],
                    'total_expenses' => 0.0,
                ];
            }

            if ($expenseTableExists) {
                $stmtChartDailyExpenses = $pdo->query("
                    SELECT
                        DATE(e.expense_date) AS period_key,
                        COALESCE(SUM(e.amount), 0) AS total_expenses
                    FROM expenses e
                    WHERE e.expense_date >= DATE_SUB(CURDATE(), INTERVAL 59 DAY)
                        AND e.expense_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                    GROUP BY DATE(e.expense_date)
                ");
                foreach ($stmtChartDailyExpenses->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!isset($dailyBuckets[$row['period_key']])) {
                        $dailyBuckets[$row['period_key']] = [
                            'period_key' => $row['period_key'],
                            'total_transactions' => 0,
                            'total_revenue' => 0.0,
                            'total_expenses' => 0.0,
                        ];
                    }
                    $dailyBuckets[$row['period_key']]['total_expenses'] = (float) $row['total_expenses'];
                }
            }

            for ($daysAgo = 59; $daysAgo >= 0; $daysAgo--) {
                $day = date('Y-m-d', strtotime("-{$daysAgo} days"));
                $bucket = $dailyBuckets[$day] ?? [
                    'period_key' => $day,
                    'total_transactions' => 0,
                    'total_revenue' => 0.0,
                    'total_expenses' => 0.0,
                ];
                $bucket['period_label'] = date('M j', strtotime($day));
                $chartDailyData[] = $bucket;
            }

            $hourlyBuckets = [];
            $stmtChartHourlySales = $pdo->query("
                SELECT
                    DATE_FORMAT(s.created_at, '%Y-%m-%d %H:00:00') AS period_key,
                    COUNT(s.id) AS total_transactions,
                    COALESCE(SUM(s.total_amount), 0) AS total_revenue
                FROM sales s
                WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    AND s.created_at <= NOW()
                GROUP BY DATE_FORMAT(s.created_at, '%Y-%m-%d %H:00:00')
            ");
            foreach ($stmtChartHourlySales->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $hourlyBuckets[$row['period_key']] = [
                    'period_key' => $row['period_key'],
                    'total_transactions' => (int) $row['total_transactions'],
                    'total_revenue' => (float) $row['total_revenue'],
                    'total_expenses' => 0.0,
                ];
            }

            if ($expenseTableExists) {
                $stmtChartHourlyExpenses = $pdo->query("
                    SELECT
                        DATE_FORMAT(e.expense_date, '%Y-%m-%d %H:00:00') AS period_key,
                        COALESCE(SUM(e.amount), 0) AS total_expenses
                    FROM expenses e
                    WHERE e.expense_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        AND e.expense_date <= NOW()
                    GROUP BY DATE_FORMAT(e.expense_date, '%Y-%m-%d %H:00:00')
                ");
                foreach ($stmtChartHourlyExpenses->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!isset($hourlyBuckets[$row['period_key']])) {
                        $hourlyBuckets[$row['period_key']] = [
                            'period_key' => $row['period_key'],
                            'total_transactions' => 0,
                            'total_revenue' => 0.0,
                            'total_expenses' => 0.0,
                        ];
                    }
                    $hourlyBuckets[$row['period_key']]['total_expenses'] = (float) $row['total_expenses'];
                }
            }

            for ($hoursAgo = 23; $hoursAgo >= 0; $hoursAgo--) {
                $hour = date('Y-m-d H:00:00', strtotime("-{$hoursAgo} hours"));
                $bucket = $hourlyBuckets[$hour] ?? [
                    'period_key' => $hour,
                    'total_transactions' => 0,
                    'total_revenue' => 0.0,
                    'total_expenses' => 0.0,
                ];
                $bucket['period_label'] = date('M j, g A', strtotime($hour));
                $chartHourlyData[] = $bucket;
            }
        }
        ?>
        <!-- STATISTICS SUMMARY -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-6 mb-8">
            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
                <p class="text-sm font-semibold text-slate-500">Total Transactions</p>
                <p class="mt-2 text-3xl font-black text-slate-800"><?= number_format($totalTransactions) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
                <p class="text-sm font-semibold text-slate-500">Average Order Value</p>
                <p class="mt-2 text-3xl font-black text-blue-600">₱<?= number_format($averageOrderValue, 2) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
                <p class="text-sm font-semibold text-slate-500">Total Revenue</p>
                <p class="mt-2 text-3xl font-black text-green-600">₱<?= number_format($totalRevenue, 2) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
                <p class="text-sm font-semibold text-slate-500">Total Expenses</p>
                <p class="mt-2 text-3xl font-black text-red-600">₱<?= number_format($totalExpenses, 2) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
                <p class="text-sm font-semibold text-slate-500">Net Profit/Loss</p>
                <p class="mt-2 text-3xl font-black <?= $netRevenue >= 0 ? 'text-emerald-700' : 'text-red-700' ?>">₱<?= number_format($netRevenue, 2) ?></p>
            </div>
        </div>

        <!-- SALES AND EXPENSE CHARTS -->
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">
            <section class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm dark:bg-slate-900 dark:border-slate-700">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-black text-slate-800 dark:text-white">Revenue, Expenses &amp; Net Profit/Loss</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Amounts by <?= $reportView === 'yearly' ? 'month for the selected filter' : 'day or hour for the selected chart range' ?>.</p>
                    </div>
                    <?php if ($reportView === 'monthly'): ?>
                        <select data-chart-range="revenueChart" aria-label="Revenue chart date range" class="no-print h-9 rounded-lg border border-slate-200 bg-white px-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                            <option value="24h">Last 24 hours</option>
                            <option value="7d">Last 7 days</option>
                            <option value="15d">Last 15 days</option>
                            <option value="30d" selected>Last 30 days</option>
                            <option value="60d">Last 60 days</option>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="relative mt-4 h-72">
                    <canvas id="revenueChart" role="img" aria-label="Revenue, expenses, and net profit or loss by report period"></canvas>
                </div>
            </section>
            <section class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm dark:bg-slate-900 dark:border-slate-700">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-black text-slate-800 dark:text-white">Transaction Trend</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Number of transactions by <?= $reportView === 'yearly' ? 'month for the selected filter' : 'day or hour for the selected chart range' ?>.</p>
                    </div>
                    <?php if ($reportView === 'monthly'): ?>
                        <select data-chart-range="transactionsChart" aria-label="Transaction chart date range" class="no-print h-9 rounded-lg border border-slate-200 bg-white px-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                            <option value="24h">Last 24 hours</option>
                            <option value="7d">Last 7 days</option>
                            <option value="15d">Last 15 days</option>
                            <option value="30d" selected>Last 30 days</option>
                            <option value="60d">Last 60 days</option>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="relative mt-4 h-72">
                    <canvas id="transactionsChart" role="img" aria-label="Transactions by report period"></canvas>
                </div>
            </section>
        </div>

        <!-- REPORT TABLE CARD -->
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm">
            <div class="p-5 border-b border-slate-200 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-black"><?= $reportView === 'yearly' ? 'Yearly Sales &amp; Expense Report by Month' : 'Monthly Sales &amp; Expense Report by Day' ?></h2>
                    <p class="text-sm text-slate-500 mt-1">Showing transactions, revenue, expenses, net profit or loss, and average sale for each <?= $reportView === 'yearly' ? 'month' : 'day' ?>.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1050px]">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="text-left px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">Period</th>
                            <th class="text-center px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">Transactions</th>
                            <th class="text-right px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">Total Revenue</th>
                            <th class="text-right px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">Expenses</th>
                            <th class="text-right px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">Net Profit/Loss</th>
                            <th class="text-right px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">Average Sale</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($reportRows)): ?>
                            <tr>
                                <td colspan="6" class="px-5 py-16 text-center">
                                    <div class="text-4xl mb-3">📊</div>
                                    <p class="font-bold text-slate-700">No sales reports found for this period</p>
                                    <p class="text-sm text-slate-400 mt-1">Try changing your date filter range or report view.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reportRows as $report): ?>
                                <?php
                                $periodLabel = htmlspecialchars($report['period_label'] ?? 'N/A');
                                $transactionCount = (int) ($report['total_transactions'] ?? 0);
                                $reportRevenue = (float) ($report['total_revenue'] ?? 0);
                                $reportExpenses = (float) ($report['total_expenses'] ?? 0);
                                $reportNet = $reportRevenue - $reportExpenses;
                                $averageRevenue = (float) ($report['average_revenue'] ?? 0);
                                ?>
                                <tr data-pagination-row class="hover:bg-slate-50/70 transition">
                                    <td class="px-5 py-4 font-bold text-slate-800"><?= $periodLabel ?></td>
                                    <td class="px-5 py-4 text-center text-sm font-semibold text-slate-700"><?= number_format($transactionCount) ?></td>
                                    <td class="px-5 py-4 text-right font-bold text-green-600">₱<?= number_format($reportRevenue, 2) ?></td>
                                    <td class="px-5 py-4 text-right font-bold text-red-600">₱<?= number_format($reportExpenses, 2) ?></td>
                                    <td class="px-5 py-4 text-right font-bold <?= $reportNet >= 0 ? 'text-emerald-700' : 'text-red-700' ?>">₱<?= number_format($reportNet, 2) ?></td>
                                    <td class="px-5 py-4 text-right font-bold text-slate-900">₱<?= number_format($averageRevenue, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div id="reportsPagination" class="hidden p-5 border-t border-slate-200 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-slate-500">Showing <span id="reportsRange" class="font-semibold text-slate-700">0-0</span> of <span id="reportsTotal" class="font-semibold text-slate-700">0</span> report periods</p>
                <div class="flex items-center justify-end gap-2">
                    <button id="reportsPrevious" type="button" class="px-3 py-2 rounded-lg border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed">Previous</button>
                    <div id="reportsPageNumbers" class="flex items-center gap-1"></div>
                    <button id="reportsNext" type="button" class="px-3 py-2 rounded-lg border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed">Next</button>
                </div>
            </div>
        </div>

        </div>
    </div>
</main>

<script>
(function () {
    const report = document.getElementById('reportExportArea');
    const reportName = <?= json_encode(($reportView === 'yearly' ? 'yearly' : 'monthly') . '-sales-report-' . date('Y-m-d')) ?>;
    const reportTitle = <?= json_encode($reportView === 'yearly' ? 'Yearly Sales & Expense Report' : 'Monthly Sales & Expense Report') ?>;
    const reportFilter = <?= json_encode($filterType === 'custom' ? $startDate . ' to ' . $endDate : ucfirst($filterType)) ?>;
    const reportSummary = <?= json_encode([
        'transactions' => $totalTransactions,
        'averageOrderValue' => $averageOrderValue,
        'revenue' => $totalRevenue,
        'expenses' => $totalExpenses,
        'netRevenue' => $netRevenue,
    ]) ?>;
    const reportData = <?= json_encode(array_map(
        static fn(array $row): array => [
            'period' => (string) ($row['period_label'] ?? 'N/A'),
            'transactions' => (int) ($row['total_transactions'] ?? 0),
            'revenue' => (float) ($row['total_revenue'] ?? 0),
            'expenses' => (float) ($row['total_expenses'] ?? 0),
            'netRevenue' => (float) ($row['total_revenue'] ?? 0) - (float) ($row['total_expenses'] ?? 0),
            'averageSale' => (float) ($row['average_revenue'] ?? 0),
        ],
        $reportRows
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let reportLabels = <?= json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let revenueData = <?= json_encode($chartRevenue) ?>;
    let expenseData = <?= json_encode($chartExpenses) ?>;
    let netRevenueData = <?= json_encode($chartNetRevenue) ?>;
    let transactionData = <?= json_encode($chartTransactions) ?>;
    const isMonthlyReport = <?= json_encode($reportView === 'monthly') ?>;
    const monthlyChartData = {
        daily: <?= json_encode($chartDailyData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        hourly: <?= json_encode($chartHourlyData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };

    function getMonthlyChartSeries(range) {
        const source = range === '24h'
            ? monthlyChartData.hourly
            : monthlyChartData.daily.slice(-Number.parseInt(range, 10));

        return {
            labels: source.map(row => row.period_label),
            revenue: source.map(row => Number(row.total_revenue)),
            expenses: source.map(row => Number(row.total_expenses)),
            netRevenue: source.map(row => Number(row.total_revenue) - Number(row.total_expenses)),
            transactions: source.map(row => Number(row.total_transactions))
        };
    }

    if (isMonthlyReport) {
        const initialChartSeries = getMonthlyChartSeries('30d');
        reportLabels = initialChartSeries.labels;
        revenueData = initialChartSeries.revenue;
        expenseData = initialChartSeries.expenses;
        netRevenueData = initialChartSeries.netRevenue;
        transactionData = initialChartSeries.transactions;
    }

    let chartTheme = document.documentElement.classList.contains('dark');
    let reportCharts = [];
    const currencyFormatter = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    });

    function updateChartTheme() {
        const isDarkMode = document.documentElement.classList.contains('dark');
        if (isDarkMode === chartTheme) {
            return;
        }

        chartTheme = isDarkMode;
        const chartTextColor = isDarkMode ? '#cbd5e1' : '#475569';
        const chartGridColor = isDarkMode ? '#334155' : '#e2e8f0';

        reportCharts.forEach(chart => {
            ['x', 'y'].forEach(axis => {
                chart.options.scales[axis].ticks.color = chartTextColor;
                chart.options.scales[axis].grid.color = chartGridColor;
            });
            if (chart.options.plugins.legend.display) {
                chart.options.plugins.legend.labels.color = chartTextColor;
            }
            chart.update('none');
        });
    }

    if (typeof window.Chart === 'function') {
        const chartTextColor = chartTheme ? '#cbd5e1' : '#475569';
        const chartGridColor = chartTheme ? '#334155' : '#e2e8f0';
        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: context => `${context.dataset.label}: ${
                            context.dataset.label === 'Transactions'
                                ? new Intl.NumberFormat().format(context.parsed.y)
                                : currencyFormatter.format(context.parsed.y)
                        }`
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: chartTextColor },
                    grid: { color: chartGridColor }
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: chartTextColor },
                    grid: { color: chartGridColor }
                }
            }
        };

        reportCharts.push(new Chart(document.getElementById('revenueChart'), {
            type: 'line',
            data: {
                labels: reportLabels,
                datasets: [
                    {
                        label: 'Revenue',
                        data: revenueData,
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(34, 197, 94, 0.12)',
                        pointBackgroundColor: '#16a34a',
                        pointRadius: 3,
                        fill: false,
                        tension: 0.3
                    },
                    {
                        label: 'Expenses',
                        data: expenseData,
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220, 38, 38, 0.12)',
                        pointBackgroundColor: '#dc2626',
                        pointRadius: 3,
                        fill: false,
                        tension: 0.3
                    },
                    {
                        label: 'Net Profit/Loss',
                        data: netRevenueData,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.12)',
                        pointBackgroundColor: '#2563eb',
                        pointRadius: 3,
                        fill: false,
                        tension: 0.3
                    }
                ]
            },
            options: {
                ...chartOptions,
                plugins: {
                    ...chartOptions.plugins,
                    legend: {
                        display: true,
                        labels: { color: chartTextColor }
                    }
                },
                scales: {
                    ...chartOptions.scales,
                    y: {
                        ...chartOptions.scales.y,
                        ticks: {
                            ...chartOptions.scales.y.ticks,
                            callback: value => currencyFormatter.format(value)
                        }
                    }
                }
            }
        }));

        reportCharts.push(new Chart(document.getElementById('transactionsChart'), {
            type: 'bar',
            data: {
                labels: reportLabels,
                datasets: [{
                    label: 'Transactions',
                    data: transactionData,
                    backgroundColor: 'rgba(59, 130, 246, 0.75)',
                    borderColor: '#2563eb',
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: chartOptions
        }));

        document.querySelectorAll('[data-chart-range]').forEach(select => {
            select.addEventListener('change', () => {
                const series = getMonthlyChartSeries(select.value);
                const chart = Chart.getChart(select.dataset.chartRange);
                if (!chart) {
                    return;
                }

                chart.data.labels = series.labels;
                if (select.dataset.chartRange === 'revenueChart') {
                    chart.data.datasets[0].data = series.revenue;
                    chart.data.datasets[1].data = series.expenses;
                    chart.data.datasets[2].data = series.netRevenue;
                } else {
                    chart.data.datasets[0].data = series.transactions;
                }
                chart.update();
            });
        });

        const themeObserver = new MutationObserver(updateChartTheme);
        themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class']
        });
    }

    function makeExportCopy() {
        const copy = report.cloneNode(true);
        copy.querySelectorAll('[data-pagination-row]').forEach(row => row.removeAttribute('hidden'));
        copy.querySelectorAll('#reportsPagination').forEach(pagination => pagination.remove());
        copy.querySelectorAll('[data-chart-range]').forEach(select => select.remove());
        const sourceCanvases = report.querySelectorAll('canvas');
        const copiedCanvases = copy.querySelectorAll('canvas');
        sourceCanvases.forEach((canvas, index) => {
            const image = document.createElement('img');
            image.src = canvas.toDataURL('image/png');
            image.alt = canvas.getAttribute('aria-label') || 'Sales chart';
            image.style.width = '100%';
            image.style.height = '288px';
            copiedCanvases[index].replaceWith(image);
        });
        [copy, ...copy.querySelectorAll('*')].forEach(element => {
            Array.from(element.classList).forEach(className => {
                if (className.startsWith('dark:')) {
                    element.classList.remove(className);
                }
            });
        });
        return copy;
    }

    function download(content, mimeType, extension) {
        const blob = new Blob(['\ufeff', content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${reportName}.${extension}`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    function exportDocument(format) {
        const copy = makeExportCopy();
        const html = `<!DOCTYPE html><html class="select-none"><head><meta charset="UTF-8"><style>
            body{font-family:Arial,sans-serif;color:#0f172a}h2{margin-bottom:4px}
            .grid{display:flex;gap:12px}.grid>div{flex:1;border:1px solid #cbd5e1;border-radius:8px;padding:12px;margin-bottom:12px}
            .grid p{margin:4px 0}
            p{color:#475569}table{width:100%;border-collapse:collapse;margin-top:16px}
            th,td{border:1px solid #cbd5e1;padding:10px;text-align:left}
            th{background:#f1f5f9}td:nth-child(n+2),th:nth-child(n+2){text-align:right}
        </style></head><body>${copy.innerHTML}</body></html>`;

        if (format === 'word') {
            download(html, 'application/msword;charset=utf-8', 'doc');
        } else {
            download(html, 'application/vnd.ms-excel;charset=utf-8', 'xls');
        }
    }

    function exportExcel() {
        if (typeof window.XLSX === 'undefined') {
            window.alert('Excel export is unavailable. Please check your internet connection and try again.');
            return;
        }

        try {
            const worksheetData = [
                [reportTitle],
                ['Report Period', reportFilter],
                [],
                ['Summary'],
                ['Total Transactions', reportSummary.transactions, 'Average Order Value', reportSummary.averageOrderValue],
                ['Total Revenue', reportSummary.revenue, 'Total Expenses', reportSummary.expenses, 'Net Profit/Loss', reportSummary.netRevenue],
                [],
                ['Period', 'Transactions', 'Total Revenue', 'Expenses', 'Net Profit/Loss', 'Average Sale'],
                ...reportData.map(row => [
                    row.period,
                    row.transactions,
                    row.revenue,
                    row.expenses,
                    row.netRevenue,
                    row.averageSale
                ])
            ];
            const worksheet = window.XLSX.utils.aoa_to_sheet(worksheetData);
            const lastRow = worksheetData.length;
            worksheet['!cols'] = [
                { wch: 24 },
                { wch: 16 },
                { wch: 20 },
                { wch: 18 },
                { wch: 20 },
                { wch: 18 }
            ];
            worksheet['!views'] = [{ state: 'frozen', xSplit: 0, ySplit: 8, topLeftCell: 'A9' }];
            if (reportData.length > 0) {
                worksheet['!autofilter'] = { ref: `A8:F${lastRow}` };
            }
            const pesoNumberFormat = '"₱"#,##0.00';
            ['D5', 'B6', 'D6', 'F6'].forEach(address => {
                if (worksheet[address] && typeof worksheet[address].v === 'number') {
                    worksheet[address].z = pesoNumberFormat;
                }
            });
            reportData.forEach((row, index) => {
                ['C', 'D', 'E', 'F'].forEach(column => {
                    const cell = worksheet[`${column}${index + 9}`];
                    if (cell && typeof cell.v === 'number') {
                        cell.z = pesoNumberFormat;
                    }
                });
            });
            const workbook = window.XLSX.utils.book_new();
            window.XLSX.utils.book_append_sheet(workbook, worksheet, 'Sales Report');
            workbook.Props = { Title: reportTitle, Subject: `Report period: ${reportFilter}` };
            window.XLSX.writeFile(workbook, `${reportName}.xlsx`);
        } catch (error) {
            console.error('Unable to export the Excel report.', error);
            window.alert('Unable to export the Excel report. Please try again.');
        }
    }

    function exportPdf() {
        const pdfLibrary = window.jspdf;
        if (!pdfLibrary || typeof pdfLibrary.jsPDF !== 'function') {
            window.alert('PDF export is unavailable. Please check your internet connection and try again.');
            return;
        }

        try {
            const pdfDocument = new pdfLibrary.jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
            if (typeof pdfDocument.autoTable !== 'function') {
                throw new Error('The PDF table plugin did not load.');
            }
            const formatPdfCurrency = amount => `PHP ${amount.toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            })}`;
            pdfDocument.setFontSize(18);
            pdfDocument.text(reportTitle, 40, 40);
            pdfDocument.setFontSize(10);
            pdfDocument.text(`Report period: ${reportFilter}`, 40, 58);
            pdfDocument.autoTable({
                startY: 75,
                head: [['Total Transactions', 'Average Order Value', 'Total Revenue', 'Total Expenses', 'Net Profit/Loss']],
                body: [[
                    reportSummary.transactions.toLocaleString(),
                    formatPdfCurrency(reportSummary.averageOrderValue),
                    formatPdfCurrency(reportSummary.revenue),
                    formatPdfCurrency(reportSummary.expenses),
                    formatPdfCurrency(reportSummary.netRevenue)
                ]],
                styles: { fontSize: 9 },
                headStyles: { fillColor: [22, 101, 52] }
            });
            pdfDocument.autoTable({
                startY: pdfDocument.lastAutoTable.finalY + 20,
                head: [['Period', 'Transactions', 'Total Revenue', 'Expenses', 'Net Profit/Loss', 'Average Sale']],
                body: reportData.map(row => [
                    row.period,
                    row.transactions.toLocaleString(),
                    formatPdfCurrency(row.revenue),
                    formatPdfCurrency(row.expenses),
                    formatPdfCurrency(row.netRevenue),
                    formatPdfCurrency(row.averageSale)
                ]),
                styles: { fontSize: 9 },
                headStyles: { fillColor: [22, 101, 52] },
                didDrawPage: () => {
                    pdfDocument.setFontSize(8);
                    pdfDocument.text(reportTitle, 40, 25);
                }
            });
            pdfDocument.save(`${reportName}.pdf`);
        } catch (error) {
            console.error('Unable to export the PDF report.', error);
            window.alert('Unable to export the PDF report. Please try again.');
        }
    }

    async function exportImage(format) {
        if (typeof window.html2canvas !== 'function') {
            window.alert('Image export is unavailable. Please check your internet connection and try again.');
            return;
        }

        const copy = makeExportCopy();
        copy.style.width = `${report.offsetWidth}px`;
        copy.style.position = 'fixed';
        copy.style.left = '-10000px';
        copy.style.top = '0';
        copy.style.backgroundColor = '#ffffff';
        copy.style.color = '#0f172a';
        document.body.appendChild(copy);

        try {
            const canvas = await window.html2canvas(copy, {
                backgroundColor: '#ffffff',
                scale: 2,
                windowWidth: copy.scrollWidth
            });
            const mimeType = format === 'jpg' ? 'image/jpeg' : 'image/png';
            const dataUrl = canvas.toDataURL(mimeType, 0.95);
            const link = document.createElement('a');
            link.href = dataUrl;
            link.download = `${reportName}.${format}`;
            document.body.appendChild(link);
            link.click();
            link.remove();
        } catch (error) {
            console.error('Unable to export the report image.', error);
            window.alert('Unable to export the report image. Please try again.');
        } finally {
            copy.remove();
        }
    }

    document.querySelectorAll('[data-export-format]').forEach(button => {
        button.addEventListener('click', () => {
            const format = button.dataset.exportFormat;
            if (format === 'pdf') {
                exportPdf();
            } else if (format === 'excel') {
                exportExcel();
            } else if (format === 'png' || format === 'jpg') {
                exportImage(format);
            } else {
                exportDocument(format);
            }
        });
    });
})();

(function () {
    const rows = Array.from(document.querySelectorAll('[data-pagination-row]'));
    const container = document.getElementById('reportsPagination');
    const range = document.getElementById('reportsRange');
    const total = document.getElementById('reportsTotal');
    const pages = document.getElementById('reportsPageNumbers');
    const previous = document.getElementById('reportsPrevious');
    const next = document.getElementById('reportsNext');
    const pageSize = 10;
    let currentPage = 1;

    function render() {
        const pageCount = Math.ceil(rows.length / pageSize);
        currentPage = Math.min(currentPage, Math.max(pageCount, 1));
        const start = (currentPage - 1) * pageSize;
        rows.forEach((row, index) => {
            row.hidden = index < start || index >= start + pageSize;
        });
        range.textContent = rows.length ? `${start + 1}-${Math.min(start + pageSize, rows.length)}` : '0-0';
        total.textContent = rows.length;
        container.classList.toggle('hidden', pageCount <= 1);
        container.classList.toggle('flex', pageCount > 1);
        previous.disabled = currentPage === 1;
        next.disabled = currentPage === pageCount;
        pages.replaceChildren();

        const maxVisiblePages = 5;
        let firstPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
        const lastPage = Math.min(pageCount, firstPage + maxVisiblePages - 1);
        firstPage = Math.max(1, lastPage - maxVisiblePages + 1);

        for (let page = firstPage; page <= lastPage; page++) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = page;
            button.setAttribute('aria-label', `Go to page ${page}`);
            button.setAttribute('aria-current', page === currentPage ? 'page' : 'false');
            button.className = page === currentPage
                ? 'w-9 h-9 rounded-lg border border-green-600 bg-green-600 text-sm font-bold text-white'
                : 'w-9 h-9 rounded-lg border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800';
            button.addEventListener('click', () => { currentPage = page; render(); });
            pages.appendChild(button);
        }
    }

    previous.addEventListener('click', () => { currentPage--; render(); });
    next.addEventListener('click', () => { currentPage++; render(); });
    render();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>