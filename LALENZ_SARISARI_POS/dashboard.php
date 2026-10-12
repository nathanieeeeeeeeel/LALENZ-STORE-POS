<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/system.php';
require_once __DIR__ . '/config/permissions.php';

// Make sure the user is logged in
requireAuth();

// Get logged-in user's ID
$userId = fetchUser();

// Get logged-in user's information
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

// If the user no longer exists in the database,
// destroy the session and return to login.
if (!$user) {
    session_unset();
    session_destroy();

    header('Location: login.php');
    exit;
}
requireAppPermission($pdo, $userId, 'dashboard.view');

// User information
$username = $user['username'] ?? 'User';
$fullName = $user['full_name'] ?? $username;
$user_role = $user['role'] ?? 'Staff';

// Fetch Dynamic KPI Metrics
$stmtProdCount = $pdo->query("SELECT COUNT(*) AS total FROM products");
$totalProducts = $stmtProdCount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmtCatCount = $pdo->query("SELECT COUNT(*) AS total FROM categories");
$totalCategories = $stmtCatCount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmtLowStock = $pdo->query("SELECT COUNT(*) AS total FROM products WHERE stock <= 5");
$lowStock = $stmtLowStock->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Fetch Recent Sales (Latest 5)
$stmtRecentSales = $pdo->query("SELECT * FROM sales ORDER BY id DESC LIMIT 5");
$recentSales = $stmtRecentSales->fetchAll(PDO::FETCH_ASSOC);

$currentPage = basename($_SERVER['PHP_SELF']);

?>


<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | <?= htmlspecialchars($systemName) ?></title>
    <meta name="description" content="LALENZ Sari-Sari POS Dashboard">
    <link rel="icon" href="<?= $logo ?>" type="image/x-icon" />
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
                            900: "#14532d",
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
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
    </style>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

<!-- SIDEBAR -->
<?php include "./includes/sidebar.php"; ?>

<!-- MAIN -->
<div class="lg:ml-64 min-h-screen dark:bg-slate-950">

<!-- TOP BAR -->
<header class="flex h-20 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8 dark:border-slate-700 dark:bg-slate-900">
        <div class="min-w-0">
            <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white leading-tight">
                <?= htmlspecialchars($pageTitle ?? 'Dashboard') ?>
            </h1>

            <p class="mt-1 text-xs sm:text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
                <?= htmlspecialchars($pageDescription ?? 'Welcome to your store management system.') ?>
            </p>
        </div>

</header>


