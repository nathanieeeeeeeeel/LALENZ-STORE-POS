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

    header('Location: /LALENZ_SARISARI_POS/login.php');
    exit;
}


// =========================================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !hasAppPermission($pdo, $userId, 'products.manage')
) {
    appLogAction($pdo, $userId, 'product.updated', 'product', (int) ($_GET['id'] ?? 0) ?: null, trim((string) ($_POST['name'] ?? '')) ?: null, 'failure', [
        'reason' => 'Permission denied.',
    ]);
}
requireAppPermission($pdo, $userId, 'products.manage');
ensureProductAuditColumns($pdo);
ensureActionLogTable($pdo);


// =========================================================
// PRODUCT ID
// =========================================================

$productId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (
    $productId === false ||
    $productId === null ||
    $productId <= 0
) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        appLogAction($pdo, $userId, 'product.updated', 'product', null, trim((string) ($_POST['name'] ?? '')) ?: null, 'failure', [
            'reason' => 'Invalid product ID.',
        ]);
    }

    header('Location: /LALENZ_SARISARI_POS/products.php');
    exit;
}


// =========================================================
// LOAD PRODUCT
// =========================================================

$stmt = $pdo->prepare("
    SELECT *
    FROM products
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$productId]);

$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        appLogAction($pdo, $userId, 'product.updated', 'product', (int) $productId, trim((string) ($_POST['name'] ?? '')) ?: null, 'failure', [
            'reason' => 'Product not found.',
        ]);
    }

    header('Location: /LALENZ_SARISARI_POS/products.php');
    exit;
}


// =========================================================
// LOAD CATEGORIES
// =========================================================

$stmt = $pdo->query("
    SELECT
        id,
        name
    FROM categories
    ORDER BY name ASC
");

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// FORM VALUES
// =========================================================

$name = $product['name'] ?? '';

$sku = $product['sku'] ?? '';

$barcode = $product['barcode'] ?? '';

$description = $product['description'] ?? '';

$categoryId = $product['category_id'] ?? '';

$unit = $product['unit'] ?? 'piece';

$costPrice = $product['cost_price'] ?? '0.00';

$sellingPrice = $product['selling_price'] ?? '0.00';

$stock = $product['stock'] ?? 0;

$reorderLevel = $product['reorder_level'] ?? '5.000';

$isActive = (int) ($product['is_active'] ?? 1);


// =========================================================
// ALLOWED UNITS
// =========================================================

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
    'liter'
];


// =========================================================
// ERRORS / SUCCESS
// =========================================================

$errors = [];

$success = '';


