<?php
/**
 * anexo_evento.php?id=<id> — serve um anexo de evento (guardado em portal_agenda_event_anexos).
 */
require_once __DIR__ . '/../auth_guard.php';
if (empty($_SESSION['autenticado'])) { http_response_code(403); exit('403'); }

require_once __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT nome, mime, conteudo FROM portal_agenda_event_anexos WHERE id = ?");
$st->execute([$id]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit('não encontrado'); }

header('Content-Type: ' . $r['mime']);
header('Content-Disposition: inline; filename="' . preg_replace('/[\r\n"]/', '', $r['nome']) . '"');
header('Content-Length: ' . strlen($r['conteudo']));
header('Cache-Control: private, max-age=3600');
echo $r['conteudo'];
