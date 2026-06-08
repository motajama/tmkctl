<?php

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/workspaces.php';

const STACK_LABELS = [
    'examining' => 'zkoušen/a',
    'done' => 'hotovo',
    'preparing' => 'potítko',
    'waiting' => 'čeká',
];

function stack_state_label(?string $state): string
{
    return STACK_LABELS[$state ?? ''] ?? (string)($state ?? '');
}

function export_timestamp(): string
{
    return date('Y-m-d-His');
}

function export_basename(PDO $pdo, int $workspaceId, string $kind): string
{
    return 'tmkctl-' . $kind . '-' . exam_filename_label($pdo, $workspaceId) . '-' . export_timestamp();
}

function export_students_rows(PDO $pdo, int $workspaceId): array
{
    $students = db_table('students');
    $examStack = db_table('exam_stack');
    $examNotes = db_table('exam_notes');
    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.uco, s.email, s.study_type, s.created_at,
               es.state AS stack_status, es.question_id,
               en.note_text AS note, en.suggested_grade, en.updated_at AS note_updated_at
        FROM {$students} s
        LEFT JOIN {$examStack} es ON es.student_id = s.id AND es.workspace_id = :workspace_id
        LEFT JOIN {$examNotes} en ON en.student_id = s.id AND (
            en.question_id = es.question_id OR (en.question_id = '__general__' AND es.question_id IS NULL)
        ) AND en.workspace_id = :workspace_id
        WHERE s.workspace_id = :workspace_id
        ORDER BY COALESCE(NULLIF(FIELD(es.state, 'examining', 'done', 'preparing', 'waiting'), 0), 5), s.name ASC, s.id ASC
    ");
    $stmt->execute([':workspace_id' => $workspaceId]);
    $questions = [];
    try {
        $questions = question_map();
    } catch (Throwable) {
        $questions = [];
    }

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $questionId = (string)($row['question_id'] ?? '');
        $row['question_title'] = isset($questions[$questionId]) ? question_display_label($questions[$questionId]) : '';
        $row['study_type_label'] = study_type_label($row['study_type'] ?? 'unknown');
        $row['stack_status_label'] = stack_state_label($row['stack_status'] ?? '');
    }
    return $rows;
}

function export_notes_rows(PDO $pdo, int $workspaceId): array
{
    $students = db_table('students');
    $examStack = db_table('exam_stack');
    $examNotes = db_table('exam_notes');
    $stmt = $pdo->prepare("
        SELECT en.student_id, en.question_id, en.note_text, en.suggested_grade, en.updated_at,
               s.name, s.uco, s.email, s.study_type,
               es.state AS stack_status
        FROM {$examNotes} en
        JOIN {$students} s ON s.id = en.student_id
        LEFT JOIN {$examStack} es ON es.student_id = en.student_id AND es.workspace_id = :workspace_id
        WHERE en.workspace_id = :workspace_id AND s.workspace_id = :workspace_id
        ORDER BY COALESCE(NULLIF(FIELD(es.state, 'examining', 'done', 'preparing', 'waiting'), 0), 5), s.name ASC, en.updated_at DESC
    ");
    $stmt->execute([':workspace_id' => $workspaceId]);
    $questions = [];
    try {
        $questions = question_map();
    } catch (Throwable) {
        $questions = [];
    }

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $questionId = $row['question_id'] === null || $row['question_id'] === '__general__' ? '' : (string)$row['question_id'];
        $isGeneral = $questionId === '';
        $row['question_title'] = $isGeneral
            ? 'Obecná poznámka ke studujícímu'
            : (isset($questions[$questionId]) ? question_display_label($questions[$questionId]) : $questionId);
        $row['question_id_export'] = $isGeneral ? '' : $questionId;
        $row['note_mode'] = $isGeneral ? 'general' : 'question';
        $row['study_type_label'] = study_type_label($row['study_type'] ?? 'unknown');
        $row['stack_status_label'] = stack_state_label($row['stack_status'] ?? '');
    }
    return $rows;
}

function markdown_inline(string $value): string
{
    return str_replace(["\r", "\n", '|'], [' ', ' ', '\|'], $value);
}

function markdown_fence(string $value): string
{
    $fence = '```';
    while (str_contains($value, $fence)) {
        $fence .= '`';
    }
    return $fence . "\n" . rtrim($value) . "\n" . $fence;
}

