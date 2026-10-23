<?php
/**
 * API de dados — BI "Balanças" (Fase 3 da feature chamados de Balanças).
 *
 * Vínculo chamado ↔ balança vem do campo "Equipamento (Balança)" do
 * container 7 do plugin Fields (glpi_plugin_fields_ticketreparoequipamentos),
 * NÃO de glpi_items_tickets — balança não é itemtype nativo do GLPI (mesmo
 * achado técnico da Fase 2). O id do catálogo "Equipamento" é sempre igual
 * ao id de portal_balancas (explicit-id mirroring, ver balancas_fields_sync.php),
 * o que permite o JOIN direto abaixo.
 *
 * GET ?dt_ini=YYYY-MM-DD&dt_fim=YYYY-MM-DD&entidade_id=N
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { echo json_encode(['ok' => false]); exit; }

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/agenda/db.php';

$dt_ini      = ($_GET['dt_ini'] ?? date('Y-m-01')) . ' 00:00:00';
$dt_fim      = ($_GET['dt_fim'] ?? date('Y-m-d'))  . ' 23:59:59';
$entidade_id = (int)($_GET['entidade_id'] ?? 0);

$entCond = $entidade_id ? " AND t.entities_id = :ent " : "";
$bind = [':ini' => $dt_ini, ':fim' => $dt_fim];
if ($entidade_id) $bind[':ent'] = $entidade_id;

try {
    // Catálogo de defeitos (id => nome)
    $DEFEITOS = [];
    $r = $pdo->query("SELECT id, name FROM glpi_plugin_fields_tipodedefeitofielddropdowns");
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) { $DEFEITOS[(int)$row['id']] = $row['name']; }

    // Chamados de Balanças no período, com a balança já resolvida via explicit-id mirroring
    $sql = "SELECT t.id, t.name, t.date, t.status,
                   r.plugin_fields_equipamentobalanafielddropdowns_id AS balanca_id,
                   r.plugin_fields_tipodedefeitofielddropdowns_id     AS defeitos_raw,
                   b.identificacao, b.loja, b.departamento
            FROM glpi_plugin_fields_ticketreparoequipamentos r
            JOIN glpi_tickets t ON t.id = r.items_id AND r.itemtype = 'Ticket'
                 AND t.is_deleted = 0 AND t.date BETWEEN :ini AND :fim
            LEFT JOIN portal_balancas b
                 ON b.id = r.plugin_fields_equipamentobalanafielddropdowns_id
            WHERE r.plugin_fields_equipamentobalanafielddropdowns_id > 0 $entCond";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);

    $porDefeito = [];   // nome => contagem
    $porBalanca = [];   // "id" => {balanca, loja, departamento, chamados}
    $chamados   = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ids_defeito = json_decode($row['defeitos_raw'] ?? '', true);
        if (!is_array($ids_defeito)) { $ids_defeito = $row['defeitos_raw'] ? [$row['defeitos_raw']] : []; }
        $nomes_defeito = array_values(array_filter(array_map(
            fn($id) => $DEFEITOS[(int)$id] ?? null,
            $ids_defeito
        )));

        foreach ($nomes_defeito as $nome) {
            $porDefeito[$nome] = ($porDefeito[$nome] ?? 0) + 1;
        }

        $balId = (int)$row['balanca_id'];
        if (!isset($porBalanca[$balId])) {
            $porBalanca[$balId] = [
                'balanca_id'   => $balId,
                'identificacao'=> $row['identificacao'] ?? ('#' . $balId),
                'loja'         => $row['loja'] ?: '—',
                'departamento' => $row['departamento'] ?: '—',
                'chamados'     => 0,
            ];
        }
        $porBalanca[$balId]['chamados']++;

        $chamados[] = [
            'id'       => (int)$row['id'],
            'titulo'   => $row['name'],
            'data'     => substr((string)$row['date'], 0, 10),
            'status'   => (int)$row['status'],
            'balanca'  => $row['identificacao'] ?? ('#' . $balId),
            'loja'     => $row['loja'] ?: '—',
            'defeitos' => $nomes_defeito,
        ];
    }

    $listaDefeitos = [];
    foreach ($porDefeito as $nome => $qtd) { $listaDefeitos[] = ['defeito' => $nome, 'qtd' => $qtd]; }
    usort($listaDefeitos, fn($a, $b) => $b['qtd'] - $a['qtd']);

    $listaBalancas = array_values($porBalanca);
    usort($listaBalancas, fn($a, $b) => $b['chamados'] - $a['chamados']);

    echo json_encode([
        'ok'              => true,
        'por_defeito'     => $listaDefeitos,
        'por_balanca'     => $listaBalancas,
        'chamados'        => $chamados,
        'kpis'            => [
            'total_chamados' => count($chamados),
            'balancas_afetadas' => count($listaBalancas),
            'top_balanca'    => $listaBalancas[0]['identificacao'] ?? '—',
            'top_balanca_qtd'=> $listaBalancas[0]['chamados'] ?? 0,
            'top_defeito'    => $listaDefeitos[0]['defeito'] ?? '—',
            'top_defeito_qtd'=> $listaDefeitos[0]['qtd'] ?? 0,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    error_log('[relatorios_balancas] falhou: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
