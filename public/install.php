<?php

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/render.php';

$message = '';
$error = '';
try {
    install_schema(db());
    $message = 'Databázové tabulky jsou připravené. Skript je bezpečné spustit opakovaně.';
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalace | <?= h(app_config()['app_name']) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-screen">
    <main class="login-box panel">
        <div class="panel-title">tmkctl install</div>
        <?php if ($message): ?>
            <div class="notice"><?= h($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert"><?= h($error) ?></div>
        <?php endif; ?>
        <p>Po instalaci nastavte skutečný hash hesla v <code>app/config.php</code> nebo v proměnné <code>TMKCTL_PASSWORD_HASH</code>.</p>
        <p><a href="login.php">Přejít na přihlášení</a></p>
    </main>
</body>
</html>
