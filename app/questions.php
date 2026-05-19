<?php

function questions_path(): string
{
    return dirname(__DIR__) . '/data/questions.reviewed.json';
}

function load_questions(): array
{
    static $questions = null;
    if ($questions !== null) {
        return $questions;
    }

    $path = questions_path();
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Soubor s otázkami nebyl nalezen nebo není čitelný.');
    }

    $raw = file_get_contents($path);
    $data = json_decode((string)$raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        throw new RuntimeException('Soubor s otázkami obsahuje neplatný JSON: ' . json_last_error_msg());
    }

    $questions = [];
    foreach ($data as $question) {
        if (is_array($question) && !empty($question['id']) && !empty($question['title'])) {
            $questions[] = $question;
        }
    }
    if (!$questions) {
        throw new RuntimeException('Soubor s otázkami neobsahuje žádné použitelné otázky.');
    }
    return $questions;
}

function try_load_questions(): array
{
    try {
        return ['questions' => load_questions(), 'error' => ''];
    } catch (Throwable $e) {
        return ['questions' => [], 'error' => $e->getMessage()];
    }
}

function question_map(): array
{
    $map = [];
    foreach (load_questions() as $question) {
        $map[(string)$question['id']] = $question;
    }
    return $map;
}

function find_question(?string $questionId): ?array
{
    if ($questionId === null || $questionId === '') {
        return null;
    }
    $map = question_map();
    return $map[$questionId] ?? null;
}

function random_question_id(): string
{
    $questions = load_questions();
    $question = $questions[random_int(0, count($questions) - 1)];
    return (string)$question['id'];
}
