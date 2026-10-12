<?php
$systemName = 'LALENZ STORE POS';
$systemTagline = 'POS';
$settingsTableExists = $pdo->query("
    SELECT 1
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_settings'
      AND TABLE_TYPE = 'BASE TABLE'
")->fetchColumn();

if ($settingsTableExists) {
    $systemNameStmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'system_name'");
    $systemNameStmt->execute();
    $settingValue = $systemNameStmt->fetchColumn();
    $configuredSystemName = is_string($settingValue) ? trim($settingValue) : '';
    if ($configuredSystemName !== '') {
        $systemName = $configuredSystemName;
    }
}

$logo = '/LALENZ_SARISARI_POS/assets/images/logo.ico';
$configuredLogo = null;
if ($settingsTableExists) {
    $logoStmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'system_logo'");
    $logoStmt->execute();
    $configuredLogo = $logoStmt->fetchColumn();
}

if ($configuredLogo !== '__default__') {
    $customLogoPath = dirname(__DIR__) . '/assets/images/custom_logo.png';
    if (is_file($customLogoPath)) {
        $logo = '/LALENZ_SARISARI_POS/assets/images/custom_logo.png';
    }
}

if ($settingsTableExists) {
    if (
        is_string($configuredLogo)
        && preg_match('#^/LALENZ_SARISARI_POS/assets/images/uploads/logo-[a-f0-9]{32}\\.(png|jpg|webp)$#', $configuredLogo)
        && is_file(dirname(__DIR__) . substr($configuredLogo, strlen('/LALENZ_SARISARI_POS')))
    ) {
        $logo = $configuredLogo;
    }
}
?>