<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

const WORKSPACE_PRESENCE_TTL_SECONDS = 180;
const CLIENT_COOKIE_NAME = 'tmkctl_client_id';

function is_https_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function client_cookie_path(): string
{
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $path === '' ? '/' : $path . '/';
}

function set_client_id_cookie(string $clientId): void
{
    setcookie(CLIENT_COOKIE_NAME, $clientId, [
        'expires' => time() + 30 * 86400,
        'path' => client_cookie_path(),
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function ensure_client_cookie_queued(): string
{
    start_app_session();
    $clientId = (string)($_COOKIE[CLIENT_COOKIE_NAME] ?? $_SESSION['pending_client_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $clientId)) {
        $clientId = bin2hex(random_bytes(16));
    }
    $_SESSION['pending_client_id'] = $clientId;
    set_client_id_cookie($clientId);
    return $clientId;
}

function current_client_id(): ?string
{
    $clientId = (string)($_COOKIE[CLIENT_COOKIE_NAME] ?? '');
    return preg_match('/^[a-f0-9]{32}$/', $clientId) ? $clientId : null;
}

function client_cookie_warning(): string
{
    return 'Pro sdílené relace musí být v prohlížeči povolené cookies. Bez cookies nelze spolehlivě určit připojeného uživatele.';
}

function require_client_id(): string
{
    $clientId = current_client_id();
    if ($clientId === null) {
        ensure_client_cookie_queued();
        throw new RuntimeException(client_cookie_warning());
    }
    return $clientId;
}

function current_workspace_id(): ?int
{
    start_app_session();
    $workspaceId = $_SESSION['workspace_id'] ?? null;
    return is_int($workspaceId) || ctype_digit((string)$workspaceId) ? (int)$workspaceId : null;
}

function set_current_workspace_id(int $workspaceId): void
{
    start_app_session();
    $_SESSION['workspace_id'] = $workspaceId;
}

function clear_current_workspace_id(): void
{
    start_app_session();
    unset($_SESSION['workspace_id']);
}

function get_workspace(PDO $pdo, int $workspaceId): ?array
{
    $workspaces = db_table('workspaces');
    $stmt = $pdo->prepare("SELECT * FROM {$workspaces} WHERE id = :id AND is_archived = 0");
    $stmt->execute([':id' => $workspaceId]);
    return $stmt->fetch() ?: null;
}

function require_current_workspace(PDO $pdo): int
{
    $clientId = current_client_id();
    if ($clientId === null) {
        ensure_client_cookie_queued();
        if (function_exists('request_expects_json') && request_expects_json()) {
            json_response(['ok' => false, 'error' => client_cookie_warning()], 400);
        }
        header('Location: ' . workspace_selection_url());
        exit;
    }
    $workspaceId = current_workspace_id();
    if (!$workspaceId || !get_workspace($pdo, $workspaceId)) {
        clear_current_workspace_id();
        if (function_exists('request_expects_json') && request_expects_json()) {
            json_response(['ok' => false, 'error' => 'Vyberte relaci / termín.'], 409);
        }
        header('Location: ' . workspace_selection_url());
        exit;
    }
    touch_workspace_presence($pdo, $workspaceId, $clientId);
    return $workspaceId;
}

function workspace_selection_url(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    return str_ends_with($script, '/api/heartbeat.php') || str_contains($script, '/api/')
        ? '../workspaces.php'
        : 'workspaces.php';
}

function workspace_default_label(): string
{
    return 'Termín ' . date('Y-m-d H:i');
}

function create_workspace(PDO $pdo, string $label, string $clientId): int
{
    $label = trim($label);
    if ($label === '') {
        $label = workspace_default_label();
    }
    $label = function_exists('mb_substr') ? mb_substr($label, 0, 255, 'UTF-8') : substr($label, 0, 255);
    $workspaces = db_table('workspaces');
    $stmt = $pdo->prepare("
        INSERT INTO {$workspaces} (label, created_by_client_id, last_seen_at)
        VALUES (:label, :client_id, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([':label' => $label, ':client_id' => $clientId]);
    return (int)$pdo->lastInsertId();
}

function touch_workspace_presence(PDO $pdo, int $workspaceId, string $clientId, ?string $userLabel = null): void
{
    $presence = db_table('workspace_presence');
    $workspaces = db_table('workspaces');
    $stmt = $pdo->prepare("
        INSERT INTO {$presence} (workspace_id, client_id, user_label, last_seen_at)
        VALUES (:workspace_id, :client_id, :user_label, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            user_label = VALUES(user_label),
            last_seen_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        ':workspace_id' => $workspaceId,
        ':client_id' => $clientId,
        ':user_label' => $userLabel,
    ]);
    $stmt = $pdo->prepare("UPDATE {$workspaces} SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->execute([':id' => $workspaceId]);
}

function remove_workspace_presence(PDO $pdo, int $workspaceId, string $clientId): void
{
    $presence = db_table('workspace_presence');
    $stmt = $pdo->prepare("DELETE FROM {$presence} WHERE workspace_id = :workspace_id AND client_id = :client_id");
    $stmt->execute([':workspace_id' => $workspaceId, ':client_id' => $clientId]);
}

function cleanup_stale_presence(PDO $pdo): void
{
    $presence = db_table('workspace_presence');
    $cutoff = date('Y-m-d H:i:s', time() - WORKSPACE_PRESENCE_TTL_SECONDS * 2);
    $stmt = $pdo->prepare("DELETE FROM {$presence} WHERE last_seen_at < :cutoff");
    $stmt->execute([':cutoff' => $cutoff]);
}

function list_active_workspaces(PDO $pdo): array
{
    cleanup_stale_presence($pdo);
    $workspaces = db_table('workspaces');
    $presence = db_table('workspace_presence');
    $cutoff = date('Y-m-d H:i:s', time() - WORKSPACE_PRESENCE_TTL_SECONDS);
    $stmt = $pdo->prepare("
        SELECT w.id, w.label, w.created_at, w.updated_at, w.last_seen_at, COUNT(p.id) AS active_user_count
        FROM {$workspaces} w
        JOIN {$presence} p ON p.workspace_id = w.id
            AND p.last_seen_at >= :cutoff
        WHERE w.is_archived = 0
        GROUP BY w.id, w.label, w.created_at, w.updated_at, w.last_seen_at
        ORDER BY MAX(p.last_seen_at) DESC, w.created_at DESC
    ");
    $stmt->execute([':cutoff' => $cutoff]);
    return $stmt->fetchAll();
}

function current_workspace_label(PDO $pdo): string
{
    $workspaceId = current_workspace_id();
    if (!$workspaceId) {
        return '';
    }
    $workspace = get_workspace($pdo, $workspaceId);
    return $workspace ? (string)$workspace['label'] : '';
}

function is_placeholder_exam_label(?string $label): bool
{
    $label = trim((string)$label);
    if ($label === '') {
        return true;
    }
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    $normalized = strtr($normalized, [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
        'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's',
        'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
    ]);
    $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    if ($normalized === '' || str_contains($normalized, 'placeholder')) {
        return true;
    }
    return in_array($normalized, [
        'current exam label',
        'datum terminu',
        'nazev terminu',
        'termin',
    ], true) || str_starts_with($normalized, 'termin...');
}

function workspace_display_label(PDO $pdo, int $workspaceId): string
{
    $workspace = get_workspace($pdo, $workspaceId);
    $workspaceLabel = trim((string)($workspace['label'] ?? ''));
    if ($workspaceLabel !== '') {
        return $workspaceLabel;
    }
    $saved = (string)get_app_setting('current_exam_label', '', $workspaceId, $pdo);
    return !is_placeholder_exam_label($saved) ? trim($saved) : 'tmkctl';
}

function exam_display_label(PDO $pdo, int $workspaceId): string
{
    return workspace_display_label($pdo, $workspaceId);
}

function exam_filename_label(PDO $pdo, int $workspaceId): string
{
    $label = exam_display_label($pdo, $workspaceId);
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $label) ?? '';
    $safe = trim($safe, '-_');
    return $safe !== '' ? $safe : 'tmkctl';
}

function debug_stage_note(): string
{
    $path = dirname(__DIR__) . '/data/debug-stage.txt';
    if (!is_file($path) || !is_readable($path)) {
        return 'Aktuální fáze není popsána. Soubor data/debug-stage.txt nebyl nalezen.';
    }
    $raw = file_get_contents($path);
    return trim((string)$raw) !== '' ? trim((string)$raw) : 'Aktuální fáze není popsána.';
}
