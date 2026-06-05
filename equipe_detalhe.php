<?php
/**
 * equipe_detalhe.php — Endpoint AJAX para detalhes do técnico na Equipe
 *
 * Uso: equipe_detalhe.php?id=123
 * Retorna JSON com rotinas, chamados e desempenho do técnico.
 */
session_start();
if (empty($_SESSION['autenticado'])) {
	http_response_code(401);
	echo json_encode(['ok' => false, 'msg' => 'Não autenticado']);
	exit;
}

header('Content-Type: application/json');

$user_id = (int)($_GET['id'] ?? 0);
if ($user_id <= 0) {
	echo json_encode(['ok' => false, 'msg' => 'ID de técnico inválido']);
	exit;
}

require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/entidade_alias.php';

// ── HELPERS ──────────────────────────────────────────────────────────────────

function esc_js(string $s): string {
	return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ── 1. ROTINAS ─────────────────────────────────────────────────────────────────
$rotinas = ['diario' => [], 'semanal' => [], 'quinzenal' => [], 'mensal' => []];

$rotinas_data = [];

try {
	$q = $pdo->prepare("
		SELECT tr.id, tr.name, tr.periodicity,
		       tr.tickettemplates_id, tt.name as template_name,
		       tpf_desc.value as descricao
		FROM glpi_ticketrecurrents tr
		LEFT JOIN glpi_tickettemplates tt ON tr.tickettemplates_id = tt.id
		LEFT JOIN glpi_tickettemplatepredefinedfields tpf_desc
		  ON tpf_desc.tickettemplates_id = tr.tickettemplates_id
		  AND tpf_desc.num = 21
		WHERE tr.is_active = 1 AND tr.entities_id = 0
		  AND EXISTS (
		    SELECT 1 FROM glpi_tickettemplatepredefinedfields tpf
		    WHERE tpf.tickettemplates_id = tr.tickettemplates_id
		      AND tpf.num = 5
		      AND tpf.value = ?
		  )
		ORDER BY tr.periodicity, tr.name
	");
	$q->execute([(string)$user_id]);
	$rotinas_data = $q->fetchAll();
} catch (Exception $e) { /* fallback */ }

try {
	if (empty($rotinas_data)) {
		$rotinas_data = $pdo->query("
			SELECT tr.id, tr.name, tr.periodicity,
			       tr.tickettemplates_id, tt.name as template_name,
			       tpf_desc.value as descricao
			FROM glpi_ticketrecurrents tr
			LEFT JOIN glpi_tickettemplates tt ON tr.tickettemplates_id = tt.id
			LEFT JOIN glpi_tickettemplatepredefinedfields tpf_desc
			  ON tpf_desc.tickettemplates_id = tr.tickettemplates_id
			  AND tpf_desc.num = 21
			WHERE tr.is_active = 1 AND tr.entities_id = 0
			ORDER BY tr.periodicity, tr.name
		")->fetchAll();
	}
} catch (Exception $e) { /* sem dados */ }

foreach ($rotinas_data as $r) {
	$periodicidade = $r['periodicity'] ?? '86400';

	if (is_numeric($periodicidade)) {
		$seg = (int)$periodicidade;
		if ($seg <= 86400) {        $chave = 'diario';
		} elseif ($seg <= 604800) {  $chave = 'semanal';
		} elseif ($seg <= 1296000) { $chave = 'quinzenal';
		} else {                     $chave = 'mensal'; }
	} else {
		$str = strtoupper($periodicidade);
		if (str_contains($str, 'DIA') || str_contains($str, 'DAY')) {
			$chave = 'diario';
		} elseif (str_contains($str, 'SEMANA') || str_contains($str, 'WEEK')) {
			$chave = 'semanal';
		} elseif (str_contains($str, 'QUINZENA')) {
			$chave = 'quinzenal';
		} else {
			$chave = 'mensal';
		}
	}

	$rotinas[$chave][] = [
		'id'            => (int)$r['id'],
		'nome'          => $r['name'] ?? 'Rotina',
		'periodicidade' => $periodicidade,
		'template_id'   => (int)($r['tickettemplates_id'] ?? 0),
		'template'      => $r['template_name'] ?? '',
		'descricao'     => trim(strip_tags(html_entity_decode($r['descricao'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
	];
}

// ── 2. CHAMADOS (SQL direto — search API tem índice desatualizado) ────────
$chamados_abertos   = [];
$chamados_concluidos = [];

$type_map    = [1=>'Incidente',2=>'Requisição',6=>'Requisição'];
$status_map  = [1=>'Novo',2=>'Atribuído',3=>'Planejado',4=>'Pendente',5=>'Solucionado',6=>'Fechado'];

$startDate = $_GET['startDate'] ?? '';
$endDate   = $_GET['endDate'] ?? '';

try {
	$sql = "SELECT t.id, t.name, t.status, t.type, t.date, t.date_mod,
	               e.completename as entity_name
	        FROM glpi_tickets t
	        JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.users_id = ? AND tu.type = 2
	        LEFT JOIN glpi_entities e ON e.id = t.entities_id
	        WHERE t.is_deleted = 0";
	$params = [$user_id];

	if ($startDate) {
		$sql .= " AND t.date >= ?";
		$params[] = $startDate . ' 00:00:00';
	}
	if ($endDate) {
		$sql .= " AND t.date <= ?";
		$params[] = $endDate . ' 23:59:59';
	}

	$sql .= " ORDER BY t.date_mod DESC LIMIT 200";

	$q = $pdo->prepare($sql);
	$q->execute($params);
	$tickets = $q->fetchAll();

	foreach ($tickets as $t) {
		$sn = (int)$t['status'];
		$item = [
			'id'        => (int)$t['id'],
			'titulo'    => $t['name'] ?? 'Sem título',
			'status'    => $status_map[$sn] ?? 'Desconhecido',
			'tipo'      => $type_map[(int)($t['type'] ?? 1)] ?? 'Incidente',
			'entidade'  => apelido_entidade($t['entity_name'] ?? ''),
			'data'      => substr($t['date'] ?? '', 0, 10),
			'atualizado'=> substr($t['date_mod'] ?? '', 0, 16),
		];

		if ($sn >= 5) {
			$chamados_concluidos[] = $item;
		} else {
			$chamados_abertos[] = $item;
		}
	}
} catch (Exception $e) {
	// Se falhar, retorna vazio
}

// ── 3. DESEMPENHO EM PROJETOS ────────────────────────────────────────────────
$desempenho = [];

// Função inline para parse de projeto (cópia mínima da de projetos.php, sem HTML)
function parseProjetoDetalhe(string $filepath): ?array {
	$content = @file_get_contents($filepath);
	if (!$content) return null;
	$lines   = explode("\n", str_replace("\r", '', $content));
	$proj    = ['titulo'=>'','objetivo'=>'','equipe'=>'','prazo'=>'','modulos'=>[],];
	$modo    = 'header';
	$moduloAtual = null;
	$inCodeBlock = false;
	foreach ($lines as $line) {
		$l = rtrim($line);
		if (preg_match('/^```/', $l)) { $inCodeBlock = !$inCodeBlock; continue; }
		if ($inCodeBlock) continue;
		if (preg_match('/^# (.+)$/u', $l, $m)) { $proj['titulo'] = trim($m[1]); $modo='header'; continue; }
		if ($modo === 'header' && preg_match('/^> \*\*(.+?):\*\*\s*(.+)/u', $l, $m)) {
			$k = mb_strtolower($m[1]);
			if (str_contains($k,'objetivo')) $proj['objetivo'] = $m[2];
			elseif (str_contains($k,'equipe')) $proj['equipe']  = $m[2];
			elseif (str_contains($k,'prazo'))  $proj['prazo']   = $m[2];
			continue;
		}
		if (preg_match('/^## (.+)$/u', $l, $m)) {
			if ($moduloAtual !== null) $proj['modulos'][] = $moduloAtual;
			$nome = trim($m[1]);
			if (preg_match('/cronograma|progresso/iu', $nome)) { $moduloAtual = null; continue; }
			$modo = 'modulo';
			$moduloAtual = ['nome'=>$nome,'descricao'=>'','prazo'=>'','tarefas'=>[]];
			continue;
		}
		if ($modo === 'modulo' && $moduloAtual !== null) {
			if (preg_match('/^- \[x\] (.+)/iu', $l, $m))
				$moduloAtual['tarefas'][] = ['done'=>true, 'texto'=>$m[1]];
			elseif (preg_match('/^- \[ \] (.+)/u', $l, $m))
				$moduloAtual['tarefas'][] = ['done'=>false, 'texto'=>$m[1]];
			elseif (preg_match('/^> \*\*Prazo:\*\*\s*(.+)/ui', $l, $m))
				$moduloAtual['prazo'] = trim($m[1]);
		}
	}
	if ($moduloAtual !== null) $proj['modulos'][] = $moduloAtual;
	$tot = $done = 0;
	foreach ($proj['modulos'] as &$mod) {
		$mt = count($mod['tarefas']);
		$md = count(array_filter($mod['tarefas'], fn($t) => $t['done']));
		$mod['pct'] = $mt > 0 ? round($md / $mt * 100) : 0;
		$mod['done'] = $md; $mod['tot'] = $mt;
		$tot += $mt; $done += $md;
	}
	unset($mod);
	$proj['pct']   = $tot > 0 ? round($done / $tot * 100) : 0;
	$proj['done']  = $done;
	$proj['total'] = $tot;
	return $proj['titulo'] ? $proj : null;
}

$pastaProj = __DIR__ . '/Docs/wiki/projects';
if (is_dir($pastaProj)) {
	foreach (glob($pastaProj . '/*.md') as $arq) {
		$p = parseProjetoDetalhe($arq);
		if (!$p) continue;

		// Verifica se o campo equipe contém o nome do técnico
		$equipe_projeto = mb_strtolower(trim($p['equipe'] ?? ''));
		if (!$equipe_projeto) continue;

		// Pega a última palavra do nome do técnico (primeiro nome)
		$nome_tec = $_GET['nome'] ?? '';
		$pnome = mb_strtolower(trim(explode(' ', $nome_tec)[0]));

		$tem_tecnico = false;
		if ($pnome && str_contains($equipe_projeto, $pnome)) {
			$tem_tecnico = true;
		}

		if (!$tem_tecnico) continue;

		// Calcula status
		$prazo = $p['prazo'] ? parsePrazoRapido($p['prazo']) : 0;
		$hoje  = time();
		$pct   = $p['pct'];

		if ($pct >= 100) {
			$status = 'concluido';
		} elseif ($prazo && $prazo < $hoje) {
			$status = 'atrasado';
		} elseif ($prazo && $prazo < $hoje + 7*86400) {
			$status = 'atencao';
		} else {
			$status = 'no_prazo';
		}

		// Módulos com atraso
		$mod_atraso = [];
		foreach ($p['modulos'] as $mod) {
			if ($mod['pct'] >= 100) continue;
			if (!empty($mod['prazo'])) {
				$mp = parseDataBRDetalhe($mod['prazo']);
				if ($mp && $mp < $hoje) {
					$mod_atraso[] = [
						'nome' => $mod['nome'],
						'pct'  => $mod['pct'],
					];
				}
			}
		}

		$desempenho[] = [
			'titulo'     => $p['titulo'],
			'pct'        => $pct,
			'done'       => $p['done'],
			'total'      => $p['total'],
			'modulos'    => count($p['modulos']),
			'status'     => $status,
			'prazo'      => $prazo ? date('d/m/Y', $prazo) : '',
			'mod_atraso' => $mod_atraso,
		];
	}
}

// Ordena: projetos com menor % primeiro (mais críticos)
usort($desempenho, fn($a, $b) => $a['pct'] <=> $b['pct']);

// Funções auxiliares inline para parse de data
function parsePrazoRapido(string $prazo): int {
	if (preg_match('/\d{1,2}\/\d{1,2}\/\d{4}.*\d{1,2}\/\d{1,2}\/\d{4}/', $prazo, $m)) {
		if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})\D*$/', $prazo, $m2))
			return mktime(0,0,0,(int)$m2[2],(int)$m2[1],(int)$m2[3]);
	}
	if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $prazo, $m))
		return mktime(0,0,0,(int)$m[2],(int)$m[1],(int)$m[3]);
	return 0;
}
function parseDataBRDetalhe(string $d): int {
	if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($d), $m))
		return mktime(0,0,0,(int)$m[2],(int)$m[1],(int)$m[3]);
	return 0;
}

echo json_encode([
	'ok'       => true,
	'rotinas'  => $rotinas,
	'chamados' => [
		'abertos'    => $chamados_abertos,
		'total_abertos'   => count($chamados_abertos),
		'concluidos' => $chamados_concluidos,
		'total_concluidos' => count($chamados_concluidos),
	],
	'desempenho' => $desempenho,
]);
