<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/question_pack.php';

require_auth();
require_post();
verify_csrf();

try {
    if (empty($_FILES['questions_json'])) {
        throw new InvalidArgumentException('Soubor questions.reviewed.json chybí.');
    }
    $result = question_pack_replace_with_upload($_FILES['questions_json']);
    if (empty($result['ok'])) {
        json_response($result, 400);
    }
    json_response($result);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
