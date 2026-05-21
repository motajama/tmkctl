<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/workspaces.php';

require_auth();

try {
    $pdo = db();
    $workspaceId = require_current_workspace($pdo);
    json_response([
        'ok' => true,
        'workspaceId' => $workspaceId,
        'workspaceLabel' => current_workspace_label($pdo),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
