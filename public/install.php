<?php

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/render.php';

$message = '';
$error = '';
try {
    install_schema(db());
    $message = 'Databázové tabulky jsou připravené. Skript je bezpečné spustit opakovaně.';
} catch (Throwable $e) {
    $error = public_error_message($e);
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
    <main class="window login-box">
        <div class="panel-title">TMKCTL INSTALL</div>
        <?php if ($message): ?><div class="message success"><?= h($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="message error"><?= h($error) ?></div><?php endif; ?>
        <p>Po instalaci nastavte heslo v <code>app/config.php</code> nebo přes <code>TMKCTL_PASSWORD_HASH</code>.</p>
        <p><a href="login.php">Přejít na přihlášení</a></p>
    </main>
</body>
</html>
