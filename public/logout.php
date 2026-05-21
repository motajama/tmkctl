<?php

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/workspaces.php';

require_auth();
$clientId = current_client_id();
$workspaceId = current_workspace_id();
if ($clientId !== null && $workspaceId !== null) {
    try {
        remove_workspace_presence(db(), $workspaceId, $clientId);
    } catch (Throwable) {
    }
}
clear_current_workspace_id();
logout();
header('Location: login.php');
exit;
