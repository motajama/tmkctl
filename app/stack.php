<?php

const STACK_STATES = ['waiting', 'preparing', 'examining', 'done'];
const STACK_MOVES = [
    'waiting' => ['preparing'],
    'preparing' => ['examining', 'waiting'],
    'examining' => ['done', 'preparing'],
    'done' => ['examining'],
];

function list_stack(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT es.*, s.name, s.uco, s.email, s.study_type
        FROM exam_stack es
        JOIN students s ON s.id = es.student_id
        ORDER BY FIELD(es.state, "waiting", "preparing", "examining", "done"), es.position ASC, es.updated_at ASC
    ');
    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $item['study_type_label'] = study_type_label($item['study_type'] ?? 'unknown');
    }
    return $items;
}

function add_to_stack(PDO $pdo, int $studentId): void
{
    $position = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM exam_stack')->fetchColumn();
    $stmt = $pdo->prepare('
        INSERT INTO exam_stack (student_id, state, position)
        VALUES (:student_id, "waiting", :position)
        ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([':student_id' => $studentId, ':position' => $position]);
}

function move_stack_item(PDO $pdo, int $stackId, string $nextState): void
{
    if (!in_array($nextState, STACK_STATES, true)) {
        throw new InvalidArgumentException('Neznámý stav.');
    }
    $stmt = $pdo->prepare('SELECT state FROM exam_stack WHERE id = :id');
    $stmt->execute([':id' => $stackId]);
    $current = $stmt->fetchColumn();
    if (!$current) {
        throw new InvalidArgumentException('Položka fronty neexistuje.');
    }
    if (!in_array($nextState, STACK_MOVES[$current] ?? [], true)) {
        throw new InvalidArgumentException('Tento přesun není povolen.');
    }
    $update = $pdo->prepare('UPDATE exam_stack SET state = :state WHERE id = :id');
    $update->execute([':state' => $nextState, ':id' => $stackId]);
}

function assign_question(PDO $pdo, int $stackId, ?string $questionId): void
{
    if ($questionId !== null && !find_question($questionId)) {
        throw new InvalidArgumentException('Otázka neexistuje.');
    }
    $stmt = $pdo->prepare('UPDATE exam_stack SET question_id = :question_id WHERE id = :id');
    $stmt->execute([':question_id' => $questionId, ':id' => $stackId]);
}

function set_setting(PDO $pdo, string $key, ?string $value): void
{
    $stmt = $pdo->prepare('
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ');
    $stmt->execute([':k' => $key, ':v' => $value]);
}

function get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :k');
    $stmt->execute([':k' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string)$value;
}

function set_active_student(PDO $pdo, int $studentId): void
{
    set_setting($pdo, 'active_student_id', (string)$studentId);
}

function get_active_student_id(PDO $pdo): ?int
{
    $value = get_setting($pdo, 'active_student_id');
    return $value !== null && ctype_digit($value) ? (int)$value : null;
}