// =========================================================
// HANDLE FORM SUBMISSION
// =========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // =====================================================
    // GET SUBMITTED VALUES
    // =====================================================

    $name = trim($_POST['name'] ?? '');

    $sku = trim($_POST['sku'] ?? '');

    $barcode = trim($_POST['barcode'] ?? '');

    $description = trim($_POST['description'] ?? '');

    $categoryId = filter_input(
        INPUT_POST,
        'category_id',
        FILTER_VALIDATE_INT
    );

    $unit = trim($_POST['unit'] ?? 'piece');

    $costPrice = trim($_POST['cost_price'] ?? '');

    $sellingPrice = trim($_POST['price'] ?? '');

    $stock = trim($_POST['stock'] ?? '');

    $reorderLevel = trim($_POST['reorder_level'] ?? (string) $reorderLevel);

    $isActive = isset($_POST['is_active']) ? 1 : 0;


    // =====================================================
    // VALIDATE PRODUCT NAME
    // =====================================================

    if ($name === '') {

        $errors[] = 'Product name is required.';

    } elseif (mb_strlen($name) > 150) {

        $errors[] = 'Product name cannot exceed 150 characters.';
    }


    // =====================================================
    // VALIDATE SKU
    // =====================================================

    if (mb_strlen($sku) > 100) {

        $errors[] = 'SKU cannot exceed 100 characters.';
    }


    // =====================================================
    // VALIDATE BARCODE
    // =====================================================

    if (mb_strlen($barcode) > 100) {

        $errors[] = 'Barcode cannot exceed 100 characters.';
    }


    // =====================================================
    // VALIDATE DESCRIPTION
    // =====================================================

    if (mb_strlen($description) > 5000) {

        $errors[] = 'Description cannot exceed 5000 characters.';
    }


    // =====================================================
    // VALIDATE CATEGORY
    // =====================================================

    if (
        $categoryId === false ||
        $categoryId === null ||
        $categoryId <= 0
    ) {

        $errors[] = 'Please select a category.';
    }


    // =====================================================
    // VALIDATE UNIT
    // =====================================================

    if (!in_array($unit, $allowedUnits, true)) {

        $errors[] = 'Please select a valid unit of measurement.';
    }


    // =====================================================
    // VALIDATE COST PRICE
    // =====================================================

    if ($costPrice === '') {

        $errors[] = 'Cost price is required.';

    } elseif (!is_numeric($costPrice)) {

        $errors[] = 'Please enter a valid cost price.';

    } elseif ((float) $costPrice < 0) {

        $errors[] = 'Cost price cannot be negative.';
    }


    // =====================================================
    // VALIDATE SELLING PRICE
    // =====================================================

    if ($sellingPrice === '') {

        $errors[] = 'Selling price is required.';

    } elseif (!is_numeric($sellingPrice)) {

        $errors[] = 'Please enter a valid selling price.';

    } elseif ((float) $sellingPrice < 0) {

        $errors[] = 'Selling price cannot be negative.';
    }


    // =====================================================
    // VALIDATE STOCK
    // =====================================================

    if ($stock === '') {

        $errors[] = 'Stock quantity is required.';

    } elseif (!is_numeric($stock)) {

        $errors[] = 'Please enter a valid stock quantity.';

    } elseif ((float) $stock < 0) {

        $errors[] = 'Stock cannot be negative.';
    }


    // =====================================================
    // STOCK MUST BE WHOLE NUMBER
    // =====================================================

    if (
        $stock !== '' &&
        is_numeric($stock) &&
        (float) $stock != floor((float) $stock)
    ) {

        $errors[] = 'Stock quantity must be a whole number.';
    }


    // =====================================================
    // VALIDATE REORDER LEVEL
    // =====================================================

    if ($reorderLevel === '') {

        $reorderLevel = '0';
    }

    if (!is_numeric($reorderLevel)) {

        $errors[] = 'Please enter a valid reorder level.';

    } elseif ((float) $reorderLevel < 0) {

        $errors[] = 'Reorder level cannot be negative.';
    }


    // =====================================================
    // VERIFY CATEGORY EXISTS
    // =====================================================

    if (
        empty($errors) &&
        $categoryId !== false &&
        $categoryId !== null
    ) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM categories
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$categoryId]);

        if (!$stmt->fetch()) {

            $errors[] = 'Selected category does not exist.';
        }
    }


    // =====================================================
    // CHECK DUPLICATE SKU
    // =====================================================

    if (
        empty($errors) &&
        $sku !== ''
    ) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM products
            WHERE sku = ?
              AND id != ?
            LIMIT 1
        ");

        $stmt->execute([
            $sku,
            $productId
        ]);

        if ($stmt->fetch()) {

            $errors[] = 'A product with this SKU already exists.';
        }
    }


    // =====================================================
    // CHECK DUPLICATE BARCODE
    // =====================================================

    if (
        empty($errors) &&
        $barcode !== ''
    ) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM products
            WHERE barcode = ?
              AND id != ?
            LIMIT 1
        ");

        $stmt->execute([
            $barcode,
            $productId
        ]);

        if ($stmt->fetch()) {

            $errors[] = 'A product with this barcode already exists.';
        }
    }


    // =====================================================
    // UPDATE PRODUCT
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
                $sku = (string) ($product['sku'] ?? '');
                if ($sku === '') {
                    $sku = generateProductSku($pdo, (int) $categoryId);
                }
            }

            // -------------------------------------------------
            // Convert values to database-safe values
            // -------------------------------------------------

            $cleanCostPrice = number_format(
                (float) $costPrice,
                2,
                '.',
                ''
            );

            $cleanSellingPrice = number_format(
                (float) $sellingPrice,
                2,
                '.',
                ''
            );

            $cleanStock = (int) $stock;

            $cleanReorderLevel = number_format(
                (float) $reorderLevel,
                3,
                '.',
                ''
            );


            // -------------------------------------------------
            // Update product
            // -------------------------------------------------

            $stmt = $pdo->prepare("
                UPDATE products
                SET
                    category_id = ?,
                    barcode = ?,
                    sku = ?,
                    name = ?,
                    description = ?,
                    unit = ?,
                    cost_price = ?,
                    selling_price = ?,
                    stock = ?,
                    reorder_level = ?,
                    is_active = ?,
                    updated_at = CURRENT_TIMESTAMP,
                    modified_by = ?
                WHERE id = ?
            ");

            $stmt->execute([

                $categoryId,

                $barcode !== ''
                    ? $barcode
                    : null,

                $sku !== ''
                    ? $sku
                    : null,

                $name,

                $description !== ''
                    ? $description
                    : null,

                $unit,

                $cleanCostPrice,

                $cleanSellingPrice,

                $cleanStock,

                $cleanReorderLevel,

                $isActive,

                $userId,

                $productId
            ]);

            // =================================================
            // RELOAD PRODUCT
            // =================================================

            $stmt = $pdo->prepare("
                SELECT *
                FROM products
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$productId]);

            $product = $stmt->fetch(PDO::FETCH_ASSOC);


            // =================================================
            // REFRESH FORM VALUES
            // =================================================

            $name = $product['name'] ?? '';

            $sku = $product['sku'] ?? '';

            $barcode = $product['barcode'] ?? '';

            $description = $product['description'] ?? '';

            $categoryId = $product['category_id'] ?? '';

            $unit = $product['unit'] ?? 'piece';

            $costPrice = $product['cost_price'] ?? '0.00';

            $sellingPrice = $product['selling_price'] ?? '0.00';

            $stock = $product['stock'] ?? 0;

            $reorderLevel = $product['reorder_level'] ?? '5.000';

            $isActive = (int) ($product['is_active'] ?? 1);

            appLogAction($pdo, $userId, 'product.updated', 'product', $productId, $name, 'success', [
                'sku' => $sku !== '' ? $sku : null,
                'category_id' => $categoryId,
            ]);
            $pdo->commit();
            $success = 'Product updated successfully.';

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $success = '';

            // -------------------------------------------------
            // Duplicate / unique constraint
            // -------------------------------------------------

            if ($e instanceof PDOException && $e->getCode() === '23000') {

                $errors[] =
                    'The SKU or barcode is already being used by another product.';

            } else {

                $errors[] =
                    'Unable to update the product. Please try again.';

                // DEVELOPMENT ONLY:
                // $errors[] = $e->getMessage();
            }
        }
    }

    if ($errors) {
        appLogAction($pdo, $userId, 'product.updated', 'product', $productId, $name !== '' ? $name : null, 'failure', [
            'errors' => $errors,
            'sku' => $sku !== '' ? $sku : null,
        ]);
    }
}


