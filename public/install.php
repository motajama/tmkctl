<?php

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/render.php';

function harden_local_config_after_install(): array
{
    $path = dirname(__DIR__) . '/app/config.local.php';
    $manualMessage = 'Instalace proběhla, ale nepodařilo se automaticky vypnout instalátor/debug. Upravte app/config.local.php ručně.';

    if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
        return ['ok' => false, 'message' => $manualMessage];
    }

    $content = file_get_contents($path);
    if ($content === false) {
        return ['ok' => false, 'message' => $manualMessage];
    }

    $updated = harden_config_source($content, $inserted);
    if ($updated === null) {
        return [
            'ok' => false,
            'message' => 'Instalace proběhla, ale app/config.local.php neobsahuje install_enabled/debug. Nastavte ručně: \'install_enabled\' => false, \'debug\' => false.',
        ];
    }

    if ($updated === $content) {
        return ['ok' => true, 'message' => 'Databázové tabulky jsou připravené. Instalátor byl vypnut a debug režim vypnut.'];
    }

    $dir = dirname($path);
    $tmp = tempnam($dir, 'config.local.');
    if ($tmp === false) {
        return ['ok' => false, 'message' => $manualMessage];
    }

    $written = file_put_contents($tmp, $updated, LOCK_EX);
    if ($written === false) {
        @unlink($tmp);
        return ['ok' => false, 'message' => $manualMessage];
    }

    $perms = fileperms($path);
    if ($perms !== false) {
        @chmod($tmp, $perms & 0777);
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return ['ok' => false, 'message' => $manualMessage];
    }

    return ['ok' => true, 'message' => 'Databázové tabulky jsou připravené. Instalátor byl vypnut a debug režim vypnut.'];
}

function harden_config_source(string $content, ?bool &$inserted = null): ?string
{
    $inserted = false;
    $keys = ['install_enabled', 'debug'];
    $missing = [];

    foreach ($keys as $key) {
        $pattern = '/([\'"])' . preg_quote($key, '/') . '\1\s*=>\s*(?:true|false)\s*,?/i';
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, "'{$key}' => false,", $content);
        } else {
            $missing[] = $key;
        }
    }

    if (!$missing) {
        return $content;
    }

    $lines = '';
    foreach ($missing as $key) {
        $lines .= "    '{$key}' => false,\n";
    }

    $count = 0;
    $content = preg_replace('/\n\s*\];\s*$/', "\n{$lines}];", $content, 1, $count);
    if ($count === 1) {
        $inserted = true;
        return $content;
    }

    $content = preg_replace('/\n\s*\);\s*$/', "\n{$lines});", $content, 1, $count);
    if ($count === 1) {
        $inserted = true;
        return $content;
    }

    return null;
}

$message = '';
$error = '';
$config = app_config();
if (empty($config['install_enabled'])) {
    $error = 'Instalátor je vypnutý. Pro spuštění nastavte v app/config.local.php hodnotu install_enabled => true. Po instalaci ji vraťte na false.';
} else {
    try {
        install_schema(db());
        $hardening = harden_local_config_after_install();
        if (!empty($hardening['ok'])) {
            $message = $hardening['message'];
        } else {
            $message = 'Databázové tabulky jsou připravené.';
            $error = $hardening['message'];
        }
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
        <p>Po úspěšné instalaci se <code>install_enabled</code> a <code>debug</code> v <code>app/config.local.php</code> vypnou automaticky. Pokud úprava selže, nastavte je ručně na <code>false</code>.</p>
        <p><a href="login.php">Přejít na přihlášení</a></p>
    </main>
</body>
</html>
