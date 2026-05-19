<?php

function is_import_normalize_text(string $raw): string
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $raw = preg_replace('/\x{00A0}/u', ' ', $raw) ?? $raw;
    $raw = preg_replace("/[ \t]+$/m", '', $raw) ?? $raw;
    return trim($raw);
}

function is_import_fold_text(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    return strtr($value, [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
        'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's',
        'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        'Á' => 'a', 'Č' => 'c', 'Ď' => 'd', 'É' => 'e', 'Ě' => 'e',
        'Í' => 'i', 'Ň' => 'n', 'Ó' => 'o', 'Ř' => 'r', 'Š' => 's',
        'Ť' => 't', 'Ú' => 'u', 'Ů' => 'u', 'Ý' => 'y', 'Ž' => 'z',
    ]);
}

function is_import_date_iso(string $dateRaw): ?string
{
    if (!preg_match('/^\s*(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})\s*$/', $dateRaw, $m)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
}

function is_import_split_title_location(string $titleLine): array
{
    $titleLine = trim($titleLine);
    $location = null;
    $title = $titleLine;
    $lastComma = strrpos($titleLine, ',');
    if ($lastComma !== false) {
        $possibleLocation = trim(substr($titleLine, $lastComma + 1));
        if ($possibleLocation !== '' && strlen($possibleLocation) <= 80) {
            $title = trim(substr($titleLine, 0, $lastComma));
            $location = $possibleLocation;
        }
    }
    return [$title, $location];
}

function is_import_detect_terms(string $raw): array
{
    $text = is_import_normalize_text($raw);
    if ($text === '') {
        return [];
    }

    $lines = explode("\n", $text);
    $headers = [];
    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*(\d{1,2}\.\s*\d{1,2}\.\s*\d{4})\s*[–—-]\s*(.+?)\s*\[id=(\d+)\]\s*$/u', $line, $m)) {
            [$title, $location] = is_import_split_title_location($m[2]);
            $headers[] = [
                'line' => $index,
                'term_id' => $m[3],
                'date_raw' => preg_replace('/\s+/', ' ', trim($m[1])),
                'date_iso' => is_import_date_iso($m[1]),
                'title' => $title,
                'location' => $location,
            ];
        }
    }

    $terms = [];
    $total = count($headers);
    for ($i = 0; $i < $total; $i++) {
        $start = $headers[$i]['line'];
        $end = $headers[$i + 1]['line'] ?? count($lines);
        $blockLines = array_slice($lines, $start, $end - $start);
        $rawBlock = trim(implode("\n", $blockLines));
        $declared = null;
        if (preg_match('/Přihlášení\s+studenti\s*\((\d+)\)/u', $rawBlock, $m)) {
            $declared = (int)$m[1];
        }
        $term = [
            'term_id' => $headers[$i]['term_id'],
            'date_raw' => $headers[$i]['date_raw'],
            'date_iso' => $headers[$i]['date_iso'],
            'title' => $headers[$i]['title'],
            'location' => $headers[$i]['location'],
            'raw_block' => $rawBlock,
            'student_count_declared' => $declared,
            'student_count_parsed' => 0,
            'is_tmk' => false,
        ];
        $term['is_tmk'] = is_import_is_tmk_term($term);
        $term['student_count_parsed'] = count(is_import_parse_students_from_term($term)['students']);
        $terms[] = $term;
    }

    return $terms;
}

function is_import_is_tmk_term(array $term): bool
{
    $title = is_import_fold_text((string)($term['title'] ?? ''));
    $block = is_import_fold_text((string)($term['raw_block'] ?? ''));

    if (str_contains($title, 'teorie masove komunikace') || preg_match('/\btmk\b/u', $title)) {
        return true;
    }

    if (preg_match('/predmety\s+szz(.{0,800})/su', $block, $m) && str_contains($m[1], 'teorie masove komunikace')) {
        return true;
    }

    return false;
}

function is_import_study_code_map(array $config = []): array
{
    $map = $config['is_import_study_code_map'] ?? [];
    return array_merge([
        'MSZU01' => 'single',
        'MSZU02' => 'double',
    ], is_array($map) ? $map : []);
}

function is_import_clean_extra_text(string $rest, ?string $studyCode, ?string $semester): string
{
    $extra = $rest;
    $extra = preg_replace('/\bFSS\s+B-MSZU\b/u', '', $extra) ?? $extra;
    if ($studyCode !== null) {
        $extra = preg_replace('/\b' . preg_quote($studyCode, '/') . '\s*\[sem\s*' . preg_quote((string)$semester, '/') . '\]/u', '', $extra, 1) ?? $extra;
    }
    $extra = preg_replace('/\bMSZU0[12]\s*\[sem\s*\d+\]/u', '', $extra) ?? $extra;
    $extra = preg_replace('/\s*,\s*,+/', ',', $extra) ?? $extra;
    $extra = preg_replace('/^\s*,\s*|\s*,\s*$/', '', $extra) ?? $extra;
    $extra = preg_replace('/\s{2,}/', ' ', $extra) ?? $extra;
    return trim($extra);
}

