<?php

return [
    'db_host' => getenv('TMKCTL_DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('TMKCTL_DB_PORT') ?: '3306',
    'db_name' => getenv('TMKCTL_DB_NAME') ?: 'tmkctl',
    'db_user' => getenv('TMKCTL_DB_USER') ?: 'tmkctl',
    'db_pass' => getenv('TMKCTL_DB_PASS') ?: 'tmkctl_dev_password',
    'db_charset' => 'utf8mb4',

    'database' => [
        'host' => getenv('TMKCTL_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('TMKCTL_DB_PORT') ?: '3306',
        'name' => getenv('TMKCTL_DB_NAME') ?: 'tmkctl',
        'user' => getenv('TMKCTL_DB_USER') ?: 'tmkctl',
        'pass' => getenv('TMKCTL_DB_PASS') ?: 'tmkctl_dev_password',
        'charset' => 'utf8mb4',
    ],

    'app_name' => 'tmkctl',
    'course_name' => getenv('TMKCTL_COURSE_NAME') ?: 'Teorie masové kultury',
    'timezone' => getenv('TMKCTL_TIMEZONE') ?: 'Europe/Prague',
    'base_path' => getenv('TMKCTL_BASE_PATH') ?: '',

    // Development login password: tmkctl. Replace before deployment.
    'app_password_hash' => getenv('TMKCTL_PASSWORD_HASH') ?: password_hash('tmkctl', PASSWORD_DEFAULT),
    'password_hash' => getenv('TMKCTL_PASSWORD_HASH') ?: password_hash('tmkctl', PASSWORD_DEFAULT),
];
