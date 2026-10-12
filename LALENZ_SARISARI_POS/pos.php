<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/system.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/product_audit.php';
require_once __DIR__ . '/config/action_log.php';
require_once __DIR__ . '/config/sale_metadata.php';

// =========================================================
// AUTHENTICATION
// =========================================================

$userId = fetchUser();

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
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !hasAppPermission($pdo, $userId, 'pos.view')
) {
    appLogAction($pdo, $userId, 'sale.created', 'sale', null, null, 'failure', [
        'reason' => 'Permission denied.',
    ]);
}
requireAppPermission($pdo, $userId, 'pos.view');
ensureProductAuditColumns($pdo);
ensureActionLogTable($pdo);
ensureSaleMetadataColumns($pdo);

$username = $user['username'] ?? 'User';
$fullName = $user['full_name'] ?? $username;
$user_role = $user['role'] ?? 'Staff';

$error = '';
$success = '';
$customerName = '';
$customerPhone = '';
$customerAddress = '';
$transactionNote = '';


// =========================================================
// LOAD CATEGORIES
// =========================================================
// Normal products require stock > 0.
// Load & E-services are displayed even when stock is 0,
// because digital services may not use physical inventory.

$stmtCategories = $pdo->query("
    SELECT
        c.id,
        c.name,
        COUNT(
            CASE
                WHEN LOWER(TRIM(c.name)) = 'load & e-services'
                    THEN p.id
                WHEN p.stock > 0
                    THEN p.id
            END
        ) AS product_count
    FROM categories c
    LEFT JOIN products p
        ON p.category_id = c.id
        AND COALESCE(p.is_active, 1) = 1
    GROUP BY c.id, c.name
    ORDER BY c.name ASC
");

$categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// LOAD POS PRODUCTS
// =========================================================
// Physical products must have stock.
// Load & E-services are always available regardless of stock.

$stmtProducts = $pdo->query("
    SELECT
        p.*,
        c.name AS category_name
    FROM products p
    LEFT JOIN categories c
        ON c.id = p.category_id
    WHERE
        COALESCE(p.is_active, 1) = 1
        AND (
            p.stock > 0
            OR LOWER(TRIM(COALESCE(c.name, ''))) = 'load & e-services'
        )
    ORDER BY
        CASE
            WHEN LOWER(TRIM(COALESCE(c.name, ''))) = 'load & e-services'
                THEN 0
            ELSE 1
        END,
        p.name ASC
");

$products = $stmtProducts->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// HANDLE CHECKOUT
// =========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $rawCart = $_POST['cart_items'] ?? '[]';
    $cartData = json_decode($rawCart, true);

    $rawCash = trim($_POST['cash_tendered'] ?? '');
    $customerName = isset($_POST['customer_name']) && is_string($_POST['customer_name'])
        ? trim($_POST['customer_name'])
        : '';
    $customerPhone = isset($_POST['customer_phone']) && is_string($_POST['customer_phone'])
        ? trim($_POST['customer_phone'])
        : '';
    $customerAddress = isset($_POST['customer_address']) && is_string($_POST['customer_address'])
        ? trim($_POST['customer_address'])
        : '';
    $transactionNote = isset($_POST['transaction_note']) && is_string($_POST['transaction_note'])
        ? trim($_POST['transaction_note'])
        : '';

    if (mb_strlen($customerName, 'UTF-8') > 150) {
        $error = 'Customer name cannot exceed 150 characters.';
    } elseif (mb_strlen($customerPhone, 'UTF-8') > 50) {
        $error = 'Customer contact cannot exceed 50 characters.';
    } elseif (mb_strlen($customerAddress, 'UTF-8') > 500) {
        $error = 'Customer address cannot exceed 500 characters.';
    } elseif (mb_strlen($transactionNote, 'UTF-8') > 2000) {
        $error = 'Transaction note cannot exceed 2,000 characters.';
    }

    if ($error !== '') {
        appLogAction($pdo, $userId, 'sale.created', 'sale', null, null, 'failure', [
            'reason' => $error,
        ]);
    } elseif (!is_array($cartData) || empty($cartData)) {

        $error = 'Your cart is empty. Please add at least one item.';
        appLogAction($pdo, $userId, 'sale.created', 'sale', null, null, 'failure', [
            'reason' => $error,
        ]);

    } else {

        try {

            $pdo->beginTransaction();

            $totalAmount = 0;
            $validatedItems = [];

            foreach ($cartData as $item) {

                $prodId = isset($item['id'])
                    ? (int) $item['id']
                    : 0;

                $qty = isset($item['quantity'])
                    ? (int) $item['quantity']
                    : 0;

                if ($prodId <= 0 || $qty <= 0) {
                    continue;
                }


                // -------------------------------------------------
                // Load product
                // -------------------------------------------------

                $stmtCheck = $pdo->prepare("
                    SELECT
                        p.id,
                        p.name,
                        p.selling_price,
                        p.stock,
                        p.category_id,
                        p.is_active,
                        c.name AS category_name
                    FROM products p
                    LEFT JOIN categories c
                        ON c.id = p.category_id
                    WHERE p.id = ?
                    FOR UPDATE
                ");

                $stmtCheck->execute([$prodId]);

                $prod = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if (!$prod) {
                    throw new Exception(
                        'Product ID #' . $prodId . ' no longer exists.'
                    );
                }


                // -------------------------------------------------
                // Check active status
                // -------------------------------------------------

                if (isset($prod['is_active']) && !(int) $prod['is_active']) {
                    throw new Exception(
                        $prod['name'] . ' is currently inactive.'
                    );
                }


                // -------------------------------------------------
                // Determine if this is Load & E-services
                // -------------------------------------------------

                $categoryName = strtolower(
                    trim($prod['category_name'] ?? '')
                );

                $isEService = ($categoryName === 'load & e-services');


                // -------------------------------------------------
                // Validate physical stock
                // -------------------------------------------------

                if (!$isEService) {

                    $availableStock = (int) ($prod['stock'] ?? 0);

                    if ($availableStock < $qty) {

                        throw new Exception(
                            'Insufficient stock for ' .
                            $prod['name'] .
                            '. Only ' .
                            $availableStock .
                            ' available.'
                        );
                    }
                }


                // -------------------------------------------------
                // Calculate price
                // -------------------------------------------------

                $price = (float) $prod['selling_price'];

                if ($price < 0) {
                    throw new Exception(
                        'Invalid selling price for ' . $prod['name'] . '.'
                    );
                }

                $subtotal = $price * $qty;

                $totalAmount += $subtotal;


                // -------------------------------------------------
                // Store validated item
                // -------------------------------------------------

                $validatedItems[] = [
                    'id' => $prodId,
                    'quantity' => $qty,
                    'price' => $price,
                    'is_e_service' => $isEService
                ];
            }


            // =====================================================
            // VALIDATE CART
            // =====================================================

            if (empty($validatedItems)) {
                throw new Exception(
                    'No valid items found in the cart.'
                );
            }


            // =====================================================
            // CASH
            // =====================================================

            $cashTendered =
                ($rawCash === '' || (float) $rawCash <= 0)
                    ? $totalAmount
                    : (float) $rawCash;

            if ($cashTendered < $totalAmount) {

                throw new Exception(
                    'Insufficient cash tendered. Total amount due is P' .
                    number_format($totalAmount, 2)
                );
            }

            $changeAmount = $cashTendered - $totalAmount;


            // =====================================================
            // CREATE SALE
            // =====================================================

            $stmtSale = $pdo->prepare("
                INSERT INTO sales (
                    user_id,
                    customer_name,
                    customer_phone,
                    customer_address,
                    transaction_note,
                    total_amount,
                    amount_tendered,
                    change_amount,
                    status,
                    receipt_type,
                    created_at
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'completed',
                    'standard',
                    NOW()
                )
            ");

            $stmtSale->execute([
                $userId,
                $customerName !== '' ? $customerName : null,
                $customerPhone !== '' ? $customerPhone : null,
                $customerAddress !== '' ? $customerAddress : null,
                $transactionNote !== '' ? $transactionNote : null,
                $totalAmount,
                $cashTendered,
                $changeAmount
            ]);

            $saleId = $pdo->lastInsertId();


            // =====================================================
            // SALE ITEMS
            // =====================================================

            $stmtItem = $pdo->prepare("
                INSERT INTO sale_items (
                    sale_id,
                    product_id,
                    quantity,
                    price,
                    subtotal
                )
                VALUES (?, ?, ?, ?, ?)
            ");


            // =====================================================
            // STOCK UPDATE
            // =====================================================

            $stmtStock = $pdo->prepare("
                UPDATE products
                SET
                    stock = stock - ?,
                    updated_at = CURRENT_TIMESTAMP,
                    modified_by = ?
                WHERE id = ?
            ");


            foreach ($validatedItems as $item) {

                $subtotal =
                    $item['price'] * $item['quantity'];


                // -------------------------------------------------
                // Save sale item
                // -------------------------------------------------

                $stmtItem->execute([
                    $saleId,
                    $item['id'],
                    $item['quantity'],
                    $item['price'],
                    $subtotal
                ]);


                // -------------------------------------------------
                // Do NOT reduce stock for Load & E-services
                // -------------------------------------------------

                if (!$item['is_e_service']) {

                    $stmtStock->execute([
                        $item['quantity'],
                        $userId,
                        $item['id']
                    ]);
                }
            }

            appLogAction($pdo, $userId, 'sale.created', 'sale', (int) $saleId, 'Sale #' . $saleId, 'success', [
                'total_amount' => number_format($totalAmount, 2, '.', ''),
                'item_count' => count($validatedItems),
            ]);

            // =====================================================
            // COMMIT
            // =====================================================

            $pdo->commit();

            $success =
                'Transaction successfully recorded! Sale ID: #' .
                $saleId .
                ' - Change: P' .
                number_format($changeAmount, 2);

            $customerName = '';
            $customerPhone = '';
            $customerAddress = '';
            $transactionNote = '';


            // =====================================================
            // RELOAD PRODUCTS
            // =====================================================

            $stmtProducts = $pdo->query("
                SELECT
                    p.*,
                    c.name AS category_name
                FROM products p
                LEFT JOIN categories c
                    ON c.id = p.category_id
                WHERE
                    COALESCE(p.is_active, 1) = 1
                    AND (
                        p.stock > 0
                        OR LOWER(TRIM(COALESCE(c.name, ''))) = 'load & e-services'
                    )
                ORDER BY
                    CASE
                        WHEN LOWER(TRIM(COALESCE(c.name, ''))) = 'load & e-services'
                            THEN 0
                        ELSE 1
                    END,
                    p.name ASC
            ");

            $products = $stmtProducts->fetchAll(PDO::FETCH_ASSOC);


            // =====================================================
            // RELOAD CATEGORIES
            // =====================================================

            $stmtCategories = $pdo->query("
                SELECT
                    c.id,
                    c.name,
                    COUNT(
                        CASE
                            WHEN LOWER(TRIM(c.name)) = 'load & e-services'
                                THEN p.id
                            WHEN p.stock > 0
                                THEN p.id
                        END
                    ) AS product_count
                FROM categories c
                LEFT JOIN products p
                    ON p.category_id = c.id
                    AND COALESCE(p.is_active, 1) = 1
                GROUP BY c.id, c.name
                ORDER BY c.name ASC
            ");

            $categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            appLogAction($pdo, $userId, 'sale.created', 'sale', null, null, 'failure', [
                'reason' => $e->getMessage(),
                'cart_item_count' => is_array($cartData) ? count($cartData) : 0,
            ]);

            $error =
                'Checkout failed: ' .
                $e->getMessage();
        }
    }
}


$currentPage = basename($_SERVER['PHP_SELF']);

?>

<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        POS Checkout - LALENZ Sari-Sari POS
    </title>

    <link
        rel="icon"
        href="<?= htmlspecialchars($logo) ?>"
        type="image/x-icon"
    >

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            200: '#bbf7d0',
                            300: '#86efac',
                            400: '#4ade80',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d'
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

        .category-scroll::-webkit-scrollbar {
            height: 4px;
        }

        .category-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .category-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
        }

    </style>

</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

<?php include("./includes/sidebar.php"); ?>


<main class="lg:ml-64 min-h-screen pb-12 dark:bg-slate-950">

    <!-- ===================================================== -->
    <!-- HEADER -->
    <!-- ===================================================== -->

    <header class="h-20 bg-white border-b border-slate-200 dark:bg-slate-900 dark:border-slate-700">

        <div class="h-full px-4 sm:px-6 lg:px-8 flex items-center justify-between">

            <div>

                <h1 class="text-2xl font-black tracking-tight">
                    Point of Sale
                </h1>

                <p class="text-sm text-slate-500 mt-1">
                    Select products to build the customer's order.
                </p>

            </div>

        </div>

    </header>


    <!-- ===================================================== -->
    <!-- CONTENT -->
    <!-- ===================================================== -->

    <div class="p-4 sm:p-6 lg:p-8">

        <div class="mb-6">

            <a
                href="sales.php"
                class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-green-600 transition"
            >
                <span>&larr;</span>
                Back to Sales History
            </a>

        </div>


        <!-- ================================================= -->
        <!-- ERROR -->
        <!-- ================================================= -->

        <?php if (!empty($error)): ?>

            <div class="mb-6 p-4 rounded-2xl bg-red-50 border border-red-200 text-red-700 text-sm font-semibold flex items-center gap-3">

                <span>!</span>

                <span>
                    <?= htmlspecialchars($error) ?>
                </span>

            </div>

        <?php endif; ?>


        <!-- ================================================= -->
        <!-- SUCCESS -->
        <!-- ================================================= -->

        <?php if (!empty($success)): ?>

            <div
                id="checkoutSuccessMessage"
                class="mb-6 p-4 rounded-2xl bg-green-50 border border-green-200 text-green-700 text-sm font-semibold flex items-center gap-3 transition-opacity duration-500"
            >

                <span>OK</span>

                <span>
                    <?= htmlspecialchars($success) ?>
                </span>

                <a
                    href="sales.php"
                    class="ml-auto underline font-bold"
                >
                    View logs
                </a>

            </div>

        <?php endif; ?>


        <!-- ================================================= -->
        <!-- POS GRID -->
        <!-- ================================================= -->

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">


            <!-- ================================================= -->
            <!-- PRODUCTS -->
            <!-- ================================================= -->

            <div class="lg:col-span-7 xl:col-span-8">

                <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">


                    <!-- SEARCH -->

                    <div class="mb-4">

                        <input
                            type="search"
                            id="productSearch"
                            placeholder="Search products..."
                            class="w-full h-11 px-4 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"
                        >

                    </div>


                    <!-- ================================================= -->
                    <!-- CATEGORY FILTERS -->
                    <!-- ================================================= -->

                    <div
                        id="categoryFilters"
                        class="category-scroll flex gap-2 overflow-x-auto pb-3 mb-4"
                    >

                        <button
                            type="button"
                            data-category-id="all"
                            class="category-btn active-category px-4 py-2 rounded-xl bg-green-600 text-white text-xs font-bold whitespace-nowrap transition"
                        >
                            All
                        </button>


                        <?php foreach ($categories as $category): ?>

                            <?php
                            $categoryProductCount =
                                (int) ($category['product_count'] ?? 0);
                            ?>

                            <?php if ($categoryProductCount > 0): ?>

                                <button
                                    type="button"
                                    data-category-id="<?= (int) $category['id'] ?>"
                                    class="category-btn px-4 py-2 rounded-xl bg-slate-100 text-slate-600 text-xs font-bold whitespace-nowrap hover:bg-slate-200 transition"
                                >

                                    <?= htmlspecialchars(
                                        $category['name']
                                    ) ?>

                                    <span class="ml-1 opacity-60">
                                        <?= $categoryProductCount ?>
                                    </span>

                                </button>

                            <?php endif; ?>

                        <?php endforeach; ?>

                    </div>


                    <!-- ================================================= -->
                    <!-- PRODUCT COUNT -->
                    <!-- ================================================= -->

                    <div class="flex items-center justify-between mb-3">

                        <p
                            id="productCount"
                            class="text-xs font-bold text-slate-400"
                        >
                            <?= count($products) ?>
                            <?= count($products) === 1 ? 'product' : 'products' ?>
                        </p>


                        <button
                            type="button"
                            id="clearFilters"
                            class="text-xs font-bold text-green-600 hover:text-green-700"
                        >
                            Clear filters
                        </button>

                    </div>


                    <!-- ================================================= -->
                    <!-- PRODUCT GRID -->
                    <!-- ================================================= -->

                    <div
                        id="productGrid"
                        class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3 max-h-[600px] overflow-y-auto p-1"
                    >

                        <?php foreach ($products as $prod): ?>

                            <?php

                            $prodCategory =
                                strtolower(
                                    trim(
                                        $prod['category_name'] ?? ''
                                    )
                                );

                            $isEService =
                                $prodCategory === 'load & e-services';

                            $stockValue =
                                (int) ($prod['stock'] ?? 0);

                            ?>

                            <div
                                class="product-card border border-slate-200 rounded-xl p-4 bg-white hover:border-green-400 hover:shadow-md transition cursor-pointer flex flex-col justify-between"
                                data-id="<?= (int) $prod['id'] ?>"
                                data-name="<?= htmlspecialchars(
                                    $prod['name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                data-category-id="<?= (int) ($prod['category_id'] ?? 0) ?>"
                                data-category-name="<?= htmlspecialchars(
                                    $prod['category_name'] ?? 'Uncategorized',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                data-price="<?= htmlspecialchars(
                                    (string) $prod['selling_price'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                data-stock="<?= $stockValue ?>"
                                data-e-service="<?= $isEService ? '1' : '0' ?>"
                            >

                                <div>

                                    <div
                                        class="text-[10px] uppercase tracking-wide font-black mb-1 <?= $isEService ? 'text-blue-600' : 'text-green-600' ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $prod['category_name'] ?? 'Uncategorized'
                                        ) ?>

                                    </div>


                                    <div class="font-bold text-slate-800 text-sm mb-1">

                                        <?= htmlspecialchars(
                                            $prod['name']
                                        ) ?>

                                    </div>


                                    <?php if ($isEService): ?>

                                        <div class="text-xs text-blue-500 font-semibold">
                                            Digital Service
                                        </div>

                                    <?php else: ?>

                                        <div class="text-xs text-slate-400">

                                            Stock:

                                            <span class="font-semibold text-slate-600">
                                                <?= $stockValue ?>
                                            </span>

                                        </div>

                                    <?php endif; ?>

                                </div>


                                <div class="mt-4 flex items-center justify-between">

                                    <span class="font-black text-green-600 text-sm">

                                        P<?= number_format(
                                            (float) $prod['selling_price'],
                                            2
                                        ) ?>

                                    </span>


                                    <span class="w-8 h-8 rounded-lg bg-green-50 text-green-600 flex items-center justify-center font-bold text-sm">
                                        +
                                    </span>

                                </div>

                            </div>

                        <?php endforeach; ?>


                        <!-- NO PRODUCTS -->

                        <div
                            id="noProductsMessage"
                            class="hidden col-span-full py-12 text-center"
                        >

                            <div class="text-4xl mb-3">
                                --
                            </div>

                            <p class="font-bold text-slate-600">
                                No products found
                            </p>

                            <p class="text-sm text-slate-400 mt-1">
                                Try another search or category.
                            </p>

                        </div>

                    </div>

                </div>

            </div>


            <!-- ================================================= -->
            <!-- CURRENT ORDER -->
            <!-- ================================================= -->

            <div class="lg:col-span-5 xl:col-span-4">

                <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm sticky top-6">

                    <h2 class="text-lg font-black mb-4 pb-3 border-b border-slate-100">
                        Current Order
                    </h2>


                    <div
                        id="cartItemsContainer"
                        class="divide-y divide-slate-100 max-h-[300px] overflow-y-auto mb-4"
                    >

                        <div
                            id="emptyCartText"
                            class="py-8 text-center text-sm text-slate-400"
                        >
                            Cart is empty. Click items from the catalog.
                        </div>

                    </div>


                    <!-- TOTAL -->

                    <div class="border-t border-slate-200 pt-4 mb-3">

                        <div class="flex items-center justify-between text-base font-black">

                            <span>
                                Total Amount:
                            </span>

                            <span
                                id="cartTotalDisplay"
                                class="text-green-600 text-xl"
                            >
                                P0.00
                            </span>

                        </div>

                    </div>


                    <!-- CHECKOUT -->

                    <form
                        action="pos.php"
                        method="POST"
                        id="checkoutForm"
                    >

                        <input
                            type="hidden"
                            name="cart_items"
                            id="cartItemsInput"
                        >

                        <div class="mb-3">
                            <label
                                for="customerName"
                                class="block text-xs font-bold text-slate-600 uppercase mb-1"
                            >
                                Customer Name <span class="font-normal normal-case text-slate-400">(Optional)</span>
                            </label>
                            <input
                                type="text"
                                name="customer_name"
                                id="customerName"
                                maxlength="150"
                                autocomplete="name"
                                placeholder="Customer name"
                                class="w-full h-11 px-4 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"
                                value="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>

                        <div class="mb-3">
                            <label
                                for="customerPhone"
                                class="block text-xs font-bold text-slate-600 uppercase mb-1"
                            >
                                Customer Contact <span class="font-normal normal-case text-slate-400">(Optional)</span>
                            </label>
                            <input
                                type="tel"
                                name="customer_phone"
                                id="customerPhone"
                                maxlength="50"
                                autocomplete="tel"
                                placeholder="Phone number or contact"
                                class="w-full h-11 px-4 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"
                                value="<?= htmlspecialchars($customerPhone, ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>

                        <div class="mb-3">
                            <label
                                for="customerAddress"
                                class="block text-xs font-bold text-slate-600 uppercase mb-1"
                            >
                                Customer Address <span class="font-normal normal-case text-slate-400">(Optional)</span>
                            </label>
                            <input
                                type="text"
                                name="customer_address"
                                id="customerAddress"
                                maxlength="500"
                                autocomplete="street-address"
                                placeholder="Customer address"
                                class="w-full h-11 px-4 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"
                                value="<?= htmlspecialchars($customerAddress, ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>

                        <div class="mb-4">
                            <label
                                for="transactionNote"
                                class="block text-xs font-bold text-slate-600 uppercase mb-1"
                            >
                                Transaction Note <span class="font-normal normal-case text-slate-400">(Optional)</span>
                            </label>
                            <textarea
                                name="transaction_note"
                                id="transactionNote"
                                maxlength="2000"
                                rows="2"
                                placeholder="Add context or an agreed payment arrangement"
                                class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"
                            ><?= htmlspecialchars($transactionNote, ENT_QUOTES, 'UTF-8') ?></textarea>
                            <p class="mt-1 text-xs text-slate-400">
                                Note only. Checkout still requires full payment and does not track balances.
                            </p>
                        </div>

                        <div class="mb-3">

                            <label
                                for="cashTendered"
                                class="block text-xs font-bold text-slate-600 uppercase mb-1"
                            >
                                Cash Tendered (P)
                            </label>

                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="cash_tendered"
                                id="cashTendered"
                                placeholder="0.00"
                                class="w-full h-11 px-4 rounded-xl border border-slate-200 bg-slate-50 text-sm font-bold outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"
                                disabled
                            >

                        </div>


                        <div class="bg-slate-50 rounded-xl p-3 border border-slate-100 mb-4 flex items-center justify-between">

                            <span class="text-xs font-bold text-slate-500">
                                Change Due:
                            </span>

                            <span
                                id="changeDisplay"
                                class="text-sm font-black text-slate-800"
                            >
                                P0.00
                            </span>

                        </div>


                        <button
                            type="submit"
                            id="checkoutBtn"
                            disabled
                            class="w-full h-12 bg-green-600 hover:bg-green-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl text-sm font-bold shadow-lg shadow-green-600/20 transition"
                        >
                            Complete Checkout
                        </button>

                    </form>

                </div>

            </div>

        </div>

    </div>

</main>


<script>

let cart = [];

let selectedCategoryId = 'all';


const productCards =
    document.querySelectorAll('.product-card');

const productSearch =
    document.getElementById('productSearch');

const cartItemsContainer =
    document.getElementById('cartItemsContainer');

const emptyCartText =
    document.getElementById('emptyCartText');

const cartTotalDisplay =
    document.getElementById('cartTotalDisplay');

const cartItemsInput =
    document.getElementById('cartItemsInput');

const cashTenderedInput =
    document.getElementById('cashTendered');

const changeDisplay =
    document.getElementById('changeDisplay');

const checkoutBtn =
    document.getElementById('checkoutBtn');

const productCount =
    document.getElementById('productCount');

const noProductsMessage =
    document.getElementById('noProductsMessage');

const clearFilters =
    document.getElementById('clearFilters');

const categoryButtons =
    document.querySelectorAll('.category-btn');


// =========================================================
// ADD PRODUCT TO CART
// =========================================================

productCards.forEach(card => {

    card.addEventListener('click', () => {

        const id =
            parseInt(card.dataset.id, 10);

        const name =
            card.dataset.name;

        const price =
            parseFloat(card.dataset.price);

        const stock =
            parseInt(card.dataset.stock || '0', 10);

        const isEService =
            card.dataset.eService === '1';


        const existingItem =
            cart.find(item => item.id === id);


        // -----------------------------------------------------
        // E-SERVICE
        // -----------------------------------------------------

        if (isEService) {

            if (existingItem) {

                existingItem.quantity++;

            } else {

                cart.push({
                    id,
                    name,
                    price,
                    stock: 999999,
                    quantity: 1,
                    isEService: true
                });
            }

            renderCart();

            return;
        }


        // -----------------------------------------------------
        // NORMAL PRODUCT
        // -----------------------------------------------------

        if (stock <= 0) {

            alert(
                'This product is currently out of stock.'
            );

            return;
        }


        if (existingItem) {

            if (existingItem.quantity < stock) {

                existingItem.quantity++;

            } else {

                alert(
                    'Maximum available stock reached for this item.'
                );
            }

        } else {

            cart.push({
                id,
                name,
                price,
                stock,
                quantity: 1,
                isEService: false
            });
        }


        renderCart();

    });

});


// =========================================================
// RENDER CART
// =========================================================

function renderCart() {

    cartItemsContainer.innerHTML = '';


    if (cart.length === 0) {

        emptyCartText.style.display = 'block';

        cartItemsContainer.appendChild(
            emptyCartText
        );

        cartTotalDisplay.textContent =
            'P0.00';

        changeDisplay.textContent =
            'P0.00';

        cashTenderedInput.value =
            '';

        cashTenderedInput.disabled =
            true;

        checkoutBtn.disabled =
            true;

        cartItemsInput.value =
            '';

        return;
    }


    emptyCartText.style.display =
        'none';


    let total = 0;


    cart.forEach((item, index) => {

        const subtotal =
            item.price * item.quantity;

        total += subtotal;


        const row =
            document.createElement('div');


        row.className =
            'py-3 flex items-center justify-between gap-3';


        row.innerHTML = `

            <div class="flex-1 min-w-0">

                <div class="font-bold text-sm text-slate-800 truncate">
                    ${escapeHtml(item.name)}
                </div>

                <div class="text-xs text-slate-400">
                    P${item.price.toFixed(2)} each
                </div>

            </div>


            <div class="flex items-center gap-2">

                <button
                    type="button"
                    class="w-7 h-7 rounded-lg bg-slate-100 font-bold text-slate-600 hover:bg-slate-200 transition decrease-qty"
                    data-index="${index}"
                >
                    -
                </button>


                <span class="text-sm font-bold w-6 text-center">
                    ${item.quantity}
                </span>


                <button
                    type="button"
                    class="w-7 h-7 rounded-lg bg-slate-100 font-bold text-slate-600 hover:bg-slate-200 transition increase-qty"
                    data-index="${index}"
                >
                    +
                </button>

            </div>


            <div class="text-right font-bold text-sm text-slate-900 w-20 shrink-0">
                P${subtotal.toFixed(2)}
            </div>

            <button
                type="button"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-red-600 transition hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-300 remove-cart-item"
                data-index="${index}"
                aria-label="Remove item from current order"
                title="Remove item"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12m-10 0 .7 13h6.6L16 7M9 7V4h6v3m-5 4v5m4-5v5" />
                </svg>
            </button>

        `;


        cartItemsContainer.appendChild(row);

    });


    cartTotalDisplay.textContent =
        'P' +
        total.toLocaleString(
            'en-US',
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        );


    // ---------------------------------------------------------
    // Only send required checkout fields to PHP
    // ---------------------------------------------------------

    cartItemsInput.value =
        JSON.stringify(
            cart.map(item => ({
                id: item.id,
                quantity: item.quantity
            }))
        );


    cashTenderedInput.disabled =
        false;

    checkoutBtn.disabled =
        false;


    calculateChange(total);


    // =========================================================
    // INCREASE QUANTITY
    // =========================================================

    document
        .querySelectorAll('.increase-qty')
        .forEach(button => {

            button.addEventListener(
                'click',
                event => {

                    const index =
                        parseInt(
                            event.currentTarget.dataset.index,
                            10
                        );

                    const item =
                        cart[index];


                    if (!item) {
                        return;
                    }


                    if (item.isEService) {

                        item.quantity++;

                        renderCart();

                        return;
                    }


                    if (
                        item.quantity <
                        item.stock
                    ) {

                        item.quantity++;

                        renderCart();

                    } else {

                        alert(
                            'Reached available stock limit.'
                        );
                    }

                }
            );

        });


    // =========================================================
    // DECREASE QUANTITY
    // =========================================================

    document
        .querySelectorAll('.decrease-qty')
        .forEach(button => {

            button.addEventListener(
                'click',
                event => {

                    const index =
                        parseInt(
                            event.currentTarget.dataset.index,
                            10
                        );


                    if (!cart[index]) {
                        return;
                    }


                    cart[index].quantity--;


                    if (
                        cart[index].quantity <= 0
                    ) {

                        cart.splice(index, 1);
                    }


                    renderCart();

                }
            );

        });


    // =========================================================
    // REMOVE ITEM FROM ORDER
    // =========================================================

    document
        .querySelectorAll('.remove-cart-item')
        .forEach(button => {

            button.addEventListener(
                'click',
                event => {

                    const index = parseInt(
                        event.currentTarget.dataset.index,
                        10
                    );

                    if (!cart[index]) {
                        return;
                    }

                    cart.splice(index, 1);
                    renderCart();
                }
            );

        });

}


// =========================================================
// CALCULATE CHANGE
// =========================================================

function calculateChange(totalAmount) {

    const rawVal =
        cashTenderedInput.value.trim();


    let cashTendered =
        parseFloat(rawVal);


    if (
        rawVal === '' ||
        isNaN(cashTendered) ||
        cashTendered <= 0
    ) {

        cashTendered =
            totalAmount;
    }


    const change =
        cashTendered - totalAmount;


    if (change >= 0) {

        changeDisplay.textContent =
            'P' +
            change.toLocaleString(
                'en-US',
                {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }
            );

        changeDisplay.classList.remove(
            'text-red-600'
        );

        changeDisplay.classList.add(
            'text-slate-800'
        );

    } else {

        changeDisplay.textContent =
            'P0.00';

        changeDisplay.classList.remove(
            'text-slate-800'
        );

        changeDisplay.classList.add(
            'text-red-600'
        );
    }

}


// =========================================================
// CASH INPUT
// =========================================================

cashTenderedInput.addEventListener(
    'input',
    () => {

        let total = 0;


        cart.forEach(item => {

            total +=
                item.price *
                item.quantity;

        });


        calculateChange(total);

    }
);


// =========================================================
// FILTER PRODUCTS
// =========================================================

function filterProducts() {

    const query =
        productSearch.value
            .toLowerCase()
            .trim();


    let visibleCount = 0;


    productCards.forEach(card => {

        const name =
            card.dataset.name
                .toLowerCase();


        const categoryId =
            card.dataset.categoryId;


        const categoryName =
            card.dataset.categoryName
                .toLowerCase();


        const matchesSearch =
            name.includes(query) ||
            categoryName.includes(query);


        const matchesCategory =
            selectedCategoryId === 'all' ||
            categoryId === selectedCategoryId;


        if (
            matchesSearch &&
            matchesCategory
        ) {

            card.style.display =
                'flex';

            visibleCount++;

        } else {

            card.style.display =
                'none';
        }

    });


    productCount.textContent =
        visibleCount +
        (
            visibleCount === 1
                ? ' product'
                : ' products'
        );


    noProductsMessage.classList.toggle(
        'hidden',
        visibleCount !== 0
    );

}


// =========================================================
// SEARCH
// =========================================================

productSearch.addEventListener(
    'input',
    filterProducts
);


// =========================================================
// CATEGORY BUTTONS
// =========================================================

categoryButtons.forEach(button => {

    button.addEventListener(
        'click',
        function() {

            selectedCategoryId =
                this.dataset.categoryId;


            categoryButtons.forEach(btn => {

                btn.classList.remove(
                    'bg-green-600',
                    'text-white'
                );

                btn.classList.add(
                    'bg-slate-100',
                    'text-slate-600'
                );

            });


            this.classList.remove(
                'bg-slate-100',
                'text-slate-600'
            );

            this.classList.add(
                'bg-green-600',
                'text-white'
            );


            filterProducts();

        }
    );

});


// =========================================================
// CLEAR FILTERS
// =========================================================

clearFilters.addEventListener(
    'click',
    () => {

        selectedCategoryId =
            'all';

        productSearch.value =
            '';


        categoryButtons.forEach(button => {

            button.classList.remove(
                'bg-green-600',
                'text-white'
            );

            button.classList.add(
                'bg-slate-100',
                'text-slate-600'
            );

        });


        const allButton =
            document.querySelector(
                '[data-category-id="all"]'
            );


        if (allButton) {

            allButton.classList.remove(
                'bg-slate-100',
                'text-slate-600'
            );

            allButton.classList.add(
                'bg-green-600',
                'text-white'
            );

        }


        filterProducts();

    }
);


// =========================================================
// CHECKOUT
// =========================================================

document
    .getElementById('checkoutForm')
    .addEventListener(
        'submit',
        event => {

            if (cart.length === 0) {

                event.preventDefault();

                alert(
                    'Your cart is empty.'
                );

                return;
            }


            let total = 0;


            cart.forEach(item => {

                total +=
                    item.price *
                    item.quantity;

            });


            const rawVal =
                cashTenderedInput.value.trim();


            let cashTendered =
                parseFloat(rawVal);


            if (
                rawVal === '' ||
                isNaN(cashTendered) ||
                cashTendered <= 0
            ) {

                cashTendered =
                    total;
            }


            if (cashTendered < total) {

                event.preventDefault();

                alert(
                    'Insufficient cash tendered. Please enter a valid amount.'
                );

                cashTenderedInput.focus();

                return;
            }


            const change =
                cashTendered - total;


            const confirmed =
                confirm(

                    'Complete this transaction?\n\n' +

                    'Total: P' +
                    total.toLocaleString(
                        'en-US',
                        {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        }
                    ) +

                    '\nCash Tendered: P' +
                    cashTendered.toLocaleString(
                        'en-US',
                        {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        }
                    ) +

                    '\nChange Due: P' +
                    change.toLocaleString(
                        'en-US',
                        {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        }
                    )
                );


            if (!confirmed) {

                event.preventDefault();

            }

        }
    );


// =========================================================
// ESCAPE HTML
// =========================================================

function escapeHtml(value) {

    const div =
        document.createElement('div');

    div.textContent =
        value;

    return div.innerHTML;
}


// =========================================================
// INITIALIZE
// =========================================================

filterProducts();
renderCart();

const checkoutSuccessMessage =
    document.getElementById('checkoutSuccessMessage');

if (checkoutSuccessMessage) {
    window.setTimeout(() => {
        checkoutSuccessMessage.classList.add('opacity-0');
        window.setTimeout(() => checkoutSuccessMessage.remove(), 500);
    }, 4500);
}

</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
