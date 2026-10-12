<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/system.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/product_audit.php';
$userId = fetchUser();
$userId = fetchUser();

/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/

$stmt =$pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);

$user =$stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_unset();
    session_destroy();

    header('Location: login.php');
    exit;
}
requireAppPermission($pdo, $userId, 'products.view');
ensureProductAuditColumns($pdo);
$canManageProducts = hasAppPermission($pdo, $userId, 'products.manage');

// User information
$username = $user['username'] ?? 'User';$fullName = $user['full_name'] ?? $username;
$user_role =$user['role'] ?? 'Staff';

/*
|--------------------------------------------------------------------------
| Statistics Setup
|--------------------------------------------------------------------------
*/

$stmtStats =$pdo->query("SELECT stock, reorder_level FROM products");
$allProductsForStats =$stmtStats->fetchAll(PDO::FETCH_ASSOC);

$totalProducts = count($allProductsForStats);$totalStock = 0;
$lowStock = 0;
$outOfStock = 0;

foreach ($allProductsForStats as$p) {
    $stock = (float) ($p['stock'] ?? 0);
    $reorderLevel = (float) ($p['reorder_level'] ?? 5);
    $totalStock +=$stock;

    if ($stock <= 0) {$outOfStock++;
    } elseif ($stock <= $reorderLevel) {$lowStock++;
    }
}

/*
|--------------------------------------------------------------------------
| Products (Fetched all at once for instant JS Pagination & Numbered Buttons)
|--------------------------------------------------------------------------
*/

