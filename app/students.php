<?php

function normalize_study_type(?string $value): string
{
    $raw = trim((string)$value);
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    $normalized = strtr($normalized, [
        'é' => 'e',
        'ě' => 'e',
        'ý' => 'y',
        'á' => 'a',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ů' => 'u',
    ]);
    if (in_array($normalized, ['jednoobor', 'jednooborove', 'jednooborovy', '1obor', 'single'], true)) {
        return 'single';
    }
    if (in_array($normalized, ['dvouobor', 'dvouoborove', 'dvouoborovy', '2obor', 'double'], true)) {
        return 'double';
    }
    return 'unknown';
}

function study_type_label(?string $value): string
{
    return match ($value) {
        'single' => 'jednoobor',
        'double' => 'dvouobor',
        default => 'neznámé',
    };
}

function list_students(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM students ORDER BY name ASC, id ASC');
    $students = $stmt->fetchAll();
    foreach ($students as &$student) {
        $student['study_type_label'] = study_type_label($student['study_type'] ?? 'unknown');
    }
    return $students;
}

function add_student(PDO $pdo, array $data): int
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Jméno je povinné.');
    }
    $uco = trim((string)($data['uco'] ?? '')) ?: null;
    $email = trim((string)($data['email'] ?? '')) ?: null;
    $studyType = normalize_study_type($data['study_type'] ?? '');

    $stmt = $pdo->prepare('
        INSERT INTO students (name, uco, email, study_type)
        VALUES (:name, :uco, :email, :study_type)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            email = VALUES(email),
            study_type = VALUES(study_type)
    ');
    $stmt->execute([
        ':name' => $name,
        ':uco' => $uco,
        ':email' => $email,
        ':study_type' => $studyType,
    ]);

    if ($pdo->lastInsertId()) {
        return (int)$pdo->lastInsertId();
    }
    $lookup = $pdo->prepare('SELECT id FROM students WHERE uco = :uco');
    $lookup->execute([':uco' => $uco]);
    return (int)$lookup->fetchColumn();
}

function get_student(PDO $pdo, int $studentId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id');
    $stmt->execute([':id' => $studentId]);
    $student = $stmt->fetch();
    if (!$student) {
        return null;
    }
    $student['study_type_label'] = study_type_label($student['study_type'] ?? 'unknown');
    return $student;
}
