<?php

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/auth.php';

const STACK_STATES = ['waiting', 'preparing', 'examining', 'done'];
const STACK_MOVES = [
    // Exam-day operation sometimes skips preparation, so FRONTA can move directly
    // to every terminal work state while still rejecting unknown backend states.
    'waiting' => ['preparing', 'examining', 'done'],
    'preparing' => ['examining', 'done', 'waiting'],
    'examining' => ['done', 'preparing'],
    'done' => ['examining'],
];

function list_stack(PDO $pdo, int $workspaceId): array
{
    cleanup_active_student($pdo, $workspaceId);
    $examStack = db_table('exam_stack');
    $students = db_table('students');
    $stmt = $pdo->prepare("
        SELECT es.id, es.student_id, es.state, es.question_id, es.position, es.updated_at,
               s.name, s.uco, s.email, s.study_type
        FROM {$examStack} es
        JOIN {$students} s ON s.id = es.student_id
        WHERE es.workspace_id = :workspace_id
        ORDER BY FIELD(es.state, 'waiting', 'preparing', 'examining', 'done'), es.position ASC, es.updated_at ASC
    ");
    $stmt->execute([':workspace_id' => $workspaceId]);
    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $item['study_type_label'] = study_type_label($item['study_type'] ?? 'unknown');
    }
    return $items;
}

function add_to_stack(PDO $pdo, int $workspaceId, int $studentId): void
{
    if (!get_student($pdo, $studentId, $workspaceId)) {
        throw new InvalidArgumentException('Studující neexistuje.');
    }
    $examStack = db_table('exam_stack');
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM {$examStack} WHERE workspace_id = :workspace_id");
    $stmt->execute([':workspace_id' => $workspaceId]);
    $position = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("
        INSERT INTO {$examStack} (workspace_id, student_id, state, position)
        VALUES (:workspace_id, :student_id, 'waiting', :position)
        ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([':workspace_id' => $workspaceId, ':student_id' => $studentId, ':position' => $position]);
}

function get_stack_item(PDO $pdo, int $workspaceId, int $stackId): ?array
{
    $examStack = db_table('exam_stack');
    $stmt = $pdo->prepare("SELECT * FROM {$examStack} WHERE id = :id AND workspace_id = :workspace_id");
    $stmt->execute([':id' => $stackId, ':workspace_id' => $workspaceId]);
    $item = $stmt->fetch();
    return $item ?: null;
}

function move_stack_item(PDO $pdo, int $workspaceId, int $stackId, string $nextState): void
{
    if (!in_array($nextState, STACK_STATES, true)) {
        throw new InvalidArgumentException('Neznámý stav fronty.');
    }
    $item = get_stack_item($pdo, $workspaceId, $stackId);
    if (!$item) {
        throw new InvalidArgumentException('Položka fronty neexistuje.');
    }
    if (!in_array($nextState, STACK_MOVES[$item['state']] ?? [], true)) {
        throw new InvalidArgumentException('Tento přesun není povolen.');
    }
    $examStack = db_table('exam_stack');
    $stmt = $pdo->prepare("UPDATE {$examStack} SET state = :state WHERE id = :id AND workspace_id = :workspace_id");
    $stmt->execute([':state' => $nextState, ':id' => $stackId, ':workspace_id' => $workspaceId]);
}

function assign_question(PDO $pdo, int $workspaceId, int $stackId, ?string $questionId): void
{
    $questionId = trim((string)$questionId);
    if ($questionId === '') {
        $questionId = null;
    }
    if ($questionId !== null && !find_question($questionId)) {
        throw new InvalidArgumentException('Neplatná otázka.');
    }
    if (!get_stack_item($pdo, $workspaceId, $stackId)) {
        throw new InvalidArgumentException('Položka fronty neexistuje.');
    }
    $examStack = db_table('exam_stack');
    $stmt = $pdo->prepare("UPDATE {$examStack} SET question_id = :question_id WHERE id = :id AND workspace_id = :workspace_id");
    $stmt->execute([':question_id' => $questionId, ':id' => $stackId, ':workspace_id' => $workspaceId]);
}

function set_active_student(PDO $pdo, int $workspaceId, int $studentId): void
{
    if (!get_student($pdo, $studentId, $workspaceId)) {
        throw new InvalidArgumentException('Studující neexistuje.');
    }
    start_app_session();
    $_SESSION['active_student_id'] = $studentId;
}

function get_active_student_id(PDO $pdo, int $workspaceId): ?int
{
    start_app_session();
    $value = $_SESSION['active_student_id'] ?? null;
    if ($value === null || !ctype_digit((string)$value)) {
        return null;
    }
    $studentId = (int)$value;
    if (!get_student($pdo, $studentId, $workspaceId)) {
        clear_active_student();
        return null;
    }
    return $studentId;
}

function clear_active_student(): void
{
    start_app_session();
    unset($_SESSION['active_student_id']);
}

function cleanup_active_student(PDO $pdo, int $workspaceId): void
{
    get_active_student_id($pdo, $workspaceId);
}
