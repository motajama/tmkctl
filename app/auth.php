<?php

require_once __DIR__ . '/db.php';

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('tmkctl_session');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]);
    }
}

function is_authenticated(): bool
{
    start_app_session();
    return !empty($_SESSION['authenticated']);
}

function require_auth(): void
{
    if (!is_authenticated()) {
        header('Location: login.php');
        exit;
    }
}

function login_with_password(string $password): bool
{
    start_app_session();
    $config = app_config();
    $hash = $config['app_password_hash'] ?? $config['password_hash'] ?? '';
    if ($hash && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return true;
    }
    return false;
}

function logout(): void
{
    start_app_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function csrf_token(): string
{
    start_app_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    start_app_session();
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        json_response(['ok' => false, 'error' => 'Neplatný bezpečnostní token. Obnovte stránku.'], 419);
    }
}
