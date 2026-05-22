<?php

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $base = rtrim(app_config()['base_path'] ?? '', '/');
    return $base . '/' . ltrim($path, '/');
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Je vyžadována metoda POST.'], 405);
    }
}

function public_error_message(Throwable $e): string
{
    $message = $e->getMessage();
    if (function_exists('app_config')) {
        try {
            $config = app_config();
            if (empty($config['debug']) && !($e instanceof InvalidArgumentException)) {
                return 'Požadavek se nepodařilo zpracovat. Zkontrolujte nastavení aplikace nebo kontaktujte správce.';
            }
            $secrets = [
                $config['db_pass'] ?? null,
                $config['database']['pass'] ?? null,
                $config['db']['password'] ?? null,
            ];
            foreach ($secrets as $secret) {
                if (is_string($secret) && $secret !== '') {
                    $message = str_replace($secret, '[redacted]', $message);
                }
            }
        } catch (Throwable) {
            // Keep public error formatting best-effort.
        }
    }
    return $message;
}
