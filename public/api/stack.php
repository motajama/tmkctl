<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/stack.php';
require_once __DIR__ . '/../../app/workspaces.php';

require_auth();

try {
    $pdo = db();
    $workspaceId = require_current_workspace($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['ok' => true, 'stack' => list_stack($pdo, $workspaceId), 'activeStudentId' => get_active_student_id($pdo, $workspaceId)]);
    }
    require_post();
    verify_csrf();

    $action = (string)($_POST['action'] ?? '');
    $pdo->beginTransaction();
    if ($action === 'add') {
        add_to_stack($pdo, $workspaceId, (int)($_POST['student_id'] ?? 0));
        $message = 'Studující byl přidán do fronty.';
    } elseif ($action === 'move') {
        move_stack_item($pdo, $workspaceId, (int)($_POST['stack_id'] ?? 0), (string)($_POST['state'] ?? ''));
        $message = 'Stav fronty byl změněn.';
    } elseif ($action === 'assign') {
        assign_question($pdo, $workspaceId, (int)($_POST['stack_id'] ?? 0), $_POST['question_id'] ?? null);
        $message = 'Otázka byla přiřazena.';
    } elseif ($action === 'active') {
        set_active_student($pdo, $workspaceId, (int)($_POST['student_id'] ?? 0));
        $message = 'Aktivní studující byl nastaven.';
    } else {
        throw new InvalidArgumentException('Neplatná akce.');
    }
    $pdo->commit();

    json_response(['ok' => true, 'message' => $message, 'stack' => list_stack($pdo, $workspaceId), 'activeStudentId' => get_active_student_id($pdo, $workspaceId)]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
