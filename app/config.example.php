<?php

return [
    // Prefer 127.0.0.1 for local TCP connections. Using localhost may make
    // some PHP/MySQL installations try a Unix socket and fail with:
    // SQLSTATE[HY000] [2002] No such file or directory
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'tmkctl',
    'db_user' => 'tmkctl',
    'db_pass' => 'change-me',
    'db_charset' => 'utf8mb4',
    'table_prefix' => 'tmkctl_',

    'database' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'tmkctl',
        'user' => 'tmkctl',
        'pass' => 'change-me',
        'charset' => 'utf8mb4',
    ],

    'app_name' => 'tmkctl',
    'course_name' => 'Teorie masové kultury',
    'timezone' => 'Europe/Prague',
    'base_path' => '',

    // Enable only while running public/install.php. Set to false after setup.
    'install_enabled' => true,

    // IS MU paste import study-code mapping.
    'is_import_study_code_map' => [
        'MSZU01' => 'single',
        'MSZU02' => 'double',
    ],

    // Generate with:
    // php -r "echo password_hash('your-shared-password', PASSWORD_DEFAULT), PHP_EOL;"
    // Development example password: tmkctl
    'app_password_hash' => password_hash('tmkctl', PASSWORD_DEFAULT),
    'password_hash' => password_hash('tmkctl', PASSWORD_DEFAULT),
];
