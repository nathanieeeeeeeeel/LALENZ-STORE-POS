<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/system.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/product_audit.php';
require_once __DIR__ . '/../config/action_log.php';
require_once __DIR__ . '/../config/product_sku.php';

// =========================================================
// AUTHENTICATION
// =========================================================

$userId = fetchUser();


// =========================================================
// GET CURRENT USER
// =========================================================

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


// =========================================================
$fullName =
    $user['full_name']
    ?? $user['name']
    ?? $user['username']
    ?? 'User';


// =========================================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !hasAppPermission($pdo, $userId, 'products.manage')
) {
    appLogAction($pdo, $userId, 'product.created', 'product', null, trim((string) ($_POST['name'] ?? '')) ?: null, 'failure', [
        'reason' => 'Permission denied.',
    ]);
}
requireAppPermission($pdo, $userId, 'products.manage');
ensureProductAuditColumns($pdo);
ensureActionLogTable($pdo);


// =========================================================
// LOAD CATEGORIES
// =========================================================

$stmt = $pdo->query("
    SELECT id, name
    FROM categories
    ORDER BY name ASC
");

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// FORM VALUES
// =========================================================

$barcode = '';
$sku = '';
$name = '';
$description = '';
$categoryId = '';
$unit = 'piece';
$costPrice = '0.00';
$sellingPrice = '0.00';
$stock = '0';
$reorderLevel = '5';
$isActive = 1;

$errors = [];
$success = '';


// =========================================================
// HANDLE FORM SUBMISSION
// =========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // -----------------------------------------------------
    // Get submitted values
    // -----------------------------------------------------

    $barcode = trim($_POST['barcode'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $categoryId = filter_input(
        INPUT_POST,
        'category_id',
        FILTER_VALIDATE_INT
    );

    $unit = trim($_POST['unit'] ?? 'piece');

    $costPrice = trim($_POST['cost_price'] ?? '');
    $sellingPrice = trim($_POST['selling_price'] ?? '');
    $stock = trim($_POST['stock'] ?? '');
    $reorderLevel = trim($_POST['reorder_level'] ?? '');

    $isActive = isset($_POST['is_active']) ? 1 : 0;


    // =====================================================
    // VALIDATION
    // =====================================================

    // -----------------------------------------------------
    // Product name
    // -----------------------------------------------------

    if ($name === '') {
        $errors[] = 'Product name is required.';
    }

    if (mb_strlen($description) > 5000) {
        $errors[] = 'Description cannot exceed 5000 characters.';
    }


    // -----------------------------------------------------
    // Category
    // -----------------------------------------------------

    if (
        $categoryId === false ||
        $categoryId === null ||
        $categoryId <= 0
    ) {
        $errors[] = 'Please select a category.';
    }

    // =====================================================
    // LOAD & E-SERVICES UNIT
    // =====================================================

    if (
        $categoryId !== false &&
        $categoryId !== null &&
        $categoryId > 0
    ) {

        $stmt = $pdo->prepare("
            SELECT name
            FROM categories
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$categoryId]);

        $selectedCategory = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            $selectedCategory &&
            strtolower(trim($selectedCategory['name'])) === 'load & e-services'
        ) {
            $unit = 'N/A';
        }
    }



    // -----------------------------------------------------
    // Unit
    // -----------------------------------------------------

    $allowedUnits = [
        'piece',
        'pack',
        'bottle',
        'can',
        'kg',
        'gram',
        'sachet',
        'pouch',
        'box',
        'liter',
        'N/A'
    ];

    if ($unit === '') {

        $errors[] = 'Unit is required.';

    } elseif (!in_array($unit, $allowedUnits, true)) {

        $errors[] = 'Please select a valid unit of measurement.';
    }

    // -----------------------------------------------------
    // Cost price
    // -----------------------------------------------------

    if ($costPrice === '') {

        $errors[] = 'Cost price is required.';

    } elseif (!is_numeric($costPrice)) {

        $errors[] = 'Please enter a valid cost price.';

    } elseif ((float) $costPrice < 0) {

        $errors[] = 'Cost price cannot be negative.';
    }


    // -----------------------------------------------------
    // Selling price
    // -----------------------------------------------------

    if ($sellingPrice === '') {

        $errors[] = 'Selling price is required.';

    } elseif (!is_numeric($sellingPrice)) {

        $errors[] = 'Please enter a valid selling price.';

    } elseif ((float) $sellingPrice < 0) {

        $errors[] = 'Selling price cannot be negative.';
    }


    // -----------------------------------------------------
    // Stock
    // -----------------------------------------------------

    if ($stock === '') {

        $errors[] = 'Stock quantity is required.';

    } elseif (!ctype_digit($stock)) {

        $errors[] = 'Stock quantity must be a whole number.';
    }


    // -----------------------------------------------------
    // Reorder level
    // -----------------------------------------------------

    if ($reorderLevel === '') {

        $errors[] = 'Reorder level is required.';

    } elseif (!is_numeric($reorderLevel)) {

        $errors[] = 'Please enter a valid reorder level.';

    } elseif ((float) $reorderLevel < 0) {

        $errors[] = 'Reorder level cannot be negative.';
    }


    // =====================================================
    // VERIFY CATEGORY
    // =====================================================

    if (empty($errors)) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM categories
            WHERE id = ?
        ");

        $stmt->execute([$categoryId]);

        if (!$stmt->fetch()) {
            $errors[] = 'Selected category does not exist.';
        }
    }


    // =====================================================
    // CHECK DUPLICATE BARCODE
    // =====================================================

    if (empty($errors) && $barcode !== '') {

        $stmt = $pdo->prepare("
            SELECT id
            FROM products
            WHERE barcode = ?
            LIMIT 1
        ");

        $stmt->execute([$barcode]);

        if ($stmt->fetch()) {
            $errors[] = 'A product with this barcode already exists.';
        }
    }


    // =====================================================
    // CHECK DUPLICATE SKU
    // =====================================================

    if (empty($errors) && $sku !== '') {

        $stmt = $pdo->prepare("
            SELECT id
            FROM products
            WHERE sku = ?
            LIMIT 1
        ");

        $stmt->execute([$sku]);

        if ($stmt->fetch()) {
            $errors[] = 'A product with this SKU already exists.';
        }
    }


    // =====================================================
    // INSERT PRODUCT
    // =====================================================

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();
            $lockCategory = $pdo->prepare('SELECT id FROM categories WHERE id = ? FOR UPDATE');
            $lockCategory->execute([$categoryId]);
            if (!$lockCategory->fetchColumn()) {
                throw new RuntimeException('Selected category no longer exists.');
            }
            if ($sku === '') {
                $sku = generateProductSku($pdo, (int) $categoryId);
            }

            $stmt = $pdo->prepare("
                INSERT INTO products (
                    category_id,
                    barcode,
                    sku,
                    name,
                    description,
                    unit,
                    cost_price,
                    selling_price,
                    stock,
                    reorder_level,
                    is_active,
                    modified_by
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
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            $stmt->execute([
                $categoryId,
                $barcode !== '' ? $barcode : null,
                $sku !== '' ? $sku : null,
                $name,
                $description !== '' ? $description : null,
                $unit,
                (float) $costPrice,
                (float) $sellingPrice,
                (int) $stock,
                (float) $reorderLevel,
                $isActive,
                $userId
            ]);

            $productId = (int) $pdo->lastInsertId();
            appLogAction($pdo, $userId, 'product.created', 'product', $productId, $name, 'success', [
                'sku' => $sku !== '' ? $sku : null,
                'category_id' => $categoryId,
            ]);
            $pdo->commit();

            // -------------------------------------------------
            // Success
            // -------------------------------------------------

            $success = 'Product added successfully.';


            // -------------------------------------------------
            // Reset form
            // -------------------------------------------------

            $barcode = '';
            $sku = '';
            $name = '';
            $description = '';
            $categoryId = '';
            $unit = 'piece';
            $costPrice = '0.00';
            $sellingPrice = '0.00';
            $stock = '0';
            $reorderLevel = '5';
            $isActive = 1;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Handle duplicate barcode/SKU safely
            if ($e instanceof PDOException && $e->getCode() === '23000') {

                $errors[] = 'Barcode or SKU already exists. Please use a different value.';

            } else {

                $errors[] = 'Unable to add the product. Please try again.';

                // DEVELOPMENT ONLY:
                // $errors[] = $e->getMessage();
            }
        }
    }

    if ($errors) {
        appLogAction($pdo, $userId, 'product.created', 'product', null, $name !== '' ? $name : null, 'failure', [
            'errors' => $errors,
            'sku' => $sku !== '' ? $sku : null,
        ]);
    }
}


// =========================================================
// CURRENT PAGE
// =========================================================

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

    <title>Add Product | <?= htmlspecialchars($systemName) ?></title>

    <meta
        name="description"
        content="Add a new product to <?= htmlspecialchars($systemName) ?> inventory."
    >
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
        html {
            scroll-behavior: smooth;
        }

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

    </style>
</head>

<body class="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

    <!-- =========================================================
         LAYOUT
    ========================================================== -->

        <!-- =====================================================
             SIDEBAR
        ====================================================== -->

        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <!-- =====================================================
             MAIN CONTENT
        ====================================================== -->

        <main class="lg:ml-64 min-h-screen dark:bg-slate-950">


            <!-- =========================================================
                HEADER
            ========================================================== -->

            <header class="h-20 bg-white border-b border-slate-200 dark:bg-slate-900 dark:border-slate-700">
                <div class="h-full px-4 sm:px-6 lg:px-8 flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-black tracking-tight">Add Product</h1>
                        <p class="text-sm text-slate-500 mt-1">Add a new item to your store inventory.</p>
                    </div>
                    <div class="flex items-center gap-3">
                    </div>
                </div>
            </header>

            <!-- =================================================
                 CONTENT
            ================================================== -->

            <div class="p-6 lg:p-8">


                <!-- Page heading -->
                <div class="mb-8">

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

                        <div>

                            <div class="flex items-center gap-3">

                                <div
                                    class="w-12 h-12 rounded-2xl bg-green-100 text-green-700 flex items-center justify-center"
                                >

                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="w-6 h-6"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="1.8"
                                            d="M12 5v14M5 12h14"
                                        />
                                    </svg>

                                </div>

                                <div>

                                    <h1 class="text-2xl sm:text-3xl font-black tracking-tight">
                                        Add Product
                                    </h1>

                                    <p class="mt-1 text-sm text-slate-500">
                                        Add a new item to your store inventory.
                                    </p>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- =================================================
                     SUCCESS MESSAGE
                ================================================== -->

                <?php if ($success): ?>

                    <div
                        class="mb-6 rounded-2xl border border-green-200 bg-green-50 p-4 flex items-start gap-3"
                    >

                        <div
                            class="w-9 h-9 rounded-xl bg-green-100 text-green-700 flex items-center justify-center flex-shrink-0"
                        >

                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="w-5 h-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M5 13l4 4L19 7"
                                />
                            </svg>

                        </div>

                        <div>

                            <p class="font-bold text-green-800">
                                Success
                            </p>

                            <p class="text-sm text-green-700 mt-1">
                                <?= htmlspecialchars($success) ?>
                            </p>

                        </div>

                    </div>

                <?php endif; ?>


                <!-- =================================================
                     ERROR MESSAGES
                ================================================== -->

                <?php if (!empty($errors)): ?>

                    <div
                        class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4"
                    >

                        <div class="flex items-start gap-3">

                            <div
                                class="w-9 h-9 rounded-xl bg-red-100 text-red-600 flex items-center justify-center flex-shrink-0"
                            >

                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="w-5 h-5"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M12 9v3m0 4h.01M10.29 3.86l-8.82 15a2 2 0 001.71 3h17.64a2 2 0 001.71-3l-8.82-15a2 2 0 00-3.42 0z"
                                    />
                                </svg>

                            </div>

                            <div>

                                <p class="font-bold text-red-800">
                                    Please fix the following:
                                </p>

                                <ul class="mt-2 space-y-1 text-sm text-red-700 list-disc list-inside">

                                    <?php foreach ($errors as $error): ?>

                                        <li>
                                            <?= htmlspecialchars($error) ?>
                                        </li>

                                    <?php endforeach; ?>

                                </ul>

                            </div>

                        </div>

                    </div>

                <?php endif; ?>


                <!-- =================================================
                     FORM
                ================================================== -->

                <form
                    action="product_add.php"
                    method="POST"
                    class="space-y-6"
                >


                    <!-- =================================================
                         BASIC INFORMATION
                    ================================================== -->

                    <div
                        class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden"
                    >

                        <div
                            class="px-6 py-5 border-b border-slate-100"
                        >

                            <h2 class="text-lg font-black text-slate-900">
                                Basic Information
                            </h2>

                            <p class="text-sm text-slate-500 mt-1">
                                Enter the basic details of the product.
                            </p>

                        </div>


                        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-5">


                            <!-- Product Name -->
                            <div class="md:col-span-2">

                                <label
                                    for="name"
                                    class="block text-sm font-bold text-slate-700 mb-2"
                                >
                                    Product Name
                                    <span class="text-red-500">*</span>
                                </label>

                                <input
                                    type="text"
                                    id="name"
                                    name="name"
                                    value="<?= htmlspecialchars($name) ?>"
                                    placeholder="e.g. Energen Choco"
                                    maxlength="150"
                                    required
                                    autofocus
                                    class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                                >

                            </div>


                            <!-- Category -->
                            <div>

                                <label
                                    for="category_id"
                                    class="block text-sm font-bold text-slate-700 mb-2"
                                >
                                    Category
                                    <span class="text-red-500">*</span>
                                </label>

                                <select
                                    id="category_id"
                                    name="category_id"
                                    required
                                    class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                                >

                                    <option value="">
                                        Select category
                                    </option>

                                    <?php foreach ($categories as $category): ?>

                                        <option
                                            value="<?= (int) $category['id'] ?>"
                                            <?= (string) $categoryId === (string) $category['id'] ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($category['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>


                            <!-- Unit -->
                            <div>

                                <label
                                    for="unit"
                                    class="block text-sm font-bold text-slate-700 mb-2"
                                >
                                    Unit of Measurement
                                    <span class="text-red-500">*</span>
                                </label>

                                <select
                                    id="unit"
                                    name="unit"
                                    required
                                    class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                                >

                                    <option value="piece" <?= ($unit ?? '') === 'piece' ? 'selected' : '' ?>>
                                        Piece
                                    </option>

                                    <option value="pack" <?= ($unit ?? '') === 'pack' ? 'selected' : '' ?>>
                                        Pack
                                    </option>

                                    <option value="bottle" <?= ($unit ?? '') === 'bottle' ? 'selected' : '' ?>>
                                        Bottle
                                    </option>

                                    <option value="can" <?= ($unit ?? '') === 'can' ? 'selected' : '' ?>>
                                        Can
                                    </option>

                                    <option value="kg" <?= ($unit ?? '') === 'kg' ? 'selected' : '' ?>>
                                        Kilogram (kg)
                                    </option>

                                    <option value="gram" <?= ($unit ?? '') === 'gram' ? 'selected' : '' ?>>
                                        Gram (g)
                                    </option>

                                    <option value="sachet" <?= ($unit ?? '') === 'sachet' ? 'selected' : '' ?>>
                                        Sachet
                                    </option>

                                    <option value="pouch" <?= ($unit ?? '') === 'pouch' ? 'selected' : '' ?>>
                                        Pouch
                                    </option>

                                    <option value="box" <?= ($unit ?? '') === 'box' ? 'selected' : '' ?>>
                                        Box
                                    </option>

                                    <option value="liter" <?= ($unit ?? '') === 'liter' ? 'selected' : '' ?>>
                                        Liter
                                    </option>

                                    <option value="N/A" <?= ($unit ?? '') === 'N/A' ? 'selected' : '' ?>>
                                        N/A
                                    </option>

                                </select>

                                <p
                                    id="unitHelpText"
                                    class="mt-1.5 text-xs text-slate-400"
                                >
                                    Select the appropriate unit for this product.
                                </p>

                            </div>



                            <!-- Barcode -->
                            <div>

                                <label
                                    for="barcode"
                                    class="block text-sm font-bold text-slate-700 mb-2"
                                >
                                    Barcode
                                    <span class="font-normal text-slate-400">
                                        (auto-generated if blank)
                                    </span>
                                </label>

                                <input
                                    type="text"
                                    id="barcode"
                                    name="barcode"
                                    value="<?= htmlspecialchars($barcode) ?>"
                                    maxlength="100"
                                    placeholder="Scan or enter barcode"
                                    class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                                >

                            </div>


                            <!-- SKU -->
                            <div>

                                <label
                                    for="sku"
                                    class="block text-sm font-bold text-slate-700 mb-2"
                                >
                                    SKU
                                    <span class="font-normal text-slate-400">
                                        (leave blank to auto-assign)
                                    </span>
                                </label>

                                <input
                                    type="text"
                                    id="sku"
                                    name="sku"
                                    value="<?= htmlspecialchars($sku) ?>"
                                    maxlength="50"
                                    placeholder="Auto-assigned by category"
                                    class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                                >

                            </div>


                            <!-- Description -->
                            <div class="md:col-span-2">

                                <label
                                    for="description"
                                    class="block text-sm font-bold text-slate-700 mb-2"
                                >
                                    Description
                                    <span class="font-normal text-slate-400">
                                        (optional)
                                    </span>
                                </label>

                                <textarea
                                    id="description"
                                    name="description"
                                    rows="3"
                                    maxlength="5000"
                                    placeholder="Add a short product description..."
                                    class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-white text-sm outline-none resize-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                                ><?= htmlspecialchars($description) ?></textarea>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         PRICING & INVENTORY
                    ================================================== -->

                    <div
                        class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden"
                    >

                        <div
                            class="px-6 py-5 border-b border-slate-100"
                        >

                            <h2 class="text-lg font-black text-slate-900">
                                Pricing & Inventory
                            </h2>

                            <p class="text-sm text-slate-500 mt-1">
                                Set the product cost, selling price, and stock.
                            </p>

                        </div>


                        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">


                            <!-- Cost Price -->
                            <div>

                                <label
                                    for="cost_price"
                                    class="block text-sm font-bold text-slate-700 mb-2"
                                >
                                    Cost Price
                                    <span class="text-red-500">*</span>
                                </label>

                                <div class="relative">

                                    <span
                                        class="absolute inset-y-0 left-0 flex items-center pl-4 text-sm font-bold text-slate-400"
                                    >
                                        ₱
                                    </span>

                                    <input
                                        type="number"
                                        id="cost_price"
                                        name="cost_price"
                                        value="<?= htmlspecialchars($costPrice) ?>"
                                        min="0"
                                        step="0.01"
                                        required
                                        class="w-full h-12 pl-9 pr-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                                    >

                                </div>

                                <p class="mt-1.5 text-xs text-slate-400">
                                    Your purchase cost.
                                </p>

                            </div>


                            <!-- Selling Price -->
                            <div>

                                <label
                                    for="selling_price"
                                    class="block text-sm font-bold text-slate-700 mb-2"
                                >
                                    Selling Price
                                    <span class="text-red-500">*</span>
                                </label>

                                <div class="relative">

                                    <span
                                        class="absolute inset-y-0 left-0 flex items-center pl-4 text-sm font-bold text-green-600"
                                    >
                                        ₱
                                    </span>

                                    <input
                                        type="number"
                                        id="selling_price"
                                        name="selling_price"
                                        value="<?= htmlspecialchars($sellingPrice) ?>"
                                        min="0"
                                        step="0.01"
                                        required
                                        class="w-full h-12 pl-9 pr-4 rounded-xl border border-slate-200 bg-white text-sm font-semibold outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                                    >

                                </div>

                                <p class="mt-1.5 text-xs text-slate-400">
                                    Customer selling price.
                                </p>

                            </div>


                            <!-- Stock -->
                            <div>

                                <label
                                    for="stock"
                                    class="block text-sm font-bold text-slate-700 mb-2"
                                >
                                    Initial Stock
                                    <span class="text-red-500">*</span>
                                </label>

                                <input
                                    type="number"
                                    id="stock"
                                    name="stock"
                                    value="<?= htmlspecialchars($stock) ?>"
                                    min="0"
                                    step="1"
                                    required
                                    class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                                >

                                <p class="mt-1.5 text-xs text-slate-400">
                                    Whole pieces only.
                                </p>

                            </div>


                            <!-- Reorder Level -->
                            <div>

                                <label
                                    for="reorder_level"
                                    class="block text-sm font-bold text-slate-700 mb-2"
                                >
                                    Reorder Level
                                    <span class="text-red-500">*</span>
                                </label>

                                <input
                                    type="number"
                                    id="reorder_level"
                                    name="reorder_level"
                                    value="<?= htmlspecialchars($reorderLevel) ?>"
                                    min="0"
                                    step="0.001"
                                    required
                                    class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                                >

                                <p class="mt-1.5 text-xs text-slate-400">
                                    Alert when stock reaches this level.
                                </p>

                            </div>


                            <!-- Profit Preview -->
                            <div
                                class="sm:col-span-2 lg:col-span-2 rounded-2xl bg-green-50 border border-green-100 p-4"
                            >

                                <div class="flex items-center gap-3">

                                    <div
                                        class="w-10 h-10 rounded-xl bg-green-100 text-green-700 flex items-center justify-center"
                                    >

                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="w-5 h-5"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="1.8"
                                                d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"
                                            />
                                        </svg>

                                    </div>

                                    <div>

                                        <p class="text-xs font-semibold text-green-700">
                                            Estimated Profit Per Unit
                                        </p>

                                        <p
                                            id="profitPreview"
                                            class="text-xl font-black text-green-800"
                                        >
                                            ₱0.00
                                        </p>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         STATUS
                    ================================================== -->

                    <div
                        class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6"
                    >

                        <div class="flex items-center justify-between gap-4">

                            <div>

                                <h2 class="text-base font-black text-slate-900">
                                    Product Status
                                </h2>

                                <p class="text-sm text-slate-500 mt-1">
                                    Active products can be sold through the POS.
                                </p>

                            </div>


                            <label
                                class="relative inline-flex items-center cursor-pointer"
                            >

                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    class="sr-only peer"
                                    <?= $isActive ? 'checked' : '' ?>
                                >

                                <div
                                    class="w-12 h-7 bg-slate-200 rounded-full peer peer-checked:bg-green-600 transition"
                                ></div>

                                <div
                                    class="absolute left-1 top-1 w-5 h-5 bg-white rounded-full shadow-sm transition peer-checked:translate-x-5"
                                ></div>

                            </label>

                        </div>

                    </div>


                    <!-- =================================================
                         ACTIONS
                    ================================================== -->

                    <div
                        class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3"
                    >

                        <a
                            href="/LALENZ_SARISARI_POS/products.php"
                            class="inline-flex items-center justify-center gap-2 h-12 px-6 rounded-xl border border-slate-200 bg-white text-slate-700 font-bold text-sm hover:bg-slate-50 transition"
                        >

                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="w-5 h-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>

                            Cancel

                        </a>


                        <button
                            type="submit"
                            class="inline-flex items-center justify-center gap-2 h-12 px-7 rounded-xl bg-green-600 hover:bg-green-700 active:bg-green-800 text-white font-bold text-sm shadow-lg shadow-green-600/20 transition"
                        >

                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="w-5 h-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 4v16m8-8H4"
                                />
                            </svg>

                            Add Product

                        </button>

                    </div>

                </form>

            </div>

        </main>


    <!-- =========================================================
         JAVASCRIPT
    ========================================================== -->

    <script>

        const costPriceInput =
            document.getElementById('cost_price');

        const sellingPriceInput =
            document.getElementById('selling_price');

        const profitPreview =
            document.getElementById('profitPreview');


        function updateProfit() {

            const cost =
                parseFloat(costPriceInput.value) || 0;

            const selling =
                parseFloat(sellingPriceInput.value) || 0;

            const profit =
                selling - cost;

            profitPreview.textContent =
                '₱' + profit.toFixed(2);

            if (profit < 0) {

                profitPreview.classList.remove(
                    'text-green-800'
                );

                profitPreview.classList.add(
                    'text-red-600'
                );

            } else {

                profitPreview.classList.remove(
                    'text-red-600'
                );

                profitPreview.classList.add(
                    'text-green-800'
                );
            }
        }


        costPriceInput.addEventListener(
            'input',
            updateProfit
        );

        sellingPriceInput.addEventListener(
            'input',
            updateProfit
        );


        updateProfit();

        const categorySelect =
            document.getElementById('category_id');

        const unitSelect =
            document.getElementById('unit');

        const unitHelpText =
            document.getElementById('unitHelpText');


        // =========================================================
        // LOAD & E-SERVICES CATEGORY HANDLING
        // =========================================================

        function updateUnitBasedOnCategory() {

            const selectedOption =
                categorySelect.options[categorySelect.selectedIndex];

            if (!selectedOption) {
                return;
            }

            const categoryName =
                selectedOption.textContent.trim().toLowerCase();

            const isLoadEServices =
                categoryName === 'load & e-services';


            if (isLoadEServices) {

                // Automatically select N/A
                unitSelect.value = 'N/A';

                // Keep the field enabled so it is submitted
                unitSelect.classList.add(
                    'bg-green-50',
                    'border-green-300',
                    'text-green-700',
                    'font-bold'
                );

                unitSelect.classList.remove(
                    'bg-white'
                );

                unitHelpText.textContent =
                    'Load & E-Services products automatically use N/A as the unit of measurement.';

            } else {

                unitSelect.classList.remove(
                    'bg-green-50',
                    'border-green-300',
                    'text-green-700',
                    'font-bold'
                );

                unitSelect.classList.add(
                    'bg-white'
                );

                if (unitSelect.value === 'N/A') {
                    unitSelect.value = 'piece';
                }

                unitHelpText.textContent =
                    'Select the appropriate unit for this product.';
            }
        }
        
        // Run when category changes
        categorySelect.addEventListener(
            'change',
            updateUnitBasedOnCategory
        );


        // Run once when page loads
        updateUnitBasedOnCategory();


    </script>

    <?php include __DIR__ . '/../includes/footer.php'; ?>

</body>
</html>