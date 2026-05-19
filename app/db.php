<?php

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        date_default_timezone_set($config['timezone'] ?? 'Europe/Prague');
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('PHP extension pdo_mysql is not enabled. Install/enable the PHP MySQL PDO driver, then restart the PHP server.');
    }

    $db = database_config(app_config());
    $dsn = database_dsn($db);

    try {
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException(sprintf(
            'Database connection failed. DSN=%s. Original error: %s',
            $dsn,
            $e->getMessage()
        ));
    }
    return $pdo;
}

function database_config(array $config): array
{
    $nested = $config['database'] ?? [];
    $legacy = $config['db'] ?? [];

    $host = first_config_value($config['db_host'] ?? null, $nested['host'] ?? null, $legacy['host'] ?? null, '127.0.0.1');
    $socket = first_config_value($config['db_unix_socket'] ?? null, $nested['unix_socket'] ?? null, $legacy['unix_socket'] ?? null, null);
    if (!$socket && $host === 'localhost') {
        $host = '127.0.0.1';
    }

    return [
        'host' => $host,
        'port' => (string)first_config_value($config['db_port'] ?? null, $nested['port'] ?? null, $legacy['port'] ?? null, '3306'),
        'name' => first_config_value($config['db_name'] ?? null, $nested['name'] ?? null, $legacy['database'] ?? null, $legacy['name'] ?? null, ''),
        'user' => first_config_value($config['db_user'] ?? null, $nested['user'] ?? null, $legacy['username'] ?? null, $legacy['user'] ?? null, ''),
        'pass' => first_config_value($config['db_pass'] ?? null, $nested['pass'] ?? null, $legacy['password'] ?? null, $legacy['pass'] ?? null, ''),
        'charset' => first_config_value($config['db_charset'] ?? null, $nested['charset'] ?? null, $legacy['charset'] ?? null, 'utf8mb4'),
        'unix_socket' => $socket,
    ];
}

function first_config_value(mixed ...$values): mixed
{
    foreach ($values as $value) {
        if ($value !== null && $value !== '') {
            return $value;
        }
    }
    return null;
}

function database_dsn(array $db): string
{
    if (!empty($db['unix_socket'])) {
        return sprintf(
            'mysql:unix_socket=%s;dbname=%s;charset=%s',
            $db['unix_socket'],
            $db['name'],
            $db['charset']
        );
    }

    return sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );
}

function install_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS students (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            uco VARCHAR(64) NULL,
            email VARCHAR(255) NULL,
            study_type VARCHAR(16) NOT NULL DEFAULT 'unknown',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY students_uco_unique (uco)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_stack (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            state VARCHAR(24) NOT NULL DEFAULT 'waiting',
            question_id VARCHAR(64) NULL,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY exam_stack_student_unique (student_id),
            KEY exam_stack_state_idx (state),
            CONSTRAINT exam_stack_student_fk FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_notes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            question_id VARCHAR(64) NOT NULL,
            note_text MEDIUMTEXT NULL,
            suggested_grade VARCHAR(64) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY exam_notes_student_question_unique (student_id, question_id),
            CONSTRAINT exam_notes_student_fk FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(128) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
