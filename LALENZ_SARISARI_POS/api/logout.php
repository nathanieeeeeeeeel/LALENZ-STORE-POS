<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';

appRevokeRememberToken($pdo);

// Clear all session variables
$_SESSION = [];

// Destroy the session
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// Redirect to login
header('Location: /LALENZ_SARISARI_POS/login.php');
exit;