// =========================================================
// CURRENT PAGE
// =========================================================

$currentPage = basename($_SERVER['PHP_SELF']);


// =========================================================
// CURRENT USER DISPLAY
// =========================================================
$fullName =
    $user['full_name']
    ?? $user['name']
    ?? $user['username']
    ?? 'User';

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
    Edit Product | <?= htmlspecialchars($systemName) ?>
</title>

<meta
    name="description"
    content="Edit product information"
>

<link
    rel="icon"
    href="<?= htmlspecialchars($logo) ?>"
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

</style>

</head>


<body class="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

<!-- =========================================================
     SIDEBAR
========================================================== -->

<?php include __DIR__ . '/../includes/sidebar.php'; ?>


<!-- =========================================================
     MAIN
========================================================== -->

<main class="lg:ml-64 min-h-screen dark:bg-slate-950">


<!-- =========================================================
     HEADER
========================================================== -->

<header class="h-20 bg-white border-b border-slate-200 dark:bg-slate-900 dark:border-slate-700">
    <div class="h-full px-4 sm:px-6 lg:px-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black tracking-tight">Edit Product</h1>
            <p class="text-sm text-slate-500 mt-1">Update product information and inventory.</p>
        </div>
        <div class="flex items-center gap-3">
        </div>
    </div>
