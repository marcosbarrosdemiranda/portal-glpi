<?php
require_once dirname(__DIR__) . '/auth_guard.php';
header('Content-Type: application/json');
if (empty($_SESSION['autenticado'])) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
echo json_encode(['ok' => true]);
