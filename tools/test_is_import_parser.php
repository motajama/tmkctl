<?php

require_once __DIR__ . '/../app/is_import.php';

$samplePath = __DIR__ . '/../data/is-mu-paste.sample.txt';
$raw = file_get_contents($samplePath);
if ($raw === false) {
    fwrite(STDERR, "Cannot read sample file.\n");
    exit(1);
}

$parsed = is_import_parse($raw, null);
$terms = $parsed['terms'];
$tmkTerms = $parsed['tmk_terms'];

echo "Detected terms: " . count($terms) . PHP_EOL;
foreach ($terms as $term) {
    echo sprintf(
        "- %s | %s | %s | TMK=%s | declared=%s | parsed=%d\n",
        $term['term_id'],
        $term['date_raw'],
        $term['title'],
        $term['is_tmk'] ? 'yes' : 'no',
        $term['student_count_declared'] ?? '-',
        $term['student_count_parsed']
    );
}

echo PHP_EOL . "TMK terms: " . count($tmkTerms) . PHP_EOL;
foreach ($tmkTerms as $term) {
    echo "- {$term['date_raw']} {$term['title']} [id={$term['term_id']}]" . PHP_EOL;
}

$errors = [];
$tmk = null;
foreach ($tmkTerms as $term) {
    if ($term['date_iso'] === '2026-06-10') {
        $tmk = $term;
        break;
    }
}
if ($tmk === null) {
    $errors[] = 'Missing 10. 6. 2026 TMK term.';
}

foreach ($terms as $term) {
    if (str_contains(is_import_fold_text($term['title']), 'obhajoby') && $term['is_tmk']) {
        $errors[] = 'Defense term was incorrectly selected as TMK: ' . $term['title'];
    }
}

if ($tmk !== null) {
    $students = is_import_parse_students_from_term($tmk)['students'];
    echo PHP_EOL . "Parsed students for selected TMK term: " . count($students) . PHP_EOL;
    foreach (array_slice($students, 0, 5) as $student) {
        echo sprintf(
            "- %s %s | %s | %s | %s\n",
            $student['time_range'],
            $student['name'],
            $student['uco'],
            $student['study_code'] ?? '-',
            $student['study_type']
        );
    }

    if (count($students) !== 25) {
        $errors[] = 'Expected 25 TMK students, parsed ' . count($students) . '.';
    }
    foreach ($students as $student) {
        if ($student['study_code'] === 'MSZU01' && $student['study_type'] !== 'single') {
            $errors[] = 'MSZU01 did not map to single for ' . $student['name'];
        }
        if ($student['study_code'] === 'MSZU02' && $student['study_type'] !== 'double') {
            $errors[] = 'MSZU02 did not map to double for ' . $student['name'];
        }
    }
}

if ($errors) {
    echo PHP_EOL . "FAIL" . PHP_EOL;
    foreach ($errors as $error) {
        echo "- {$error}" . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . "OK" . PHP_EOL;
