<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings.php';
require_once __DIR__ . '/../../app/workspaces.php';

require_auth();

try {
    $pdo = db();
    $workspaceId = require_current_workspace($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response([
            'ok' => true,
            'currentExamLabel' => (string)get_app_setting('current_exam_label', '', $workspaceId, $pdo),
            'examDisplayLabel' => exam_display_label($pdo, $workspaceId),
        ]);
    }
    require_post();
    verify_csrf();
    $label = trim((string)($_POST['current_exam_label'] ?? ''));
    if (function_exists('mb_substr')) {
        $label = mb_substr($label, 0, 255, 'UTF-8');
    } else {
        $label = substr($label, 0, 255);
    }
    set_app_setting('current_exam_label', $label, $workspaceId, $pdo);
    json_response([
        'ok' => true,
        'message' => 'Název termínu byl uložen.',
        'currentExamLabel' => $label,
        'examDisplayLabel' => exam_display_label($pdo, $workspaceId),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
