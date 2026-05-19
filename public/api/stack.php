<?php

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/render.php';
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
    } elseif ($action === 'move') {
        move_stack_item($pdo, (int)$_POST['stack_id'], (string)$_POST['state']);
    } elseif ($action === 'assign') {
        assign_question($pdo, (int)$_POST['stack_id'], trim((string)$_POST['question_id']) ?: null);
    } elseif ($action === 'random_assign') {
        assign_question($pdo, (int)$_POST['stack_id'], random_question_id());
    } elseif ($action === 'active') {
        set_active_student($pdo, (int)$_POST['student_id']);
    } else {
        throw new InvalidArgumentException('Neznámá akce.');
    }

    json_response(['ok' => true, 'stack' => list_stack($pdo), 'activeStudentId' => get_active_student_id($pdo)]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
