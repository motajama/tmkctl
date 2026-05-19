<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/notes.php';

require_auth();

try {
    $format = ($_GET['format'] ?? 'txt') === 'md' ? 'md' : 'txt';
    $studentId = (int)($_GET['student_id'] ?? 0);
    $questionId = (string)($_GET['question_id'] ?? '');
    $body = export_note_text(db(), $studentId, $questionId, $format);
    $extension = $format === 'md' ? 'md' : 'txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="tmkctl-note-' . $studentId . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $questionId) . '.' . $extension . '"');
    echo $body;
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo public_error_message($e);
}
