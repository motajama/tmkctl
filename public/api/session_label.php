<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings.php';

require_auth();

try {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['ok' => true, 'currentExamLabel' => (string)get_app_setting('current_exam_label', '', $pdo)]);
    }
    require_post();
    verify_csrf();
    $label = trim((string)($_POST['current_exam_label'] ?? ''));
    if (function_exists('mb_substr')) {
        $label = mb_substr($label, 0, 255, 'UTF-8');
    } else {
        $label = substr($label, 0, 255);
    }
    set_app_setting('current_exam_label', $label, $pdo);
    json_response(['ok' => true, 'message' => 'Název termínu byl uložen.', 'currentExamLabel' => $label]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
