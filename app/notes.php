<?php

const GENERAL_NOTE_QUESTION_ID = '__general__';

function normalize_note_question_id(?string $questionId): ?string
{
    $questionId = trim((string)$questionId);
    return $questionId === '' || $questionId === GENERAL_NOTE_QUESTION_ID ? null : $questionId;
}

function is_general_note_question_id(?string $questionId): bool
{
    return normalize_note_question_id($questionId) === null;
}

function note_storage_question_id(?string $questionId): string
{
    return normalize_note_question_id($questionId) ?? GENERAL_NOTE_QUESTION_ID;
}

function public_note_row(array $note): array
{
    if (($note['question_id'] ?? null) === GENERAL_NOTE_QUESTION_ID) {
        $note['question_id'] = null;
    }
    $note['lock_version'] = (int)($note['lock_version'] ?? 0);
    return $note;
}

function get_note(PDO $pdo, int $workspaceId, int $studentId, ?string $questionId): array
{
    $questionId = normalize_note_question_id($questionId);
    if (!get_student($pdo, $studentId, $workspaceId)) {
        throw new InvalidArgumentException('Studující neexistuje.');
    }
    if (!is_general_note_question_id($questionId) && !find_question($questionId)) {
        throw new InvalidArgumentException('Otázka neexistuje.');
    }
    $examNotes = db_table('exam_notes');
    $stmt = $pdo->prepare("SELECT * FROM {$examNotes} WHERE workspace_id = :workspace_id AND student_id = :student_id AND question_id = :question_id");
    $stmt->execute([
        ':workspace_id' => $workspaceId,
        ':student_id' => $studentId,
        ':question_id' => note_storage_question_id($questionId),
    ]);
    $note = $stmt->fetch();
    return $note ? public_note_row($note) : [
        'student_id' => $studentId,
        'question_id' => $questionId,
        'note_text' => '',
        'suggested_grade' => '',
        'lock_version' => 0,
    ];
}

function save_note(PDO $pdo, int $workspaceId, int $studentId, string $questionId, string $noteText, string $suggestedGrade, int $baseLockVersion): array
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
    $storageQuestionId = note_storage_question_id($questionId);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM {$examNotes} WHERE workspace_id = :workspace_id AND student_id = :student_id AND question_id = :question_id FOR UPDATE");
        $stmt->execute([
            ':workspace_id' => $workspaceId,
            ':student_id' => $studentId,
            ':question_id' => $storageQuestionId,
        ]);
        $existing = $stmt->fetch();

        if ($existing) {
            $currentVersion = (int)($existing['lock_version'] ?? 0);
            if ($currentVersion !== $baseLockVersion) {
                $pdo->rollBack();
                throw new NoteConflictException(public_note_row($existing));
            }
            $stmt = $pdo->prepare("
                UPDATE {$examNotes}
                SET note_text = :note_text,
                    suggested_grade = :suggested_grade,
                    lock_version = lock_version + 1
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => (int)$existing['id'],
                ':note_text' => $noteText,
                ':suggested_grade' => $suggestedGrade,
            ]);
        } else {
            if ($baseLockVersion !== 0) {
                $pdo->rollBack();
                throw new NoteConflictException(get_note($pdo, $workspaceId, $studentId, $questionId));
            }
            $stmt = $pdo->prepare("
                INSERT INTO {$examNotes} (workspace_id, student_id, question_id, note_text, suggested_grade, lock_version)
                VALUES (:workspace_id, :student_id, :question_id, :note_text, :suggested_grade, 1)
            ");
            $stmt->execute([
                ':workspace_id' => $workspaceId,
                ':student_id' => $studentId,
                ':question_id' => $storageQuestionId,
                ':note_text' => $noteText,
                ':suggested_grade' => $suggestedGrade,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            throw new NoteConflictException(get_note($pdo, $workspaceId, $studentId, $questionId));
        }
        throw $e;
    }

    return get_note($pdo, $workspaceId, $studentId, $questionId);
}

class NoteConflictException extends RuntimeException
{
    public function __construct(private array $currentNote)
    {
        parent::__construct('Poznámku mezitím upravil jiný uživatel. Načtěte aktuální verzi a sloučte změny ručně.');
    }

    public function currentNote(): array
    {
        return $this->currentNote;
    }
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
