<?php

require_once __DIR__ . '/db.php';

function get_app_setting(string $key, mixed $default = null, ?PDO $pdo = null): mixed
{
    $pdo ??= db();
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :k');
    $stmt->execute([':k' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : $value;
}

function set_app_setting(string $key, ?string $value, ?PDO $pdo = null): void
{
    $pdo ??= db();
    $stmt = $pdo->prepare('
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ');
    $stmt->execute([':k' => $key, ':v' => $value]);
}
