<?php

$defaults = [
    'db_host' => getenv('TMKCTL_DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('TMKCTL_DB_PORT') ?: '3306',
    'db_name' => getenv('TMKCTL_DB_NAME') ?: '',
    'db_user' => getenv('TMKCTL_DB_USER') ?: '',
    'db_pass' => getenv('TMKCTL_DB_PASS') ?: '',
    'db_charset' => 'utf8mb4',

    'database' => [
        'host' => getenv('TMKCTL_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('TMKCTL_DB_PORT') ?: '3306',
        'name' => getenv('TMKCTL_DB_NAME') ?: '',
        'user' => getenv('TMKCTL_DB_USER') ?: '',
        'pass' => getenv('TMKCTL_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    'app_name' => 'tmkctl',
    'course_name' => getenv('TMKCTL_COURSE_NAME') ?: 'Teorie masové kultury',
    'timezone' => getenv('TMKCTL_TIMEZONE') ?: 'Europe/Prague',
    'base_path' => getenv('TMKCTL_BASE_PATH') ?: '',

    'install_enabled' => false,

    'is_import_study_code_map' => [
        'MSZU01' => 'single',
        'MSZU02' => 'double',
    ],

    'app_password_hash' => getenv('TMKCTL_PASSWORD_HASH') ?: '',
    'password_hash' => getenv('TMKCTL_PASSWORD_HASH') ?: '',
];

$localPath = __DIR__ . '/config.local.php';
if (is_file($localPath)) {
    $local = require $localPath;
    if (!is_array($local)) {
        throw new RuntimeException('app/config.local.php must return a configuration array.');
    }
    return array_replace_recursive($defaults, $local);
}

return $defaults;
