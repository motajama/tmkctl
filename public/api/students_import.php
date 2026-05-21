<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/stack.php';
require_once __DIR__ . '/../../app/workspaces.php';

require_auth();
require_post();
verify_csrf();

try {
    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        throw new InvalidArgumentException('CSV soubor chybí.');
    }
    $pdo = db();
    $workspaceId = require_current_workspace($pdo);
    $pdo->beginTransaction();
    try {
        $result = import_students_csv($pdo, $workspaceId, $_FILES['csv']['tmp_name']);
        foreach (list_students($pdo, $workspaceId) as $student) {
            add_to_stack($pdo, $workspaceId, (int)$student['id']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    json_response([
        'ok' => true,
        'message' => sprintf('CSV import: přidáno %d, aktualizováno %d, přeskočeno %d.', $result['imported'], $result['updated'], $result['skipped']),
        'result' => $result,
        'students' => list_students($pdo, $workspaceId),
        'stack' => list_stack($pdo, $workspaceId),
        'activeStudentId' => get_active_student_id($pdo, $workspaceId),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
