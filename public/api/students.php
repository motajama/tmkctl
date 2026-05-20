<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/stack.php';

require_auth();

try {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['ok' => true, 'students' => list_students($pdo)]);
    }
    require_post();
    verify_csrf();
    $result = add_student($pdo, $_POST);
    add_to_stack($pdo, (int)$result['id']);
    json_response([
        'ok' => true,
        'message' => $result['created'] ? 'Studující byl přidán.' : 'Studující byl aktualizován.',
        'result' => $result,
        'students' => list_students($pdo),
        'stack' => list_stack($pdo),
        'activeStudentId' => get_active_student_id($pdo),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