function build_all_notes_markdown(PDO $pdo, int $workspaceId): string
{
    $label = exam_display_label($pdo, $workspaceId);
    $rows = export_notes_rows($pdo, $workspaceId);

    $out = "# Zápisy ze zkoušení\n\n";
    $out .= '| Pole | Hodnota |' . "\n" . '| --- | --- |' . "\n";
    $out .= '| Exportováno | ' . markdown_inline(date('Y-m-d H:i:s')) . ' |' . "\n";
    $out .= '| Termín | ' . markdown_inline($label) . ' |' . "\n\n";

    if (!$rows) {
        return $out . "_Žádné poznámky._\n";
    }

    foreach ($rows as $row) {
        $out .= '## ' . markdown_inline((string)$row['name']) . "\n\n";
        $out .= '| Pole | Hodnota |' . "\n" . '| --- | --- |' . "\n";
        $out .= '| Termín | ' . markdown_inline($label) . ' |' . "\n";
        $out .= '| Studující | ' . markdown_inline((string)$row['name']) . ' |' . "\n";
        $out .= '| UČO | ' . markdown_inline((string)($row['uco'] ?? '')) . ' |' . "\n";
        $out .= '| E-mail | ' . markdown_inline((string)($row['email'] ?? '')) . ' |' . "\n";
        $out .= '| Typ studia | ' . markdown_inline((string)$row['study_type_label']) . ' |' . "\n";
        $out .= '| Stav | ' . markdown_inline((string)$row['stack_status_label']) . ' |' . "\n";
        $out .= '| Režim poznámky | ' . markdown_inline($row['note_mode'] === 'general' ? 'Obecná poznámka ke studujícímu' : 'Poznámka k otázce') . ' |' . "\n";
        $out .= '| Otázka | ' . markdown_inline((string)$row['question_title']) . ' |' . "\n";
        $out .= '| ID otázky | ' . markdown_inline((string)$row['question_id_export']) . ' |' . "\n";
        $out .= '| Navržené hodnocení | ' . markdown_inline((string)($row['suggested_grade'] ?? '')) . ' |' . "\n";
        $out .= '| Poslední úprava | ' . markdown_inline((string)($row['updated_at'] ?? '')) . ' |' . "\n\n";
        $out .= "### Poznámka\n\n" . markdown_fence((string)($row['note_text'] ?? '')) . "\n\n";
    }

    return $out;
}

function build_all_notes_text(PDO $pdo, int $workspaceId): string
{
    $label = exam_display_label($pdo, $workspaceId);
    $rows = export_notes_rows($pdo, $workspaceId);
    $out = "Zápisy ze zkoušení\n";
    $out .= "Exportováno: " . date('Y-m-d H:i:s') . "\n";
    $out .= "Termín: " . $label . "\n\n";
    if (!$rows) {
        return $out . "Žádné poznámky.\n";
    }
    foreach ($rows as $row) {
        $out .= str_repeat('=', 72) . "\n";
        $out .= "Studující: " . ($row['name'] ?? '') . "\n";
        $out .= "UČO: " . ($row['uco'] ?? '') . "\n";
        $out .= "E-mail: " . ($row['email'] ?? '') . "\n";
        $out .= "Typ studia: " . ($row['study_type_label'] ?? '') . "\n";
        $out .= "Stav: " . ($row['stack_status_label'] ?? '') . "\n";
        $out .= "Režim poznámky: " . (($row['note_mode'] ?? '') === 'general' ? 'Obecná poznámka ke studujícímu' : 'Poznámka k otázce') . "\n";
        $out .= "Otázka: " . ($row['question_title'] ?? '') . "\n";
        $out .= "ID otázky: " . ($row['question_id_export'] ?? '') . "\n";
        $out .= "Navržené hodnocení: " . ($row['suggested_grade'] ?? '') . "\n";
        $out .= "Poslední úprava: " . ($row['updated_at'] ?? '') . "\n\n";
        $out .= "Poznámka:\n" . rtrim((string)($row['note_text'] ?? '')) . "\n\n";
    }
    return $out;
}

function build_students_csv(PDO $pdo, int $workspaceId): string
{
    $handle = fopen('php://temp', 'r+b');
    if (!$handle) {
        throw new RuntimeException('Nelze připravit CSV export.');
    }
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, ['name', 'uco', 'email', 'study_type', 'stack_status', 'question_id', 'question_title', 'note', 'created_at']);
    foreach (export_students_rows($pdo, $workspaceId) as $row) {
        fputcsv($handle, [
            $row['name'] ?? '',
            $row['uco'] ?? '',
            $row['email'] ?? '',
            $row['study_type'] ?? '',
            $row['stack_status'] ?? '',
            $row['question_id'] ?? '',
            $row['question_title'] ?? '',
            $row['note'] ?? '',
            $row['created_at'] ?? '',
        ]);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv === false ? '' : $csv;
}

function build_exam_state(PDO $pdo, int $workspaceId): array
{
    $questions = [];
    try {
        foreach (load_questions() as $question) {
            $questions[] = [
                'id' => $question['id'] ?? '',
                'title' => $question['title'] ?? '',
                'short_title' => $question['short_title'] ?? '',
            ];
        }
    } catch (Throwable) {
        $questions = [];
    }

    return [
        'exported_at' => date('c'),
        'current_exam_label' => exam_display_label($pdo, $workspaceId),
        'students' => export_students_rows($pdo, $workspaceId),
        'stack' => list_stack($pdo, $workspaceId),
        'notes' => export_notes_rows($pdo, $workspaceId),
        'questions' => $questions,
    ];
}
