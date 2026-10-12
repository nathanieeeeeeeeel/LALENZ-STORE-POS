<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/system.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/sale_metadata.php';

ensureSaleMetadataColumns($pdo);

$userId = fetchUser();

/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
");

$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_unset();
    session_destroy();

    header('Location: login.php');
    exit;
}
requireAppPermission($pdo, $userId, 'sales.view');

// User information
$username = $user['username'] ?? 'User';
$fullName = $user['full_name'] ?? $username;
$user_role = $user['role'] ?? 'Staff';

/*
|--------------------------------------------------------------------------
| Sales Statistics
|--------------------------------------------------------------------------
*/

$stmtSales = $pdo->query("
    SELECT 
        s.*,
        u.full_name AS cashier_name
    FROM sales s
    LEFT JOIN users u 
        ON s.user_id = u.id
    ORDER BY s.created_at DESC
");

$sales = $stmtSales->fetchAll(PDO::FETCH_ASSOC);

$totalTransactions = count($sales);
$totalRevenue = 0;

foreach ($sales as $sale) {

    $amount = (float) ($sale['total_amount'] ?? 0);

    $totalRevenue += $amount;
}

$todayRevenueStmt = $pdo->query("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM sales
    WHERE DATE(created_at) = CURDATE()
");
$todayRevenue = (float) $todayRevenueStmt->fetchColumn();

$currentPage = basename($_SERVER['PHP_SELF']);

?>

<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Sales History | <?= htmlspecialchars($systemName) ?></title>

<meta
    name="description"
    content="View sales transactions and revenue history"
>

<link
    rel="icon"
    href="<?= $logo ?>"
    type="image/x-icon"
/>

<script src="https://cdn.tailwindcss.com"></script>

<script>

tailwind.config = {
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
                brand: {
                    50: "#f0fdf4",
                    100: "#dcfce7",
                    200: "#bbf7d0",
                    300: "#86efac",
                    400: "#4ade80",
                    500: "#22c55e",
                    600: "#16a34a",
                    700: "#15803d",
                    800: "#166534",
                    900: "#14532d"
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

body {
    font-family:
        Inter,
        ui-sans-serif,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: #f8fafc;
}

::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 999px;
}

::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Modal animation */

.modal-backdrop {
    opacity: 0;
    visibility: hidden;
    transition:
        opacity 0.2s ease,
        visibility 0.2s ease;
}

.modal-backdrop.show {
    opacity: 1;
    visibility: visible;
}

.modal-panel {
    transform: translateY(20px) scale(0.98);
    transition: transform 0.2s ease;
}

.modal-backdrop.show .modal-panel {
    transform: translateY(0) scale(1);
}

/* Prevent background scrolling */

body.modal-open {
    overflow: hidden;
}

</style>

</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

<!-- ========================================================= -->
<!-- SIDEBAR -->
<!-- ========================================================= -->

<?php

if (file_exists(__DIR__ . '/includes/sidebar.php')) {
    include __DIR__ . '/includes/sidebar.php';
}

?>

<!-- ========================================================= -->
<!-- MAIN -->
<!-- ========================================================= -->

<main class="lg:ml-64 min-h-screen dark:bg-slate-950">

    <!-- ===================================================== -->
    <!-- HEADER -->
    <!-- ===================================================== -->

    <header class="flex h-20 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8 dark:border-slate-700 dark:bg-slate-900">
            <div>
                <h1 class="text-2xl font-black tracking-tight">
                    Sales History
                </h1>

                <p class="text-sm text-slate-500 mt-1">
                    Monitor store transactions and revenue performance.
                </p>

            </div>

    </header>


    <!-- ===================================================== -->
    <!-- CONTENT -->
    <!-- ===================================================== -->

    <div class="p-6 lg:p-8">

        <!-- ================================================= -->
        <!-- STATISTICS -->
        <!-- ================================================= -->

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 mb-8">

            <!-- Total Transactions -->

            <div class="bg-white rounded-2xl border border-slate-200 p-5">

                <p class="text-sm font-semibold text-slate-500">
                    Total Transactions
                </p>

                <p class="mt-2 text-3xl font-black">
                    <?= number_format($totalTransactions) ?>
                </p>

            </div>


            <!-- Today's Revenue -->

            <div class="bg-white rounded-2xl border border-slate-200 p-5">

                <p class="text-sm font-semibold text-slate-500">
                    Today's Revenue
                </p>

                <p class="mt-2 text-3xl font-black text-green-600">
                    ₱<?= number_format($todayRevenue, 2) ?>
                </p>

            </div>


            <!-- Total Revenue -->

            <div class="bg-white rounded-2xl border border-slate-200 p-5 sm:col-span-2 xl:col-span-1">

                <p class="text-sm font-semibold text-slate-500">
                    Total Revenue
                </p>

                <p class="mt-2 text-3xl font-black text-brand-700">
                    ₱<?= number_format($totalRevenue, 2) ?>
                </p>

            </div>

        </div>


        <!-- ================================================= -->
        <!-- SALES CARD -->
        <!-- ================================================= -->

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">

            <!-- ================================================= -->
            <!-- TOOLBAR -->
            <!-- ================================================= -->

            <div class="p-5 border-b border-slate-200 flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4">

                <div>

                    <h2 class="text-lg font-black">
                        Transaction Log
                    </h2>

                    <p
                        class="text-sm text-slate-500 mt-1"
                        id="salesCountText"
                    >
                        Showing sales records
                    </p>

                </div>


                <div class="flex flex-wrap items-center gap-3">

                    <!-- NEW TRANSACTION -->

                    <a
                        href="pos.php"
                        class="h-11 px-5 bg-green-600 hover:bg-green-700 text-white rounded-xl text-sm font-bold flex items-center gap-2 shadow-lg shadow-green-600/20 transition"
                    >
                        <span>+</span>
                        New Transaction
                    </a>


                    <!-- ITEMS PER PAGE -->

                    <div class="flex items-center gap-2">

                        <label
                            for="perPageSelect"
                            class="text-sm text-slate-500 font-medium"
                        >
                            Show:
                        </label>

                        <select
                            id="perPageSelect"
                            class="h-11 px-3 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10"
                        >

                            <option value="5">
                                5
                            </option>

                            <option value="10" selected>
                                10
                            </option>

                            <option value="25">
                                25
                            </option>

                            <option value="50">
                                50
                            </option>

                            <option value="100">
                                100
                            </option>

                        </select>

                    </div>


                    <!-- SEARCH -->

                    <div class="relative">

                        <input
                            type="search"
                            id="salesSearch"
                            placeholder="Search receipt / cashier..."
                            class="w-full sm:w-64 h-11 pl-10 pr-4 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10"
                        >

                        <span
                            class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"
                        >
                            🔎
                        </span>

                    </div>

                    <!-- DATE FILTER -->

                    <div class="flex flex-wrap items-center gap-2">

                        <label
                            for="salesStartDate"
                            class="text-sm text-slate-500 font-medium"
                        >
                            From:
                        </label>

                        <input
                            type="date"
                            id="salesStartDate"
                            aria-label="Filter sales from date"
                            class="h-11 px-3 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10"
                        >

                        <label
                            for="salesEndDate"
                            class="text-sm text-slate-500 font-medium"
                        >
                            To:
                        </label>

                        <input
                            type="date"
                            id="salesEndDate"
                            aria-label="Filter sales to date"
                            class="h-11 px-3 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10"
                        >

                        <button
                            type="button"
                            id="clearSalesDateFilter"
                            class="h-11 px-3 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition"
                        >
                            Clear dates
                        </button>

                    </div>

                </div>

            </div>


            <!-- ================================================= -->
            <!-- TABLE -->
            <!-- ================================================= -->

            <div class="overflow-x-auto">

                <table class="w-full min-w-[900px]">

                    <thead class="bg-slate-50 dark:bg-slate-800">

                        <tr>

                            <th class="text-left px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">
                                Transaction ID
                            </th>

                            <th class="text-left px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">
                                Cashier
                            </th>

                            <th class="text-left px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">
                                Date & Time
                            </th>

                            <th class="text-left px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Transaction Note
                            </th>

                            <th class="text-right px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">
                                Total Amount
                            </th>

                            <th class="text-center px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">
                                Status
                            </th>

                            <th class="text-right px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500">
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody
                        id="salesTable"
                        class="divide-y divide-slate-100"
                    >

                        <?php if (!empty($sales)): ?>

                            <tr id="salesNoFilterResults" class="hidden">
                                <td colspan="7" class="px-5 py-12 text-center">
                                    <p class="font-bold text-slate-700">No sales match your filters</p>
                                    <p class="text-sm text-slate-400 mt-1">Try a different search or date range.</p>
                                </td>
                            </tr>

                        <?php endif; ?>

                        <?php if (empty($sales)): ?>

                            <tr>

                                <td
                                    colspan="7"
                                    class="px-5 py-16 text-center"
                                >

                                    <div class="text-4xl mb-3">
                                        🧾
                                    </div>

                                    <p class="font-bold text-slate-700">
                                        No sales transactions found
                                    </p>

                                    <p class="text-sm text-slate-400 mt-1">
                                        Completed POS checkouts will appear here.
                                    </p>

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($sales as $sale): ?>

                                <?php

                                $saleId = (int) ($sale['id'] ?? 0);

                                $cashier = htmlspecialchars(
                                    $sale['cashier_name'] ?? 'Unknown',
                                    ENT_QUOTES,
                                    'UTF-8'
                                );

                                $createdAt = htmlspecialchars(
                                    $sale['created_at'] ?? '---',
                                    ENT_QUOTES,
                                    'UTF-8'
                                );

                                $saleDate = substr(
                                    (string) ($sale['created_at'] ?? ''),
                                    0,
                                    10
                                );

                                $amount = (float) (
                                    $sale['total_amount'] ?? 0
                                );

                                $status = htmlspecialchars(
                                    $sale['status'] ?? 'Completed',
                                    ENT_QUOTES,
                                    'UTF-8'
                                );

                                $transactionNote = trim((string) ($sale['transaction_note'] ?? ''));
                                $transactionNoteExcerpt = mb_substr($transactionNote, 0, 120, 'UTF-8');

                                $searchText = strtolower(
                                    '#' .
                                    $saleId .
                                    ' ' .
                                    strip_tags($cashier) .
                                    ' ' .
                                    strip_tags($createdAt) .
                                    ' ' .
                                    $transactionNote .
                                    ' ' .
                                    strip_tags($status)
                                );

                                ?>

                                <tr
                                    class="sales-row hover:bg-slate-50/70 transition"
                                    data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>"
                                    data-date="<?= htmlspecialchars($saleDate, ENT_QUOTES, 'UTF-8') ?>"
                                >

                                    <!-- Transaction ID -->

                                    <td class="px-5 py-4">

                                        <div class="font-bold text-slate-800">
                                            #<?= $saleId ?>
                                        </div>

                                    </td>


                                    <!-- Cashier -->

                                    <td class="px-5 py-4">

                                        <span class="text-sm text-slate-600">
                                            <?= $cashier ?>
                                        </span>

                                    </td>


                                    <!-- Date -->

                                    <td class="px-5 py-4">

                                        <span class="text-sm text-slate-500">
                                            <?= $createdAt ?>
                                        </span>

                                    </td>

                                    <!-- Transaction Note -->

                                    <td class="max-w-xs px-5 py-4">

                                        <?php if ($transactionNote !== ''): ?>
                                            <span
                                                class="block truncate text-sm text-slate-600 dark:text-slate-300"
                                                title="<?= htmlspecialchars($transactionNote, ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                                <?= htmlspecialchars($transactionNoteExcerpt, ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($transactionNote, 'UTF-8') > 120 ? '…' : '' ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-sm text-slate-400">—</span>
                                        <?php endif; ?>

                                    </td>


                                    <!-- Amount -->

                                    <td class="px-5 py-4 text-right font-bold text-slate-900">

                                        ₱<?= number_format($amount, 2) ?>

                                    </td>


                                    <!-- Status -->

                                    <td class="px-5 py-4 text-center">

                                        <span
                                            class="inline-flex px-3 py-1 rounded-full text-xs font-bold bg-green-50 text-green-600"
                                        >
                                            <?= ucfirst($status) ?>
                                        </span>

                                    </td>


                                    <!-- Actions -->

                                    <td class="px-5 py-4">

                                        <div class="flex items-center justify-end gap-2">

                                            <!-- Edit -->

                                            <?php if (hasAppPermission($pdo, $userId, 'sales.manage')): ?>
                                            <a
                                                href="./api/edit_sale.php?id=<?= $saleId ?>"
                                                class="px-3 py-2 rounded-lg text-sm font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 transition"
                                            >
                                                Edit
                                            </a>
                                            <?php endif; ?>


                                            <!-- View Transaction Modal -->

                                            <button
                                                type="button"
                                                class="view-sale-btn px-3 py-2 rounded-lg text-sm font-semibold text-green-700 bg-green-50 hover:bg-green-100 transition dark:text-green-300 dark:bg-green-900/30 dark:hover:bg-green-900/50"
                                                data-sale-id="<?= $saleId ?>"
                                            >
                                                View Details
                                            </button>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>


            <!-- ================================================= -->
            <!-- PAGINATION -->
            <!-- ================================================= -->

            <div
                id="paginationContainer"
                class="p-5 border-t border-slate-200 hidden flex-col sm:flex-row items-center justify-between gap-4"
            >

                <div class="text-sm text-slate-500">

                    Page

                    <span
                        class="font-bold text-slate-700"
                        id="currentPageNum"
                    >
                        1
                    </span>

                    of

                    <span
                        class="font-bold text-slate-700"
                        id="totalPageNum"
                    >
                        1
                    </span>

                </div>


                <div class="flex items-center gap-2">

                    <button
                        type="button"
                        id="prevPageBtn"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 transition cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        Previous
                    </button>


                    <div
                        id="pageNumbersContainer"
                        class="flex items-center gap-1"
                    ></div>


                    <button
                        type="button"
                        id="nextPageBtn"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 transition cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        Next
                    </button>

                </div>

            </div>

        </div>

    </div>

</main>


<!-- ========================================================= -->
<!-- TRANSACTION DETAILS MODAL -->
<!-- ========================================================= -->

<div
    id="saleModal"
    class="modal-backdrop fixed inset-0 z-[9999] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4"
    aria-hidden="true"
>

    <div
        id="saleModalPanel"
        class="modal-panel w-full max-w-5xl max-h-[92vh] bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col dark:bg-slate-900"
        role="dialog"
        aria-modal="true"
        aria-labelledby="saleModalTitle"
    >

        <!-- ================================================= -->
        <!-- MODAL HEADER -->
        <!-- ================================================= -->

        <div class="flex items-center justify-between px-5 sm:px-6 py-4 border-b border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">

            <div>

                <h2
                    id="saleModalTitle"
                    class="text-lg sm:text-xl font-black text-slate-900 dark:text-slate-100"
                >
                    Transaction Details
                </h2>

                <p
                    id="saleModalSubtitle"
                    class="text-sm text-slate-500 mt-1 dark:text-slate-400"
                >
                    Loading transaction...
                </p>

            </div>


            <div class="flex items-center gap-2">

                <!-- Open Receipt -->

                <button
                    type="button"
                    id="openReceiptBtn"
                    class="hidden h-10 px-4 rounded-xl bg-green-50 text-green-700 hover:bg-green-100 text-sm font-bold transition dark:bg-green-900/30 dark:text-green-300 dark:hover:bg-green-900/50"
                >
                    🧾 Receipt / Print
                </button>


                <!-- Close -->

                <button
                    type="button"
                    id="closeSaleModal"
                    class="w-10 h-10 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center text-xl font-bold transition dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                    aria-label="Close transaction details"
                >
                    ×
                </button>

            </div>

        </div>


        <!-- ================================================= -->
        <!-- MODAL BODY -->
        <!-- ================================================= -->

        <div
            id="saleModalBody"
            class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-6 dark:bg-slate-950"
        >

            <!-- Loading -->

            <div
                id="saleModalLoading"
                class="py-20 text-center"
            >

                <div
                    class="w-10 h-10 border-4 border-green-200 border-t-green-600 rounded-full animate-spin mx-auto mb-4"
                ></div>

                <p class="font-bold text-slate-700 dark:text-slate-200">
                    Loading transaction...
                </p>

                <p class="text-sm text-slate-400 mt-1 dark:text-slate-500">
                    Please wait.
                </p>

            </div>


            <!-- Error -->

            <div
                id="saleModalError"
                class="hidden py-16 text-center"
            >

                <div class="text-4xl mb-4">
                    ⚠️
                </div>

                <p class="font-bold text-red-700">
                    Unable to load transaction
                </p>

                <p
                    id="saleModalErrorText"
                    class="text-sm text-slate-500 mt-2 dark:text-slate-400"
                >
                    Something went wrong.
                </p>

                <button
                    type="button"
                    id="retrySaleBtn"
                    class="mt-5 px-5 py-2.5 rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold transition"
                >
                    Try Again
                </button>

            </div>


            <!-- Receipt iframe -->

            <div
                id="saleDetailsContainer"
                class="hidden"
            >
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                    <div class="bg-white rounded-xl border border-slate-200 p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                            Transaction ID
                        </p>
                        <p
                            id="detailSaleId"
                            class="mt-1 text-lg font-black text-slate-900 dark:text-slate-100"
                        >
                            —
                        </p>
                    </div>

                    <div class="bg-white rounded-xl border border-slate-200 p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                            Cashier
                        </p>
                        <p
                            id="detailCashier"
                            class="mt-1 text-lg font-bold text-slate-900 dark:text-slate-100"
                        >
                            —
                        </p>
                    </div>

                    <div class="bg-white rounded-xl border border-slate-200 p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                            Date & Time
                        </p>
                        <p
                            id="detailDate"
                            class="mt-1 text-sm font-semibold text-slate-700 dark:text-slate-200"
                        >
                            —
                        </p>
                    </div>

                    <div class="bg-white rounded-xl border border-slate-200 p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                            Status
                        </p>
                        <p
                            id="detailStatus"
                            class="mt-1 text-sm font-bold text-green-600"
                        >
                            —
                        </p>
                    </div>

                    <div
                        id="detailCustomerContainer"
                        class="hidden md:col-span-2 bg-white rounded-xl border border-slate-200 p-4"
                    >
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                            Customer Details
                        </p>
                        <p id="detailCustomerName" class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100"></p>
                        <p id="detailCustomerPhone" class="mt-1 text-sm text-slate-600 dark:text-slate-300"></p>
                        <p id="detailCustomerAddress" class="mt-1 text-sm text-slate-600 dark:text-slate-300"></p>
                    </div>

                </div>

                <div
                    id="detailTransactionNoteContainer"
                    class="hidden mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/40"
                >
                    <p class="text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                        Transaction Note
                    </p>
                    <p id="detailTransactionNote" class="mt-1 whitespace-pre-wrap text-sm text-slate-800 dark:text-slate-100"></p>
                    <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                        Note only; checkout requires full payment and does not track balances.
                    </p>
                </div>

                <!-- ITEMS -->

                <div class="mt-5 bg-white rounded-xl border border-slate-200 overflow-hidden">

                    <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-700">
                        <h3 class="font-black text-slate-900 dark:text-slate-100">
                            Order Items
                        </h3>
                    </div>

                    <div class="overflow-x-auto">

                        <table class="w-full">

                            <thead class="bg-slate-50 dark:bg-slate-800">

                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-black uppercase text-slate-500">
                                        Item
                                    </th>

                                    <th class="px-4 py-3 text-center text-xs font-black uppercase text-slate-500">
                                        Qty
                                    </th>

                                    <th class="px-4 py-3 text-right text-xs font-black uppercase text-slate-500">
                                        Price
                                    </th>

                                    <th class="px-4 py-3 text-right text-xs font-black uppercase text-slate-500">
                                        Total
                                    </th>
                                </tr>

                            </thead>

                            <tbody
                                id="detailItems"
                                class="divide-y divide-slate-100"
                            ></tbody>

                        </table>

                    </div>

                </div>


                <!-- TOTALS -->

                <div class="mt-5 bg-white rounded-xl border border-slate-200 p-5">

                    <div class="space-y-3 max-w-md ml-auto">

                        <!-- SUBTOTAL -->

                        <div
                            id="detailSubtotalRow"
                            class="flex justify-between text-sm"
                        >

                            <span class="text-slate-500">
                                Subtotal
                            </span>

                            <span
                                id="detailSubtotal"
                                class="font-semibold text-slate-900"
                            >
                                ₱0.00
                            </span>

                        </div>


                        <!-- DISCOUNT -->

                        <div
                            id="detailDiscountRow"
                            class="hidden flex justify-between text-sm"
                        >

                            <span class="text-slate-500">
                                Discount
                            </span>

                            <span
                                id="detailDiscount"
                                class="font-semibold text-red-600"
                            >
                                ₱0.00
                            </span>

                        </div>


                        <!-- TOTAL -->

                        <div class="pt-3 border-t border-slate-200 flex justify-between dark:border-slate-700">

                            <span class="font-black">
                                Total
                            </span>

                            <span
                                id="detailTotal"
                                class="text-xl font-black text-green-600"
                            >
                                ₱0.00
                            </span>

                        </div>


                        <!-- CASH TENDERED -->

                        <div class="flex justify-between text-sm pt-2">

                            <span class="text-slate-500">
                                Cash Tendered
                            </span>

                            <span
                                id="detailCashTendered"
                                class="font-bold text-slate-900"
                            >
                                ₱0.00
                            </span>

                        </div>


                        <!-- CHANGE -->

                        <div
                            id="detailChangeRow"
                            class="hidden justify-between text-sm"
                        >

                            <span class="text-slate-500">
                                Change
                            </span>

                            <span
                                id="detailChange"
                                class="font-bold text-slate-900"
                            >
                                ₱0.00
                            </span>

                        </div>


                    </div>

                </div>



            </div>

        </div>


        <!-- ================================================= -->
        <!-- MODAL FOOTER -->
        <!-- ================================================= -->

        <div class="px-5 sm:px-6 py-4 border-t border-slate-200 bg-white flex items-center justify-between gap-3 dark:border-slate-700 dark:bg-slate-900">

            <p
                id="modalSaleStatus"
                class="text-xs text-slate-400"
            >
                Transaction details
            </p>

            <button
                type="button"
                id="closeSaleModalFooter"
                class="h-10 px-5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-sm font-bold text-slate-600 transition dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
            >
                Close
            </button>

        </div>

    </div>

</div>


<!-- ========================================================= -->
<!-- JAVASCRIPT -->
<!-- ========================================================= -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | SALES SEARCH / PAGINATION
    |--------------------------------------------------------------------------
    */

    const searchInput = document.getElementById('salesSearch');
    const startDateInput = document.getElementById('salesStartDate');
    const endDateInput = document.getElementById('salesEndDate');
    const clearDateFilterButton = document.getElementById('clearSalesDateFilter');
    const noFilterResults = document.getElementById('salesNoFilterResults');
    const perPageSelect = document.getElementById('perPageSelect');
    const salesRows = Array.from(
        document.querySelectorAll('.sales-row')
    );

    const paginationContainer =
        document.getElementById('paginationContainer');

    const pageNumbersContainer =
        document.getElementById('pageNumbersContainer');

    const prevPageBtn =
        document.getElementById('prevPageBtn');

    const nextPageBtn =
        document.getElementById('nextPageBtn');

    const salesCountText =
        document.getElementById('salesCountText');

    const currentPageNum =
        document.getElementById('currentPageNum');

    const totalPageNum =
        document.getElementById('totalPageNum');

    let currentPage = 1;


    function getFilteredRows() {

        const search =
            searchInput.value.toLowerCase().trim();
        const startDate = startDateInput.value;
        const endDate = endDateInput.value;

        return salesRows.filter(function (row) {

            const dataText =
                row.dataset.search || '';
            const saleDate =
                row.dataset.date || '';

            return dataText.includes(search) &&
                (!startDate || saleDate >= startDate) &&
                (!endDate || saleDate <= endDate);

        });

    }


    function renderPageNumbers(totalPages) {

        pageNumbersContainer.innerHTML = '';

        const maxVisible = 5;

        let startPage = Math.max(
            1,
            currentPage - Math.floor(maxVisible / 2)
        );

        let endPage = Math.min(
            totalPages,
            startPage + maxVisible - 1
        );

        if (
            endPage - startPage + 1 <
            maxVisible
        ) {

            startPage = Math.max(
                1,
                endPage - maxVisible + 1
            );

        }


        for (
            let i = startPage;
            i <= endPage;
            i++
        ) {

            const btn =
                document.createElement('button');

            btn.type = 'button';

            btn.textContent = i;

            btn.className =
                `w-10 h-10 rounded-xl text-sm font-bold border transition cursor-pointer flex items-center justify-center ${
                    i === currentPage
                        ? 'bg-green-600 text-white border-green-600 shadow-md shadow-green-600/20'
                        : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                }`;

            btn.addEventListener(
                'click',
                function () {

                    currentPage = i;

                    updateTable();

                }
            );

            pageNumbersContainer.appendChild(btn);

        }

    }


    function updateTable() {

        const filteredRows =
            getFilteredRows();

        const perPage =
            parseInt(
                perPageSelect.value,
                10
            ) || 10;

        const totalFiltered =
            filteredRows.length;

        if (noFilterResults) {
            noFilterResults.classList.toggle(
                'hidden',
                totalFiltered > 0
            );
        }

        const totalPages =
            Math.ceil(
                totalFiltered / perPage
            ) || 1;


        if (currentPage > totalPages) {
            currentPage = totalPages;
        }


        salesRows.forEach(function (row) {

            row.style.display = 'none';

        });


        const start =
            (currentPage - 1) * perPage;

        const end =
            start + perPage;

        const pageRows =
            filteredRows.slice(start, end);


        pageRows.forEach(function (row) {

            row.style.display = '';

        });


        salesCountText.textContent =
            `Showing ${totalFiltered} record${
                totalFiltered === 1 ? '' : 's'
            }`;

        currentPageNum.textContent =
            currentPage;

        totalPageNum.textContent =
            totalPages;


        if (totalPages > 1) {

            paginationContainer.classList.remove(
                'hidden'
            );

            paginationContainer.classList.add(
                'flex'
            );

            renderPageNumbers(totalPages);

            prevPageBtn.disabled =
                currentPage === 1;

            nextPageBtn.disabled =
                currentPage === totalPages;

        } else {

            paginationContainer.classList.add(
                'hidden'
            );

            paginationContainer.classList.remove(
                'flex'
            );

        }

    }


    searchInput.addEventListener(
        'input',
        function () {

            currentPage = 1;

            updateTable();

        }
    );

    startDateInput.addEventListener('change', function () {
        currentPage = 1;
        updateTable();
    });

    endDateInput.addEventListener('change', function () {
        currentPage = 1;
        updateTable();
    });

    clearDateFilterButton.addEventListener('click', function () {
        startDateInput.value = '';
        endDateInput.value = '';
        currentPage = 1;
        updateTable();
    });


    perPageSelect.addEventListener(
        'change',
        function () {

            currentPage = 1;

            updateTable();

        }
    );


    prevPageBtn.addEventListener(
        'click',
        function () {

            if (currentPage > 1) {

                currentPage--;

                updateTable();

            }

        }
    );


    nextPageBtn.addEventListener(
        'click',
        function () {

            const filteredRows =
                getFilteredRows();

            const perPage =
                parseInt(
                    perPageSelect.value,
                    10
                ) || 10;

            const totalPages =
                Math.ceil(
                    filteredRows.length / perPage
                ) || 1;


            if (currentPage < totalPages) {

                currentPage++;

                updateTable();

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | TRANSACTION MODAL
    |--------------------------------------------------------------------------
    */

    const saleModal =
        document.getElementById('saleModal');

    const saleModalBody =
        document.getElementById('saleModalBody');

    const saleModalTitle =
        document.getElementById('saleModalTitle');

    const saleModalSubtitle =
        document.getElementById('saleModalSubtitle');

    const saleModalLoading =
        document.getElementById('saleModalLoading');

    const saleModalError =
        document.getElementById('saleModalError');

    const saleModalErrorText =
        document.getElementById('saleModalErrorText');
    const saleDetailsContainer =
    document.getElementById('saleDetailsContainer');

    const detailSaleId =
        document.getElementById('detailSaleId');

    const detailCashier =
        document.getElementById('detailCashier');

    const detailDate =
        document.getElementById('detailDate');

    const detailStatus =
        document.getElementById('detailStatus');

    const detailCustomerContainer =
        document.getElementById('detailCustomerContainer');

    const detailCustomerName =
        document.getElementById('detailCustomerName');

    const detailCustomerPhone =
        document.getElementById('detailCustomerPhone');

    const detailCustomerAddress =
        document.getElementById('detailCustomerAddress');

    const detailTransactionNoteContainer =
        document.getElementById('detailTransactionNoteContainer');

    const detailTransactionNote =
        document.getElementById('detailTransactionNote');

    const detailItems =
        document.getElementById('detailItems');

    const detailSubtotal =
        document.getElementById('detailSubtotal');

    const detailDiscount =
        document.getElementById('detailDiscount');

    const detailTotal =
        document.getElementById('detailTotal');

    const detailCashTendered =
        document.getElementById('detailCashTendered');

    const detailChange =
        document.getElementById('detailChange');

    const closeSaleModal =
        document.getElementById('closeSaleModal');

    const closeSaleModalFooter =
        document.getElementById('closeSaleModalFooter');

    const retrySaleBtn =
        document.getElementById('retrySaleBtn');

    const openReceiptBtn =
        document.getElementById('openReceiptBtn');

    const modalSaleStatus =
        document.getElementById('modalSaleStatus');

    let currentSaleId = null;


    /*
    |--------------------------------------------------------------------------
    | OPEN MODAL
    |--------------------------------------------------------------------------
    */

    async function openSaleModal(saleId) {

        currentSaleId = saleId;

        saleModalTitle.textContent =
            'Transaction #' + saleId;

        saleModalSubtitle.textContent =
            'Loading transaction details...';

        modalSaleStatus.textContent =
            'Loading transaction...';

        saleModalLoading.classList.remove('hidden');

        saleModalError.classList.add('hidden');

        saleDetailsContainer.classList.add('hidden');

        openReceiptBtn.classList.add('hidden');

        saleModal.classList.add('show');

        saleModal.setAttribute(
            'aria-hidden',
            'false'
        );

        document.body.classList.add('modal-open');


        try {

            const response = await fetch(
                './api/sale_details.php?id=' +
                encodeURIComponent(saleId),
                {
                    headers: {
                        'Accept': 'application/json'
                    }
                }
            );

            if (!response.ok) {
                throw new Error(
                    'Unable to load transaction.'
                );
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(
                    data.message ||
                    'Transaction not found.'
                );
            }


            const sale = data.sale;
            const items = data.items || [];


            detailSaleId.textContent =
                '#' + sale.id;

            detailCashier.textContent =
                (await fetchUser(sale.user_id)).full_name || 'Unknown';

            detailDate.textContent =
                sale.created_at || '—';

            detailStatus.textContent =
                (sale.status.length > 0) ? (sale.status.slice(0, 1).toUpperCase() + sale.status.slice(1)) : 'Completed';

            const customerName = sale.customer_name || '';
            const customerPhone = sale.customer_phone || '';
            const customerAddress = sale.customer_address || '';
            detailCustomerContainer.classList.toggle(
                'hidden',
                !customerName && !customerPhone && !customerAddress
            );
            detailCustomerName.textContent = customerName;
            detailCustomerPhone.textContent = customerPhone
                ? 'Contact: ' + customerPhone
                : '';
            detailCustomerAddress.textContent = customerAddress
                ? 'Address: ' + customerAddress
                : '';

            const transactionNote = sale.transaction_note || '';
            detailTransactionNoteContainer.classList.toggle(
                'hidden',
                !transactionNote
            );
            detailTransactionNote.textContent = transactionNote;


            detailItems.innerHTML = '';


            if (items.length === 0) {

                detailItems.innerHTML = `
                    <tr>
                        <td
                            colspan="4"
                            class="px-4 py-8 text-center text-sm text-slate-400 dark:text-slate-500"
                        >
                            No item details available.
                        </td>
                    </tr>
                `;

            } else {

                items.forEach(function (item) {

                    const row =
                        document.createElement('tr');

                    row.innerHTML = `
                        <td class="px-4 py-3 text-sm font-semibold text-slate-700 dark:text-slate-200">
                            ${escapeHtml(item.product_name || 'Unknown Item')}
                        </td>

                        <td class="px-4 py-3 text-center text-sm text-slate-600 dark:text-slate-300">
                            ${Number(item.quantity || 0)}
                        </td>

                        <td class="px-4 py-3 text-right text-sm text-slate-600 dark:text-slate-300">
                            ₱${Number(item.price || 0).toLocaleString(
                                'en-PH',
                                {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                }
                            )}
                        </td>

                        <td class="px-4 py-3 text-right text-sm font-bold text-slate-800 dark:text-slate-100">
                            ₱${Number(item.subtotal || 0).toLocaleString(
                                'en-PH',
                                {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                }
                            )}
                        </td>
                    `;

                    detailItems.appendChild(row);

                });

            }

            /*
            console.log("[DEBUG]: Sale details loaded:", sale);
            OUTPUT: {
                "id": 12,
                "sale_id": 12,
                "user_id": 1,
                "total_amount": 153,
                "amount_tendered": 153,
                "change_amount": 0,
                "status": "completed",
                "receipt_type": "order_slip",
                "created_at": "2026-09-27 12:55:59",
                "username": "osianathan17",
                "full_name": "Nathaniel Osia",
                "role": "admin",
                "cashier": {
                    "id": 1,
                    "username": "osianathan17",
                    "full_name": "Nathaniel Osia",
                    "role": "admin"
                },
                "item_count": 5,
                "items": [
                    {
                        "id": 15,
                        "sale_item_id": 15,
                        "sale_id": 12,
                        "product_id": 35,
                        "product_name": "Nova Country Cheddar (38g)",
                        "category_name": "Food",
                        "quantity": 2,
                        "price": 20,
                        "subtotal": 40
                    }, {...}, {...}
                ]
            }
            */

            /*
            |--------------------------------------------------------------------------
            | CALCULATE TOTALS
            |--------------------------------------------------------------------------
            */

            // Calculate subtotal directly from the sale items
            const calculatedSubtotal = items.reduce(
                function (sum, item) {
                    return sum + Number(item.subtotal || 0);
                },
                0
            );


            // Discount
            const discountAmount = Number(
                sale.discount || 0
            );


            // Total
            const totalAmount = Number(
                sale.total_amount || 0
            );


            // Cash Tendered
            const cashTendered = Number(
                sale.amount_tendered || 0
            );


            // Change
            const changeAmount = Number(
                sale.change_amount || 0
            );


            // Format Philippine Peso
            function formatPeso(amount) {

                return '₱' + Number(amount).toLocaleString(
                    'en-PH',
                    {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }
                );

            }


            /*
            |--------------------------------------------------------------------------
            | DISPLAY SUBTOTAL
            |--------------------------------------------------------------------------
            */

            detailSubtotal.textContent =
                formatPeso(calculatedSubtotal);


            /*
            |--------------------------------------------------------------------------
            | DISPLAY DISCOUNT
            |--------------------------------------------------------------------------
            */

            const detailDiscountRow =
                document.getElementById('detailDiscountRow');

            if (discountAmount > 0) {

                detailDiscountRow.classList.remove('hidden');
                detailDiscountRow.classList.add('flex');

                detailDiscount.textContent =
                    formatPeso(discountAmount);

            } else {

                detailDiscountRow.classList.add('hidden');
                detailDiscountRow.classList.remove('flex');

            }


            /*
            |--------------------------------------------------------------------------
            | DISPLAY TOTAL
            |--------------------------------------------------------------------------
            */

            detailTotal.textContent =
                formatPeso(totalAmount);


            /*
            |--------------------------------------------------------------------------
            | DISPLAY CASH TENDERED
            |--------------------------------------------------------------------------
            */

            detailCashTendered.textContent =
                formatPeso(cashTendered);

            const detailChangeRow =
                document.getElementById('detailChangeRow');

            if (changeAmount > 0) {

                detailChangeRow.classList.remove('hidden');
                detailChangeRow.classList.add('flex');

                detailChange.textContent =
                    formatPeso(changeAmount);

            } else {

                detailChangeRow.classList.add('hidden');
                detailChangeRow.classList.remove('flex');

            }

            saleModalLoading.classList.add(
                'hidden'
            );

            saleDetailsContainer.classList.remove(
                'hidden'
            );

            openReceiptBtn.classList.remove(
                'hidden'
            );

            saleModalSubtitle.textContent =
                'Transaction information';

            modalSaleStatus.textContent =
                'Transaction #' +
                saleId +
                ' loaded';

        } catch (error) {

            showSaleModalError(
                error.message ||
                'Unable to load transaction.'
            );

        }

    }
    
    async function fetchUser(userId) {
        try {
            const response = await fetch(
                './api/user_details.php?id=' +
                encodeURIComponent(userId),
                {
                    headers: {
                        'Accept': 'application/json'
                    }
                }
            );

            if (!response.ok) {
                return userId;
            }

            const data = await response.json();

            console.log("User Fetched:", data);
            return data;

        } catch (err) {
            console.error('Failed to fetch user details:', err);
            return userId;
        }
    }

    function escapeHtml(value) {

        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

    }


    /*
    |--------------------------------------------------------------------------
    | CLOSE MODAL
    |--------------------------------------------------------------------------
    */

    function closeSaleModalWindow() {

        saleModal.classList.remove('show');

        saleModal.setAttribute(
            'aria-hidden',
            'true'
        );

        document.body.classList.remove(
            'modal-open'
        );

        setTimeout(function () {

            saleModalLoading.classList.add(
                'hidden'
            );

            saleModalError.classList.add(
                'hidden'
            );

            saleDetailsContainer.classList.add(
                'hidden'
            );

            openReceiptBtn.classList.add(
                'hidden'
            );

            detailItems.innerHTML = '';

        }, 200);

    }

    /*
    |--------------------------------------------------------------------------
    | SHOW ERROR
    |--------------------------------------------------------------------------
    */

    function showSaleModalError(message) {

        saleModalLoading.classList.add(
            'hidden'
        );

        saleDetailsContainer.classList.add(
            'hidden'
        );

        openReceiptBtn.classList.add(
            'hidden'
        );

        saleModalError.classList.remove(
            'hidden'
        );

        saleModalErrorText.textContent =
            message;

        saleModalSubtitle.textContent =
            'Unable to load transaction';

        modalSaleStatus.textContent =
            'Failed to load transaction';

    }


    /*
    |--------------------------------------------------------------------------
    | VIEW DETAILS BUTTONS
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll('.view-sale-btn')
        .forEach(function (button) {

            button.addEventListener(
                'click',
                function () {

                    const saleId =
                        parseInt(
                            this.dataset.saleId,
                            10
                        );

                    if (
                        !saleId ||
                        saleId <= 0
                    ) {

                        return;

                    }

                    openSaleModal(saleId);

                }
            );

        });


    /*
    |--------------------------------------------------------------------------
    | CLOSE BUTTONS
    |--------------------------------------------------------------------------
    */

    closeSaleModal.addEventListener(
        'click',
        closeSaleModalWindow
    );


    closeSaleModalFooter.addEventListener(
        'click',
        closeSaleModalWindow
    );


    /*
    |--------------------------------------------------------------------------
    | CLICK BACKDROP TO CLOSE
    |--------------------------------------------------------------------------
    */

    saleModal.addEventListener(
        'click',
        function (event) {

            if (
                event.target === saleModal
            ) {

                closeSaleModalWindow();

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | ESCAPE KEY
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'Escape' &&
                saleModal.classList.contains('show')
            ) {

                closeSaleModalWindow();

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | RETRY
    |--------------------------------------------------------------------------
    */

    retrySaleBtn.addEventListener(
        'click',
        function () {

            if (
                currentSaleId
            ) {

                openSaleModal(
                    currentSaleId
                );

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | OPEN RECEIPT IN NEW WINDOW
    |--------------------------------------------------------------------------
    */

    openReceiptBtn.addEventListener(
        'click',
        function () {

            if (!currentSaleId) {
                return;
            }

            window.open(
                '/LALENZ_SARISARI_POS/api/sale_receipt.php?id=' +
                encodeURIComponent(currentSaleId),
                '_blank',
                'width=1000,height=800,resizable=yes,scrollbars=yes'
            );

        }
    );

    /*
    |--------------------------------------------------------------------------
    | INITIAL TABLE RENDER
    |--------------------------------------------------------------------------
    */

    updateTable();

});

</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>

</html>
