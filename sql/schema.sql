CREATE TABLE IF NOT EXISTS tmkctl_app_settings (
    setting_key VARCHAR(128) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tmkctl_students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    uco VARCHAR(64) NULL,
    email VARCHAR(255) NULL,
    study_type VARCHAR(16) NOT NULL DEFAULT 'unknown',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY students_uco_unique (uco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tmkctl_exam_stack (
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
    CONSTRAINT tmkctl_fk_exam_stack_student
        FOREIGN KEY (student_id)
        REFERENCES tmkctl_students(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tmkctl_exam_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    question_id VARCHAR(64) NULL,
    note_text MEDIUMTEXT NULL,
    suggested_grade VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY exam_notes_student_question_unique (student_id, question_id),
    KEY exam_notes_student_question_idx (student_id, question_id),
    CONSTRAINT tmkctl_fk_exam_notes_student
        FOREIGN KEY (student_id)
        REFERENCES tmkctl_students(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
