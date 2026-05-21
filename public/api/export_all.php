<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/stack.php';
require_once __DIR__ . '/../../app/notes.php';
require_once __DIR__ . '/../../app/exam_exports.php';
require_once __DIR__ . '/../../app/workspaces.php';

require_auth();

try {
    $pdo = db();
    $workspaceId = require_current_workspace($pdo);
    $state = build_exam_state($pdo, $workspaceId);
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Nelze připravit JSON export.');
    }

    if (class_exists('ZipArchive')) {
        $zipPath = tempnam(sys_get_temp_dir(), 'tmkctl-export-');
        if ($zipPath === false) {
            throw new RuntimeException('Nelze vytvořit dočasný ZIP soubor.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Nelze otevřít ZIP export.');
        }
        $zip->addFromString('notes.md', build_all_notes_markdown($pdo, $workspaceId));
        $zip->addFromString('notes.txt', build_all_notes_text($pdo, $workspaceId));
        $zip->addFromString('students.csv', build_students_csv($pdo, $workspaceId));
        $zip->addFromString('exam_state.json', $json . "\n");
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . export_basename($pdo, $workspaceId, 'export') . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . export_basename($pdo, $workspaceId, 'export') . '.json"');
    echo $json . "\n";
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo public_error_message($e);
}
