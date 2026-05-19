<?php

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/students.php';

require_auth();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['ok' => true, 'students' => list_students(db())]);
    }
    require_post();
    verify_csrf();
    add_student(db(), $_POST);
    json_response(['ok' => true, 'students' => list_students(db())]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
