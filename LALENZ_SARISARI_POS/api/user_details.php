<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/permissions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Your session has expired. Please log in again.',
    ]);
    exit;
}

try {
    if (!hasAppPermission($pdo, fetchUser(), 'sales.view')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'You do not have permission to view sales.',
        ]);
        exit;
    }

    // ---------------------------------------------------------
    // GET USER ID
    // ---------------------------------------------------------

    $userId = filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );

    if (!$userId || $userId <= 0) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid user ID.'
        ]);

        exit;
    }


    // ---------------------------------------------------------
    // GET USER
    // ---------------------------------------------------------

    $stmt = $pdo->prepare("
        SELECT
            id,
            username,
            full_name,
            role
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);


    // ---------------------------------------------------------
    // USER NOT FOUND
    // ---------------------------------------------------------

    if (!$user) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'User not found.'
        ]);

        exit;
    }


    // ---------------------------------------------------------
    // RETURN USER DETAILS
    // ---------------------------------------------------------

    echo json_encode([
        'success' => true,
        'id' => (int) $user['id'],
        'username' => $user['username'] ?? '',
        'full_name' => $user['full_name'] ?? '',
        'role' => $user['role'] ?? 'Staff'
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database error.'
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Server error.'
    ]);
}
