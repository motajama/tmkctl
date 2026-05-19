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
    $stmt = $pdo->query('SELECT id, name, uco, email, study_type, created_at, updated_at FROM students ORDER BY name ASC, id ASC');
    $students = $stmt->fetchAll();
    foreach ($students as &$student) {
        $student['study_type_label'] = study_type_label($student['study_type'] ?? 'unknown');
    }
    return $students;
}

function validate_student_data(array $data): array
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Jméno je povinné.');
    }
    $email = trim((string)($data['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('E-mail nemá platný formát.');
    }

    return [
        'name' => $name,
        'uco' => trim((string)($data['uco'] ?? '')) ?: null,
        'email' => $email ?: null,
        'study_type' => normalize_study_type($data['study_type'] ?? ''),
    ];
}

function find_existing_student(PDO $pdo, ?string $uco, string $name, ?string $email): ?int
{
    if ($uco !== null) {
        $stmt = $pdo->prepare('SELECT id FROM students WHERE uco = :uco');
        $stmt->execute([':uco' => $uco]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
    }
    if ($email !== null) {
        $stmt = $pdo->prepare('SELECT id FROM students WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name)) AND LOWER(TRIM(email)) = LOWER(TRIM(:email)) LIMIT 1');
        $stmt->execute([':name' => $name, ':email' => $email]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
    }
    return null;
}

function add_student(PDO $pdo, array $data): array
{
    $student = validate_student_data($data);
    $existingId = find_existing_student($pdo, $student['uco'], $student['name'], $student['email']);

    if ($existingId !== null) {
        $stmt = $pdo->prepare('
            UPDATE students
            SET name = :name, uco = :uco, email = :email, study_type = :study_type
            WHERE id = :id
        ');
        $stmt->execute([
            ':id' => $existingId,
            ':name' => $student['name'],
            ':uco' => $student['uco'],
            ':email' => $student['email'],
            ':study_type' => $student['study_type'],
        ]);
        return ['id' => $existingId, 'created' => false];
    }

    $stmt = $pdo->prepare('
        INSERT INTO students (name, uco, email, study_type)
        VALUES (:name, :uco, :email, :study_type)
    ');
    $stmt->execute([
        ':name' => $student['name'],
        ':uco' => $student['uco'],
        ':email' => $student['email'],
        ':study_type' => $student['study_type'],
    ]);

    return ['id' => (int)$pdo->lastInsertId(), 'created' => true];
}

function get_student(PDO $pdo, int $studentId): ?array
{
    $stmt = $pdo->prepare('SELECT id, name, uco, email, study_type FROM students WHERE id = :id');
    $stmt->execute([':id' => $studentId]);
    $student = $stmt->fetch();
    if (!$student) {
        return null;
    }
    $student['study_type_label'] = study_type_label($student['study_type'] ?? 'unknown');
    return $student;
}

function import_students_csv(PDO $pdo, string $path): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('CSV soubor nelze otevřít.');
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        throw new InvalidArgumentException('CSV soubor je prázdný.');
    }

    $header = array_map(static function ($value): string {
        return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$value));
    }, $header);
    if (array_search('name', $header, true) === false) {
        fclose($handle);
        throw new InvalidArgumentException('CSV musí obsahovat sloupec name.');
    }

    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $line = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $line++;
        $allEmpty = true;
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                $allEmpty = false;
                break;
            }
        }
        if ($allEmpty) {
            $skipped++;
            continue;
        }

        $values = array_slice(array_pad($row, count($header), ''), 0, count($header));
        $data = array_combine($header, array_map(static fn($value): string => trim((string)$value), $values));
        if (!$data || trim((string)($data['name'] ?? '')) === '') {
            $skipped++;
            $errors[] = 'Řádek ' . $line . ': chybí jméno.';
            continue;
        }

        try {
            $result = add_student($pdo, [
                'name' => $data['name'] ?? '',
                'uco' => $data['uco'] ?? '',
                'email' => $data['email'] ?? '',
                'study_type' => $data['study_type'] ?? '',
            ]);
            $result['created'] ? $imported++ : $updated++;
        } catch (Throwable $e) {
            $skipped++;
            $errors[] = 'Řádek ' . $line . ': ' . $e->getMessage();
        }
    }

    fclose($handle);
    return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
}
