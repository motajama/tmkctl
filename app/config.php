<?php

return [
    // Flat config keys
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'tmkctl',
    'db_user' => 'tmkctl',
    'db_pass' => 'tmkctl_dev_password',
    'db_charset' => 'utf8mb4',

    // Nested config keys, in case app/db.php expects this structure
    'database' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'tmkctl',
        'user' => 'tmkctl',
        'pass' => 'tmkctl_dev_password',
        'charset' => 'utf8mb4',
    ],

    'app_name' => 'tmkctl',
    'course_name' => 'Teorie masové kultury',
    'timezone' => 'Europe/Prague',

    // Development login password: tmkctl
    'app_password_hash' => password_hash('tmkctl', PASSWORD_DEFAULT),
    'password_hash' => password_hash('tmkctl', PASSWORD_DEFAULT),
];
