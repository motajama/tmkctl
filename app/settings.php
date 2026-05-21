<?php

require_once __DIR__ . '/db.php';

function normalize_setting_args(mixed $workspaceOrPdo = null, ?PDO $pdo = null): array
{
    if ($workspaceOrPdo instanceof PDO) {
        return [null, $workspaceOrPdo];
    }
    return [$workspaceOrPdo === null ? null : (int)$workspaceOrPdo, $pdo ?? db()];
}

function get_app_setting(string $key, mixed $default = null, mixed $workspaceOrPdo = null, ?PDO $pdo = null): mixed
{
    [$workspaceId, $pdo] = normalize_setting_args($workspaceOrPdo, $pdo);
    $appSettings = db_table('app_settings');
    if ($workspaceId === null) {
        $stmt = $pdo->prepare("SELECT setting_value FROM {$appSettings} WHERE setting_key = :k AND workspace_id IS NULL LIMIT 1");
        $stmt->execute([':k' => $key]);
    } else {
        $stmt = $pdo->prepare("SELECT setting_value FROM {$appSettings} WHERE setting_key = :k AND workspace_id = :workspace_id LIMIT 1");
        $stmt->execute([':k' => $key, ':workspace_id' => $workspaceId]);
    }
    $value = $stmt->fetchColumn();
    return $value === false ? $default : $value;
}

function set_app_setting(string $key, ?string $value, mixed $workspaceOrPdo = null, ?PDO $pdo = null): void
{
    [$workspaceId, $pdo] = normalize_setting_args($workspaceOrPdo, $pdo);
    $appSettings = db_table('app_settings');
    if ($workspaceId === null) {
        $stmt = $pdo->prepare("SELECT setting_key FROM {$appSettings} WHERE setting_key = :k AND workspace_id IS NULL LIMIT 1");
        $stmt->execute([':k' => $key]);
        if ($stmt->fetchColumn() !== false) {
            $stmt = $pdo->prepare("UPDATE {$appSettings} SET setting_value = :v WHERE setting_key = :k AND workspace_id IS NULL");
            $stmt->execute([':k' => $key, ':v' => $value]);
            return;
        }
        $stmt = $pdo->prepare("INSERT INTO {$appSettings} (workspace_id, setting_key, setting_value) VALUES (NULL, :k, :v)");
        $stmt->execute([':k' => $key, ':v' => $value]);
        return;
    }
    $stmt = $pdo->prepare("
        INSERT INTO {$appSettings} (workspace_id, setting_key, setting_value)
        VALUES (:workspace_id, :k, :v)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([':workspace_id' => $workspaceId, ':k' => $key, ':v' => $value]);
}
