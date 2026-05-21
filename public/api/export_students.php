<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/stack.php';
require_once __DIR__ . '/../../app/exam_exports.php';
require_once __DIR__ . '/../../app/workspaces.php';

require_auth();

try {
    header('Content-Type: text/csv; charset=utf-8');
    $pdo = db();
    $workspaceId = require_current_workspace($pdo);
    header('Content-Disposition: attachment; filename="' . export_basename($pdo, $workspaceId, 'students') . '.csv"');
    echo build_students_csv($pdo, $workspaceId);
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo public_error_message($e);
}
