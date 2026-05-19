<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/notes.php';

require_auth();

try {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['ok' => true, 'note' => get_note($pdo, (int)($_GET['student_id'] ?? 0), (string)($_GET['question_id'] ?? ''))]);
    }
    require_post();
    verify_csrf();
    save_note(
        $pdo,
        (int)($_POST['student_id'] ?? 0),
        (string)($_POST['question_id'] ?? ''),
        (string)($_POST['note_text'] ?? ''),
        (string)($_POST['suggested_grade'] ?? '')
    );
    json_response([
        'ok' => true,
        'message' => 'Poznámka byla uložena.',
        'note' => get_note($pdo, (int)$_POST['student_id'], (string)$_POST['question_id']),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
