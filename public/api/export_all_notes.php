<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/stack.php';
require_once __DIR__ . '/../../app/exam_exports.php';

require_auth();

try {
    $body = build_all_notes_markdown(db());
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="tmkctl-notes-' . export_timestamp() . '.md"');
    echo $body;
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo public_error_message($e);
}
