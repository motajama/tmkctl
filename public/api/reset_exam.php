<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/stack.php';
require_once __DIR__ . '/../../app/settings.php';
require_once __DIR__ . '/../../app/workspaces.php';

require_auth();

try {
    require_post();
    verify_csrf();

    if ((string)($_POST['confirmation'] ?? '') !== 'RESET') {
        throw new InvalidArgumentException('Reset vyžaduje přesné potvrzení RESET.');
    }

    $pdo = db();
    $workspaceId = require_current_workspace($pdo);
    $clearLabel = (string)($_POST['clear_label'] ?? '') === '1';
    $examNotes = db_table('exam_notes');
    $examStack = db_table('exam_stack');
    $students = db_table('students');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("DELETE FROM {$examNotes} WHERE workspace_id = :workspace_id");
        $stmt->execute([':workspace_id' => $workspaceId]);
        $stmt = $pdo->prepare("DELETE FROM {$examStack} WHERE workspace_id = :workspace_id");
        $stmt->execute([':workspace_id' => $workspaceId]);
        $stmt = $pdo->prepare("DELETE FROM {$students} WHERE workspace_id = :workspace_id");
        $stmt->execute([':workspace_id' => $workspaceId]);
        clear_active_student();
        if ($clearLabel) {
            set_app_setting('current_exam_label', '', $workspaceId, $pdo);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    json_response([
        'ok' => true,
        'message' => $clearLabel
            ? 'Termín byl resetován včetně názvu.'
            : 'Termín byl resetován. Název termínu zůstal zachován.',
        'currentExamLabel' => (string)get_app_setting('current_exam_label', '', $workspaceId, $pdo),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
