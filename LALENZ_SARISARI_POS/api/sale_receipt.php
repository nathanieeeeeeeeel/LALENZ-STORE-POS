<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/system.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/sale_metadata.php';

requireAuth();
$userId = fetchUser();
requireAppPermission($pdo, $userId, 'sales.view');
ensureSaleMetadataColumns($pdo);

/*
|--------------------------------------------------------------------------
| Validate & Fetch Sale ID
|--------------------------------------------------------------------------
*/
$saleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$saleId) {
    header('Location: ../sales.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Fetch Sale
|--------------------------------------------------------------------------
*/
$stmtSale = $pdo->prepare("
    SELECT 
        s.*,
        u.username AS cashier_name,
        u.full_name AS cashier_fullname
    FROM sales s
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.id = ?
");
$stmtSale->execute([$saleId]);

$sale = $stmtSale->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    echo "
        <div style='font-family:sans-serif;text-align:center;padding:50px;'>
            <h2>Receipt not found.</h2>
            <a href='../sales.php'>Back to Sales</a>
        </div>
    ";
    exit;
}

/*
|--------------------------------------------------------------------------
| Fetch Sale Items
|--------------------------------------------------------------------------
*/
$stmtItems = $pdo->prepare("
    SELECT 
        si.*,
        p.name AS product_name,
        p.sku
    FROM sale_items si
    LEFT JOIN products p ON si.product_id = p.id
    WHERE si.sale_id = ?
");
$stmtItems->execute([$saleId]);

$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Store Details
|--------------------------------------------------------------------------
*/
$storeName = "LALEN'S STORE";
$storeAddress = "B15 L19, VILLA KARANGAHAN SUBD., SAN FELIPE, NAGA CITY";
$storeContact = "";
$receiptFooter = 'Thank you for your purchase! Please come again.';
$settingsTableExists = $pdo->query("
    SELECT 1
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'app_settings'
        AND TABLE_TYPE = 'BASE TABLE'
")->fetchColumn();

if ($settingsTableExists) {
    $receiptAddressStmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'receipt_address'");
    $receiptAddressStmt->execute();
    $configuredReceiptAddress = $receiptAddressStmt->fetchColumn();
    if (is_string($configuredReceiptAddress)) {
        $storeAddress = $configuredReceiptAddress;
    }

    $receiptFooterStmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'receipt_footer'");
    $receiptFooterStmt->execute();
    $configuredReceiptFooter = $receiptFooterStmt->fetchColumn();
    if (is_string($configuredReceiptFooter)) {
        $receiptFooter = $configuredReceiptFooter;
    }
}

/*
|--------------------------------------------------------------------------
| Receipt Footer Date
|--------------------------------------------------------------------------
| Uses the sale's actual created_at date.
|--------------------------------------------------------------------------
*/
$receiptDate = !empty($sale['created_at'])
    ? date('m/d/Y h:i A', strtotime($sale['created_at']))
    : date('m/d/Y h:i A');

$receiptNumber = str_pad($saleId, 4, '0', STR_PAD_LEFT);
?>

<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>
        Receipt #<?= $saleId ?> | <?= htmlspecialchars($systemName) ?>
    </title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="icon"
          href="<?= htmlspecialchars($logo) ?>"
          type="image/x-icon">

    <style>

        body {
            font-family: 'Courier New', Courier, monospace;
        }

        /*
        |--------------------------------------------------------------------------
        | PRINT
        |--------------------------------------------------------------------------
        */

        @media print {

            @page {
                margin: 0;
            }

            html,
            body {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                display: block !important;
            }

            .no-print {
                display: none !important;
            }

            .receipt-card {
                width: 100% !important;
                max-width: none !important;

                margin: 0 !important;

                padding-top: 6px !important;
                padding-left: 6px !important;
                padding-right: 6px !important;
                padding-bottom: 4px !important;

                border: none !important;
                border-radius: 0 !important;
                box-shadow: none !important;
            }

            .receipt-content {
                width: 100% !important;
            }

        }

    </style>

</head>


<body id="pageBody"
      class="bg-slate-100 min-h-screen py-10 px-4 flex flex-col items-center justify-center mode-standard">


    <!-- ==========================================================
         ACTION BUTTONS
    =========================================================== -->

    <div class="no-print mb-6 flex items-center flex-wrap justify-center gap-3">

        <!-- PRINT -->
        <button
            onclick="window.print()"
            class="px-5 py-2.5 rounded-xl
                   bg-green-600 hover:bg-green-700
                   text-white font-bold text-sm
                   shadow-lg shadow-green-600/20
                   transition cursor-pointer
                   flex items-center gap-2">

            <span>🖨️</span>

            Print Receipt

        </button>


        <!-- WIDTH TOGGLE -->
        <select
            id="receiptWidthToggle"
            class="px-4 py-2.5 rounded-xl
                   bg-white border border-slate-200
                   text-slate-700 font-bold text-sm
                   shadow-sm outline-none
                   cursor-pointer
                   focus:border-green-500">

            <option value="standard">
                Standard Size (80mm)
            </option>

            <option value="58mm">
                58mm Thermal Width
            </option>

        </select>


    </div>



    <!-- ==========================================================
         RECEIPT
    =========================================================== -->

    <div
        id="receiptCard"
        class="receipt-card
               bg-white
               w-full max-w-sm
               rounded-2xl
               border border-slate-200
               shadow-xl
               p-5
               text-slate-900
               font-bold
               transition-all duration-300">


        <div class="receipt-content">


            <!-- ==================================================
                 STORE HEADER
            =================================================== -->

            <div
                class="text-center
                       pb-3
                       ">


                <h1
                    id="storeTitle"
                    class="font-black
                           text-lg
                           tracking-wider
                           text-black
                           leading-tight font-mono tracking-tighter">

                    <?= htmlspecialchars($storeName) ?>

                </h1>


                <?php if ($storeAddress !== ''): ?>
                    <p class="mt-1 whitespace-pre-line break-words text-[9px] font-bold leading-tight text-slate-800">
                        <?= htmlspecialchars($storeAddress, ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>


                <?php if (!empty($storeContact)): ?>

                    <p
                        class="text-[9px]
                               font-bold
                               text-slate-800">

                        <?= htmlspecialchars($storeContact) ?>

                    </p>

                <?php endif; ?>

                <p class="font-bold text-xs font-sans">ORDER SLIP</p>

            </div>



            <!-- ==================================================
                 ITEMS
            =================================================== -->

            <div
                class="py-3
                       border-b
                       border-dashed
                       border-black">


                <!-- COLUMN HEADER -->

                <div
                    class="flex
                           justify-between
                           text-[9px]
                           text-slate-900
                           font-black
                           border-b
                           border-black
                           pb-1
                           mb-2">

                    <span>
                        ITEM
                    </span>

                    <span>
                        SUBTOTAL
                    </span>

                </div>



                <!-- ITEM LIST -->

                <div class="space-y-2">


                    <?php foreach ($items as $item): ?>


                        <div
                            class="flex
                                   justify-between
                                   items-start
                                   text-[10px]">


                            <!-- PRODUCT -->

                            <div class="pr-2 min-w-0">

                                <div
                                    class="font-black
                                           text-black
                                           text-[10px]
                                           leading-tight
                                           break-words">

                                    <?= htmlspecialchars(
                                        $item['product_name']
                                        ?? 'Unknown Product'
                                    ) ?>

                                </div>


                                <div
                                    class="text-[8px]
                                           font-bold
                                           text-slate-700
                                           mt-0.5">

                                    <?= (int)$item['quantity'] ?>
                                    x
                                    ₱<?= number_format(
                                        (float)$item['price'],
                                        2
                                    ) ?>

                                </div>

                            </div>


                            <!-- SUBTOTAL -->

                            <div
                                class="text-right
                                       font-black
                                       text-black
                                       shrink-0
                                       text-[10px]">

                                ₱<?= number_format(
                                    (float)$item['subtotal'],
                                    2
                                ) ?>

                            </div>


                        </div>


                    <?php endforeach; ?>


                </div>

            </div>

            <!-- ==================================================
                 TOTALS
            =================================================== -->

            <div
                class="py-3
                       space-y-1
                       text-[10px]
                       border-b
                       border-dashed
                       border-black">


                <!-- TOTAL -->

                <div
                    class="flex
                           justify-between
                           text-[13px]
                           font-black
                           text-black
                           pt-1">

                    <span>
                        TOTAL:
                    </span>

                    <span>

                        ₱<?= number_format(
                            (float)$sale['total_amount'],
                            2
                        ) ?>

                    </span>

                </div>


                <!-- CASH -->

                <div
                    class="flex
                           justify-between
                           text-slate-800
                           font-bold">

                    <span>
                        Cash:
                    </span>

                    <span class="text-black">

                        ₱<?= number_format(
                            (float)$sale['amount_tendered'],
                            2
                        ) ?>

                    </span>

                </div>


                <!-- CHANGE -->

                <?php if ((float)$sale['change_amount'] > 0): ?>

                    <div
                        class="flex
                               justify-between
                               text-slate-800
                               font-bold">

                        <span>
                            Change:
                        </span>

                        <span class="text-black">

                            ₱<?= number_format(
                                (float)$sale['change_amount'],
                                2
                            ) ?>

                        </span>

                    </div>

                <?php endif; ?>


            </div>

            <div class="border-b border-dashed border-black py-2 text-left text-[9px] font-bold text-black">
                <p class="break-words">
                    Customer: <?= htmlspecialchars(trim((string) ($sale['customer_name'] ?? '')) !== '' ? (string) $sale['customer_name'] : 'WALK-IN Customer NO INFO GIVEN', ENT_QUOTES, 'UTF-8') ?>
                </p>
                <p class="break-words">
                    TIN: <?= htmlspecialchars(trim((string) ($sale['customer_tin'] ?? '')) !== '' ? (string) $sale['customer_tin'] : '_________________________', ENT_QUOTES, 'UTF-8') ?>
                </p>
                <p class="break-words">
                    Address: <?= htmlspecialchars(trim((string) ($sale['customer_address'] ?? '')) !== '' ? (string) $sale['customer_address'] : '_____________________', ENT_QUOTES, 'UTF-8') ?>
                </p>
                <p class="break-words">
                    VAT REG TIN: <?= htmlspecialchars(trim((string) ($sale['vat_reg_tin'] ?? '')) !== '' ? (string) $sale['vat_reg_tin'] : '____________', ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>



            <!-- ==================================================
                 RECEIPT FOOTER
            =================================================== -->

            <div
                class="text-center
                       pt-3">


                <!-- DATE + RECEIPT NUMBER -->

                 
                <?php if ($receiptFooter !== ''): ?>
                    <p class="mt-2 whitespace-pre-line break-words text-[10px] font-bold text-slate-600">
                        <?= htmlspecialchars($receiptFooter, ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>

                <div
                    class="flex
                           justify-between
                           items-center
                           text-[9px]
                           font-bold
                           text-black
                           whitespace-nowrap">

                    <span>
                        <?= htmlspecialchars($receiptDate) ?>
                    </span>

                    <span>
                        #<?= $receiptNumber ?>
                    </span>

                </div>

            </div>


        </div>

    </div>



    <!-- ==========================================================
         WIDTH TOGGLE SCRIPT
    =========================================================== -->

    <script>

        const widthToggle =
            document.getElementById('receiptWidthToggle');

        const receiptCard =
            document.getElementById('receiptCard');

        const pageBody =
            document.getElementById('pageBody');

        const storeTitle =
            document.getElementById('storeTitle');

        window.addEventListener('afterprint', () => {
            window.close();
        }, { once: true });


        widthToggle.addEventListener('change', (e) => {

            const mode = e.target.value;


            /*
            |--------------------------------------------------------------------------
            | 58MM
            |--------------------------------------------------------------------------
            */

            if (mode === '58mm') {

                pageBody.className =
                    pageBody.className.replace(
                        'mode-standard',
                        'mode-58mm'
                    );


                receiptCard.classList.remove(
                    'max-w-sm',
                    'p-5'
                );


                receiptCard.classList.add(
                    'max-w-[220px]',
                    'p-2'
                );


                storeTitle.classList.remove(
                    'text-lg'
                );


                storeTitle.classList.add(
                    'text-sm'
                );


            }


            /*
            |--------------------------------------------------------------------------
            | 80MM
            |--------------------------------------------------------------------------
            */

            else {

                pageBody.className =
                    pageBody.className.replace(
                        'mode-58mm',
                        'mode-standard'
                    );


                receiptCard.classList.remove(
                    'max-w-[220px]',
                    'p-2'
                );


                receiptCard.classList.add(
                    'max-w-sm',
                    'p-5'
                );


                storeTitle.classList.remove(
                    'text-sm'
                );


                storeTitle.classList.add(
                    'text-lg'
                );

            }

        });

    </script>


<?php include __DIR__ . '/../includes/no-drag.php'; ?>
</body>

</html>
