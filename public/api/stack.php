<?php

require_once __DIR__ . '/../../app/render.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/questions.php';
require_once __DIR__ . '/../../app/students.php';
require_once __DIR__ . '/../../app/stack.php';

require_auth();

try {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['ok' => true, 'stack' => list_stack($pdo), 'activeStudentId' => get_active_student_id($pdo)]);
    }
    require_post();
    verify_csrf();

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add') {
        add_to_stack($pdo, (int)$_POST['student_id']);
        $message = 'Studující byl přidán do fronty.';
    } elseif ($action === 'move') {
        move_stack_item($pdo, (int)$_POST['stack_id'], (string)$_POST['state']);
        $message = 'Stav fronty byl změněn.';
    } elseif ($action === 'assign') {
        assign_question($pdo, (int)$_POST['stack_id'], trim((string)$_POST['question_id']) ?: null);
        $message = 'Otázka byla přiřazena.';
    } elseif ($action === 'random_assign') {
        assign_question($pdo, (int)$_POST['stack_id'], random_question_id());
        $message = 'Otázka byla vylosována.';
    } elseif ($action === 'active') {
        set_active_student($pdo, (int)$_POST['student_id']);
        $message = 'Aktivní studující byl nastaven.';
    } else {
        throw new InvalidArgumentException('Neplatná akce.');
    }

    json_response(['ok' => true, 'message' => $message, 'stack' => list_stack($pdo), 'activeStudentId' => get_active_student_id($pdo)]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => public_error_message($e)], 400);
}