$stmt =$pdo->query("
    SELECT
        p.*,
        c.name AS category_name
    FROM products p
    LEFT JOIN categories c
        ON p.category_id = c.id
    ORDER BY 
        CASE 
            WHEN p.stock > 5 THEN 1                
            WHEN p.stock > 0 AND p.stock <= 5 THEN 2 
            ELSE 3                                   
        END ASC,
        p.name ASC
");

$products =$stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

$stmt =$pdo->query("
    SELECT id, name
    FROM categories
    ORDER BY name ASC
");

$categories =$stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Products | <?= htmlspecialchars($systemName) ?></title>
<meta name="description" content="Manage products and inventory">

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<link rel="icon" href="<?= htmlspecialchars($logo) ?>" type="image/x-icon" />
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
</style>
</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

<!-- SIDEBAR -->
<?php 
$currentPage = basename($_SERVER['PHP_SELF']);
include "./includes/sidebar.php";
?>

<!-- MAIN -->
<main class="lg:ml-64 min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

    <!-- HEADER -->
    <header class="flex h-20 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8 dark:border-slate-700 dark:bg-slate-900">
            <div>
                <h1 class="text-2xl font-black tracking-tight text-slate-900 dark:text-white">
                    Products
                </h1>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Manage your products and inventory.
                </p>
            </div>

    </header>


    <!-- CONTENT -->
    <div class="p-4 sm:p-6 lg:p-8">

        <!-- STATISTICS -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-8">

            <!-- TOTAL PRODUCTS -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">
                    Total Products
                </p>
                <p class="mt-2 text-3xl font-black text-slate-900 dark:text-white">
                    <?= number_format($totalProducts) ?>
                </p>
            </div>

            <!-- TOTAL STOCK -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">
                    Total Stock
                </p>
                <p class="mt-2 text-3xl font-black text-green-600 dark:text-green-400">
                    <?= number_format($totalStock, 0) ?>
                </p>
            </div>

            <!-- LOW STOCK -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">
                    Low Stock
                </p>
                <p class="mt-2 text-3xl font-black text-amber-500 dark:text-amber-400">
                    <?= number_format($lowStock) ?>
                </p>
            </div>

            <!-- OUT OF STOCK -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">
                    Out of Stock
                </p>
                <p class="mt-2 text-3xl font-black text-red-500 dark:text-red-400">
                    <?= number_format($outOfStock) ?>
                </p>
            </div>

        </div>


        <!-- PRODUCTS CARD -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden shadow-sm">

            <!-- TOOLBAR -->
            <div class="p-5 border-b border-slate-200 dark:border-slate-800 flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4">

                <div class="flex items-center gap-4">
                    <div>
                        <h2 class="text-lg font-black text-slate-900 dark:text-white">
                            Product Inventory
                        </h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1" id="productCountText">
                            Showing products
                        </p>
                    </div>
                </div>


                <div class="flex flex-wrap items-center gap-3">

                    <!-- ITEMS PER PAGE -->
                    <div class="flex items-center gap-2">
                        <label
                            for="perPageSelect"
                            class="text-sm text-slate-500 dark:text-slate-400 font-medium"
                        >
                            Show:
                        </label>

                        <select
                            id="perPageSelect"
                            class="
                                h-11 px-3 rounded-xl
                                border border-slate-200 dark:border-slate-700
                                bg-slate-50 dark:bg-slate-800
                                text-slate-700 dark:text-slate-200
                                text-sm
                                outline-none
                                transition
                                focus:border-green-500
                                focus:ring-4 focus:ring-green-500/10
                            "
                        >
                            <option value="5">5</option>
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>


                    <!-- SEARCH -->
                    <div class="relative">
                        <input
                            type="search"
                            id="productSearch"
                            placeholder="Search products..."
                            class="
                                w-full sm:w-56 h-11
                                pl-10 pr-4
                                rounded-xl
                                border border-slate-200 dark:border-slate-700
                                bg-slate-50 dark:bg-slate-800
                                text-slate-900 dark:text-slate-100
                                placeholder:text-slate-400 dark:placeholder:text-slate-500
                                text-sm
                                outline-none
                                transition
                                focus:border-green-500
                                focus:ring-4 focus:ring-green-500/10
                            "
                        >

                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                            🔎
                        </span>
                    </div>


                    <!-- CATEGORY FILTER -->
                    <select
                        id="categoryFilter"
                        class="
                            h-11 px-4 rounded-xl
                            border border-slate-200 dark:border-slate-700
                            bg-slate-50 dark:bg-slate-800
                            text-slate-700 dark:text-slate-200
                            text-sm
                            outline-none
                            transition
                            focus:border-green-500
                            focus:ring-4 focus:ring-green-500/10
                        "
                    >
                        <option value="">All Categories</option>

                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>">
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>


                    <!-- ADD PRODUCT -->
                    <?php if ($canManageProducts): ?>
                        <a
                            href="./api/product_add.php"
                            class="
                                h-11 px-5
                                rounded-xl
                                bg-green-600
                                hover:bg-green-700
                                dark:bg-green-500
                                dark:hover:bg-green-600
                                text-white
                                font-bold
                                text-sm
                                inline-flex items-center justify-center gap-2
                                shadow-lg shadow-green-600/20
                                transition-all duration-200
                                hover:-translate-y-0.5
                            "
                        >
                            <span class="text-lg">+</span>
                            Add Product
                        </a>
                    <?php endif; ?>

                </div>
            </div>


            <!-- TABLE -->
            <div class="overflow-x-auto">

                <table class="w-full min-w-[900px]">

                    <!-- TABLE HEADER -->
                    <thead class="bg-slate-50 dark:bg-slate-800/70">

                        <tr>

                            <th
                                class="
                                    text-left px-5 py-4
                                    text-xs font-black uppercase tracking-wider
                                    text-slate-500 dark:text-slate-400
                                    cursor-pointer select-none
                                    hover:bg-slate-200 dark:hover:bg-slate-700
                                    hover:text-slate-700 dark:hover:text-slate-200
                                    transition-colors duration-150
                                "
                                data-sort="name"
                            >
                                <div class="flex items-center gap-1.5">
                                    Product
                                    <span class="sort-icon text-slate-400 dark:text-slate-500">↕</span>
                                </div>
                            </th>


                            <th
                                class="
                                    text-left px-5 py-4
                                    text-xs font-black uppercase tracking-wider
                                    text-slate-500 dark:text-slate-400
                                    cursor-pointer select-none
                                    hover:bg-slate-200 dark:hover:bg-slate-700
                                    hover:text-slate-700 dark:hover:text-slate-200
                                    transition-colors duration-150
                                "
                                data-sort="category"
                            >
                                <div class="flex items-center gap-1.5">
                                    Category
                                    <span class="sort-icon text-slate-400 dark:text-slate-500">↕</span>
                                </div>
                            </th>


                            <th
                                class="
                                    text-right px-5 py-4
                                    text-xs font-black uppercase tracking-wider
                                    text-slate-500 dark:text-slate-400
                                    cursor-pointer select-none
                                    hover:bg-slate-200 dark:hover:bg-slate-700
                                    hover:text-slate-700 dark:hover:text-slate-200
                                    transition-colors duration-150
                                "
                                data-sort="price"
                            >
                                <div class="flex items-center justify-end gap-1.5">
                                    Price
                                    <span class="sort-icon text-slate-400 dark:text-slate-500">↕</span>
                                </div>
                            </th>


                            <th
                                class="
                                    text-right px-5 py-4
                                    text-xs font-black uppercase tracking-wider
                                    text-slate-500 dark:text-slate-400
                                    cursor-pointer select-none
                                    hover:bg-slate-200 dark:hover:bg-slate-700
                                    hover:text-slate-700 dark:hover:text-slate-200
                                    transition-colors duration-150
                                "
                                data-sort="stock"
                            >
                                <div class="flex items-center justify-end gap-1.5">
                                    Stock
                                    <span class="sort-icon text-slate-400 dark:text-slate-500">↕</span>
                                </div>
                            </th>


                            <th
                                class="
                                    text-center px-5 py-4
                                    text-xs font-black uppercase tracking-wider
                                    text-slate-500 dark:text-slate-400
                                    hover:bg-slate-200 dark:hover:bg-slate-700
                                    hover:text-slate-700 dark:hover:text-slate-200
                                    transition-colors duration-150
                                "
                            >
                                Status
                            </th>


                            <th
                                class="
                                    text-right px-5 py-4
                                    text-xs font-black uppercase tracking-wider
                                    text-slate-500 dark:text-slate-400
                                    hover:bg-slate-200 dark:hover:bg-slate-700
                                    hover:text-slate-700 dark:hover:text-slate-200
                                    transition-colors duration-150
                                "
                            >
                                Actions
                            </th>

                        </tr>

                    </thead>



                    <!-- TABLE BODY -->
                    <tbody
                        id="productTable"
                        class="divide-y divide-slate-100 dark:divide-slate-800"
                    >

                        <?php if (empty($products)): ?>

                            <tr id="noProductsRow">

                                <td colspan="6" class="px-5 py-16 text-center">

                                    <div class="text-4xl mb-3">📦</div>

                                    <p class="font-bold text-slate-700 dark:text-slate-200">
                                        No products found
                                    </p>

                                    <p class="text-sm text-slate-400 dark:text-slate-500 mt-1">
                                        Add your first product to get started.
                                    </p>

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($products as $product): ?>

                                <?php
                                $stock = (float) ($product['stock'] ?? 0);

                                if ($stock <= 0) {
                                    $status = 'Out of Stock';
                                    $statusClass = 'bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-400';
                                } elseif ($stock <= $product['reorder_level'] ?? 5) {
                                    $status = 'Low Stock';
                                    $statusClass = 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400';
                                } else {
                                    $status = 'In Stock';
                                    $statusClass = 'bg-green-50 text-green-600 dark:bg-green-950/40 dark:text-green-400';
                                }
                                ?>

                                <tr
                                    class="
                                        product-row
                                        hover:bg-slate-50/70
                                        dark:hover:bg-slate-800/50
                                        transition
                                    "
                                    data-name="<?= htmlspecialchars(strtolower($product['name'] ?? '')) ?>"
                                    data-category="<?= htmlspecialchars(strtolower($product['category_name'] ?? 'uncategorized')) ?>"
                                    data-price="<?= (float) ($product['selling_price'] ?? 0) ?>"
                                    data-stock="<?= $stock ?>"
                                >

                                    <!-- PRODUCT -->
                                    <td class="px-5 py-4">

                                        <div class="font-bold text-slate-800 dark:text-slate-100">
                                            <?= htmlspecialchars($product['name'] ?? 'Unnamed Product') ?>
                                        </div>

                                        <?php if (!empty($product['sku'])): ?>

                                            <div class="text-xs text-slate-400 dark:text-slate-500 mt-1">
                                                SKU: <?= htmlspecialchars($product['sku']) ?>
                                            </div>

                                        <?php endif; ?>

                                    </td>


                                    <!-- CATEGORY -->
                                    <td class="px-5 py-4">

                                        <span class="text-sm text-slate-600 dark:text-slate-300">
                                            <?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?>
                                        </span>

                                    </td>


                                    <!-- PRICE -->
                                    <td class="px-5 py-4 text-right font-bold text-slate-800 dark:text-slate-100">
                                        ₱<?= number_format((float) ($product['selling_price'] ?? 0), 2) ?>
                                    </td>


                                    <!-- STOCK -->
                                    <td class="px-5 py-4 text-right font-bold text-slate-800 dark:text-slate-100">
                                        <?= number_format($stock, 0) ?>
                                    </td>


                                    <!-- STATUS -->
                                    <td class="px-5 py-4 text-center">

                                        <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold <?= $statusClass ?>">
                                            <?= $status ?>
                                        </span>

                                    </td>


                                    <!-- ACTIONS -->
                                    <td class="px-5 py-4">

                                        <div class="flex items-center justify-end gap-2">

                                            <?php if ($canManageProducts): ?>
                                            <a
                                                href="./api/product_edit.php?id=<?= (int) $product['id'] ?>"
                                                class="
                                                    px-3 py-2
                                                    rounded-lg
                                                    text-sm font-semibold
                                                    text-green-700
                                                    bg-green-50
                                                    hover:bg-green-100
                                                    dark:text-green-400
                                                    dark:bg-green-950/40
                                                    dark:hover:bg-green-900/60
                                                    transition
                                                "
                                            >
                                                Edit
                                            </a>
                                            <?php endif; ?>

                                            <?php if ($canManageProducts): ?>

                                                <a
                                                    href="./api/product_delete.php?id=<?= (int) $product['id'] ?>"
                                                    onclick="return confirm('Delete this product? This action cannot be undone.');"
                                                    class="
                                                        px-3 py-2
                                                        rounded-lg
                                                        text-sm font-semibold
                                                        text-red-600
                                                        bg-red-50
                                                        hover:bg-red-100
                                                        dark:text-red-400
                                                        dark:bg-red-950/40
                                                        dark:hover:bg-red-900/60
                                                        transition
                                                    "
                                                >
                                                    Delete
                                                </a>

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>


            <!-- PAGINATION CONTROLS -->
            <div
                id="paginationContainer"
                class="
                    p-5
                    border-t border-slate-200 dark:border-slate-800
                    hidden
                    flex flex-col sm:flex-row
                    items-center justify-between
                    gap-4
                    bg-white dark:bg-slate-900
                "
            >

                <!-- PAGE INFO -->
                <div class="text-sm text-slate-500 dark:text-slate-400 whitespace-nowrap">

                    Page
                    <span
                        class="font-bold text-slate-700 dark:text-slate-200"
                        id="currentPageNum"
                    >
                        1
                    </span>

                    of

                    <span
                        class="font-bold text-slate-700 dark:text-slate-200"
                        id="totalPageNum"
                    >
                        1
                    </span>

                </div>


                <!-- PAGINATION BUTTONS -->
                <div class="flex items-center justify-center gap-2 flex-wrap">

                    <!-- PREVIOUS -->
                    <button
                        type="button"
                        id="prevPageBtn"
                        class="
                            h-10 px-4
                            rounded-lg
                            border
                            text-sm font-semibold
                            inline-flex items-center justify-center
                            transition-all duration-200
                            cursor-pointer

                            bg-white
                            text-slate-600
                            border-slate-200

                            hover:bg-green-50
                            hover:text-green-700
                            hover:border-green-300
                            hover:shadow-md
                            hover:-translate-y-0.5

                            dark:bg-slate-800
                            dark:text-slate-300
                            dark:border-slate-700

                            dark:hover:bg-green-950/50
                            dark:hover:text-green-400
                            dark:hover:border-green-700

                            disabled:opacity-40
                            disabled:cursor-not-allowed
                            disabled:hover:translate-y-0
                            disabled:hover:shadow-none
                            disabled:hover:bg-white
                            disabled:hover:text-slate-600
                            disabled:hover:border-slate-200

                            dark:disabled:hover:bg-slate-800
                            dark:disabled:hover:text-slate-300
                            dark:disabled:hover:border-slate-700
                        "
                    >
                        Previous
                    </button>


                    <!-- DYNAMIC NUMBERED PAGE BUTTONS -->
                    <div
                        id="pageNumbersContainer"
                        class="
                            flex items-center justify-center
                            gap-2
                            flex-wrap
                        "
                    ></div>


                    <!-- NEXT -->
                    <button
                        type="button"
                        id="nextPageBtn"
                        class="
                            h-10 px-4
                            rounded-lg
                            border
                            text-sm font-semibold
                            inline-flex items-center justify-center
                            transition-all duration-200
                            cursor-pointer

                            bg-white
                            text-slate-600
                            border-slate-200

                            hover:bg-green-50
                            hover:text-green-700
                            hover:border-green-300
                            hover:shadow-md
                            hover:-translate-y-0.5

                            dark:bg-slate-800
                            dark:text-slate-300
                            dark:border-slate-700

                            dark:hover:bg-green-950/50
                            dark:hover:text-green-400
                            dark:hover:border-green-700

                            disabled:opacity-40
                            disabled:cursor-not-allowed
                            disabled:hover:translate-y-0
                            disabled:hover:shadow-none
                            disabled:hover:bg-white
                            disabled:hover:text-slate-600
                            disabled:hover:border-slate-200

                            dark:disabled:hover:bg-slate-800
                            dark:disabled:hover:text-slate-300
                            dark:disabled:hover:border-slate-700
                        "
                    >
                        Next
                    </button>

                </div>

            </div>

        </div>

    </div>

</main>

<!-- INSTANT CLIENT-SIDE SEARCH, FILTER, LIMIT, SORT & PAGINATION JAVASCRIPT -->
<script>
    const searchInput = document.getElementById('productSearch');
    const categoryFilter = document.getElementById('categoryFilter');
    const perPageSelect = document.getElementById('perPageSelect');
    const productRows = Array.from(document.querySelectorAll('.product-row'));
    const sortHeaders = document.querySelectorAll('th[data-sort]');
    
    const paginationContainer = document.getElementById('paginationContainer');
    const pageNumbersContainer = document.getElementById('pageNumbersContainer');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');
    const productCountText = document.getElementById('productCountText');
    const currentPageNum = document.getElementById('currentPageNum');
    const totalPageNum = document.getElementById('totalPageNum');

    let currentPage = 1;
    let currentSortColumn = null;
    let currentSortDirection = 'asc'; // 'asc' or 'desc'

    function getFilteredAndSortedRows() {
        const search = searchInput.value.toLowerCase().trim();
        const category = categoryFilter.value;

        // 1. Filter
        const filtered = productRows.filter(row => {
            const name = row.dataset.name || '';
            const rowCategory = row.dataset.category || '';
            const matchesSearch = name.includes(search);
            const matchesCategory = !category || row.dataset.category_id === category; // or use standard filtering logic
            return matchesSearch && matchesCategory;
        });

        // 2. Sort
        if (currentSortColumn) {
            filtered.sort((a, b) => {
                let valA, valB;

                if (currentSortColumn === 'price') {
                    valA = parseFloat(a.dataset.price) || 0;
                    valB = parseFloat(b.dataset.price) || 0;
                } else if (currentSortColumn === 'stock') {
                    valA = parseFloat(a.dataset.stock) || 0;
                    valB = parseFloat(b.dataset.stock) || 0;
                } else {
                    valA = a.dataset[currentSortColumn] || '';
                    valB = b.dataset[currentSortColumn] || '';
                }

                if (valA < valB) return currentSortDirection === 'asc' ? -1 : 1;
                if (valA > valB) return currentSortDirection === 'asc' ? 1 : -1;
                return 0;
            });
        }

        return filtered;
    }

    // Adjusting category filtering condition safely based on select values
    function getFilteredRows() {
        const search = searchInput.value.toLowerCase().trim();
        const categoryId = categoryFilter.value;

        return productRows.filter(row => {
            const name = row.dataset.name || '';
            const matchesSearch = name.includes(search);
            
            // Match category option value to row data-category attribute if needed, 
            // or pass category ID cleanly from PHP during rendering.
            // Let's grab the category ID directly from select if matched.
            const matchesCategory = !categoryId || row.querySelector('td:nth-child(2)').textContent.trim() === categoryFilter.options[categoryFilter.selectedIndex].text;
            return matchesSearch && matchesCategory;
        });
    }

    // Refined filtering to handle both search & sorting pipeline together
    function getProcessedRows() {
        const search = searchInput.value.toLowerCase().trim();
        const categoryId = categoryFilter.value;

        // Find selected category name if any
        let categoryName = '';
        if (categoryId) {
            const selectedOpt = categoryFilter.querySelector(`option[value="${categoryId}"]`);
            categoryName = selectedOpt ? selectedOpt.textContent.trim().toLowerCase() : '';
        }

        // Filter
        const filtered = productRows.filter(row => {
            const name = row.dataset.name || '';
            const rowCatText = row.querySelector('td:nth-child(2)').textContent.trim().toLowerCase();
            
            const matchesSearch = name.includes(search);
            const matchesCategory = !categoryName || rowCatText === categoryName;
            
            return matchesSearch && matchesCategory;
        });

        // Sort
        if (currentSortColumn) {
            filtered.sort((a, b) => {
                let valA, valB;

                if (currentSortColumn === 'price') {
                    valA = parseFloat(a.dataset.price) || 0;
                    valB = parseFloat(b.dataset.price) || 0;
                } else if (currentSortColumn === 'stock') {
                    valA = parseFloat(a.dataset.stock) || 0;
                    valB = parseFloat(b.dataset.stock) || 0;
                } else if (currentSortColumn === 'category') {
                    valA = a.querySelector('td:nth-child(2)').textContent.trim().toLowerCase();
                    valB = b.querySelector('td:nth-child(2)').textContent.trim().toLowerCase();
                } else {
                    valA = a.dataset.name || '';
                    valB = b.dataset.name || '';
                }

                if (valA < valB) return currentSortDirection === 'asc' ? -1 : 1;
                if (valA > valB) return currentSortDirection === 'asc' ? 1 : -1;
                return 0;
            });
        }

        return filtered;
    }

    function renderPageNumbers(totalPages) {
        pageNumbersContainer.innerHTML = '';

        const maxVisible = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(totalPages, startPage + maxVisible - 1);

        if (endPage - startPage + 1 < maxVisible) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }

        for (let i = startPage; i <= endPage; i++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = i;

            btn.className = `
                w-10 h-10
                shrink-0
                rounded-lg
                text-sm font-semibold
                border
                inline-flex items-center justify-center
                transition-all duration-200
                cursor-pointer
                focus:outline-none
                focus:ring-2
                focus:ring-green-500/40
                ${
                    i === currentPage
                        ? `
                            bg-green-600
                            text-white
                            border-green-600
                            shadow-md shadow-green-600/25
                            hover:bg-green-700
                            hover:shadow-lg hover:shadow-green-600/30
                            dark:bg-green-500
                            dark:border-green-500
                            dark:hover:bg-green-600
                        `
                        : `
                            bg-white
                            text-slate-600
                            border-slate-200

                            hover:bg-green-50
                            hover:text-green-700
                            hover:border-green-300
                            hover:shadow-md
                            hover:-translate-y-0.5

                            dark:bg-slate-800
                            dark:text-slate-300
                            dark:border-slate-700

                            dark:hover:bg-green-950/50
                            dark:hover:text-green-400
                            dark:hover:border-green-700
                            dark:hover:shadow-md
                        `
                }
            `;

            btn.addEventListener('click', () => {
                currentPage = i;
                updateTable();
            });

            pageNumbersContainer.appendChild(btn);
        }

    }

    function updateTable() {
        const processedRows = getProcessedRows();
        const perPage = parseInt(perPageSelect.value) || 10;
        const totalFiltered = processedRows.length;
        const totalPages = Math.ceil(totalFiltered / perPage) || 1;

        if (currentPage > totalPages) {
            currentPage = totalPages;
        }

        // Hide all rows first
        productRows.forEach(row => row.style.display = 'none');

        // Reorder DOM rows according to sorting order
        const tbody = document.getElementById('productTable');
        processedRows.forEach(row => tbody.appendChild(row));

        // Slice and display only current page items
        const start = (currentPage - 1) * perPage;
        const end = start + perPage;
        const pageRows = processedRows.slice(start, end);

        pageRows.forEach(row => {
            row.style.display = '';
        });

        productCountText.textContent = `Showing ${totalFiltered} product${totalFiltered === 1 ? '' : 's'}`;
        currentPageNum.textContent = currentPage;
        totalPageNum.textContent = totalPages;

        if (totalPages > 1) {
            paginationContainer.classList.remove('hidden');
            paginationContainer.classList.add('flex');
            
            renderPageNumbers(totalPages);

            prevPageBtn.disabled = currentPage === 1;
            nextPageBtn.disabled = currentPage === totalPages;
        } else {
            paginationContainer.classList.add('hidden');
            paginationContainer.classList.remove('flex');
        }
    }

    // Sort Click Handlers
    sortHeaders.forEach(th => {
        th.addEventListener('click', () => {
            const column = th.dataset.sort;

            if (currentSortColumn === column) {
                currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortColumn = column;
                currentSortDirection = 'asc';
            }

            // Update Header Indicators
            sortHeaders.forEach(header => {
                const icon = header.querySelector('.sort-icon');
                if (header.dataset.sort === currentSortColumn) {
                    icon.textContent = currentSortDirection === 'asc' ? '↑' : '↓';
                    icon.className = 'sort-icon text-green-600 font-bold';
                } else {
                    icon.textContent = '↕';
                    icon.className = 'sort-icon text-slate-400';
                }
            });

            currentPage = 1;
            updateTable();
        });
    });

    // Event Listeners
    searchInput.addEventListener('input', () => {
        currentPage = 1;
        updateTable();
    });

    categoryFilter.addEventListener('change', () => {
        currentPage = 1;
        updateTable();
    });

    perPageSelect.addEventListener('change', () => {
        currentPage = 1;
        updateTable();
    });

    prevPageBtn.addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            updateTable();
        }
    });

    nextPageBtn.addEventListener('click', () => {
        const processedRows = getProcessedRows();
        const perPage = parseInt(perPageSelect.value) || 10;
        const totalPages = Math.ceil(processedRows.length / perPage) || 1;

        if (currentPage < totalPages) {
            currentPage++;
            updateTable();
        }
    });

    // Initial load execution
    updateTable();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>