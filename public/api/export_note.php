<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/notes.php';
require_once __DIR__ . '/../../app/exam_exports.php';
require_once __DIR__ . '/../../app/workspaces.php';

require_auth();

try {
    $format = ($_GET['format'] ?? 'txt') === 'md' ? 'md' : 'txt';
    $pdo = db();
    $workspaceId = require_current_workspace($pdo);
    $studentId = (int)($_GET['student_id'] ?? 0);
    $questionId = (string)($_GET['question_id'] ?? '');
    $body = export_note_text($pdo, $workspaceId, $studentId, $questionId, $format);
    $extension = $format === 'md' ? 'md' : 'txt';
    $safeQuestion = preg_replace('/[^A-Za-z0-9_-]/', '', $questionId) ?: 'general';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . export_basename($pdo, $workspaceId, 'note') . '-' . $studentId . '-' . $safeQuestion . '.' . $extension . '"');
    echo $body;
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo public_error_message($e);
}
