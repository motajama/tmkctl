<?php

function normalize_note_question_id(?string $questionId): ?string
{
    $questionId = trim((string)$questionId);
    return $questionId === '' ? null : $questionId;
}

function is_general_note_question_id(?string $questionId): bool
{
    return normalize_note_question_id($questionId) === null;
}

function get_note(PDO $pdo, int $workspaceId, int $studentId, string $questionId): array
{
    $questionId = normalize_note_question_id($questionId);
    if (!get_student($pdo, $studentId, $workspaceId)) {
        throw new InvalidArgumentException('Studující neexistuje.');
    }
    if (!is_general_note_question_id($questionId) && !find_question($questionId)) {
        throw new InvalidArgumentException('Otázka neexistuje.');
    }
    $examNotes = db_table('exam_notes');
    if ($questionId === null) {
        $stmt = $pdo->prepare("SELECT * FROM {$examNotes} WHERE workspace_id = :workspace_id AND student_id = :student_id AND question_id IS NULL");
        $stmt->execute([':workspace_id' => $workspaceId, ':student_id' => $studentId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM {$examNotes} WHERE workspace_id = :workspace_id AND student_id = :student_id AND question_id = :question_id");
        $stmt->execute([':workspace_id' => $workspaceId, ':student_id' => $studentId, ':question_id' => $questionId]);
    }
    return $stmt->fetch() ?: [
        'student_id' => $studentId,
        'question_id' => $questionId,
        'note_text' => '',
        'suggested_grade' => '',
    ];
}

function save_note(PDO $pdo, int $workspaceId, int $studentId, string $questionId, string $noteText, string $suggestedGrade): void
{
    $questionId = normalize_note_question_id($questionId);
    if (!get_student($pdo, $studentId, $workspaceId)) {
        throw new InvalidArgumentException('Studující neexistuje.');
    }
    if (!is_general_note_question_id($questionId) && !find_question($questionId)) {
        throw new InvalidArgumentException('Otázka neexistuje.');
    }
    $suggestedGrade = function_exists('mb_substr') ? mb_substr(trim($suggestedGrade), 0, 64) : substr(trim($suggestedGrade), 0, 64);
    $examNotes = db_table('exam_notes');
    if ($questionId === null) {
        $stmt = $pdo->prepare("SELECT id FROM {$examNotes} WHERE workspace_id = :workspace_id AND student_id = :student_id AND question_id IS NULL");
        $stmt->execute([':workspace_id' => $workspaceId, ':student_id' => $studentId]);
        $noteId = $stmt->fetchColumn();
        if ($noteId !== false) {
            $stmt = $pdo->prepare("UPDATE {$examNotes} SET note_text = :note_text, suggested_grade = :suggested_grade WHERE id = :id");
            $stmt->execute([
                ':id' => (int)$noteId,
                ':note_text' => $noteText,
                ':suggested_grade' => $suggestedGrade,
            ]);
            return;
        }
    }
    $stmt = $pdo->prepare("
        INSERT INTO {$examNotes} (workspace_id, student_id, question_id, note_text, suggested_grade)
        VALUES (:workspace_id, :student_id, :question_id, :note_text, :suggested_grade)
        ON DUPLICATE KEY UPDATE
            note_text = VALUES(note_text),
            suggested_grade = VALUES(suggested_grade)
    ");
    $stmt->execute([
        ':workspace_id' => $workspaceId,
        ':student_id' => $studentId,
        ':question_id' => $questionId,
        ':note_text' => $noteText,
        ':suggested_grade' => $suggestedGrade,
    ]);
}

function export_note_text(PDO $pdo, int $workspaceId, int $studentId, string $questionId, string $format): string
{
    $questionId = normalize_note_question_id($questionId);
    $student = get_student($pdo, $studentId, $workspaceId);
    $question = is_general_note_question_id($questionId) ? null : find_question($questionId);
    if (!$student || (!is_general_note_question_id($questionId) && !$question)) {
        throw new InvalidArgumentException('Chybí studující nebo otázka.');
    }
    $note = get_note($pdo, $workspaceId, $studentId, $questionId);
    $config = app_config();

    $fields = [
        'Kurz' => $config['course_name'],
        'Datum' => date('Y-m-d H:i:s'),
        'Studující' => $student['name'],
        'UČO' => $student['uco'] ?: '',
        'E-mail' => $student['email'] ?: '',
        'Typ studia' => study_type_label($student['study_type'] ?? 'unknown'),
        'Režim poznámky' => is_general_note_question_id($questionId) ? 'Obecná poznámka ke studujícímu' : 'Poznámka k otázce',
        'Otázka' => $question['title'] ?? '',
        'Navržené hodnocení' => $note['suggested_grade'] ?? '',
    ];
    $notes = (string)($note['note_text'] ?? '');

    if ($format === 'md') {
        $out = "# Zápis ze zkoušení\n\n";
        foreach ($fields as $key => $value) {
            $out .= '**' . $key . ':** ' . str_replace(["\r", "\n"], ' ', (string)$value) . "\n\n";
        }
        return $out . "## Poznámky zkoušejícího\n\n" . $notes . "\n";
    }

    $lines = [];
    foreach ($fields as $key => $value) {
        $lines[] = $key . ': ' . str_replace(["\r", "\n"], ' ', (string)$value);
    }
    $lines[] = '';
    $lines[] = 'Poznámky zkoušejícího:';
    $lines[] = $notes;
    return implode("\n", $lines) . "\n";
}
