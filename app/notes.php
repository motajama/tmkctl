<?php

function get_note(PDO $pdo, int $studentId, string $questionId): array
{
    $stmt = $pdo->prepare('SELECT * FROM exam_notes WHERE student_id = :student_id AND question_id = :question_id');
    $stmt->execute([':student_id' => $studentId, ':question_id' => $questionId]);
    $note = $stmt->fetch();
    return $note ?: [
        'student_id' => $studentId,
        'question_id' => $questionId,
        'note_text' => '',
        'suggested_grade' => '',
    ];
}

function save_note(PDO $pdo, int $studentId, string $questionId, string $noteText, string $suggestedGrade): void
{
    if (!find_question($questionId)) {
        throw new InvalidArgumentException('Otázka neexistuje.');
    }
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
    $note = get_note($pdo, $studentId, $questionId);
    if (!$student || !$question) {
        throw new InvalidArgumentException('Chybí student nebo otázka.');
    }

    $config = app_config();
    $lines = [
        'Kurz: ' . $config['course_name'],
        'Datum: ' . date('Y-m-d H:i:s'),
        'Studující: ' . $student['name'],
        'UČO: ' . ($student['uco'] ?: ''),
        'E-mail: ' . ($student['email'] ?: ''),
        'Typ studia: ' . study_type_label($student['study_type'] ?? 'unknown'),
        'Otázka: ' . $question['title'],
        'Navržené hodnocení: ' . ($note['suggested_grade'] ?? ''),
        '',
        'Poznámky zkoušejícího:',
        (string)($note['note_text'] ?? ''),
    ];

    if ($format === 'md') {
        return "# Zápis ze zkoušení\n\n"
            . implode("\n", array_map(static function (string $line): string {
                return $line === '' || str_ends_with($line, ':') ? $line : '- ' . $line;
            }, $lines)) . "\n";
    }

    return implode("\n", $lines) . "\n";
}
