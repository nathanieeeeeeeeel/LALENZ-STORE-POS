<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

header('Location: login.php');
exit;
