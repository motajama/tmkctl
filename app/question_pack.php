<?php

require_once __DIR__ . '/questions.php';

const QUESTION_PACK_ALLOWED_REVIEW_STATUSES = ['reviewed', 'generated', 'needs_review'];
const QUESTION_PACK_MAX_UPLOAD_BYTES = 2097152;

function question_pack_relative_path(): string
{
    return 'data/questions.reviewed.json';
}

function question_pack_backup_dir(): string
{
    return dirname(__DIR__) . '/data/backups';
}

function question_pack_status(): array
{
    $path = questions_path();
    $validation = question_pack_validate_file($path);
    $stats = $validation['stats'];

    return [
        'path' => question_pack_relative_path(),
        'question_count' => $stats['question_count'],
        'reviewed_count' => $stats['reviewed_count'],
        'generated_count' => $stats['generated_count'],
        'needs_review_count' => $stats['needs_review_count'],
        'without_source_refs_count' => $stats['without_source_refs_count'],
        'last_modified' => is_file($path) ? date('Y-m-d H:i:s', (int)filemtime($path)) : '',
        'valid_json' => $validation['valid_json'],
        'schema_valid' => $validation['schema_valid'],
        'errors' => $validation['errors'],
        'warnings' => $validation['warnings'],
        'backups' => question_pack_list_backups(),
    ];
}

function question_pack_validate_file(string $path): array
{
    $emptyStats = question_pack_empty_stats();
    if (!is_file($path) || !is_readable($path)) {
        return [
            'valid_json' => false,
            'schema_valid' => false,
            'errors' => ['Soubor s otázkami nebyl nalezen nebo není čitelný.'],
            'warnings' => [],
            'stats' => $emptyStats,
            'data' => null,
        ];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return [
            'valid_json' => false,
            'schema_valid' => false,
            'errors' => ['Soubor s otázkami nelze přečíst.'],
            'warnings' => [],
            'stats' => $emptyStats,
            'data' => null,
        ];
    }

    return question_pack_validate_json($raw);
}

function question_pack_validate_json(string $raw): array
{
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'valid_json' => false,
            'schema_valid' => false,
            'errors' => ['Neplatný JSON: ' . json_last_error_msg()],
            'warnings' => [],
            'stats' => question_pack_empty_stats(),
            'data' => null,
        ];
    }

    return question_pack_validate_data($data);
}

function question_pack_empty_stats(): array
{
    return [
        'question_count' => 0,
        'reviewed_count' => 0,
        'generated_count' => 0,
        'needs_review_count' => 0,
        'without_source_refs_count' => 0,
    ];
}

