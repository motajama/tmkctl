<?php

function get_note(PDO $pdo, int $studentId, string $questionId): array
{
    if (!get_student($pdo, $studentId)) {
        throw new InvalidArgumentException('Studující neexistuje.');
    }
    if (!find_question($questionId)) {
        throw new InvalidArgumentException('Otázka neexistuje.');
    }
    $stmt = $pdo->prepare('SELECT * FROM exam_notes WHERE student_id = :student_id AND question_id = :question_id');
    $stmt->execute([':student_id' => $studentId, ':question_id' => $questionId]);
    return $stmt->fetch() ?: [
        'student_id' => $studentId,
        'question_id' => $questionId,
        'note_text' => '',
        'suggested_grade' => '',
    ];
}

function save_note(PDO $pdo, int $studentId, string $questionId, string $noteText, string $suggestedGrade): void
{
    if (!get_student($pdo, $studentId)) {
        throw new InvalidArgumentException('Studující neexistuje.');
    }
    if (!find_question($questionId)) {
        throw new InvalidArgumentException('Otázka neexistuje.');
    }
    $suggestedGrade = function_exists('mb_substr') ? mb_substr(trim($suggestedGrade), 0, 64) : substr(trim($suggestedGrade), 0, 64);
    $stmt = $pdo->prepare('
        INSERT INTO exam_notes (student_id, question_id, note_text, suggested_grade)
        VALUES (:student_id, :question_id, :note_text, :suggested_grade)
        ON DUPLICATE KEY UPDATE
            note_text = VALUES(note_text),
            suggested_grade = VALUES(suggested_grade)
    ');
    $stmt->execute([
        ':student_id' => $studentId,
        ':question_id' => $questionId,
        ':note_text' => $noteText,
        ':suggested_grade' => $suggestedGrade,
    ]);
}

function export_note_text(PDO $pdo, int $studentId, string $questionId, string $format): string
{
    $student = get_student($pdo, $studentId);
    $question = find_question($questionId);
    if (!$student || !$question) {
        throw new InvalidArgumentException('Chybí studující nebo otázka.');
    }
    $note = get_note($pdo, $studentId, $questionId);
    $config = app_config();

    $fields = [
        'Kurz' => $config['course_name'],
        'Datum' => date('Y-m-d H:i:s'),
        'Studující' => $student['name'],
        'UČO' => $student['uco'] ?: '',
        'E-mail' => $student['email'] ?: '',
        'Typ studia' => study_type_label($student['study_type'] ?? 'unknown'),
        'Otázka' => $question['title'],
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
