<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/stack.php';
require_once __DIR__ . '/../../app/notes.php';
require_once __DIR__ . '/../../app/exam_exports.php';
require_once __DIR__ . '/../../app/workspaces.php';

require_auth();

try {
    $format = ($_GET['format'] ?? 'md') === 'txt' ? 'txt' : 'md';
    $pdo = db();
    $workspaceId = require_current_workspace($pdo);
    $body = $format === 'txt' ? build_all_notes_text($pdo, $workspaceId) : build_all_notes_markdown($pdo, $workspaceId);
    $extension = $format === 'txt' ? 'txt' : 'md';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="tmkctl-notes-' . export_timestamp() . '.' . $extension . '"');
    echo $body;
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo public_error_message($e);
}
