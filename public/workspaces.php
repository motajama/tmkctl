<?php

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/render.php';
require_once __DIR__ . '/../app/workspaces.php';

require_auth();

$pdo = db();
$cookieWarning = '';
$clientId = current_client_id();
if ($clientId === null) {
    ensure_client_cookie_queued();
    $cookieWarning = client_cookie_warning();
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($clientId === null) {
        $error = client_cookie_warning();
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'enter') {
                $workspaceId = (int)($_POST['workspace_id'] ?? 0);
                if (!get_workspace($pdo, $workspaceId)) {
                    throw new InvalidArgumentException('Relace nebyla nalezena.');
                }
            } elseif ($action === 'create') {
                $workspaceId = create_workspace($pdo, (string)($_POST['label'] ?? ''), $clientId);
            } else {
                throw new InvalidArgumentException('Neplatná akce.');
            }
            $previousWorkspaceId = current_workspace_id();
            if ($previousWorkspaceId !== null && $previousWorkspaceId !== $workspaceId) {
                remove_workspace_presence($pdo, $previousWorkspaceId, $clientId);
            }
            set_current_workspace_id($workspaceId);
            touch_workspace_presence($pdo, $workspaceId, $clientId);
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            $error = public_error_message($e);
        }
    }
}

$workspaces = $clientId === null ? [] : list_active_workspaces($pdo);
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Relace | <?= h(app_config()['app_name']) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-screen">
    <main class="window login-box workspace-select">
        <div class="panel-title">VÝBĚR RELACE / TERMÍNU</div>
        <?php if ($cookieWarning): ?><div class="alert"><?= h($cookieWarning) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>

        <section class="workspace-section">
            <div class="split-title">AKTIVNÍ RELACE</div>
            <?php if (!$workspaces): ?>
                <div class="empty-row">Žádná aktivní relace. Založ nový termín.</div>
            <?php else: ?>
                <div class="workspace-list">
                    <?php foreach ($workspaces as $workspace): ?>
                        <form method="post" class="workspace-row">
                            <input type="hidden" name="action" value="enter">
                            <input type="hidden" name="workspace_id" value="<?= h((string)$workspace['id']) ?>">
                            <div>
                                <strong><?= h($workspace['label']) ?></strong>
                                <span>Vytvořeno: <?= h((string)$workspace['created_at']) ?> · Aktivní: <?= h((string)$workspace['active_user_count']) ?> · Naposledy: <?= h((string)$workspace['last_seen_at']) ?></span>
                            </div>
                            <button type="submit">VSTOUPIT</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="workspace-section">
            <div class="split-title">NOVÝ TERMÍN</div>
            <form method="post" class="compact-form">
                <input type="hidden" name="action" value="create">
                <label for="workspace-label">Název relace</label>
                <input id="workspace-label" name="label" placeholder="<?= h(workspace_default_label()) ?>" <?= $clientId === null ? 'disabled' : '' ?>>
                <button type="submit" <?= $clientId === null ? 'disabled' : '' ?>>NOVÝ WORKSPACE</button>
            </form>
        </section>
        <p><a href="logout.php">LOGOUT</a></p>
    </main>
</body>
</html>
