<?php

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/students.php';

require_auth();
require_post();
verify_csrf();

try {
    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        throw new InvalidArgumentException('CSV soubor chybí.');
    }
    $handle = fopen($_FILES['csv']['tmp_name'], 'rb');
    if (!$handle) {
        throw new RuntimeException('CSV soubor nelze otevřít.');
    }

    $pdo = db();
    $header = fgetcsv($handle);
    if (!$header) {
        throw new InvalidArgumentException('CSV soubor je prázdný.');
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $count = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $data = array_combine($header, array_slice(array_pad($row, count($header), ''), 0, count($header)));
        if (!$data || trim((string)($data['name'] ?? '')) === '') {
            continue;
        }
        add_student($pdo, $data);
        $count++;
    }
    fclose($handle);
    json_response(['ok' => true, 'imported' => $count, 'students' => list_students($pdo)]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
