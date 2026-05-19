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
    $raw = file_get_contents(questions_path());
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Nelze načíst otázky.');
    }
    $questions = $data;
    return $questions;
}

function question_map(): array
{
    $map = [];
    foreach (load_questions() as $question) {
        if (!empty($question['id'])) {
            $map[$question['id']] = $question;
        }
    }
    return $map;
}

function find_question(?string $questionId): ?array
{
    if (!$questionId) {
        return null;
    }
    $map = question_map();
    return $map[$questionId] ?? null;
}

function random_question_id(): ?string
{
    $questions = load_questions();
    if (!$questions) {
        return null;
    }
    $question = $questions[random_int(0, count($questions) - 1)];
    return $question['id'] ?? null;
}
