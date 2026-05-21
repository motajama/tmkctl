<?php

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/render.php';
require_once __DIR__ . '/../app/questions.php';
require_once __DIR__ . '/../app/students.php';
require_once __DIR__ . '/../app/stack.php';
require_once __DIR__ . '/../app/settings.php';
require_once __DIR__ . '/../app/workspaces.php';

require_auth();

$config = app_config();
$setupError = '';
$questionLoad = ['questions' => [], 'error' => ''];
$students = [];
$stack = [];
$activeStudentId = null;
$currentExamLabel = '';
$workspaceId = null;
$workspaceLabel = '';
$debugStageNote = '';

try {
    $pdo = db();
    $workspaceId = require_current_workspace($pdo);
    $questionLoad = try_load_questions();
    $students = list_students($pdo, $workspaceId);
    $stack = list_stack($pdo, $workspaceId);
    $activeStudentId = get_active_student_id($pdo, $workspaceId);
    $currentExamLabel = (string)get_app_setting('current_exam_label', '', $workspaceId, $pdo);
    $workspaceLabel = current_workspace_label($pdo);
    if (!empty($config['debug'])) {
        $debugStageNote = debug_stage_note();
    }
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
            <section class="panel window setup-error">
                <div class="panel-title">NASTAVENÍ DATABÁZE</div>
                <div class="alert"><?= h($setupError) ?></div>
                <p>Lokálně obvykle pomůže nainstalovat PHP MySQL rozšíření a restartovat vestavěný server.</p>
                <pre>sudo apt install php8.2-mysql
php -S 127.0.0.1:8000 -t public</pre>
                <p>Na sdíleném hostingu musí být povolené rozšíření <code>pdo_mysql</code>.</p>
            </section>
        <?php else: ?>
        <section id="students-window" class="panel window left-panel">
            <div class="panel-title">STUDUJÍCÍ</div>
            <section class="status-accordion status-examining">
                <button id="toggle-examining" class="accordion-title" type="button" aria-expanded="false">ZKOUŠENÝ / ZKOUŠENÁ <span id="examining-count">(0)</span></button>
                <div id="examining-list" class="accordion-body hidden"></div>
            </section>
            <section class="status-accordion status-preparing">
                <button id="toggle-preparing" class="accordion-title" type="button" aria-expanded="false">NA POTÍTKU <span id="preparing-count">(0)</span></button>
                <div id="preparing-list" class="accordion-body hidden"></div>
            </section>
            <div class="split-title">FRONTA</div>
            <div id="students-list" class="listbox"></div>
            <section class="status-accordion status-done">
                <button id="toggle-done" class="accordion-title" type="button" aria-expanded="false">HOTOVO <span id="done-count">(0)</span></button>
                <div id="done-list" class="accordion-body hidden"></div>
            </section>
        </section>

        <section id="question-window" class="panel window center-panel">
            <div class="panel-title">OTÁZKA</div>
            <section class="question-toolbar panel-controls">
                <button id="question-mode-follow" class="mode-button selected" type="button" title="Otázka podle studujícího" aria-label="Otázka podle studujícího" aria-pressed="true"><img src="assets/student.png" alt="Otázka podle studujícího"></button>
                <button id="question-mode-manual" class="mode-button" type="button" title="Ruční výběr otázky" aria-label="Ruční výběr otázky" aria-pressed="false"><img src="assets/teacher.png" alt="Ruční výběr otázky"></button>
                <select id="manual-question-select"></select>
                <button id="assign-current-question" type="button">PŘIŘADIT</button>
            </section>
            <?php if ($questionLoad['error']): ?>
                <div class="manual-warning"><?= h($questionLoad['error']) ?></div>
            <?php endif; ?>
            <div id="manual-warning" class="manual-warning hidden">!! RUČNÍ REŽIM – tato otázka není přiřazena aktivnímu studujícímu.</div>
            <article id="question-panel" class="question-panel"></article>
        </section>

        <section id="notes-window" class="panel window right-panel">
            <div class="panel-title">POZNÁMKY</div>
            <div id="note-context" class="note-context">Vyberte aktivního studujícího a otázku.</div>
            <section class="notes-toolbar panel-controls">
                <div id="note-mode-label" class="mode-line">ŽÁDNÝ STUDUJÍCÍ</div>
                <input id="suggested-grade" type="text" placeholder="Navržené hodnocení">
                <div class="button-row tight">
                    <button id="save-note" type="button">ULOŽIT</button>
                    <button id="copy-note" type="button">KOPÍROVAT</button>
                    <button id="download-txt" type="button">TXT</button>
                    <button id="download-md" type="button">MD</button>
                </div>
                <div id="save-status" class="status-line"></div>
            </section>
            <label for="note-text">Text poznámky</label>
            <textarea id="note-text" rows="18"></textarea>
        </section>

        <?php endif; ?>
    </main>

    <?php if (!$setupError): ?>
    <?php if (!empty($config['debug'])): ?>
    <div id="debug-modal-layer" class="modal-layer" aria-live="polite">
        <section id="debug-modal" class="modal-window debug-window" role="dialog" aria-modal="true" aria-labelledby="debug-modal-title">
            <div class="modal-title debug-title"><span id="debug-modal-title">DEBUG REŽIM — NEFINÁLNÍ VERZE</span></div>
            <div class="modal-body debug-body">
                <p><strong>Toto není finální produkční verze aplikace.</strong></p>
                <p><strong>Debug režim je zapnutý.</strong></p>
                <pre><?= h($debugStageNote) ?></pre>
                <button id="debug-modal-close" type="button">ROZUMÍM</button>
            </div>
        </section>
    </div>
    <?php endif; ?>
    <button id="ai-chat-toggle" class="ai-chat-toggle" type="button" aria-expanded="false" aria-controls="ai-chat-window">AI</button>
    <section id="ai-chat-window" class="panel window ai-chat-window hidden" role="dialog" aria-modal="false" aria-labelledby="ai-chat-title">
        <div class="panel-title ai-chat-title"><span id="ai-chat-title">AI CHAT</span><button id="ai-chat-close" class="modal-close" type="button">[X]</button></div>
        <div class="disabled-ai">AI chat bude doplněn v další fázi.</div>
    </section>
    <div id="modal-layer" class="modal-layer hidden" aria-live="polite">
        <section id="modal-add" class="modal-window hidden" role="dialog" aria-modal="true" aria-labelledby="modal-add-title">
            <div class="modal-title"><span id="modal-add-title">PŘIDAT STUDUJÍCÍHO</span><button class="modal-close" type="button" data-close-modal>[X]</button></div>
            <div class="modal-body">
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
                    <textarea name="note_text" rows="4" placeholder="Volitelná poznámka"></textarea>
                    <div class="button-row tight">
                        <button type="submit">PŘIDAT</button>
                        <button type="button" data-close-modal>ZAVŘÍT</button>
                    </div>
                </form>
                <div id="add-status" class="status-line"></div>
            </div>
        </section>

        <section id="modal-import" class="modal-window hidden" role="dialog" aria-modal="true" aria-labelledby="modal-import-title">
            <div class="modal-title"><span id="modal-import-title">IMPORT</span><button class="modal-close" type="button" data-close-modal>[X]</button></div>
            <div class="modal-body">
                <div class="split-title">CSV IMPORT</div>
                <form id="import-form" class="compact-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <div class="file-control">
                        <input id="csv-file" class="file-native" type="file" name="csv" accept=".csv,text/csv" required>
                        <label class="file-button" for="csv-file" tabindex="0">VYBRAT CSV</label>
                        <span id="csv-file-name" class="file-name">no file selected</span>
                    </div>
                    <button type="submit">IMPORTOVAT CSV</button>
                </form>
                <section class="is-import-panel">
                    <div class="split-title">IMPORT Z IS MU</div>
                    <p>V IS MU otevři stránku termínů SZZ, stiskni Ctrl+A, potom Ctrl+C, a vlož celý obsah sem. tmkctl najde pouze termíny Teorie masové komunikace / TMK.</p>
                    <textarea id="is-import-text" rows="7" placeholder="Vlož celý text z IS MU"></textarea>
                    <div class="button-row tight">
                        <button id="is-detect-terms" type="button">NAJÍT TERMÍNY</button>
                        <button id="is-preview-students" type="button" disabled>NÁHLED STUDUJÍCÍCH</button>
                        <button id="is-import-selected" type="button" disabled>IMPORTOVAT VYBRANÉ</button>
                        <button id="is-import-all" type="button" disabled>IMPORTOVAT VŠE</button>
                        <button id="is-import-clear" type="button">VYČISTIT</button>
                    </div>
                    <div id="is-term-list" class="is-term-list"></div>
                    <div id="is-preview" class="is-preview"></div>
                </section>
            </div>
        </section>

        <section id="modal-reset" class="modal-window hidden" role="dialog" aria-modal="true" aria-labelledby="modal-reset-title">
            <div class="modal-title"><span id="modal-reset-title">RESET TERMÍNU</span><button class="modal-close" type="button" data-close-modal>[X]</button></div>
            <div class="modal-body">
                <form id="reset-exam-form" class="compact-form operations-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <div class="reset-warning">Reset smaže studující, frontu, stav zkoušení a poznámky aktuálního běhu. Otázky zůstanou zachovány. Před resetem exportuj data.</div>
                    <input name="confirmation" placeholder="RESET" autocomplete="off" aria-label="Potvrzení resetu">
                    <label class="inline-check"><input type="checkbox" name="clear_label" value="1"> Smazat i název termínu</label>
                    <div class="button-row tight">
                        <button type="submit">RESET</button>
                        <button type="button" data-close-modal>ZAVŘÍT</button>
                    </div>
                </form>
                <div id="operations-status" class="status-line"></div>
            </div>
        </section>

        <section id="modal-export" class="modal-window hidden" role="dialog" aria-modal="true" aria-labelledby="modal-export-title">
            <div class="modal-title"><span id="modal-export-title">EXPORTUJ VŠE</span><button class="modal-close" type="button" data-close-modal>[X]</button></div>
            <div class="modal-body">
                <div class="button-row export-actions">
                    <a class="button-link" href="api/export_all_notes.php?format=md">MARKDOWN FILE</a>
                    <a class="button-link" href="api/export_all_notes.php?format=txt">TXT FILE</a>
                    <a class="button-link" href="api/export_all.php">JSON/ZIP</a>
                    <button id="copy-all-notes" type="button">KOPÍROVAT VŠE</button>
                </div>
                <textarea id="clipboard-fallback" class="hidden" rows="8" readonly></textarea>
                <div id="export-status" class="status-line"></div>
            </div>
        </section>

        <section id="modal-help" class="modal-window hidden" role="dialog" aria-modal="true" aria-labelledby="modal-help-title">
            <div class="modal-title"><span id="modal-help-title">NÁPOVĚDA</span><button class="modal-close" type="button" data-close-modal>[X]</button></div>
            <div id="help-content" class="modal-body help-text" data-help-src="assets/help.html">Nápověda se načítá.</div>
        </section>

        <section id="modal-questions" class="modal-window hidden" role="dialog" aria-modal="true" aria-labelledby="modal-questions-title">
            <div class="modal-title"><span id="modal-questions-title">OTÁZKY</span><button class="modal-close" type="button" data-close-modal>[X]</button></div>
            <div class="modal-body">
                <div class="reset-warning">Otázky se používají při zkoušení. Nahrávej pouze ručně zkontrolovaný questions.reviewed.json.</div>
                <div id="question-pack-status" class="question-pack-status"></div>
                <div class="button-row tight">
                    <button id="validate-question-pack" type="button">VALIDOVAT</button>
                </div>
                <form id="question-pack-upload-form" class="compact-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <div class="file-control">
                        <input id="questions-json-file" class="file-native" type="file" name="questions_json" accept=".json,application/json" required>
                        <label class="file-button" for="questions-json-file" tabindex="0">VYBRAT JSON</label>
                        <span id="questions-json-file-name" class="file-name">no file selected</span>
                    </div>
                    <div class="button-row tight">
                        <button type="submit">NAHRÁT NOVÝ BALÍK</button>
                        <button type="button" data-close-modal>ZAVŘÍT</button>
                    </div>
                </form>
                <div class="split-title">MERGE JSON</div>
                <form id="question-pack-merge-form" class="compact-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <div class="file-control">
                        <input id="merge-questions-json-files" class="file-native" type="file" name="merge_questions_json[]" accept=".json,application/json" multiple required>
                        <label class="file-button" for="merge-questions-json-files" tabindex="0">VYBRAT JSON</label>
                        <span id="merge-questions-json-file-name" class="file-name">no file selected</span>
                    </div>
                    <label for="merge-conflict-strategy">Konflikty ID</label>
                    <select id="merge-conflict-strategy" name="strategy">
                        <option value="keep-existing">ponechat existující</option>
                        <option value="replace-existing">nahradit nahranými</option>
                    </select>
                    <div class="button-row tight">
                        <button type="submit" name="action" value="validate">VALIDOVAT MERGE</button>
                        <button type="submit" name="action" value="merge">SLOUČIT / MERGE</button>
                    </div>
                </form>
                <div id="question-merge-preview" class="question-merge-preview"></div>
                <div id="question-pack-result" class="status-line"></div>
            </div>
        </section>

        <section id="modal-console" class="modal-window console-window hidden" role="dialog" aria-modal="true" aria-labelledby="modal-console-title">
            <div class="modal-title"><span id="modal-console-title">KONZOLE</span><button class="modal-close" type="button" data-close-modal>[X]</button></div>
            <div class="modal-body">
                <div id="console-log" class="console-log" aria-live="polite"></div>
                <form id="console-form" class="console-form">
                    <label for="console-input">PŘÍKAZ</label>
                    <input id="console-input" autocomplete="off" placeholder=":help">
                </form>
            </div>
        </section>
    </div>
    <?php endif; ?>

    <footer class="global-command-bar">
        <nav class="global-actions" aria-label="Globální příkazy">
            <?php if (!$setupError): ?>
                <button id="global-add" type="button">PŘIDAT</button>
                <button id="global-import" type="button">IMPORT</button>
                <button id="global-reset" type="button">RESET</button>
                <button id="global-export-all" type="button">EXPORT</button>
                <button id="global-questions" type="button">OTÁZKY</button>
                <button id="global-help" type="button">NÁPOVĚDA</button>
                <button id="global-console" type="button">KONZOLE</button>
            <?php endif; ?>
            <a href="logout.php">LOGOUT</a>
        </nav>
        <div id="global-status-message" class="global-status-message" role="status" aria-live="polite"></div>
        <div class="global-status">
            <?php if (!$setupError): ?>
                <span id="global-active-student"></span>
                <span id="global-workspace-label">RELACE: <?= h($workspaceLabel) ?></span>
                <form id="session-label-form" class="session-bar-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <label for="current-exam-label">TERMÍN</label>
                    <input id="current-exam-label" name="current_exam_label" value="<?= h($currentExamLabel) ?>" placeholder="TMK - 10. 6. 2026">
                    <button type="submit">ULOŽIT TERMÍN</button>
                </form>
            <?php endif; ?>
            <span><?= h($config['app_name']) ?></span>
            <time id="global-time" datetime="<?= h(date('c')) ?>"><?= h(date('H:i')) ?></time>
        </div>
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
                'currentExamLabel' => $currentExamLabel,
                'workspaceId' => $workspaceId,
                'workspaceLabel' => $workspaceLabel,
                'debug' => !empty($config['debug']),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        </script>
        <script src="assets/app.js"></script>
    <?php endif; ?>
</body>
</html>
