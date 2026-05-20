<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings.php';

require_auth();

try {
    require_post();
    verify_csrf();

    if ((string)($_POST['confirmation'] ?? '') !== 'RESET') {
        throw new InvalidArgumentException('Reset vyžaduje přesné potvrzení RESET.');
    }

    $pdo = db();
    $clearLabel = (string)($_POST['clear_label'] ?? '') === '1';
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM exam_notes');
        $pdo->exec('DELETE FROM exam_stack');
        $pdo->exec('DELETE FROM students');
        set_app_setting('active_student_id', null, $pdo);
        if ($clearLabel) {
            set_app_setting('current_exam_label', '', $pdo);
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
        'currentExamLabel' => (string)get_app_setting('current_exam_label', '', $pdo),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
