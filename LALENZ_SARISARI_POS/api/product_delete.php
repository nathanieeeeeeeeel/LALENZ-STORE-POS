<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/system.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/action_log.php';

requireAuth();
$userId = fetchUser();
if (!hasAppPermission($pdo, $userId, 'products.manage')) {
    appLogAction($pdo, $userId, 'product.deleted', 'product', (int) ($_POST['product_id'] ?? $_GET['id'] ?? 0) ?: null, null, 'failure', [
        'reason' => 'Permission denied.',
    ]);
}
requireAppPermission($pdo, $userId, 'products.manage');
ensureActionLogTable($pdo);

// Get product ID from POST or GET request
$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($productId <= 0) {
    appLogAction($pdo, $userId, 'product.deleted', 'product', null, null, 'failure', [
        'reason' => 'Invalid product ID.',
    ]);
    header('Location: ../products.php?error=Invalid product ID.');
    exit;
}

try {
    // Verify product exists
    $stmtCheck = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmtCheck->execute([$productId]);
    $product = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        appLogAction($pdo, $userId, 'product.deleted', 'product', $productId, null, 'failure', [
            'reason' => 'Product not found.',
        ]);
        header('Location: ../products.php?error=Product not found.');
        exit;
    }

    // Attempt to delete the product (Fixed -> instead of .)
    $pdo->beginTransaction();
    $stmtDelete = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmtDelete->execute([$productId]);
    appLogAction($pdo, $userId, 'product.deleted', 'product', $productId, (string) $product['name'], 'success');
    $pdo->commit();

    header('Location: ../products.php?success=Product successfully deleted.');
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appLogAction($pdo, $userId, 'product.deleted', 'product', $productId, isset($product['name']) ? (string) $product['name'] : null, 'failure', [
        'reason' => $e->getCode() === '23000'
            ? 'Product is referenced by existing sales.'
            : 'Database error while deleting product.',
    ]);
    // Handle foreign key constraint error (if product is used in sale_items)
    if ($e->getCode() == '23000') {
        header('Location: ../products.php?error=Cannot delete this product because it is tied to past sales transactions.');
    } else {
        header('Location: ../products.php?error=Failed to delete product: ' . $e->getMessage());
    }
    exit;
}