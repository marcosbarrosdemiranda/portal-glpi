<?php
require_once dirname(__DIR__) . '/auth_guard.php';
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
