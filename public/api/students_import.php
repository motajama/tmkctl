<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/students.php';

require_auth();
require_post();
verify_csrf();

try {
    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        throw new InvalidArgumentException('CSV soubor chybí.');
    }
    $pdo = db();
    $result = import_students_csv($pdo, $_FILES['csv']['tmp_name']);
    json_response([
        'ok' => true,
        'message' => sprintf('CSV import: přidáno %d, aktualizováno %d, přeskočeno %d.', $result['imported'], $result['updated'], $result['skipped']),
        'result' => $result,
        'students' => list_students($pdo),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
