<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/stack.php';
require_once __DIR__ . '/../../app/is_import.php';

require_auth();
require_post();
verify_csrf();

const IS_IMPORT_MAX_BYTES = 512000;

function is_import_preview_status(PDO $pdo, array $student): array
{
    $students = db_table('students');
    if (($student['uco'] ?? '') !== '') {
        $stmt = $pdo->prepare("SELECT id FROM {$students} WHERE uco = :uco LIMIT 1");
        $stmt->execute([':uco' => $student['uco']]);
        if ($stmt->fetchColumn() !== false) {
            return ['status' => 'duplicate_uco', 'label' => 'duplicita podle UČO', 'can_import' => false];
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM {$students} WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name)) LIMIT 1");
        $stmt->execute([':name' => $student['name']]);
        if ($stmt->fetchColumn() !== false) {
            return ['status' => 'possible_duplicate', 'label' => 'možná duplicita', 'can_import' => false];
        }
    }

    if (!empty($student['warnings'])) {
        return ['status' => 'parse_warning', 'label' => 'chyba parsování', 'can_import' => true];
    }

    return ['status' => 'new', 'label' => 'nový', 'can_import' => true];
}

function is_import_preview_rows(PDO $pdo, array $students): array
{
    foreach ($students as &$student) {
        $status = is_import_preview_status($pdo, $student);
        $student['status'] = $status['status'];
        $student['status_label'] = $status['label'];
        $student['can_import'] = $status['can_import'];
    }
    unset($student);
    return $students;
}

function is_import_load_selected_rows(): ?array
{
    if (!isset($_POST['selected_rows'])) {
        return null;
    }
    $raw = $_POST['selected_rows'];
    if (is_array($raw)) {
        return array_values(array_filter(array_map('intval', $raw), static fn(int $value): bool => $value >= 0));
    }
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('intval', $decoded), static fn(int $value): bool => $value >= 0));
        }
    }
    return [];
}

try {
    $action = (string)($_POST['action'] ?? '');
    $raw = (string)($_POST['raw_text'] ?? '');
    if (strlen($raw) > IS_IMPORT_MAX_BYTES) {
        throw new InvalidArgumentException('Vložený text je příliš dlouhý. Limit je 500 KB.');
    }

    $pdo = db();
    $config = app_config();

    if ($action === 'detect_terms') {
        $parsed = is_import_parse($raw, null, $config);
        $tmkTerms = array_map(static function (array $term): array {
            unset($term['raw_block']);
            return $term;
        }, $parsed['tmk_terms']);
        json_response([
            'ok' => true,
            'message' => count($tmkTerms) > 0
                ? 'Termíny TMK byly nalezeny.'
                : 'Nenalezen žádný termín Teorie masové komunikace / TMK.',
            'terms' => $tmkTerms,
            'allTermCount' => count($parsed['terms']),
        ]);
    }

    if ($action === 'preview' || $action === 'import') {
        $selectedTermId = (string)($_POST['term_id'] ?? '');
        $parsed = is_import_parse($raw, $selectedTermId, $config);
        if (!$parsed['selected_term']) {
            throw new InvalidArgumentException('Vyberte termín Teorie masové komunikace / TMK.');
        }
        $students = is_import_preview_rows($pdo, $parsed['students']);

        if ($action === 'preview') {
            json_response([
                'ok' => true,
                'message' => sprintf('Náhled: nalezeno %d řádků studujících.', count($students)),
                'term' => array_diff_key($parsed['selected_term'], ['raw_block' => true]),
                'students' => $students,
                'warnings' => $parsed['warnings'],
            ]);
        }

        $selectedRows = is_import_load_selected_rows();
        $imported = 0;
        $skippedDuplicates = 0;
        $skipped = 0;
        $warnings = $parsed['warnings'];

        $pdo->beginTransaction();
        try {
            foreach ($students as $student) {
                if ($selectedRows !== null && !in_array((int)$student['row_index'], $selectedRows, true)) {
                    continue;
                }
                if (empty($student['can_import'])) {
                    if (($student['status'] ?? '') === 'duplicate_uco' || ($student['status'] ?? '') === 'possible_duplicate') {
                        $skippedDuplicates++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                $status = is_import_preview_status($pdo, $student);
                if (!$status['can_import']) {
                    $skippedDuplicates++;
                    continue;
                }

                $result = add_student($pdo, [
                    'name' => $student['name'],
                    'uco' => $student['uco'],
                    'email' => '',
                    'study_type' => $student['study_type'],
                ]);
                if (!empty($result['created'])) {
                    $imported++;
                }
                add_to_stack($pdo, (int)$result['id']);
                set_app_setting('student_import_note:' . (int)$result['id'], $student['import_note'] ?? null, $pdo);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        json_response([
            'ok' => true,
            'message' => sprintf('IS import: přidáno %d, duplicit přeskočeno %d, ostatní přeskočeno %d.', $imported, $skippedDuplicates, $skipped),
            'imported' => $imported,
            'skippedDuplicates' => $skippedDuplicates,
            'skipped' => $skipped,
            'warnings' => $warnings,
            'students' => list_students($pdo),
            'stack' => list_stack($pdo),
            'activeStudentId' => get_active_student_id($pdo),
        ]);
    }

    throw new InvalidArgumentException('Neplatná akce.');
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