<!-- CONTENT -->
<main class="p-4 sm:p-6 lg:p-8">

    <!-- PAGE TITLE -->
    <div class="mb-8">
        <h2 class="text-2xl font-black">Store Overview</h2>
        <p class="text-sm text-slate-500 mt-1">Here's what's happening with your sari-sari store.</p>
    </div>

    <!-- STAT CARDS -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">

        <!-- PRODUCTS -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm font-semibold text-slate-500">Total Products</p>
                    <p class="mt-3 text-3xl font-black text-slate-900"><?= number_format($totalProducts) ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-green-100 text-green-700 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7h16M4 7l2 13h12l2-13M9 7V5a3 3 0 016 0v2"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- CATEGORIES -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm font-semibold text-slate-500">Categories</p>
                    <p class="mt-3 text-3xl font-black text-slate-900"><?= number_format($totalCategories) ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h7v7H4V6zm9 0h7v7h-7V6zM4 15h7v3H4v-3zm9 0h7v3h-7v-3z"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- LOW STOCK -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm font-semibold text-slate-500">Low Stock</p>
                    <p class="mt-3 text-3xl font-black text-slate-900"><?= number_format($lowStock) ?></p>
                    <p class="mt-1 text-xs text-slate-400">5 units or less</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-orange-100 text-orange-700 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v4m0 4h.01M10.3 3.8L2.9 17a2 2 0 001.7 3h14.8a2 2 0 001.7-3L13.7 3.8a2 2 0 00-3.4 0z"/>
                    </svg>
                </div>
            </div>
        </div>

    </div>

    <!-- QUICK ACTIONS -->
    <div class="mt-8">
        <h3 class="text-lg font-black mb-4">Quick Actions</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <a href="products.php" class="bg-white border border-slate-200 rounded-2xl p-5 hover:border-green-300 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-xl bg-green-100 text-green-700 flex items-center justify-center mb-4">+</div>
                <div class="font-bold">Manage Products</div>
                <div class="text-xs text-slate-500 mt-1">Add, edit and manage your inventory.</div>
            </a>
            <a href="categories.php" class="bg-white border border-slate-200 rounded-2xl p-5 hover:border-blue-300 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center mb-4">#</div>
                <div class="font-bold">Categories</div>
                <div class="text-xs text-slate-500 mt-1">Organize your store products.</div>
            </a>
            <a href="sales.php" class="bg-white border border-slate-200 rounded-2xl p-5 hover:border-purple-300 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-xl bg-purple-100 text-purple-700 flex items-center justify-center mb-4">₱</div>
                <div class="font-bold">New Sale</div>
                <div class="text-xs text-slate-500 mt-1">Start a new POS transaction.</div>
            </a>
            <a href="reports.php" class="bg-white border border-slate-200 rounded-2xl p-5 hover:border-orange-300 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-xl bg-orange-100 text-orange-700 flex items-center justify-center mb-4">↗</div>
                <div class="font-bold">Reports</div>
                <div class="text-xs text-slate-500 mt-1">View sales and store reports.</div>
            </a>
        </div>
    </div>

    <!-- RECENT SALES -->
    <div class="mt-8">
        <div class="bg-white border border-slate-200 rounded-2xl p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-black">Recent Sales</h3>
                    <p class="text-sm text-slate-500 mt-1">Your latest transactions will appear here.</p>
                </div>
                <a href="sales.php" class="text-sm font-bold text-green-600 hover:text-green-700">View all</a>
            </div>

            <?php if (empty($recentSales)): ?>
                <!-- EMPTY RECENT SALES -->
                <div class="py-12 text-center">
                    <div class="mx-auto w-14 h-14 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 14h6M9 10h6M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"/>
                        </svg>
                    </div>
                    <p class="mt-4 font-bold text-slate-700">No sales yet</p>
                    <p class="mt-1 text-sm text-slate-400">Transactions will appear here once you make a sale.</p>
                </div>
            <?php else: ?>
                <!-- SALES TABLE -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 text-xs font-black text-slate-400 uppercase">
                                <th class="py-3 px-4">Sale ID</th>
                                <th class="py-3 px-4">Total Amount</th>
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                            <?php foreach ($recentSales as $sale): ?>
                                <tr>
                                    <td class="py-3 px-4 font-bold text-slate-800">#<?= $sale['id'] ?></td>
                                    <td class="py-3 px-4 font-black text-green-600">₱<?= number_format($sale['total_amount'], 2) ?></td>
                                    <td class="py-3 px-4 text-slate-500"><?= htmlspecialchars($sale['created_at'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <?php if (hasAppPermission($pdo, $userId, 'sales.manage')): ?>
                                                <a href="./api/edit_sale.php?id=<?= $sale['id'] ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 transition">Edit</a>
                                            <?php endif; ?>
                                            <button
                                                type="button"
                                                onclick="openReceipt(<?= $sale['id'] ?>)"
                                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-green-700 bg-green-50 hover:bg-green-100 transition dark:bg-green-900/30 dark:text-green-300 dark:hover:bg-green-900/50"
                                            >
                                                Receipt
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div id="receiptModal" class="fixed inset-0 z-50 hidden bg-black/50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-4xl h-[90vh] overflow-hidden">

                <div class="flex items-center justify-between px-4 py-3 border-b">
                    <h3 class="font-semibold text-gray-800">Sale Receipt</h3>

                    <button
                        type="button"
                        onclick="closeReceipt()"
                        class="text-gray-500 hover:text-gray-800 text-xl"
                    >
                        &times;
                    </button>
                </div>

                <iframe
                    id="receiptFrame"
                    src=""
                    class="w-full h-[calc(90vh-57px)] border-0"
                ></iframe>

            </div>
        </div>
    </div>

</main>

</div>

<script>
function openReceipt(saleId) {
    const receiptUrl = '/LALENZ_SARISARI_POS/api/sale_receipt.php?id=' + encodeURIComponent(saleId);

    window.open(
        receiptUrl,
        '_blank',
        'width=1000,height=800,resizable=yes,scrollbars=yes'
    );
}

function closeReceipt() {
    const modal = document.getElementById('receiptModal');
    const frame = document.getElementById('receiptFrame');

    modal.classList.add('hidden');
    frame.src = '';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>