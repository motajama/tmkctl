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
        throw new RuntimeException('PHP extension pdo_mysql is not enabled. Install or enable the PHP MySQL PDO driver, then restart the PHP server.');
    }

    $cfg = database_config(app_config());
    $dsn = database_dsn($cfg);

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('Database connection failed. DSN=' . $dsn . '. Original error: ' . $e->getMessage());
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

function database_dsn(array $cfg): string
{
    if (!empty($cfg['unix_socket'])) {
        return sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $cfg['unix_socket'], $cfg['name'], $cfg['charset']);
    }
    return sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);
}

function db_table_name(string $baseName): string
{
    $prefix = (string)(app_config()['table_prefix'] ?? 'tmkctl_');
    $name = $prefix . $baseName;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix) || !preg_match('/^[A-Za-z0-9_]+$/', $baseName) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('Neplatný název databázové tabulky.');
    }
    return $name;
}

function db_table(string $baseName): string
{
    return '`' . db_table_name($baseName) . '`';
}

function install_schema(PDO $pdo): void
{
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $appSettings = db_table('app_settings');
    $students = db_table('students');
    $examStack = db_table('exam_stack');
    $examNotes = db_table('exam_notes');
    $studentsName = db_table_name('students');
    $examStackName = db_table_name('exam_stack');
    $examNotesName = db_table_name('exam_notes');
    $fkStackStudent = db_table('fk_exam_stack_student');
    $fkNotesStudent = db_table('fk_exam_notes_student');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$appSettings} (
            setting_key VARCHAR(128) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$students} (
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
    normalize_students_table_for_foreign_keys($pdo, $dbName, $studentsName);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$examStack} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            state VARCHAR(24) NOT NULL DEFAULT 'waiting',
            question_id VARCHAR(64) NULL,
            position INT NOT NULL DEFAULT 0,
            preparation_started_at DATETIME NULL,
            examination_started_at DATETIME NULL,
            finished_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY exam_stack_student_unique (student_id),
            KEY exam_stack_state_idx (state),
            CONSTRAINT {$fkStackStudent} FOREIGN KEY (student_id) REFERENCES {$students}(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    add_column_if_missing($pdo, $dbName, $examStackName, 'preparation_started_at', 'DATETIME NULL');
    add_column_if_missing($pdo, $dbName, $examStackName, 'examination_started_at', 'DATETIME NULL');
    add_column_if_missing($pdo, $dbName, $examStackName, 'finished_at', 'DATETIME NULL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$examNotes} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            question_id VARCHAR(64) NULL,
            note_text MEDIUMTEXT NULL,
            suggested_grade VARCHAR(64) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY exam_notes_student_question_unique (student_id, question_id),
            KEY exam_notes_student_question_idx (student_id, question_id),
            CONSTRAINT {$fkNotesStudent} FOREIGN KEY (student_id) REFERENCES {$students}(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    make_column_nullable_if_needed($pdo, $dbName, $examNotesName, 'question_id', 'VARCHAR(64) NULL');
    $pdo->exec("UPDATE {$examNotes} SET question_id = NULL WHERE question_id = \"__general__\"");
}

function normalize_students_table_for_foreign_keys(PDO $pdo, string $dbName, string $studentsName): void
{
    $stmt = $pdo->prepare('
        SELECT ENGINE
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table
    ');
    $stmt->execute([':schema' => $dbName, ':table' => $studentsName]);
    $engine = (string)$stmt->fetchColumn();
    if ($engine !== '' && strcasecmp($engine, 'InnoDB') !== 0) {
        $pdo->exec('ALTER TABLE ' . db_table('students') . ' ENGINE=InnoDB');
    }

    $stmt = $pdo->prepare('
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = "id"
    ');
    $stmt->execute([':schema' => $dbName, ':table' => $studentsName]);
    $columnType = strtolower((string)$stmt->fetchColumn());
    if ($columnType !== '' && !str_contains($columnType, 'unsigned')) {
        $pdo->exec('ALTER TABLE ' . db_table('students') . ' MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');
    }
}

function add_column_if_missing(PDO $pdo, string $dbName, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column
    ');
    $stmt->execute([':schema' => $dbName, ':table' => $table, ':column' => $column]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    }
}

function make_column_nullable_if_needed(PDO $pdo, string $dbName, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare('
        SELECT IS_NULLABLE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column
    ');
    $stmt->execute([':schema' => $dbName, ':table' => $table, ':column' => $column]);
    if ((string)$stmt->fetchColumn() === 'NO') {
        $pdo->exec(sprintf('ALTER TABLE `%s` MODIFY `%s` %s', $table, $column, $definition));
    }
}