function question_pack_is_list(array $value): bool
{
    $expected = 0;
    foreach ($value as $key => $_) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

function question_pack_validate_data(mixed $data): array
{
    $errors = [];
    $warnings = [];
    $stats = question_pack_empty_stats();
    $seenIds = [];

    if (!is_array($data) || !question_pack_is_list($data)) {
        return [
            'valid_json' => true,
            'schema_valid' => false,
            'errors' => ['Kořen JSON musí být pole otázek.'],
            'warnings' => [],
            'stats' => $stats,
            'data' => null,
        ];
    }

    foreach ($data as $index => $question) {
        $label = 'Otázka #' . ($index + 1);
        $stats['question_count']++;
        if (!is_array($question)) {
            $errors[] = "{$label}: položka musí být objekt.";
            continue;
        }

        $id = trim((string)($question['id'] ?? ''));
        if ($id === '') {
            $errors[] = "{$label}: chybí id.";
        } elseif (isset($seenIds[$id])) {
            $errors[] = "{$label}: duplicitní id {$id}.";
        } else {
            $seenIds[$id] = true;
        }

        if (trim((string)($question['title'] ?? '')) === '') {
            $errors[] = "{$label}: chybí title.";
        }
        if (trim((string)($question['short_title'] ?? '')) === '') {
            $warnings[] = "{$label}: chybí short_title.";
        }

        foreach (['outline', 'key_terms', 'authors', 'examiner_focus', 'followup_questions', 'common_mistakes', 'source_refs'] as $field) {
            if (!array_key_exists($field, $question) || !is_array($question[$field])) {
                $errors[] = "{$label}: {$field} musí být pole.";
                continue;
            }
            if ($field !== 'source_refs' && count($question[$field]) === 0) {
                $warnings[] = "{$label}: {$field} je prázdné.";
            }
        }

        if (isset($question['outline']) && is_array($question['outline']) && count($question['outline']) === 0) {
            $warnings[] = "{$label}: outline je prázdné.";
        }
        if (isset($question['key_terms']) && is_array($question['key_terms']) && count($question['key_terms']) === 0) {
            $warnings[] = "{$label}: key_terms je prázdné.";
        }
        if (!isset($question['source_refs']) || !is_array($question['source_refs']) || count($question['source_refs']) === 0) {
            $stats['without_source_refs_count']++;
            $warnings[] = "{$label}: chybí source_refs.";
        }

        $status = (string)($question['review_status'] ?? '');
        if (!in_array($status, QUESTION_PACK_ALLOWED_REVIEW_STATUSES, true)) {
            $errors[] = "{$label}: neplatný review_status.";
        } else {
            if ($status === 'reviewed') {
                $stats['reviewed_count']++;
            } elseif ($status === 'generated') {
                $stats['generated_count']++;
                $warnings[] = "{$label}: review_status není reviewed.";
            } elseif ($status === 'needs_review') {
                $stats['needs_review_count']++;
                $warnings[] = "{$label}: review_status není reviewed.";
            }
        }
    }

    return [
        'valid_json' => true,
        'schema_valid' => count($errors) === 0,
        'errors' => $errors,
        'warnings' => array_values(array_unique($warnings)),
        'stats' => $stats,
        'data' => count($errors) === 0 ? $data : null,
    ];
}

function question_pack_list_backups(int $limit = 10): array
{
    $dir = question_pack_backup_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/questions.reviewed.*.json') ?: [];
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    $backups = [];
    foreach (array_slice($files, 0, $limit) as $file) {
        $backups[] = [
            'name' => basename($file),
            'size' => filesize($file) ?: 0,
            'modified' => date('Y-m-d H:i:s', (int)filemtime($file)),
        ];
    }
    return $backups;
}

function question_pack_create_backup(): string
{
    $source = questions_path();
    if (!is_file($source) || !is_readable($source)) {
        throw new RuntimeException('Původní soubor otázek nelze zálohovat.');
    }

    $dir = question_pack_backup_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nelze vytvořit adresář pro zálohy.');
    }

    $base = $dir . '/questions.reviewed.' . date('Ymd-His');
    $backup = $base . '.json';
    $counter = 1;
    while (is_file($backup)) {
        $backup = $base . '-' . $counter . '.json';
        $counter++;
    }
    if (!copy($source, $backup)) {
        throw new RuntimeException('Nelze vytvořit zálohu původního souboru.');
    }
    return $backup;
}

function question_pack_replace_with_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Soubor se nepodařilo nahrát.');
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Nahraný soubor chybí.');
    }
    if ((int)($file['size'] ?? 0) > QUESTION_PACK_MAX_UPLOAD_BYTES) {
        throw new InvalidArgumentException('Soubor je příliš velký. Limit je 2 MB.');
    }

    $name = (string)($file['name'] ?? '');
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
        throw new InvalidArgumentException('Nahraj soubor s příponou .json.');
    }

    $raw = file_get_contents($file['tmp_name']);
    if ($raw === false) {
        throw new RuntimeException('Nahraný soubor nelze přečíst.');
    }

    $validation = question_pack_validate_json($raw);
    if (!$validation['valid_json'] || !$validation['schema_valid']) {
        return [
            'ok' => false,
            'message' => 'Balík otázek nebyl nahrán, validace obsahuje chyby.',
            'validation' => question_pack_public_validation($validation),
        ];
    }

    $backup = question_pack_create_backup();
    $target = questions_path();
    $tmp = tempnam(dirname($target), 'questions.reviewed.');
    if ($tmp === false) {
        throw new RuntimeException('Nelze připravit dočasný soubor pro otázky.');
    }
    if (file_put_contents($tmp, rtrim($raw) . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('Nelze zapsat nový soubor otázek.');
    }
    if (!rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('Nelze nahradit soubor otázek.');
    }

    return [
        'ok' => true,
        'message' => 'Balík otázek byl nahrán. Původní soubor byl zálohován.',
        'backup' => basename($backup),
        'validation' => question_pack_public_validation($validation),
        'status' => question_pack_status(),
        'questions' => $validation['data'],
    ];
}

function question_pack_public_validation(array $validation): array
{
    return [
        'valid_json' => (bool)$validation['valid_json'],
        'schema_valid' => (bool)$validation['schema_valid'],
        'errors' => $validation['errors'],
        'warnings' => $validation['warnings'],
        'stats' => $validation['stats'],
    ];
}
