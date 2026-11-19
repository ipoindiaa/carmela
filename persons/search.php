<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
Auth::check();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    new AccountingEngine(Auth::user('business_id'), Auth::user('user_id'));
    $people = new PersonIdentity(Auth::user('business_id'));
    $query = is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 180) : '';
    echo json_encode($people->search($query), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Person search is unavailable.']);
}
