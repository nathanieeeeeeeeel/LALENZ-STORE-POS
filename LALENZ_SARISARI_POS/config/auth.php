<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Current User ID
|--------------------------------------------------------------------------
*/

function fetchUser(): int
{
    if (empty($_SESSION['user_id'])) {
        appRestoreRememberedUser();
    }

    return (int) ($_SESSION['user_id'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Authentication Check
|--------------------------------------------------------------------------
*/

function isLoggedIn(): bool
{
    if (empty($_SESSION['user_id'])) {
        appRestoreRememberedUser();
    }

    return !empty($_SESSION['user_id']);
}

function appRememberCookiePath(): string
{
    return '/LALENZ_SARISARI_POS/';
}

function appSetRememberCookie(string $value, int $expiresAt): void
{
    setcookie('lalenz_remember', $value, [
        'expires' => $expiresAt,
        'path' => appRememberCookiePath(),
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function appClearRememberCookie(): void
{
    appSetRememberCookie('', time() - 3600);
    unset($_COOKIE['lalenz_remember']);
}

function appSetAuthenticatedUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
}

function appEnsureRememberTokenTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_remember_tokens (
            selector CHAR(32) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_remember_tokens_user_id (user_id),
            INDEX idx_user_remember_tokens_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function appIssueRememberToken(PDO $pdo, int $userId): void
{
    appEnsureRememberTokenTable($pdo);

    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $expiresAt = time() + (30 * 24 * 60 * 60);

    $stmt = $pdo->prepare("
        INSERT INTO user_remember_tokens (selector, user_id, token_hash, expires_at)
        VALUES (:selector, :user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 30 DAY))
    ");
    $stmt->execute([
        ':selector' => $selector,
        ':user_id' => $userId,
        ':token_hash' => hash('sha256', $validator),
    ]);

    appSetRememberCookie($selector . ':' . $validator, $expiresAt);
}

function appRevokeRememberToken(PDO $pdo): void
{
    $cookie = $_COOKIE['lalenz_remember'] ?? '';
    if (is_string($cookie) && preg_match('/^([a-f0-9]{32}):[a-f0-9]{64}$/', $cookie, $matches)) {
        appEnsureRememberTokenTable($pdo);
        $stmt = $pdo->prepare('DELETE FROM user_remember_tokens WHERE selector = :selector');
        $stmt->execute([':selector' => $matches[1]]);
    }

    appClearRememberCookie();
}

function appRestoreRememberedUser(): void
{
    $cookie = $_COOKIE['lalenz_remember'] ?? '';
    if (!is_string($cookie) || !preg_match('/^([a-f0-9]{32}):([a-f0-9]{64})$/', $cookie, $matches)) {
        return;
    }

    global $pdo;
    if (!$pdo instanceof PDO) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT
            token.selector,
            token.token_hash,
            u.id,
            u.username,
            u.email,
            u.full_name,
            u.role,
            u.is_active
        FROM user_remember_tokens AS token
        INNER JOIN users AS u ON u.id = token.user_id
        WHERE token.selector = :selector
          AND token.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([':selector' => $matches[1]]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || (int) $user['is_active'] !== 1 || !hash_equals($user['token_hash'], hash('sha256', $matches[2]))) {
        appRevokeRememberToken($pdo);
        return;
    }

    $delete = $pdo->prepare('DELETE FROM user_remember_tokens WHERE selector = :selector');
    $delete->execute([':selector' => $matches[1]]);

    appSetAuthenticatedUser($user);
    appIssueRememberToken($pdo, (int) $user['id']);
}

/*
|--------------------------------------------------------------------------
| Require Authentication
|--------------------------------------------------------------------------
|
| Use this on pages that require a logged-in user.
|
*/

function requireAuth(): void
{
    if (!isLoggedIn()) {
        header('Location: /LALENZ_SARISARI_POS/login.php');
        exit;
    }
}
