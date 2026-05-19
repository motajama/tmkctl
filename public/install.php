<?php

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/render.php';

$message = '';
$error = '';
$config = app_config();
if (empty($config['install_enabled'])) {
    $error = 'Instalátor je vypnutý. Pro spuštění nastavte v app/config.local.php hodnotu install_enabled => true. Po instalaci ji vraťte na false.';
} else {
    try {
        install_schema(db());
        $message = 'Databázové tabulky jsou připravené. Skript je bezpečné spustit opakovaně.';
    } catch (Throwable $e) {
        $error = function_exists('public_error_message') ? public_error_message($e) : $e->getMessage();
    }
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
        <p>Po instalaci nastavte <code>install_enabled =&gt; false</code> v <code>app/config.local.php</code>.</p>
        <p><a href="login.php">Přejít na přihlášení</a></p>
    </main>
</body>
</html>
