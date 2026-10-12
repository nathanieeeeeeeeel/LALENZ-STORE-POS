<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/system.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/action_log.php';
require_once __DIR__ . '/../config/sale_metadata.php';

requireAuth();
$userId = fetchUser();
$currentUserStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
$currentUserStmt->execute([$userId]);
if (!$currentUserStmt->fetchColumn()) {
    session_unset();
    session_destroy();
    header('Location: /LALENZ_SARISARI_POS/login.php');
    exit;
}
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'save_sale' &&
    !hasAppPermission($pdo, $userId, 'sales.manage')
) {
    appLogAction($pdo, $userId, 'sale.updated', 'sale', (int) ($_GET['id'] ?? 0) ?: null, null, 'failure', [
        'reason' => 'Permission denied.',
    ]);
}
requireAppPermission($pdo, $userId, 'sales.manage');
ensureActionLogTable($pdo);
ensureSaleMetadataColumns($pdo);

$saleId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$saleId) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_sale') {
        appLogAction($pdo, $userId, 'sale.updated', 'sale', null, null, 'failure', [
            'reason' => 'Invalid sale ID.',
        ]);
    }

    header(
        'Location: /LALENZ_SARISARI_POS/sales.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Fetch sale
|--------------------------------------------------------------------------
*/

$stmtSale = $pdo->prepare("
    SELECT *
    FROM sales
    WHERE id = ?
");

$stmtSale->execute([
    $saleId
]);

$sale = $stmtSale->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_sale') {
        appLogAction($pdo, $userId, 'sale.updated', 'sale', (int) $saleId, 'Sale #' . $saleId, 'failure', [
            'reason' => 'Sale not found.',
        ]);
    }

    die("Sale not found.");

}


/*
|--------------------------------------------------------------------------
| SAVE CHANGES
|--------------------------------------------------------------------------
*/

