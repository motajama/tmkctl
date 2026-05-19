<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/students.php';

require_auth();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['ok' => true, 'students' => list_students(db())]);
    }
    require_post();
    verify_csrf();
    $pdo = db();
    $result = add_student($pdo, $_POST);
    json_response([
        'ok' => true,
        'message' => $result['created'] ? 'Studující byl přidán.' : 'Studující byl aktualizován.',
        'students' => list_students($pdo),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
