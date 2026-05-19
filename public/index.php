<?php

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/render.php';
require_once __DIR__ . '/../app/questions.php';
require_once __DIR__ . '/../app/students.php';
require_once __DIR__ . '/../app/stack.php';

require_auth();

$config = app_config();
$setupError = '';
$questionLoad = ['questions' => [], 'error' => ''];
$students = [];
$stack = [];
$activeStudentId = null;

try {
    $pdo = db();
    $questionLoad = try_load_questions();
    $students = list_students($pdo);
    $stack = list_stack($pdo);
    $activeStudentId = get_active_student_id($pdo);
} catch (Throwable $e) {
    $setupError = public_error_message($e);
}
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($config['app_name']) ?> | <?= h($config['course_name']) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="workstation">
        <?php if ($setupError): ?>
            <section class="window setup-error">
                <div class="panel-title">NASTAVENÍ</div>
                <div class="message error"><?= h($setupError) ?></div>
            </section>
        <?php else: ?>
            <section id="students-window" class="window left-panel">
                <div class="panel-title">STUDUJÍCÍ</div>
                <form id="student-form" class="compact-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input name="name" placeholder="Jméno" required>
                    <input name="uco" placeholder="UČO">
                    <input name="email" placeholder="E-mail">
                    <select name="study_type">
                        <option value="unknown">neznámé</option>
                        <option value="single">jednoobor</option>
                        <option value="double">dvouobor</option>
                    </select>
                    <button type="submit">Přidat</button>
                </form>
                <form id="import-form" class="compact-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input id="csv-file" type="file" name="csv" accept=".csv,text/csv" required>
                    <button type="submit">Import CSV</button>
                </form>
                <div id="messages" class="messages"></div>
                <h2>Seznam</h2>
                <div id="students-list" class="listbox"></div>
                <h2>Fronta</h2>
                <div id="stack-board" class="stack-board"></div>
            </section>

            <section id="question-window" class="window center-panel">
                <div class="panel-title">OTÁZKA</div>
                <?php if ($questionLoad['error']): ?><div class="message error"><?= h($questionLoad['error']) ?></div><?php endif; ?>
                <div id="manual-warning" class="message warning hidden">!! RUČNÍ REŽIM – tato otázka není přiřazena aktivnímu studujícímu.</div>
                <article id="question-panel" class="question-panel"></article>
                <footer class="window-menu question-toolbar">
                    <label><input type="radio" name="question_mode" value="follow" checked> ACTIVE</label>
                    <label><input type="radio" name="question_mode" value="manual"> MANUAL</label>
                    <select id="manual-question-select"></select>
                    <button id="back-to-active" type="button">Zpět na aktivního</button>
                </footer>
            </section>

            <section id="notes-window" class="window right-panel">
                <div class="panel-title">POZNÁMKY</div>
                <div id="note-context" class="note-context">Vyberte aktivního studujícího a otázku.</div>
                <label for="note-text">Poznámky zkoušejícího</label>
                <textarea id="note-text" rows="18"></textarea>
                <label for="suggested-grade">Navržené hodnocení</label>
                <input id="suggested-grade" type="text" maxlength="64">
                <footer class="window-menu">
                    <button id="save-note" type="button">Uložit</button>
                    <button id="copy-note" type="button">Kopírovat</button>
                    <button id="download-txt" type="button">TXT</button>
                    <button id="download-md" type="button">MD</button>
                </footer>
                <div id="save-status" class="status-line"></div>
            </section>

            <section class="window ai-panel">
                <div class="panel-title">AI CHAT</div>
                <div class="disabled-ai">AI chat bude doplněn v další fázi.</div>
            </section>
        <?php endif; ?>
    </main>
    <footer class="global-command-bar">
        <?php if (!$setupError): ?>
            <a href="#students-window">STUDENTS</a>
            <a href="#question-window">QUESTION</a>
            <a href="#notes-window">NOTES</a>
            <button id="global-save" type="button">SAVE</button>
            <button id="global-export" type="button">EXPORT</button>
        <?php endif; ?>
        <a href="logout.php">LOGOUT</a>
        <span class="bar-right"><?= h($config['app_name']) ?> <?= h(date('H:i')) ?></span>
    </footer>

    <?php if (!$setupError): ?>
        <script>
            window.TMKCTL = <?= json_encode([
                'csrfToken' => csrf_token(),
                'questions' => $questionLoad['questions'],
                'questionsError' => $questionLoad['error'],
                'students' => $students,
                'stack' => $stack,
                'activeStudentId' => $activeStudentId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        </script>
        <script src="assets/app.js"></script>
    <?php endif; ?>
</body>
</html>