$errorMessage = '';
$customerName = (string) ($sale['customer_name'] ?? '');
$customerTin = (string) ($sale['customer_tin'] ?? '');
$customerAddress = (string) ($sale['customer_address'] ?? '');
$vatRegTin = (string) ($sale['vat_reg_tin'] ?? '');
$transactionNote = (string) ($sale['transaction_note'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';


    if ($action === 'save_sale') {

        $itemsJson = $_POST['items'] ?? '[]';

        $items = is_string($itemsJson)
            ? json_decode($itemsJson, true)
            : null;

        $customerName = $_POST['customer_name'] ?? $customerName;
        $customerTin = $_POST['customer_tin'] ?? $customerTin;
        $customerAddress = $_POST['customer_address'] ?? $customerAddress;
        $vatRegTin = $_POST['vat_reg_tin'] ?? $vatRegTin;
        $transactionNote = $_POST['transaction_note'] ?? $transactionNote;

        if (!is_array($items)) {

            $errorMessage = 'Invalid sale items.';
            appLogAction($pdo, $userId, 'sale.updated', 'sale', (int) $saleId, 'Sale #' . $saleId, 'failure', [
                'reason' => $errorMessage,
            ]);

        } elseif (
            !is_string($customerName) ||
            !is_string($customerTin) ||
            !is_string($customerAddress) ||
            !is_string($vatRegTin) ||
            !is_string($transactionNote)
        ) {

            $errorMessage = 'Invalid customer or transaction details.';
            $customerName = is_string($customerName)
                ? $customerName
                : (string) ($sale['customer_name'] ?? '');
            $customerTin = is_string($customerTin)
                ? $customerTin
                : (string) ($sale['customer_tin'] ?? '');
            $customerAddress = is_string($customerAddress)
                ? $customerAddress
                : (string) ($sale['customer_address'] ?? '');
            $vatRegTin = is_string($vatRegTin)
                ? $vatRegTin
                : (string) ($sale['vat_reg_tin'] ?? '');
            $transactionNote = is_string($transactionNote)
                ? $transactionNote
                : (string) ($sale['transaction_note'] ?? '');
            appLogAction($pdo, $userId, 'sale.updated', 'sale', (int) $saleId, 'Sale #' . $saleId, 'failure', [
                'reason' => $errorMessage,
            ]);

        } elseif (mb_strlen($customerName, 'UTF-8') > 150) {

            $errorMessage = 'Customer name cannot exceed 150 characters.';
            appLogAction($pdo, $userId, 'sale.updated', 'sale', (int) $saleId, 'Sale #' . $saleId, 'failure', [
                'reason' => $errorMessage,
            ]);

        } elseif (mb_strlen($customerTin, 'UTF-8') > 50 || mb_strlen($vatRegTin, 'UTF-8') > 50) {

            $errorMessage = 'Customer TIN fields cannot exceed 50 characters.';
            appLogAction($pdo, $userId, 'sale.updated', 'sale', (int) $saleId, 'Sale #' . $saleId, 'failure', [
                'reason' => $errorMessage,
            ]);

        } elseif (mb_strlen($customerAddress, 'UTF-8') > 500) {

            $errorMessage = 'Customer address cannot exceed 500 characters.';
            appLogAction($pdo, $userId, 'sale.updated', 'sale', (int) $saleId, 'Sale #' . $saleId, 'failure', [
                'reason' => $errorMessage,
            ]);

        } elseif (mb_strlen($transactionNote, 'UTF-8') > 2000) {

            $errorMessage = 'Transaction note cannot exceed 2,000 characters.';
            appLogAction($pdo, $userId, 'sale.updated', 'sale', (int) $saleId, 'Sale #' . $saleId, 'failure', [
                'reason' => $errorMessage,
            ]);

        } else {

            $customerName = trim($customerName);
            $customerTin = trim($customerTin);
            $customerAddress = trim($customerAddress);
            $vatRegTin = trim($vatRegTin);
            $transactionNote = trim($transactionNote);

            /*
            |--------------------------------------------------------------------------
            | Get cash tendered
            |--------------------------------------------------------------------------
            */

            $cashTendered = isset($_POST['cash_tendered'])
                ? (float) $_POST['cash_tendered']
                : 0;


            try {

                $pdo->beginTransaction();


                /*
                |--------------------------------------------------------------------------
                | Delete current sale items
                |--------------------------------------------------------------------------
                */

                $stmtDelete = $pdo->prepare("
                    DELETE FROM sale_items
                    WHERE sale_id = ?
                ");

                $stmtDelete->execute([
                    $saleId
                ]);


                /*
                |--------------------------------------------------------------------------
                | Insert edited items
                |--------------------------------------------------------------------------
                */

                $stmtInsert = $pdo->prepare("
                    INSERT INTO sale_items
                        (
                            sale_id,
                            product_id,
                            quantity,
                            price,
                            subtotal
                        )
                    VALUES
                        (?, ?, ?, ?, ?)
                ");


                $totalAmount = 0;


                foreach ($items as $item) {

                    $productId = filter_var(
                        $item['product_id'] ?? null,
                        FILTER_VALIDATE_INT
                    );


                    $quantity = filter_var(
                        $item['quantity'] ?? null,
                        FILTER_VALIDATE_INT
                    );


                    if (
                        !$productId ||
                        !$quantity ||
                        $quantity <= 0
                    ) {

                        continue;

                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Get current product price
                    |--------------------------------------------------------------------------
                    */

                    $stmtProduct = $pdo->prepare("
                        SELECT
                            id,
                            selling_price
                        FROM products
                        WHERE id = ?
                    ");

                    $stmtProduct->execute([
                        $productId
                    ]);


                    $product = $stmtProduct->fetch(
                        PDO::FETCH_ASSOC
                    );


                    if (!$product) {

                        continue;

                    }


                    $price = (float) $product['selling_price'];


                    $subtotal =
                        $price * $quantity;


                    $stmtInsert->execute([

                        $saleId,

                        $productId,

                        $quantity,

                        $price,

                        $subtotal

                    ]);


                    $totalAmount += $subtotal;

                }


                /*
                |--------------------------------------------------------------------------
                | Validate cash tendered
                |--------------------------------------------------------------------------
                */

                if ($cashTendered < $totalAmount) {

                    throw new Exception(
                        'Cash tendered cannot be less than the total amount.'
                    );

                }


                /*
                |--------------------------------------------------------------------------
                | Calculate change
                |--------------------------------------------------------------------------
                */

                $changeAmount =
                    $cashTendered - $totalAmount;


                /*
                |--------------------------------------------------------------------------
                | Update sale
                |--------------------------------------------------------------------------
                */

                $stmtUpdateSale = $pdo->prepare("
                    UPDATE sales
                    SET
                        total_amount = ?,
                        amount_tendered = ?,
                        change_amount = ?,
                        customer_name = ?,
                        customer_tin = ?,
                        customer_address = ?,
                        vat_reg_tin = ?,
                        transaction_note = ?
                    WHERE id = ?
                ");


                $stmtUpdateSale->execute([

                    $totalAmount,

                    $cashTendered,

                    $changeAmount,

                    $customerName !== '' ? $customerName : null,

                    $customerTin !== '' ? $customerTin : null,

                    $customerAddress !== '' ? $customerAddress : null,

                    $vatRegTin !== '' ? $vatRegTin : null,

                    $transactionNote !== '' ? $transactionNote : null,

                    $saleId

                ]);

                appLogAction($pdo, $userId, 'sale.updated', 'sale', (int) $saleId, 'Sale #' . $saleId, 'success', [
                    'total_amount' => number_format($totalAmount, 2, '.', ''),
                    'item_count' => count($items),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Commit
                |--------------------------------------------------------------------------
                */

                $pdo->commit();

                /*
                |--------------------------------------------------------------------------
                | Redirect after successful save
                |--------------------------------------------------------------------------
                */

                header(
                    "Location: edit_sale.php?id=" .
                    $saleId .
                    "&saved=1"
                );

                exit;


            } catch (Throwable $e) {

                /*
                |--------------------------------------------------------------------------
                | Rollback
                |--------------------------------------------------------------------------
                */

                if ($pdo->inTransaction()) {

                    $pdo->rollBack();

                }

                appLogAction($pdo, $userId, 'sale.updated', 'sale', (int) $saleId, 'Sale #' . $saleId, 'failure', [
                    'reason' => $e->getMessage(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Show friendly error
                |--------------------------------------------------------------------------
                */

                $errorMessage =
                    $e->getMessage();

            }

        }

    }

}


/*
|--------------------------------------------------------------------------
| Fetch current sale items
|--------------------------------------------------------------------------
*/

$stmtItems = $pdo->prepare("
    SELECT
        si.id,
        si.product_id,
        si.quantity,
        si.price,
        si.subtotal,
        p.name AS product_name
    FROM sale_items si
    LEFT JOIN products p
        ON si.product_id = p.id
    WHERE si.sale_id = ?
    ORDER BY si.id ASC
");

$stmtItems->execute([
    $saleId
]);

$items = $stmtItems->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| Fetch all products
|--------------------------------------------------------------------------
*/

$stmtProducts = $pdo->query("
    SELECT
        id,
        name,
        selling_price
    FROM products
    ORDER BY name ASC
");

$allProducts = $stmtProducts->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| Existing cash tendered
|--------------------------------------------------------------------------
*/

$existingCashTendered =
    (float) (
        $sale['amount_tendered']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| Existing change
|--------------------------------------------------------------------------
*/

$existingChange =
    (float) (
        $sale['change_amount']
        ?? 0
    );

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
        Edit Sale #<?= (int) $saleId ?> | <?= htmlspecialchars($systemName) ?>
    </title>

    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            darkMode: 'class'
        };

        (function () {
            const savedTheme = localStorage.getItem('lalenz_theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    <link
        rel="icon"
        href="<?= htmlspecialchars($logo) ?>"
        type="image/x-icon"
    >

</head>


<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 p-6 lg:p-12">


<div class="max-w-4xl mx-auto">


    <div
        class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 lg:p-8 dark:bg-slate-900 dark:border-slate-700"
    >


        <!-- =====================================================
             HEADER
        ====================================================== -->

        <div
            class="flex justify-between items-center mb-6 pb-4 border-b border-slate-200 dark:border-slate-700"
        >

            <div>

                <h1 class="text-2xl font-black">

                    Edit Transaction #<?= (int) $saleId ?>

                </h1>


                <p class="text-sm text-slate-500 dark:text-slate-400">

                    Modify the items and payment details below,
                    then save your changes.

                </p>

            </div>


            <a
                href="/LALENZ_SARISARI_POS/sales.php"
                data-protected-navigation
                class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 font-bold text-sm text-slate-700 transition dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200"
            >

                Back to Sales

            </a>

        </div>


        <!-- =====================================================
             SUCCESS MESSAGE
        ====================================================== -->

        <?php if (isset($_GET['saved'])): ?>

            <div
                class="mb-6 p-4 rounded-xl bg-green-50 border border-green-200 text-green-700 font-bold text-sm dark:bg-green-950/40 dark:border-green-800 dark:text-green-300"
            >

                ✓ Sale updated successfully.

            </div>

        <?php endif; ?>


        <!-- =====================================================
             ERROR MESSAGE
        ====================================================== -->

        <?php if ($errorMessage !== ''): ?>

            <div
                class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 font-bold text-sm dark:bg-red-950/40 dark:border-red-800 dark:text-red-300"
            >

                <?= htmlspecialchars($errorMessage) ?>

            </div>

        <?php endif; ?>


        <!-- =====================================================
             ADD PRODUCT
        ====================================================== -->

        <div
            class="bg-slate-50 p-4 rounded-xl border border-slate-200 mb-6 dark:bg-slate-800/70 dark:border-slate-700"
        >

            <div
                class="flex flex-col sm:flex-row items-end gap-3"
            >


                <!-- PRODUCT -->

                <div class="flex-1 w-full">

                    <label
                        class="block text-xs font-bold text-slate-600 mb-1 dark:text-slate-300"
                    >

                        Select Product

                    </label>


                    <select
                        id="productSelect"
                        class="w-full h-11 px-3 rounded-xl border border-slate-200 bg-white text-slate-900 text-sm outline-none focus:border-green-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                    >

                        <option value="">
                            -- Choose Product --
                        </option>


                        <?php foreach ($allProducts as $p): ?>

                            <option
                                value="<?= (int) $p['id'] ?>"
                                data-price="<?= htmlspecialchars($p['selling_price']) ?>"
                                data-name="<?= htmlspecialchars($p['name']) ?>"
                            >

                                <?= htmlspecialchars($p['name']) ?>

                                (₱<?= number_format(
                                    (float) $p['selling_price'],
                                    2
                                ) ?>)

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- QUANTITY -->

                <div class="w-full sm:w-32">

                    <label
                        class="block text-xs font-bold text-slate-600 mb-1 dark:text-slate-300"
                    >

                        Quantity

                    </label>


                    <input
                        type="number"
                        id="quantityInput"
                        value="1"
                        min="1"
                        class="w-full h-11 px-3 rounded-xl border border-slate-200 bg-white text-slate-900 text-sm outline-none focus:border-green-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                    >

                </div>


                <!-- ADD -->

                <button
                    type="button"
                    onclick="addItem()"
                    class="w-full sm:w-auto h-11 px-6 bg-green-600 hover:bg-green-700 text-white font-bold text-sm rounded-xl transition"
                >

                    Add Item

                </button>

            </div>

        </div>


        <!-- =====================================================
             ITEMS
        ====================================================== -->

        <div class="overflow-x-auto mb-6">

            <table class="w-full text-left border-collapse">

                <thead>

                    <tr
                        class="border-b border-slate-200 text-xs font-black text-slate-400 uppercase dark:border-slate-700 dark:text-slate-500"
                    >

                        <th class="py-3 px-4">
                            Product
                        </th>

                        <th class="py-3 px-4">
                            Price
                        </th>

                        <th class="py-3 px-4">
                            Qty
                        </th>

                        <th class="py-3 px-4">
                            Subtotal
                        </th>

                        <th class="py-3 px-4 text-right">
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody
                    id="itemsTable"
                    class="divide-y divide-slate-100 text-sm dark:divide-slate-700"
                ></tbody>

            </table>

        </div>


        <!-- =====================================================
             TOTAL / CASH / CHANGE
        ====================================================== -->

        <div
            class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6"
        >


            <!-- TOTAL -->

            <div
                class="flex justify-between items-center bg-green-50 border border-green-200 p-4 rounded-xl dark:bg-green-950/30 dark:border-green-800"
            >

                <span
                    class="font-bold text-green-900 text-sm dark:text-green-300"
                >

                    Updated Total:

                </span>


                <span
                    id="totalDisplay"
                    class="font-black text-green-700 text-xl dark:text-green-300"
                >

                    ₱0.00

                </span>

            </div>


            <!-- CASH TENDERED -->

            <div
                class="bg-slate-50 border border-slate-200 p-4 rounded-xl dark:bg-slate-800/70 dark:border-slate-700"
            >

                <label
                    for="cashTendered"
                    class="block text-sm font-bold text-slate-700 mb-2 dark:text-slate-200"
                >

                    Cash Tendered

                </label>


                <div class="relative">

                    <span
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 font-bold dark:text-slate-400"
                    >

                        ₱

                    </span>


                    <input
                        type="number"
                        id="cashTendered"
                        name="cash_tendered"
                        min="0"
                        step="0.01"
                        value="<?= htmlspecialchars(
                            number_format(
                                $existingCashTendered,
                                2,
                                '.',
                                ''
                            )
                        ) ?>"
                        class="w-full h-11 pl-8 pr-3 rounded-xl border border-slate-200 bg-white text-slate-900 text-sm font-bold outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                    >

                </div>

            </div>


            <!-- CHANGE -->

            <div
                class="flex justify-between items-center bg-blue-50 border border-blue-200 p-4 rounded-xl dark:bg-blue-950/30 dark:border-blue-800"
            >

                <span
                    class="font-bold text-blue-900 text-sm dark:text-blue-300"
                >

                    Change:

                </span>


                <span
                    id="changeDisplay"
                    class="font-black text-blue-700 text-xl dark:text-blue-300"
                >

                    -

                </span>

            </div>

        </div>


        <!-- =====================================================
             SAVE FORM
        ====================================================== -->

        <form
            method="POST"
            id="saveForm"
        >

            <input
                type="hidden"
                name="action"
                value="save_sale"
            >


            <input
                type="hidden"
                name="items"
                id="itemsInput"
            >


            <!-- Cash is copied here when saving -->

            <input
                type="hidden"
                name="cash_tendered"
                id="cashTenderedInput"
            >

            <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="customerName" class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-200">
                        Customer Name
                    </label>
                    <input
                        type="text"
                        id="customerName"
                        name="customer_name"
                        maxlength="150"
                        value="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="WALK-IN Customer NO INFO GIVEN"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-green-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                    >
                </div>

                <div>
                    <label for="customerTin" class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-200">
                        TIN
                    </label>
                    <input
                        type="text"
                        id="customerTin"
                        name="customer_tin"
                        maxlength="50"
                        value="<?= htmlspecialchars($customerTin, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="TIN (optional)"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-green-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                    >
                </div>

                <div>
                    <label for="customerAddress" class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-200">
                        Address
                    </label>
                    <input
                        type="text"
                        id="customerAddress"
                        name="customer_address"
                        maxlength="500"
                        value="<?= htmlspecialchars($customerAddress, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Address (optional)"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-green-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                    >
                </div>

                <div>
                    <label for="vatRegTin" class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-200">
                        VAT REG TIN
                    </label>
                    <input
                        type="text"
                        id="vatRegTin"
                        name="vat_reg_tin"
                        maxlength="50"
                        value="<?= htmlspecialchars($vatRegTin, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="VAT REG TIN (optional)"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-green-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                    >
                </div>
            </div>

            <div class="mb-6">
                <label
                    for="transactionNote"
                    class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-200"
                >
                    Transaction Note
                </label>
                <textarea
                    id="transactionNote"
                    name="transaction_note"
                    maxlength="2000"
                    rows="3"
                    class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-green-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                    placeholder="Add context or an agreed payment arrangement"
                ><?= htmlspecialchars($transactionNote, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>


            <div
                class="flex justify-end gap-3"
            >

                <a
                    href="<?php echo $_SERVER['HTTP_REFERER'] ?? '/LALENZ_SARISARI_POS/sales.php'; ?>"
                    data-protected-navigation
                    class="px-6 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200"
                >

                    Cancel

                </a>


                <button
                    type="submit"
                    class="px-6 py-3 rounded-xl bg-green-600 hover:bg-green-700 text-white font-bold shadow-sm"
                >

                    Save Changes

                </button>

            </div>

        </form>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| Existing items loaded from PHP
|--------------------------------------------------------------------------
*/

let items = <?= json_encode(
    array_map(
        function ($item) {

            return [

                'product_id' =>
                    (int) $item['product_id'],

                'product_name' =>
                    $item['product_name'],

                'price' =>
                    (float) $item['price'],

                'quantity' =>
                    (int) $item['quantity']

            ];

        },
        $items
    ),
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
) ?>;


/*
|--------------------------------------------------------------------------
| Original state
|--------------------------------------------------------------------------
*/

const originalItems =
    JSON.stringify(items);


const originalCashTendered =
    <?= json_encode($existingCashTendered) ?>;


let isSaving = false;


/*
|--------------------------------------------------------------------------
| Check unsaved changes
|--------------------------------------------------------------------------
*/

function hasUnsavedChanges() {

    const currentCash =
        parseFloat(
            document.getElementById(
                'cashTendered'
            ).value
        ) || 0;


    return (
        JSON.stringify(items) !== originalItems ||
        currentCash !== originalCashTendered
    );

}


/*
|--------------------------------------------------------------------------
| Browser refresh / close protection
|--------------------------------------------------------------------------
*/

window.addEventListener(
    'beforeunload',
    function (event) {

        if (isSaving) {

            return;

        }


        if (hasUnsavedChanges()) {

            event.preventDefault();

            event.returnValue = '';

        }

    }
);


/*
|--------------------------------------------------------------------------
| Navigation protection
|--------------------------------------------------------------------------
*/

function confirmNavigation(url) {

    if (!hasUnsavedChanges()) {

        window.location.href = url;

        return;

    }


    const leavePage = confirm(
        'You have unsaved changes.\n\n' +
        'If you leave this page, all modifications you made will be lost.\n\n' +
        'Do you want to leave without saving?'
    );


    if (leavePage) {

        window.location.href = url;

    }

}


/*
|--------------------------------------------------------------------------
| Render Items
|--------------------------------------------------------------------------
*/

function renderItems() {

    const table =
        document.getElementById(
            'itemsTable'
        );


    table.innerHTML = '';


    let total = 0;


    /*
    |--------------------------------------------------------------------------
    | No items
    |--------------------------------------------------------------------------
    */

    if (items.length === 0) {

        table.innerHTML = `

            <tr>

                <td
                    colspan="5"
                    class="py-6 text-center text-slate-400 dark:text-slate-500"
                >

                    No items in this transaction.

                </td>

            </tr>

        `;

    }


    /*
    |--------------------------------------------------------------------------
    | Render each item
    |--------------------------------------------------------------------------
    */

    items.forEach(
        function (item, index) {

            const subtotal =
                item.price *
                item.quantity;


            total += subtotal;


            const row =
                document.createElement('tr');


            row.innerHTML = `

                <td class="py-3 px-4 font-bold text-slate-800 dark:text-slate-100">

                    ${escapeHtml(
                        item.product_name
                    )}

                </td>


                <td class="py-3 px-4 text-slate-600 dark:text-slate-300">

                    ₱${item.price.toFixed(2)}

                </td>


                <td class="py-3 px-4">

                    <input
                        type="number"
                        min="1"
                        value="${item.quantity}"
                        onchange="updateQuantity(
                            ${index},
                            this.value
                        )"
                        class="w-20 px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-900 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                    >

                </td>


                <td class="py-3 px-4 font-bold text-slate-900 dark:text-slate-100">

                    ₱${subtotal.toFixed(2)}

                </td>


                <td class="py-3 px-4 text-right">

                    <button
                        type="button"
                        onclick="removeItem(${index})"
                        class="text-red-600 hover:text-red-800 font-bold text-xs bg-red-50 hover:bg-red-100 px-3 py-1.5 rounded-lg transition dark:bg-red-950/40 dark:hover:bg-red-900/60 dark:text-red-300"
                    >

                        Remove

                    </button>

                </td>

            `;


            table.appendChild(row);

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Update total
    |--------------------------------------------------------------------------
    */

    document.getElementById(
        'totalDisplay'
    ).textContent =
        '₱' +
        total.toFixed(2);


    /*
    |--------------------------------------------------------------------------
    | Calculate change
    |--------------------------------------------------------------------------
    */

    const cashInput =
        document.getElementById(
            'cashTendered'
        );


    const changeDisplay =
        document.getElementById(
            'changeDisplay'
        );


    const cashTendered =
        parseFloat(
            cashInput.value
        ) || 0;


    const change =
        cashTendered -
        total;


    /*
    |--------------------------------------------------------------------------
    | Display change
    |--------------------------------------------------------------------------
    */

    if (change > 0) {

        changeDisplay.textContent =
            '₱' +
            change.toFixed(2);

    } else {

        changeDisplay.textContent =
            '-';

    }

}


/*
|--------------------------------------------------------------------------
| Add Item
|--------------------------------------------------------------------------
*/

function addItem() {

    const select =
        document.getElementById(
            'productSelect'
        );


    const quantityInput =
        document.getElementById(
            'quantityInput'
        );


    const option =
        select.options[
            select.selectedIndex
        ];


    const productId =
        parseInt(
            select.value
        );


    const quantity =
        parseInt(
            quantityInput.value
        );


    if (!productId) {

        alert(
            'Please select a product.'
        );

        return;

    }


    if (
        !quantity ||
        quantity <= 0
    ) {

        alert(
            'Please enter a valid quantity.'
        );

        return;

    }


    const productName =
        option.dataset.name;


    const price =
        parseFloat(
            option.dataset.price
        );


    const existing =
        items.find(
            item =>
                item.product_id === productId
        );


    if (existing) {

        existing.quantity += quantity;

    } else {

        items.push({

            product_id:
                productId,

            product_name:
                productName,

            price:
                price,

            quantity:
                quantity

        });

    }


    renderItems();


    select.value = '';

    quantityInput.value = 1;

}


/*
|--------------------------------------------------------------------------
| Update Quantity
|--------------------------------------------------------------------------
*/

function updateQuantity(
    index,
    quantity
) {

    quantity =
        parseInt(
            quantity
        );


    if (
        !quantity ||
        quantity <= 0
    ) {

        items.splice(
            index,
            1
        );

    } else {

        items[index].quantity =
            quantity;

    }


    renderItems();

}


/*
|--------------------------------------------------------------------------
| Remove Item
|--------------------------------------------------------------------------
*/

function removeItem(index) {

    if (
        !confirm(
            'Remove this item from the sale?'
        )
    ) {

        return;

    }


    items.splice(
        index,
        1
    );


    renderItems();

}


/*
|--------------------------------------------------------------------------
| Escape HTML
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

    const div =
        document.createElement(
            'div'
        );


    div.textContent =
        value;


    return div.innerHTML;

}


/*
|--------------------------------------------------------------------------
| Cash Tendered Change Listener
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'cashTendered'
    )
    .addEventListener(
        'input',
        function () {

            renderItems();

        }
    );


/*
|--------------------------------------------------------------------------
| Save Form
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'saveForm'
    )
    .addEventListener(
        'submit',
        function (event) {

            /*
            |--------------------------------------------------------------------------
            | Calculate current total
            |--------------------------------------------------------------------------
            */

            let total = 0;


            items.forEach(
                function (item) {

                    total +=
                        item.price *
                        item.quantity;

                }
            );


            /*
            |--------------------------------------------------------------------------
            | Get cash
            |--------------------------------------------------------------------------
            */

            const cashTendered =
                parseFloat(
                    document.getElementById(
                        'cashTendered'
                    ).value
                ) || 0;


            /*
            |--------------------------------------------------------------------------
            | Validate cash
            |--------------------------------------------------------------------------
            */

            if (
                cashTendered <
                total
            ) {

                event.preventDefault();


                alert(
                    'Cash tendered cannot be less than the total amount.\n\n' +
                    'Total: ₱' +
                    total.toFixed(2) +
                    '\n' +
                    'Cash Tendered: ₱' +
                    cashTendered.toFixed(2)
                );


                return;

            }


            /*
            |--------------------------------------------------------------------------
            | Confirm save
            |--------------------------------------------------------------------------
            */

            if (
                !confirm(
                    'Save all changes to this sale?'
                )
            ) {

                event.preventDefault();

                return;

            }


            /*
            |--------------------------------------------------------------------------
            | Tell beforeunload this is intentional
            |--------------------------------------------------------------------------
            */

            isSaving = true;


            /*
            |--------------------------------------------------------------------------
            | Put current items into hidden input
            |--------------------------------------------------------------------------
            */

            document.getElementById(
                'itemsInput'
            ).value =
                JSON.stringify(items);


            /*
            |--------------------------------------------------------------------------
            | Put cash tendered into hidden input
            |--------------------------------------------------------------------------
            */

            document.getElementById(
                'cashTenderedInput'
            ).value =
                cashTendered.toFixed(2);

        }
    );


/*
|--------------------------------------------------------------------------
| Initial Render
|--------------------------------------------------------------------------
*/

renderItems();


/*
|--------------------------------------------------------------------------
| Protect Back / Cancel links
|--------------------------------------------------------------------------
*/

document.querySelectorAll(
    '[data-protected-navigation]'
).forEach(
    function (link) {

        link.addEventListener(
            'click',
            function (event) {

                event.preventDefault();


                confirmNavigation(
                    this.href
                );

            }
        );

    }
);

</script>


<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>

</html>
