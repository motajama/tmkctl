<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/stack.php';
require_once __DIR__ . '/../../app/workspaces.php';

require_auth();

try {
    $pdo = db();
    $workspaceId = require_current_workspace($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['ok' => true, 'students' => list_students($pdo, $workspaceId)]);
    }
    require_post();
    verify_csrf();
    $pdo->beginTransaction();
    try {
        $result = add_student($pdo, $workspaceId, $_POST);
        add_to_stack($pdo, $workspaceId, (int)$result['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    json_response([
        'ok' => true,
        'message' => $result['created'] ? 'Studující byl přidán.' : 'Studující byl aktualizován.',
        'result' => $result,
        'students' => list_students($pdo, $workspaceId),
        'stack' => list_stack($pdo, $workspaceId),
        'activeStudentId' => get_active_student_id($pdo, $workspaceId),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
