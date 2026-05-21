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
    $workspaces = db_table('workspaces');
    $workspacePresence = db_table('workspace_presence');
    $students = db_table('students');
    $examStack = db_table('exam_stack');
    $examNotes = db_table('exam_notes');
    $appSettingsName = db_table_name('app_settings');
    $workspacesName = db_table_name('workspaces');
    $studentsName = db_table_name('students');
    $examStackName = db_table_name('exam_stack');
    $examNotesName = db_table_name('exam_notes');
    $fkPresenceWorkspace = db_table('fk_workspace_presence_workspace');
    $fkStackStudent = db_table('fk_exam_stack_student');
    $fkNotesStudent = db_table('fk_exam_notes_student');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$appSettings} (
            workspace_id INT UNSIGNED NULL,
            setting_key VARCHAR(128) NOT NULL,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY app_settings_scope_key_unique (workspace_id, setting_key),
            KEY app_settings_key_idx (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    add_column_if_missing($pdo, $dbName, $appSettingsName, 'workspace_id', 'INT UNSIGNED NULL FIRST');
    drop_primary_key_if_columns($pdo, $dbName, $appSettingsName, ['setting_key']);
    add_index_if_missing($pdo, $dbName, $appSettingsName, 'app_settings_scope_key_unique', 'UNIQUE KEY app_settings_scope_key_unique (workspace_id, setting_key)');
    add_index_if_missing($pdo, $dbName, $appSettingsName, 'app_settings_key_idx', 'KEY app_settings_key_idx (setting_key)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$workspaces} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_seen_at TIMESTAMP NULL,
            created_by_client_id VARCHAR(64) NULL,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            KEY workspaces_active_idx (is_archived, last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$workspacePresence} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT UNSIGNED NOT NULL,
            client_id VARCHAR(64) NOT NULL,
            user_label VARCHAR(255) NULL,
            last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY workspace_client (workspace_id, client_id),
            KEY workspace_presence_seen_idx (last_seen_at),
            CONSTRAINT {$fkPresenceWorkspace} FOREIGN KEY (workspace_id) REFERENCES {$workspaces}(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$students} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            uco VARCHAR(64) NULL,
            email VARCHAR(255) NULL,
            study_type VARCHAR(16) NOT NULL DEFAULT 'unknown',
            workspace_id INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY students_workspace_uco_unique (workspace_id, uco),
            KEY students_workspace_idx (workspace_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    normalize_students_table_for_foreign_keys($pdo, $dbName, $studentsName);
    add_column_if_missing($pdo, $dbName, $studentsName, 'workspace_id', 'INT UNSIGNED NULL AFTER study_type');
    drop_index_if_not_used_by_foreign_key($pdo, $dbName, $studentsName, 'students_uco_unique');
    add_index_if_missing($pdo, $dbName, $studentsName, 'students_workspace_uco_unique', 'UNIQUE KEY students_workspace_uco_unique (workspace_id, uco)');
    add_index_if_missing($pdo, $dbName, $studentsName, 'students_workspace_idx', 'KEY students_workspace_idx (workspace_id)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$examStack} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT UNSIGNED NULL,
            student_id INT UNSIGNED NOT NULL,
            state VARCHAR(24) NOT NULL DEFAULT 'waiting',
            question_id VARCHAR(64) NULL,
            position INT NOT NULL DEFAULT 0,
            preparation_started_at DATETIME NULL,
            examination_started_at DATETIME NULL,
            finished_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY exam_stack_workspace_student_unique (workspace_id, student_id),
            KEY exam_stack_student_idx (student_id),
            KEY exam_stack_state_idx (workspace_id, state),
            CONSTRAINT {$fkStackStudent} FOREIGN KEY (student_id) REFERENCES {$students}(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    add_column_if_missing($pdo, $dbName, $examStackName, 'workspace_id', 'INT UNSIGNED NULL AFTER id');
    add_column_if_missing($pdo, $dbName, $examStackName, 'preparation_started_at', 'DATETIME NULL');
    add_column_if_missing($pdo, $dbName, $examStackName, 'examination_started_at', 'DATETIME NULL');
    add_column_if_missing($pdo, $dbName, $examStackName, 'finished_at', 'DATETIME NULL');
    add_index_if_missing($pdo, $dbName, $examStackName, 'exam_stack_workspace_student_unique', 'UNIQUE KEY exam_stack_workspace_student_unique (workspace_id, student_id)');
    add_index_if_missing($pdo, $dbName, $examStackName, 'exam_stack_student_idx', 'KEY exam_stack_student_idx (student_id)');
    add_index_if_missing($pdo, $dbName, $examStackName, 'exam_stack_state_idx', 'KEY exam_stack_state_idx (workspace_id, state)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$examNotes} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT UNSIGNED NULL,
            student_id INT UNSIGNED NOT NULL,
            question_id VARCHAR(64) NULL,
            note_text MEDIUMTEXT NULL,
            suggested_grade VARCHAR(64) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY exam_notes_workspace_student_question_unique (workspace_id, student_id, question_id),
            KEY exam_notes_student_idx (student_id),
            KEY exam_notes_student_question_idx (workspace_id, student_id, question_id),
            CONSTRAINT {$fkNotesStudent} FOREIGN KEY (student_id) REFERENCES {$students}(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    add_column_if_missing($pdo, $dbName, $examNotesName, 'workspace_id', 'INT UNSIGNED NULL AFTER id');
    make_column_nullable_if_needed($pdo, $dbName, $examNotesName, 'question_id', 'VARCHAR(64) NULL');
    $pdo->exec("UPDATE {$examNotes} SET question_id = NULL WHERE question_id = \"__general__\"");
    add_index_if_missing($pdo, $dbName, $examNotesName, 'exam_notes_workspace_student_question_unique', 'UNIQUE KEY exam_notes_workspace_student_question_unique (workspace_id, student_id, question_id)');
    add_index_if_missing($pdo, $dbName, $examNotesName, 'exam_notes_student_idx', 'KEY exam_notes_student_idx (student_id)');
    add_index_if_missing($pdo, $dbName, $examNotesName, 'exam_notes_student_question_idx', 'KEY exam_notes_student_question_idx (workspace_id, student_id, question_id)');

    migrate_existing_data_to_default_workspace($pdo);
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

function add_index_if_missing(PDO $pdo, string $dbName, string $table, string $index, string $definition): void
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND INDEX_NAME = :index_name
    ');
    $stmt->execute([':schema' => $dbName, ':table' => $table, ':index_name' => $index]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD %s', $table, $definition));
    }
}

function drop_index_if_not_used_by_foreign_key(PDO $pdo, string $dbName, string $table, string $index): void
{
    $stmt = $pdo->prepare('
        SELECT COLUMN_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND INDEX_NAME = :index_name
        ORDER BY SEQ_IN_INDEX
    ');
    $stmt->execute([':schema' => $dbName, ':table' => $table, ':index_name' => $index]);
    $indexColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$indexColumns) {
        return;
    }

    $stmt = $pdo->prepare('
        SELECT COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = :table
          AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY CONSTRAINT_NAME, POSITION_IN_UNIQUE_CONSTRAINT
    ');
    $stmt->execute([':schema' => $dbName, ':table' => $table]);
    $foreignKeyColumns = array_unique($stmt->fetchAll(PDO::FETCH_COLUMN));
    if (array_intersect($indexColumns, $foreignKeyColumns)) {
        return;
    }

    $pdo->exec(sprintf('ALTER TABLE `%s` DROP KEY `%s`', $table, $index));
}

function drop_primary_key_if_columns(PDO $pdo, string $dbName, string $table, array $columns): void
{
    $stmt = $pdo->prepare('
        SELECT COLUMN_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND INDEX_NAME = "PRIMARY"
        ORDER BY SEQ_IN_INDEX
    ');
    $stmt->execute([':schema' => $dbName, ':table' => $table]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($existing === $columns) {
        $pdo->exec(sprintf('ALTER TABLE `%s` DROP PRIMARY KEY', $table));
    }
}

function migrate_existing_data_to_default_workspace(PDO $pdo): void
{
    $workspaces = db_table('workspaces');
    $students = db_table('students');
    $examStack = db_table('exam_stack');
    $examNotes = db_table('exam_notes');
    $appSettings = db_table('app_settings');
    $hasRows = (int)$pdo->query("SELECT COUNT(*) FROM {$students} WHERE workspace_id IS NULL")->fetchColumn() > 0
        || (int)$pdo->query("SELECT COUNT(*) FROM {$examStack} WHERE workspace_id IS NULL")->fetchColumn() > 0
        || (int)$pdo->query("SELECT COUNT(*) FROM {$examNotes} WHERE workspace_id IS NULL")->fetchColumn() > 0;
    if (!$hasRows) {
        return;
    }
    $stmt = $pdo->prepare("SELECT id FROM {$workspaces} WHERE label = :label ORDER BY id ASC LIMIT 1");
    $stmt->execute([':label' => 'Migrated default workspace']);
    $workspaceId = $stmt->fetchColumn();
    if ($workspaceId === false) {
        $stmt = $pdo->prepare("INSERT INTO {$workspaces} (label, last_seen_at) VALUES (:label, CURRENT_TIMESTAMP)");
        $stmt->execute([':label' => 'Migrated default workspace']);
        $workspaceId = (int)$pdo->lastInsertId();
    } else {
        $workspaceId = (int)$workspaceId;
    }
    $pdo->exec("UPDATE {$students} SET workspace_id = {$workspaceId} WHERE workspace_id IS NULL");
    $pdo->exec("UPDATE {$examStack} es JOIN {$students} s ON s.id = es.student_id SET es.workspace_id = s.workspace_id WHERE es.workspace_id IS NULL");
    $pdo->exec("UPDATE {$examNotes} en JOIN {$students} s ON s.id = en.student_id SET en.workspace_id = s.workspace_id WHERE en.workspace_id IS NULL");
    $stmt = $pdo->prepare("UPDATE {$appSettings} SET workspace_id = :workspace_id WHERE workspace_id IS NULL AND setting_key = 'current_exam_label'");
    $stmt->execute([':workspace_id' => $workspaceId]);
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
