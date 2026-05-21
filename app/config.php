<?php

$defaultConfig = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'tmkctl',
    'db_user' => 'tmkctl',
    'db_pass' => '',
    'db_charset' => 'utf8mb4',

    'database' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'tmkctl',
        'user' => 'tmkctl',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    'table_prefix' => 'tmkctl_',

    'app_name' => 'tmkctl',
    'course_name' => 'Teorie masové komunikace',
    'timezone' => 'Europe/Prague',

    'install_enabled' => false,
    'debug' => false,

    'app_password_hash' => '',
    'password_hash' => '',

    'is_import_study_code_map' => [
        'MSZU01' => 'single',
        'MSZU02' => 'double',
    ],
];

$localConfigPath = __DIR__ . '/config.local.php';

if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;

    if (!is_array($localConfig)) {
        throw new RuntimeException('app/config.local.php must return an array.');
    }

    /*
     * Important:
     * local config must override defaults.
     */
    $defaultConfig = array_replace_recursive($defaultConfig, $localConfig);
}

return $defaultConfig;
