<?php

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/render.php';
require_once __DIR__ . '/../app/workspaces.php';

if (is_authenticated()) {
    ensure_client_cookie_queued();
    header('Location: workspaces.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login_with_password((string)($_POST['password'] ?? ''))) {
        ensure_client_cookie_queued();
        header('Location: workspaces.php');
        exit;
    }
    $error = 'Neplatné heslo.';
}
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Přihlášení | <?= h(app_config()['app_name']) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-screen">
    <main class="window login-box">
        <div class="panel-title">TMKCTL LOGIN</div>
        <img class="login-cover" src="assets/ascii-art-text.png" alt="tmkctl">
        <?php if ($error): ?><div class="message error"><?= h($error) ?></div><?php endif; ?>
        <form method="post">
            <label for="password">Sdílené heslo</label>
            <input id="password" name="password" type="password" autofocus required>
            <button type="submit">Přihlásit</button>
        </form>
    </main>
</body>
</html>
