<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LALENZ SARI-SARI POS
| API - SALE DETAILS
|--------------------------------------------------------------------------
|
| URL:
|   /api/sale_details.php?id=17
|
| Returns JSON only.
|--------------------------------------------------------------------------
*/


// =========================================================
// JSON RESPONSE HEADER
// =========================================================

header('Content-Type: application/json; charset=utf-8');


// =========================================================
// PHP ERROR SETTINGS
// =========================================================

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');


// =========================================================
// JSON RESPONSE FUNCTION
// =========================================================

function sendJson(
    bool $success,
    string $message,
    array $data = [],
    int $status = 200
): void {

    http_response_code($status);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


// =========================================================
// ERROR HANDLER
// =========================================================

set_error_handler(
    function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {

        error_log(
            'sale_details.php ERROR: ' .
            $message .
            ' in ' .
            $file .
            ':' .
            $line
        );

        return true;
    }
);


// =========================================================
// EXCEPTION HANDLER
// =========================================================

set_exception_handler(
    function (Throwable $e): void {

        error_log(
            'sale_details.php EXCEPTION: ' .
            $e->getMessage() .
            ' in ' .
            $e->getFile() .
            ':' .
            $e->getLine()
        );

        sendJson(
            false,
            'Server error while loading transaction.',
            [],
            500
        );
    }
);


// =========================================================
// DATABASE
// =========================================================

require_once __DIR__ . '/../config/database.php';


if (
    !isset($pdo) ||
    !($pdo instanceof PDO)
) {

    sendJson(
        false,
        'Database connection is not available.',
        [],
        500
    );
}


// =========================================================
// AUTH
// =========================================================

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/sale_metadata.php';


$userId = fetchUser();


if (!$userId) {

    sendJson(
        false,
        'Your session has expired. Please log in again.',
        [],
        401
    );
}


$userId = (int) $userId;
if (!hasAppPermission($pdo, $userId, 'sales.view')) {
    sendJson(false, 'You do not have permission to view sales.', [], 403);
}
ensureSaleMetadataColumns($pdo);


// =========================================================
// GET SALE ID
// =========================================================

$saleId = 0;


if (isset($_GET['id'])) {

    $saleId = (int) $_GET['id'];
}


if (
    $saleId <= 0 &&
    isset($_GET['sale_id'])
) {

    $saleId = (int) $_GET['sale_id'];
}


if (
    $saleId <= 0 &&
    isset($_POST['sale_id'])
) {

    $saleId = (int) $_POST['sale_id'];
}


if (
    $saleId <= 0 &&
    isset($_POST['id'])
) {

    $saleId = (int) $_POST['id'];
}


// =========================================================
// VALIDATE SALE ID
// =========================================================

if ($saleId <= 0) {

    sendJson(
        false,
        'Invalid sale ID.',
        [
            'error_code' => 'INVALID_SALE_ID'
        ],
        400
    );
}


// =========================================================
// LOAD SALE
// =========================================================

$stmtSale = $pdo->prepare("
    SELECT
        s.id,
        s.user_id,
        s.customer_name,
        s.customer_phone,
        s.customer_address,
        s.transaction_note,
        s.total_amount,
        s.amount_tendered,
        s.change_amount,
        s.status,
        s.receipt_type,
        s.created_at,

        u.username,
        u.full_name,
        u.role

    FROM sales s

    LEFT JOIN users u
        ON u.id = s.user_id

    WHERE s.id = ?

    LIMIT 1
");

$stmtSale->execute([
    $saleId
]);

$sale = $stmtSale->fetch(
    PDO::FETCH_ASSOC
);


// =========================================================
// SALE NOT FOUND
// =========================================================

if (!$sale) {

    sendJson(
        false,
        'Transaction #' . $saleId . ' was not found.',
        [
            'error_code' => 'SALE_NOT_FOUND',
            'sale_id' => $saleId
        ],
        404
    );
}


// =========================================================
// LOAD SALE ITEMS
// =========================================================
//
// IMPORTANT:
// We also load p.selling_price.
//
// This allows the API to recover the price if the
// sale_items.price value is NULL, empty, or zero.
//
// =========================================================

$stmtItems = $pdo->prepare("
    SELECT

        si.id,
        si.sale_id,
        si.product_id,
        si.quantity,

        si.price AS sale_item_price,
        si.subtotal AS sale_item_subtotal,

        p.name AS product_name,
        p.selling_price AS product_selling_price,

        c.name AS category_name

    FROM sale_items si

    LEFT JOIN products p
        ON p.id = si.product_id

    LEFT JOIN categories c
        ON c.id = p.category_id

    WHERE si.sale_id = ?

    ORDER BY si.id ASC
");

$stmtItems->execute([
    $saleId
]);

$items = $stmtItems->fetchAll(
    PDO::FETCH_ASSOC
);


// =========================================================
// FORMAT ITEMS
// =========================================================

$formattedItems = [];

$calculatedTotal = 0.00;


foreach ($items as $item) {

    $quantity = (int) (
        $item['quantity'] ?? 0
    );


    if ($quantity < 0) {
        $quantity = 0;
    }


    // ---------------------------------------------------------
    // SALE ITEM PRICE
    // ---------------------------------------------------------
    //
    // First preference:
    // sale_items.price
    //
    // Fallback:
    // products.selling_price
    //
    // ---------------------------------------------------------

    $saleItemPriceRaw =
        $item['sale_item_price'] ?? null;

    $productPriceRaw =
        $item['product_selling_price'] ?? null;


    $saleItemPrice = 0.00;

    if (
        $saleItemPriceRaw !== null &&
        $saleItemPriceRaw !== '' &&
        is_numeric($saleItemPriceRaw)
    ) {

        $saleItemPrice =
            (float) $saleItemPriceRaw;
    }


    $productPrice = 0.00;

    if (
        $productPriceRaw !== null &&
        $productPriceRaw !== '' &&
        is_numeric($productPriceRaw)
    ) {

        $productPrice =
            (float) $productPriceRaw;
    }


    // ---------------------------------------------------------
    // USE PRODUCT PRICE IF SALE ITEM PRICE IS INVALID
    // ---------------------------------------------------------

    if ($saleItemPrice <= 0 && $productPrice > 0) {

        $price = $productPrice;

    } else {

        $price = $saleItemPrice;
    }


    // ---------------------------------------------------------
    // SUBTOTAL
    // ---------------------------------------------------------

    $saleItemSubtotalRaw =
        $item['sale_item_subtotal'] ?? null;


    $storedSubtotal = 0.00;


    if (
        $saleItemSubtotalRaw !== null &&
        $saleItemSubtotalRaw !== '' &&
        is_numeric($saleItemSubtotalRaw)
    ) {

        $storedSubtotal =
            (float) $saleItemSubtotalRaw;
    }


    // ---------------------------------------------------------
    // ALWAYS CALCULATE FROM PRICE × QUANTITY
    // ---------------------------------------------------------
    //
    // This prevents old/broken zero subtotal values from
    // appearing in the transaction details.
    //
    // ---------------------------------------------------------

    $calculatedSubtotal =
        $price * $quantity;


    if ($calculatedSubtotal > 0) {

        $subtotal =
            $calculatedSubtotal;

    } else {

        $subtotal =
            $storedSubtotal;
    }


    // ---------------------------------------------------------
    // ADD TO TOTAL
    // ---------------------------------------------------------

    $calculatedTotal += $subtotal;


    // ---------------------------------------------------------
    // PRODUCT NAME
    // ---------------------------------------------------------

    $productName =
        trim(
            (string) (
                $item['product_name'] ??
                ''
            )
        );


    if ($productName === '') {

        $productName =
            'Unknown Product';
    }


    // ---------------------------------------------------------
    // CATEGORY
    // ---------------------------------------------------------

    $categoryName =
        trim(
            (string) (
                $item['category_name'] ??
                ''
            )
        );


    if ($categoryName === '') {

        $categoryName =
            'Uncategorized';
    }


    // ---------------------------------------------------------
    // STORE FORMATTED ITEM
    // ---------------------------------------------------------

    $formattedItems[] = [

        'id' =>
            (int) (
                $item['id'] ?? 0
            ),

        'sale_item_id' =>
            (int) (
                $item['id'] ?? 0
            ),

        'sale_id' =>
            (int) (
                $item['sale_id'] ??
                $saleId
            ),

        'product_id' =>
            (int) (
                $item['product_id'] ?? 0
            ),

        'product_name' =>
            $productName,

        'category_name' =>
            $categoryName,

        'quantity' =>
            $quantity,

        'price' =>
            round($price, 2),

        'subtotal' =>
            round($subtotal, 2)
    ];
}


// =========================================================
// SALE TOTAL
// =========================================================
//
// Prefer the actual sales.total_amount if it contains a
// valid positive value.
//
// If it is zero/null, calculate the total from sale_items.
//
// =========================================================

$storedSaleTotal = 0.00;


if (
    isset($sale['total_amount']) &&
    $sale['total_amount'] !== '' &&
    is_numeric($sale['total_amount'])
) {

    $storedSaleTotal =
        (float) $sale['total_amount'];
}


if ($storedSaleTotal > 0) {

    $totalAmount =
        $storedSaleTotal;

} else {

    $totalAmount =
        $calculatedTotal;
}


// =========================================================
// AMOUNT TENDERED
// =========================================================

$amountTendered = 0.00;


if (
    isset($sale['amount_tendered']) &&
    $sale['amount_tendered'] !== '' &&
    is_numeric($sale['amount_tendered'])
) {

    $amountTendered =
        (float) $sale['amount_tendered'];
}


// =========================================================
// CHANGE
// =========================================================

$changeAmount = 0.00;


if (
    isset($sale['change_amount']) &&
    $sale['change_amount'] !== '' &&
    is_numeric($sale['change_amount'])
) {

    $changeAmount =
        (float) $sale['change_amount'];
}


// =========================================================
// FALLBACK CHANGE CALCULATION
// =========================================================

if (
    $changeAmount <= 0 &&
    $amountTendered >= $totalAmount
) {

    $changeAmount =
        $amountTendered -
        $totalAmount;
}


// =========================================================
// CASHIER
// =========================================================

$cashierId =
    (int) (
        $sale['user_id'] ?? 0
    );


$username =
    trim(
        (string) (
            $sale['username'] ??
            ''
        )
    );


if ($username === '') {

    $username =
        'Unknown';
}


$fullName =
    trim(
        (string) (
            $sale['full_name'] ??
            ''
        )
    );


if ($fullName === '') {

    $fullName =
        $username;
}


$role =
    trim(
        (string) (
            $sale['role'] ??
            ''
        )
    );


if ($role === '') {

    $role =
        'Staff';
}


// =========================================================
// FORMAT SALE
// =========================================================

$formattedSale = [

    'id' =>
        (int) $sale['id'],

    'sale_id' =>
        (int) $sale['id'],

    'user_id' =>
        $cashierId,

    'customer_name' =>
        $sale['customer_name'] ?? null,

    'customer_phone' =>
        $sale['customer_phone'] ?? null,

    'customer_address' =>
        $sale['customer_address'] ?? null,

    'transaction_note' =>
        $sale['transaction_note'] ?? null,

    'total_amount' =>
        round($totalAmount, 2),

    'amount_tendered' =>
        round($amountTendered, 2),

    'change_amount' =>
        round($changeAmount, 2),

    'status' =>
        $sale['status'] ??
        'completed',

    'receipt_type' =>
        $sale['receipt_type'] ??
        'standard',

    'created_at' =>
        $sale['created_at'] ??
        null,

    'username' =>
        $username,

    'full_name' =>
        $fullName,

    'role' =>
        $role,

    'cashier' => [

        'id' =>
            $cashierId,

        'username' =>
            $username,

        'full_name' =>
            $fullName,

        'role' =>
            $role
    ],

    'item_count' =>
        count($formattedItems),

    'items' =>
        $formattedItems
];


// =========================================================
// RETURN JSON
// =========================================================

sendJson(
    true,
    'Transaction loaded successfully.',
    [
        'sale' =>
            $formattedSale,

        'items' =>
            $formattedItems
    ],
    200
);
