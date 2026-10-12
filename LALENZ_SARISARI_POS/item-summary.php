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
requireAppPermission($pdo, $userId, 'item_summary.view');

// User information
$username = $user['username'] ?? 'User';
$fullName = $user['full_name'] ?? $username;
$user_role = $user['role'] ?? 'Staff';

/*
|--------------------------------------------------------------------------
| Report Filtering Setup
|--------------------------------------------------------------------------
*/

$filterType = $_GET['filter'] ?? 'today';
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$dateCondition = "DATE(s.created_at) = CURDATE()";
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
| Fetch Item Sales Summary
|--------------------------------------------------------------------------
*/

$sqlItems = "
    SELECT 
        p.name AS product_name,
        SUM(si.quantity) AS total_quantity_sold,
        AVG(si.price) AS average_selling_price,
        SUM(si.quantity * si.price) AS total_item_revenue
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    JOIN products p ON si.product_id = p.id
    WHERE $dateCondition
    GROUP BY p.id, p.name
    ORDER BY total_quantity_sold DESC
";

$stmtItems = $pdo->prepare($sqlItems);
$stmtItems->execute($params);
$itemSummaries = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Calculate overall summary metrics
$totalUnitsSold = 0;
$totalProductRevenue = 0;
$uniqueProductsCount = count($itemSummaries);

foreach ($itemSummaries as $item) {
    $totalUnitsSold += (int) ($item['total_quantity_sold'] ?? 0);
    $totalProductRevenue += (float) ($item['total_item_revenue'] ?? 0);
}

?>

<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Item Sales Summary | <?= htmlspecialchars($systemName) ?></title>
<meta name="description" content="View product sales performance and movement summary">
<link rel="icon" href="<?= $logo ?>" type="image/x-icon" />
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
        .no-print { display: none !important; }
        main { margin-left: 0 !important; }
    }
</style>
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
                <h1 class="text-2xl font-black tracking-tight">Item Sales Summary</h1>
                <p class="text-sm text-slate-500 mt-1">Track product performance, units moved, and category revenue.</p>
            </div>
    </header>

    <!-- CONTENT -->
    <div class="p-6 lg:p-8">

        <!-- FILTER BAR -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-8 shadow-sm no-print">
            <form method="GET" action="item-summary.php" class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-bold text-slate-700 mr-2">Quick Filter:</span>
                    <a href="item-summary.php?filter=today" class="px-4 py-2 rounded-xl text-sm font-semibold transition <?= $filterType === 'today' ? 'bg-green-600 text-white shadow-md shadow-green-600/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Today</a>
                    <a href="item-summary.php?filter=week" class="px-4 py-2 rounded-xl text-sm font-semibold transition <?= $filterType === 'week' ? 'bg-green-600 text-white shadow-md shadow-green-600/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">This Week</a>
                    <a href="item-summary.php?filter=month" class="px-4 py-2 rounded-xl text-sm font-semibold transition <?= $filterType === 'month' ? 'bg-green-600 text-white shadow-md shadow-green-600/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">This Month</a>
                    <a href="item-summary.php?filter=all" class="px-4 py-2 rounded-xl text-sm font-semibold transition <?= $filterType === 'all' ? 'bg-green-600 text-white shadow-md shadow-green-600/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">All Time</a>
                </div>

                <!-- Custom Date Range Form -->
                <div class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="filter" value="custom">
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="h-10 px-3 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500">
                    <span class="text-slate-400 text-sm">to</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="h-10 px-3 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500">
                    <button type="submit" class="h-10 px-4 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-sm font-bold transition">Apply</button>
                    <button type="button" onclick="window.print()" class="h-10 px-4 bg-green-600 hover:bg-green-700 text-white rounded-xl text-sm font-bold flex items-center gap-2 transition shadow-md shadow-green-600/20">
                        <span>🖨️</span> Print / PDF
                    </button>
                </div>
            </form>
        </div>

        <!-- STATISTICS SUMMARY -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
                <p class="text-sm font-semibold text-slate-500">Unique Items Sold</p>
                <p class="mt-2 text-3xl font-black text-slate-800"><?= number_format($uniqueProductsCount) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
                <p class="text-sm font-semibold text-slate-500">Total Units Moved</p>
                <p class="mt-2 text-3xl font-black text-blue-600"><?= number_format($totalUnitsSold) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
                <p class="text-sm font-semibold text-slate-500">Total Item Revenue</p>
                <p class="mt-2 text-3xl font-black text-green-600">₱<?= number_format($totalProductRevenue, 2) ?></p>
            </div>
        </div>

        <!-- ITEM SUMMARY TABLE CARD -->
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm">
            <div class="p-5 border-b border-slate-200 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-black">Product Sales Breakdown</h2>
                    <p class="text-sm text-slate-500 mt-1">Ranked by highest quantity sold during the selected timeframe.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px]">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="text-left px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">Product Name</th>
                            <th class="text-center px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">Units Sold</th>
                            <th class="text-right px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">Avg. Price</th>
                            <th class="text-right px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">Total Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($itemSummaries)): ?>
                            <tr>
                                <td colspan="4" class="px-5 py-16 text-center">
                                    <div class="text-4xl mb-3">📦</div>
                                    <p class="font-bold text-slate-700">No item sales recorded for this period</p>
                                    <p class="text-sm text-slate-400 mt-1">Completed checkouts with items will appear here.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($itemSummaries as $index => $item): ?>
                                <?php 
                                $productName = htmlspecialchars($item['product_name'] ?? 'Unknown Item');
                                $quantitySold = (int) ($item['total_quantity_sold'] ?? 0);
                                $avgPrice = (float) ($item['average_selling_price'] ?? 0);
                                $itemRevenue = (float) ($item['total_item_revenue'] ?? 0);
                                ?>
                                <tr data-pagination-row class="hover:bg-slate-50/70 transition">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <span class="w-7 h-7 rounded-lg bg-slate-100 text-slate-600 text-xs font-black flex items-center justify-center">#<?= $index + 1 ?></span>
                                            <span class="font-bold text-slate-800"><?= $productName ?></span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-center font-bold text-slate-700"><?= number_format($quantitySold) ?></td>
                                    <td class="px-5 py-4 text-right text-slate-600">₱<?= number_format($avgPrice, 2) ?></td>
                                    <td class="px-5 py-4 text-right font-bold text-slate-900">₱<?= number_format($itemRevenue, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div id="itemSummaryPagination" class="hidden p-5 border-t border-slate-200 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-slate-500">Showing <span id="itemSummaryRange" class="font-semibold text-slate-700">0-0</span> of <span id="itemSummaryTotal" class="font-semibold text-slate-700">0</span> items</p>
                <div class="flex items-center justify-end gap-2">
                    <button id="itemSummaryPrevious" type="button" class="px-3 py-2 rounded-lg border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed">Previous</button>
                    <div id="itemSummaryPageNumbers" class="flex items-center gap-1"></div>
                    <button id="itemSummaryNext" type="button" class="px-3 py-2 rounded-lg border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed">Next</button>
                </div>
            </div>
        </div>

    </div>
</main>

<script>
(function () {
    const rows = Array.from(document.querySelectorAll('[data-pagination-row]'));
    const container = document.getElementById('itemSummaryPagination');
    const range = document.getElementById('itemSummaryRange');
    const total = document.getElementById('itemSummaryTotal');
    const pages = document.getElementById('itemSummaryPageNumbers');
    const previous = document.getElementById('itemSummaryPrevious');
    const next = document.getElementById('itemSummaryNext');
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