</header>


<!-- =========================================================
     CONTENT
========================================================== -->

<div class="p-6 lg:p-8">

    <div class="w-full max-w-4xl mx-auto">


        <!-- =================================================
             ALERTS
        ================================================== -->

        <?php if (!empty($errors)): ?>

            <div
                class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4"
            >

                <div class="font-bold text-red-800">

                    Please fix the following:

                </div>

                <ul
                    class="mt-2 list-disc list-inside text-sm text-red-700 space-y-1"
                >

                    <?php foreach ($errors as $error): ?>

                        <li>
                            <?= htmlspecialchars($error) ?>
                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>


        <?php if ($success): ?>

            <div
                class="mb-6 rounded-2xl border border-green-200 bg-green-50 p-4"
            >

                <div
                    class="flex items-center justify-between gap-4"
                >

                    <p class="text-sm font-bold text-green-800">

                        <?= htmlspecialchars($success) ?>

                    </p>


                    <a
                        href="/LALENZ_SARISARI_POS/products.php"
                        class="text-sm font-bold text-green-700 hover:text-green-900"
                    >

                        View Products →

                    </a>

                </div>

            </div>

        <?php endif; ?>


        <!-- =================================================
             FORM CARD
        ================================================== -->

        <div
            class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm"
        >


            <!-- FORM HEADER -->

            <div
                class="px-6 py-5 border-b border-slate-200"
            >

                <div class="flex items-center gap-4">

                    <div
                        class="w-12 h-12 rounded-xl bg-green-50 text-green-600 flex items-center justify-center"
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
                                d="M11 4H6a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2v-5"
                            />

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M16.5 3.5a2.1 2.1 0 013 3L11 15l-4 1 1-4 8.5-8.5z"
                            />

                        </svg>

                    </div>


                    <div>

                        <h2 class="font-black text-lg">

                            <?= htmlspecialchars($name) ?>

                        </h2>

                        <p class="text-sm text-slate-500">

                            Product ID #<?= (int) $productId ?>

                        </p>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 FORM
            ================================================== -->

            <form
                method="POST"
                action="product_edit.php?id=<?= (int) $productId ?>"
                class="p-6 space-y-6"
            >


                <!-- =================================================
                     PRODUCT NAME
                ================================================== -->

                <div>

                    <label
                        for="name"
                        class="block text-sm font-bold text-slate-700 mb-2"
                    >

                        Product Name

                        <span class="text-red-500">
                            *
                        </span>

                    </label>


                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?= htmlspecialchars($name) ?>"
                        placeholder="Enter product name"
                        maxlength="150"
                        required
                        class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                    >

                </div>


                <!-- =================================================
                     SKU + BARCODE
                ================================================== -->

                <div
                    class="grid grid-cols-1 md:grid-cols-2 gap-5"
                >


                    <!-- SKU -->

                    <div>

                        <label
                            for="sku"
                            class="block text-sm font-bold text-slate-700 mb-2"
                        >

                            SKU

                            <span class="font-normal text-slate-400">
                                (auto-assigned if missing)
                            </span>

                        </label>


                        <input
                            type="text"
                            id="sku"
                            name="sku"
                            value="<?= htmlspecialchars($sku) ?>"
                            maxlength="100"
                            placeholder="Auto-assigned by category if blank"
                            class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                        >

                    </div>


                    <!-- BARCODE -->

                    <div>

                        <label
                            for="barcode"
                            class="block text-sm font-bold text-slate-700 mb-2"
                        >

                            Barcode

                            <span class="font-normal text-slate-400">
                                (optional)
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

                </div>


                <!-- =================================================
                     CATEGORY + UNIT
                ================================================== -->

                <div
                    class="grid grid-cols-1 md:grid-cols-2 gap-5"
                >


                    <!-- CATEGORY -->

                    <div>

                        <label
                            for="category_id"
                            class="block text-sm font-bold text-slate-700 mb-2"
                        >

                            Category

                            <span class="text-red-500">
                                *
                            </span>

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


                    <!-- UNIT -->

                    <div>

                        <label
                            for="unit"
                            class="block text-sm font-bold text-slate-700 mb-2"
                        >

                            Unit of Measurement

                            <span class="text-red-500">
                                *
                            </span>

                        </label>


                        <select
                            id="unit"
                            name="unit"
                            required
                            class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                        >

                            <option
                                value="piece"
                                <?= $unit === 'piece' ? 'selected' : '' ?>
                            >
                                Piece
                            </option>

                            <option
                                value="pack"
                                <?= $unit === 'pack' ? 'selected' : '' ?>
                            >
                                Pack
                            </option>

                            <option
                                value="bottle"
                                <?= $unit === 'bottle' ? 'selected' : '' ?>
                            >
                                Bottle
                            </option>

                            <option
                                value="can"
                                <?= $unit === 'can' ? 'selected' : '' ?>
                            >
                                Can
                            </option>

                            <option
                                value="kg"
                                <?= $unit === 'kg' ? 'selected' : '' ?>
                            >
                                Kilogram (kg)
                            </option>

                            <option
                                value="gram"
                                <?= $unit === 'gram' ? 'selected' : '' ?>
                            >
                                Gram (g)
                            </option>

                            <option
                                value="sachet"
                                <?= $unit === 'sachet' ? 'selected' : '' ?>
                            >
                                Sachet
                            </option>

                            <option
                                value="pouch"
                                <?= $unit === 'pouch' ? 'selected' : '' ?>
                            >
                                Pouch
                            </option>

                            <option
                                value="box"
                                <?= $unit === 'box' ? 'selected' : '' ?>
                            >
                                Box
                            </option>

                            <option
                                value="liter"
                                <?= $unit === 'liter' ? 'selected' : '' ?>
                                >
                                Liter
                            </option>

                        </select>

                    </div>

                </div>


                <!-- =================================================
                     DESCRIPTION
                ================================================== -->

                <div>

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
                        placeholder="Enter product description..."
                        class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-white text-sm outline-none resize-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                    ><?= htmlspecialchars($description) ?></textarea>

                </div>


                <!-- =================================================
                     PRICE + STOCK
                ================================================== -->

                <div
                    class="grid grid-cols-1 md:grid-cols-3 gap-5"
                >


                    <!-- COST PRICE -->

                    <div>

                        <label
                            for="cost_price"
                            class="block text-sm font-bold text-slate-700 mb-2"
                        >

                            Cost Price

                            <span class="text-red-500">
                                *
                            </span>

                        </label>


                        <div class="relative">

                            <span
                                class="absolute left-4 top-1/2 -translate-y-1/2 font-bold text-slate-500"
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
                                placeholder="0.00"
                                required
                                class="w-full h-12 pl-9 pr-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                            >

                        </div>

                    </div>


                    <!-- SELLING PRICE -->

                    <div>

                        <label
                            for="price"
                            class="block text-sm font-bold text-slate-700 mb-2"
                        >

                            Selling Price

                            <span class="text-red-500">
                                *
                            </span>

                        </label>


                        <div class="relative">

                            <span
                                class="absolute left-4 top-1/2 -translate-y-1/2 font-bold text-slate-500"
                            >
                                ₱
                            </span>


                            <input
                                type="number"
                                id="price"
                                name="price"
                                value="<?= htmlspecialchars($sellingPrice) ?>"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                                required
                                class="w-full h-12 pl-9 pr-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                            >

                        </div>

                    </div>


                    <!-- STOCK -->

                    <div>

                        <label
                            for="stock"
                            class="block text-sm font-bold text-slate-700 mb-2"
                        >

                            Stock Quantity

                            <span class="text-red-500">
                                *
                            </span>

                        </label>


                        <input
                            type="number"
                            id="stock"
                            name="stock"
                            value="<?= htmlspecialchars($stock) ?>"
                            min="0"
                            step="1"
                            placeholder="0"
                            required
                            class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                        >


                        <p class="mt-1.5 text-xs text-slate-400">

                            Current available quantity.

                        </p>

                    </div>

                </div>


                <!-- =================================================
                     REORDER LEVEL
                ================================================== -->

                <div
                    class="grid grid-cols-1 md:grid-cols-2 gap-5"
                >


                    <div>

                        <label
                            for="reorder_level"
                            class="block text-sm font-bold text-slate-700 mb-2"
                        >

                            Reorder Level

                            <span class="font-normal text-slate-400">
                                (optional)
                            </span>

                        </label>


                        <input
                            type="number"
                            id="reorder_level"
                            name="reorder_level"
                            value="<?= htmlspecialchars($reorderLevel) ?>"
                            min="0"
                            step="1"
                            placeholder="5"
                            class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
                        >


                        <p class="mt-1.5 text-xs text-slate-400">

                            The stock level where the product should be considered low.

                        </p>

                    </div>


                    <!-- ACTIVE STATUS -->

                    <div>

                        <label
                            class="block text-sm font-bold text-slate-700 mb-2"
                        >

                            Product Status

                        </label>


                        <label
                            class="h-12 px-4 rounded-xl border border-slate-200 bg-slate-50 flex items-center gap-3 cursor-pointer"
                        >

                            <input
                                type="checkbox"
                                name="is_active"
                                value="1"
                                <?= $isActive === 1 ? 'checked' : '' ?>
                                class="w-5 h-5 rounded border-slate-300 text-green-600 focus:ring-green-500"
                            >


                            <span>

                                <span class="block text-sm font-bold text-slate-700">

                                    Active Product

                                </span>

                                <span class="block text-xs text-slate-400">

                                    Product can be used in sales.

                                </span>

                            </span>

                        </label>

                    </div>

                </div>


                <!-- =================================================
                     INVENTORY INFORMATION
                ================================================== -->

                <div
                    class="rounded-xl bg-slate-50 border border-slate-100 p-4"
                >

                    <div class="flex items-start gap-3">

                        <div class="text-green-600 text-lg">
                            ℹ
                        </div>


                        <div>

                            <p class="text-sm font-bold text-slate-700">

                                Inventory information

                            </p>


                            <p class="mt-1 text-xs text-slate-500 leading-relaxed">

                                Stock represents the current quantity available for sale.
                                When a customer buys this product, your sales process should
                                decrease the stock automatically.

                            </p>

                        </div>

                    </div>

                </div>


                <!-- =================================================
                     PRICING INFORMATION
                ================================================== -->

                <div
                    class="rounded-xl bg-green-50 border border-green-100 p-4"
                >

                    <div class="flex items-start gap-3">

                        <div class="text-green-600 text-lg">
                            ₱
                        </div>


                        <div>

                            <p class="text-sm font-bold text-green-800">

                                Pricing tip

                            </p>


                            <p class="mt-1 text-xs text-green-700 leading-relaxed">

                                Enter the cost price and selling price for one unit.
                                For example, if a box of 12 costs ₱100 and you sell each
                                piece separately, the cost per piece is approximately ₱8.33.

                            </p>

                        </div>

                    </div>

                </div>


                <!-- =================================================
                     BUTTONS
                ================================================== -->

                <div
                    class="pt-2 flex flex-col-reverse sm:flex-row sm:justify-end gap-3"
                >


                    <a
                        href="/LALENZ_SARISARI_POS/products.php"
                        class="h-12 px-6 rounded-xl border border-slate-200 bg-white text-slate-600 font-bold text-sm inline-flex items-center justify-center hover:bg-slate-50 transition"
                    >

                        Cancel

                    </a>


                    <button
                        type="submit"
                        class="h-12 px-7 rounded-xl bg-green-600 hover:bg-green-700 text-white font-bold text-sm inline-flex items-center justify-center gap-2 shadow-lg shadow-green-600/20 transition"
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

                        Save Changes

                    </button>

                </div>

            </form>

        </div>

    </div>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

</body>

</html>
