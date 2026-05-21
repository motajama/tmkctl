<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/question_pack.php';

require_auth();
require_post();
verify_csrf();

try {
    $action = (string)($_POST['action'] ?? 'validate');
    $strategy = (string)($_POST['strategy'] ?? 'keep-existing');
    if (!in_array($action, ['validate', 'merge'], true)) {
        throw new InvalidArgumentException('Neplatná akce merge.');
    }
    $result = question_pack_merge_uploads($_FILES['merge_questions_json'] ?? null, $strategy, $action === 'merge');
    if (empty($result['ok'])) {
        json_response($result, 400);
    }
    json_response($result);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