function is_import_build_note(array $term, array $student): string
{
    $parts = [
        'IS: ' . ($term['date_raw'] ?? '') . ', ' . ($term['title'] ?? ''),
        'čas ' . $student['time_from'] . '–' . $student['time_to'],
    ];
    if ($student['study_code']) {
        $parts[] = 'kód ' . $student['study_code'];
    }
    if ($student['semester']) {
        $parts[] = 'semestr ' . $student['semester'];
    }
    if ($student['extra'] !== '') {
        $parts[] = 'další: ' . $student['extra'];
    }
    return implode('; ', $parts) . '.';
}

function is_import_parse_students_from_term(array $term, array $config = []): array
{
    $students = [];
    $warnings = [];
    $map = is_import_study_code_map($config);
    $lines = explode("\n", (string)($term['raw_block'] ?? ''));
    $rowPattern = '/^\s*(\d{1,2}[:.]\d{2})\s*[–—-]\s*(\d{1,2}[:.]\d{2})\s+(.+?),\s*učo\s+(\d+),\s*(.+)$/u';

    foreach ($lines as $lineNumber => $line) {
        if (!preg_match($rowPattern, $line, $m)) {
            continue;
        }

        $rest = trim($m[5]);
        preg_match_all('/\b(MSZU0[12])\s*\[sem\s*(\d+)\]/u', $rest, $matches, PREG_SET_ORDER);
        $studyCode = null;
        $semester = null;
        $rowWarnings = [];

        if ($matches) {
            foreach ($matches as $match) {
                if ($match[1] === 'MSZU02') {
                    $studyCode = $match[1];
                    $semester = $match[2];
                    break;
                }
            }
            if ($studyCode === null) {
                $studyCode = $matches[0][1];
                $semester = $matches[0][2];
            }
            $codes = array_unique(array_map(static fn(array $match): string => $match[1], $matches));
            if (count($codes) > 1) {
                $rowWarnings[] = 'Řádek obsahuje MSZU01 i MSZU02, použit MSZU02.';
            }
        } else {
            $rowWarnings[] = 'Nenalezen kód MSZU01/MSZU02.';
        }

        $student = [
            'row_index' => count($students),
            'line_number' => $lineNumber + 1,
            'time_from' => str_replace('.', ':', $m[1]),
            'time_to' => str_replace('.', ':', $m[2]),
            'name' => trim($m[3]),
            'uco' => trim($m[4]),
            'email' => '',
            'study_code' => $studyCode,
            'study_type' => $studyCode !== null ? ($map[$studyCode] ?? 'unknown') : 'unknown',
            'semester' => $semester,
            'extra' => is_import_clean_extra_text($rest, $studyCode, $semester),
            'warnings' => $rowWarnings,
            'raw_line' => trim($line),
        ];
        $student['time_range'] = $student['time_from'] . '–' . $student['time_to'];
        $student['import_note'] = is_import_build_note($term, $student);
        $students[] = $student;
        foreach ($rowWarnings as $warning) {
            $warnings[] = $student['name'] . ': ' . $warning;
        }
    }

    return ['students' => $students, 'warnings' => $warnings];
}

function is_import_parse(string $raw, ?string $selectedTermId = null, array $config = []): array
{
    $terms = is_import_detect_terms($raw);
    $tmkTerms = array_values(array_filter($terms, static fn(array $term): bool => !empty($term['is_tmk'])));
    $selectedTerm = null;

    if ($selectedTermId !== null && $selectedTermId !== '') {
        foreach ($tmkTerms as $term) {
            if ((string)$term['term_id'] === (string)$selectedTermId) {
                $selectedTerm = $term;
                break;
            }
        }
    } elseif (count($tmkTerms) === 1) {
        $selectedTerm = $tmkTerms[0];
    }

    $students = [];
    $warnings = [];
    if ($selectedTerm !== null) {
        $parsed = is_import_parse_students_from_term($selectedTerm, $config);
        $students = $parsed['students'];
        $warnings = $parsed['warnings'];
    }

    return [
        'terms' => $terms,
        'tmk_terms' => $tmkTerms,
        'selected_term' => $selectedTerm,
        'students' => $students,
        'warnings' => $warnings,
    ];
}
