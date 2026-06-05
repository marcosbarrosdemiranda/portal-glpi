<?php
/**
 * chamado_ajax.php — Endpoint AJAX para detalhes do chamado (modal)
 *
 * Uso: chamado_ajax.php?id=1234
 * Retorna JSON com dados do ticket para exibição em modal flutuante.
 */
session_start();
if (empty($_SESSION['autenticado'])) {
	http_response_code(401);
	echo json_encode(['ok' => false, 'msg' => 'Não autenticado']);
	exit;
}

header('Content-Type: application/json');

$ticket_id = (int)($_GET['id'] ?? 0);
if ($ticket_id <= 0) {
	echo json_encode(['ok' => false, 'msg' => 'ID de chamado inválido']);
	exit;
}

require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/entidade_alias.php';

function apelidoAtendente($nome): string {
	if (is_array($nome)) $nome = $nome['name'] ?? $nome['firstname'] ?? $nome['realname'] ?? 'Sistema';
	$partes = explode(' ', trim($nome));
	return end($partes) ?: $nome;
}

function glpi_req(string $url, string $token): array {
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => ['Session-Token: ' . $token, 'App-Token: ' . GLPI_APP_TOKEN],
		CURLOPT_SSL_VERIFYPEER => false,
	]);
	$r = json_decode(curl_exec($ch), true);
	curl_close($ch);
	return is_array($r) ? $r : [];
}

// Inicia sessão GLPI
$auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth, 'App-Token: ' . GLPI_APP_TOKEN],
	CURLOPT_SSL_VERIFYPEER => false,
]);
$r = json_decode(curl_exec($ch), true);
curl_close($ch);
$token = $r['session_token'] ?? '';

if (!$token) {
	echo json_encode(['ok' => false, 'msg' => 'Falha de autenticação GLPI']);
	exit;
}

// Busca dados do ticket com expand_dropdowns
$ticket = glpi_req(
	GLPI_URL . '/apirest.php/Ticket/' . $ticket_id . '?expand_dropdowns=true',
	$token
);

// Busca followups
$followups = glpi_req(
	GLPI_URL . '/apirest.php/Ticket/' . $ticket_id . '/ITILFollowup?expand_dropdowns=true',
	$token
);

// Busca anexos (documentos) dos followups
$docs = [];
if (is_array($followups)) {
	foreach ($followups as $f) {
		$fu_id = (int)($f['id'] ?? 0);
		if (!$fu_id) continue;
		$di_list = glpi_req(
			GLPI_URL . '/apirest.php/ITILFollowup/' . $fu_id . '/Document_Item',
			$token
		);
		foreach ((array)$di_list as $di) {
			$docid = (int)($di['documents_id'] ?? 0);
			if (!$docid) continue;
			$docData = glpi_req(
				GLPI_URL . '/apirest.php/Document/' . $docid,
				$token
			);
			if (empty($docData['id'])) continue;
			$fname = $docData['filename'] ?? $docData['name'] ?? 'arquivo';
			$ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
			$docs[] = [
				'id'    => $docid,
				'nome'  => $fname,
				'isImg' => in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg']),
				'size'  => (int)($docData['filesize'] ?? 0),
			];
		}
	}
}

// Encerra sessão
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Session-Token: '.$token,'App-Token: '.GLPI_APP_TOKEN]]);
curl_exec($ch);
curl_close($ch);

if (empty($ticket['id'])) {
	echo json_encode(['ok' => false, 'msg' => 'Chamado não encontrado']);
	exit;
}

$status_map  = [1=>'Novo',2=>'Atribuído',3=>'Planejado',4=>'Pendente',5=>'Solucionado',6=>'Fechado'];
$type_map    = [1=>'Incidente',2=>'Requisição'];
$urg_map     = [1=>'Muito baixa',2=>'Baixa',3=>'Média',4=>'Alta',5=>'Muito alta'];

// Processa followups — mesmo padrão de descrição/solução: detecta HTML, senão converte
$fu_list = [];
foreach ($followups as $fu) {
	if (empty($fu['id'])) continue;
	$conteudo = html_entity_decode($fu['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
	if (!preg_match('/<[a-z][\s\S]*>/i', $conteudo)) {
		$conteudo = nl2br(htmlspecialchars($conteudo));
	}
	$fu_list[] = [
		'id'       => (int)$fu['id'],
		'data'     => substr($fu['date'] ?? '', 0, 16),
		'autor'    => apelidoAtendente($fu['users_id'] ?? 'Sistema'),
		'conteudo' => $conteudo,
		'tipo'     => is_array($fu['requesttypes_id'] ?? null) ? ($fu['requesttypes_id']['name'] ?? '—') : ($fu['requesttypes_id'] ?? '—'),
	];
}

// Normaliza campos que podem vir como array com expand_dropdowns
$entidade = $ticket['entities_id'] ?? '';
if (is_array($entidade)) $entidade = $entidade['completename'] ?? $entidade['name'] ?? '';

$requerente = $ticket['_users_id_requester'] ?? '—';
if (is_array($requerente)) $requerente = $requerente['name'] ?? $requerente['firstname'] ?? '—';

$categoria = $ticket['_categories_id'] ?? $ticket['categories_id'] ?? '—';
if (is_array($categoria)) $categoria = $categoria['name'] ?? '—';

// Processa description: mantém HTML leve para renderização no modal
$descricao = $ticket['content'] ?? '';
$descricao = html_entity_decode($descricao, ENT_QUOTES | ENT_HTML5, 'UTF-8');
if (!preg_match('/<[a-z][\s\S]*>/i', $descricao)) {
	$descricao = nl2br(htmlspecialchars($descricao));
}

$solucao = $ticket['solution'] ?? '';
$solucao = html_entity_decode($solucao, ENT_QUOTES | ENT_HTML5, 'UTF-8');
if (!preg_match('/<[a-z][\s\S]*>/i', $solucao)) {
	$solucao = nl2br(htmlspecialchars($solucao));
}

$sn = (int)(is_array($ticket['status'] ?? null) ? ($ticket['status']['id'] ?? 0) : ($ticket['status'] ?? 0));

echo json_encode([
	'ok'       => true,
	'chamado'  => [
		'id'         => (int)$ticket['id'],
		'titulo'     => $ticket['name'] ?? 'Sem título',
		'status'     => $status_map[$sn] ?? 'Desconhecido',
		'status_num' => $sn,
		'tipo'       => $type_map[(int)(is_array($ticket['type'] ?? null) ? ($ticket['type']['id'] ?? 1) : ($ticket['type'] ?? 1))] ?? 'Incidente',
		'urgencia'   => $urg_map[(int)(is_array($ticket['urgency'] ?? null) ? ($ticket['urgency']['id'] ?? 3) : ($ticket['urgency'] ?? 3))] ?? 'Média',
		'entidade'   => apelido_entidade($entidade),
		'requerente' => $requerente,
		'categoria'  => $categoria,
		'descricao'  => $descricao,
		'data'       => substr($ticket['date'] ?? '', 0, 10),
		'atualizado' => substr($ticket['date_mod'] ?? '', 0, 16),
		'solucao'    => $solucao,
	],
	'followups' => $fu_list,
	'docs'      => $docs,
]);
