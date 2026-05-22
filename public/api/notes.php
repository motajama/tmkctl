<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/notes.php';
require_once __DIR__ . '/../../app/workspaces.php';

require_auth();

try {
    $pdo = db();
    $workspaceId = require_current_workspace($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['ok' => true, 'note' => get_note($pdo, $workspaceId, (int)($_GET['student_id'] ?? 0), (string)($_GET['question_id'] ?? ''))]);
    }
    require_post();
    verify_csrf();
    $note = save_note(
        $pdo,
        $workspaceId,
        (int)($_POST['student_id'] ?? 0),
        (string)($_POST['question_id'] ?? ''),
        (string)($_POST['note_text'] ?? ''),
        (string)($_POST['suggested_grade'] ?? ''),
        (int)($_POST['base_lock_version'] ?? 0)
    );
    json_response([
        'ok' => true,
        'message' => 'Poznámka byla uložena.',
        'note' => $note,
    ]);
} catch (NoteConflictException $e) {
    json_response([
        'ok' => false,
        'error' => $e->getMessage(),
        'conflict' => true,
        'note' => $e->currentNote(),
    ], 409);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
