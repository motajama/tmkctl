<?php

require_once __DIR__ . '/settings.php';

const STACK_STATES = ['waiting', 'preparing', 'examining', 'done'];
const STACK_MOVES = [
    'waiting' => ['preparing'],
    'preparing' => ['examining', 'waiting'],
    'examining' => ['done', 'preparing'],
    'done' => ['examining'],
];

function list_stack(PDO $pdo): array
{
    cleanup_active_student($pdo);
    $stmt = $pdo->query('
        SELECT es.id, es.student_id, es.state, es.question_id, es.position, es.updated_at,
               s.name, s.uco, s.email, s.study_type
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
    if (!get_student($pdo, $studentId)) {
        throw new InvalidArgumentException('Studující neexistuje.');
    }
    $position = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM exam_stack')->fetchColumn();
    $stmt = $pdo->prepare('
        INSERT INTO exam_stack (student_id, state, position)
        VALUES (:student_id, "waiting", :position)
        ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([':student_id' => $studentId, ':position' => $position]);
}

function get_stack_item(PDO $pdo, int $stackId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM exam_stack WHERE id = :id');
    $stmt->execute([':id' => $stackId]);
    $item = $stmt->fetch();
    return $item ?: null;
}

function move_stack_item(PDO $pdo, int $stackId, string $nextState): void
{
    if (!in_array($nextState, STACK_STATES, true)) {
        throw new InvalidArgumentException('Neznámý stav fronty.');
    }
    $item = get_stack_item($pdo, $stackId);
    if (!$item) {
        throw new InvalidArgumentException('Položka fronty neexistuje.');
    }
    if (!in_array($nextState, STACK_MOVES[$item['state']] ?? [], true)) {
        throw new InvalidArgumentException('Tento přesun není povolen.');
    }
    $stmt = $pdo->prepare('UPDATE exam_stack SET state = :state WHERE id = :id');
    $stmt->execute([':state' => $nextState, ':id' => $stackId]);
}

function assign_question(PDO $pdo, int $stackId, ?string $questionId): void
{
    $questionId = trim((string)$questionId);
    if ($questionId === '') {
        $questionId = null;
    }
    if ($questionId !== null && !find_question($questionId)) {
        throw new InvalidArgumentException('Neplatná otázka.');
    }
    if (!get_stack_item($pdo, $stackId)) {
        throw new InvalidArgumentException('Položka fronty neexistuje.');
    }
    $stmt = $pdo->prepare('UPDATE exam_stack SET question_id = :question_id WHERE id = :id');
    $stmt->execute([':question_id' => $questionId, ':id' => $stackId]);
}

function set_active_student(PDO $pdo, int $studentId): void
{
    if (!get_student($pdo, $studentId)) {
        throw new InvalidArgumentException('Studující neexistuje.');
    }
    set_app_setting('active_student_id', (string)$studentId, $pdo);
}

function get_active_student_id(PDO $pdo): ?int
{
    cleanup_active_student($pdo);
    $value = get_app_setting('active_student_id', null, $pdo);
    return $value !== null && ctype_digit($value) ? (int)$value : null;
}

function cleanup_active_student(PDO $pdo): void
{
    $value = get_app_setting('active_student_id', null, $pdo);
    if ($value === null || !ctype_digit($value)) {
        return;
    }
    $stmt = $pdo->prepare('SELECT id FROM students WHERE id = :id');
    $stmt->execute([':id' => (int)$value]);
    if (!$stmt->fetchColumn()) {
        set_app_setting('active_student_id', null, $pdo);
    }
}
