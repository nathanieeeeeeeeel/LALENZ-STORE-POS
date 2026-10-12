<?php

/**
 * LALENZ Sari-Sari POS
 * Database Connection
 */

// Database server
$host = 'localhost';

// Database name
$dbname = 'lalenz_sarisari_pos';

// XAMPP default MySQL username
$username = 'root';

// XAMPP default MySQL password
$password = '';

// Character set
$charset = 'utf8mb4';


/*
|--------------------------------------------------------------------------
| PDO Connection
|--------------------------------------------------------------------------
*/

$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];


try {

    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        $options
    );

} catch (PDOException $e) {

    // Don't expose database credentials/details to users.
    die('Database connection failed. Please contact the administrator.');

}