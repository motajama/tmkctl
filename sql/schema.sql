CREATE TABLE IF NOT EXISTS tmkctl_workspaces (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NULL,
    created_by_client_id VARCHAR(64) NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    KEY workspaces_active_idx (is_archived, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tmkctl_workspace_presence (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    client_id VARCHAR(64) NOT NULL,
    user_label VARCHAR(255) NULL,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY workspace_client (workspace_id, client_id),
    KEY workspace_presence_seen_idx (last_seen_at),
    CONSTRAINT tmkctl_fk_workspace_presence_workspace
        FOREIGN KEY (workspace_id)
        REFERENCES tmkctl_workspaces(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tmkctl_app_settings (
    workspace_id INT UNSIGNED NULL,
    setting_key VARCHAR(128) NOT NULL,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY app_settings_scope_key_unique (workspace_id, setting_key),
    KEY app_settings_key_idx (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tmkctl_students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    uco VARCHAR(64) NULL,
    email VARCHAR(255) NULL,
    study_type VARCHAR(16) NOT NULL DEFAULT 'unknown',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY students_workspace_uco_unique (workspace_id, uco),
    KEY students_workspace_idx (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tmkctl_exam_stack (
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
    CONSTRAINT tmkctl_fk_exam_stack_student
        FOREIGN KEY (student_id)
        REFERENCES tmkctl_students(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tmkctl_exam_notes (
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
    CONSTRAINT tmkctl_fk_exam_notes_student
        FOREIGN KEY (student_id)
        REFERENCES tmkctl_students(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
