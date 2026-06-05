<?php
/**
 * API de dados para o Painel de Relatórios (BI)
 * SQL direto via PDO — ignora REST API (índice de busca desatualizado do GLPI)
 *
 * GET ?dt_ini=YYYY-MM-DD&dt_fim=YYYY-MM-DD&entidade_id=N
 */
session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { echo json_encode([]); exit; }

header('Content-Type: application/json');
require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/entidade_alias.php';

$dt_ini       = $_GET['dt_ini'] ?? date('Y-m-01');
$dt_fim       = $_GET['dt_fim'] ?? date('Y-m-d');
$entidade_id  = (int)($_GET['entidade_id'] ?? 0);
$dt_ini_full  = $dt_ini . ' 00:00:00';
$dt_fim_full  = $dt_fim . ' 23:59:59';

$result = [];

try {
    // ── Helper: monta cláusula WHERE de entidade ──────────────────
    // Quando sem filtro, exclui entidade raiz (entities_id=0 = rotinas internas)
    $sql_ent     = $entidade_id ? ' AND t.entities_id = :entidade_id' : ' AND t.entities_id != 0';
    $params_ent  = $entidade_id ? [':entidade_id' => $entidade_id] : [];

    $bind_periodo = function(array $extra = []) use ($dt_ini_full, $dt_fim_full, $params_ent) {
        return array_merge([':ini' => $dt_ini_full, ':fim' => $dt_fim_full], $params_ent, $extra);
    };

    // ═════════════════════════════════════════════════════════════
    // 1. KPIs
    // ═════════════════════════════════════════════════════════════

    // Total abertos no período
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.date BETWEEN :ini AND :fim $sql_ent");
    $stmt->execute($bind_periodo());
    $total_abertos = (int)$stmt->fetchColumn();

    // Total fechados no período
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.status IN (5,6)
          AND t.closedate BETWEEN :ini AND :fim $sql_ent");
    $stmt->execute($bind_periodo());
    $total_fechados = (int)$stmt->fetchColumn();

    // Em andamento AGORA (status 2), independente do período
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.status = 2 $sql_ent");
    $stmt->execute($params_ent);
    $em_andamento = (int)$stmt->fetchColumn();

    // Tempo médio de fechamento (horas)
    $stmt = $pdo->prepare("SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR, t.date, t.closedate)), 0)
        FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.status IN (5,6)
          AND t.closedate IS NOT NULL AND t.closedate BETWEEN :ini AND :fim $sql_ent");
    $stmt->execute($bind_periodo());
    $tempo_medio = round((float)$stmt->fetchColumn(), 1);

    $result['kpis'] = [
        'total_abertos'  => $total_abertos,
        'total_fechados' => $total_fechados,
        'em_andamento'   => $em_andamento,
        'tempo_medio'    => $tempo_medio,
    ];

    // ═════════════════════════════════════════════════════════════
    // 2. Fechados por Atendente (produtividade real)
    // ═════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare("
        SELECT tu.users_id,
               CONCAT(COALESCE(u.realname,''),' ',COALESCE(u.firstname,'')) as nome,
               COUNT(DISTINCT t.id) as total
        FROM glpi_tickets t
        JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.type = 2
        LEFT JOIN glpi_users u ON u.id = tu.users_id
        WHERE t.is_deleted = 0 AND t.status IN (5,6)
          AND t.closedate BETWEEN :ini AND :fim $sql_ent
          AND u.is_active = 1
        GROUP BY tu.users_id
        ORDER BY total DESC
    ");
    $stmt->execute($bind_periodo());
    $result['por_atendente'] = array_map(function($r) {
        return [
            'nome'  => primeiro_nome($r['nome'] ?: 'Sem atendente'),
            'total' => (int)$r['total'],
        ];
    }, $stmt->fetchAll());

    // ═════════════════════════════════════════════════════════════
    // 3. Em andamento por Atendente (carga atual)
    // ═════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare("
        SELECT tu.users_id,
               CONCAT(COALESCE(u.realname,''),' ',COALESCE(u.firstname,'')) as nome,
               COUNT(DISTINCT t.id) as total
        FROM glpi_tickets t
        JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.type = 2
        LEFT JOIN glpi_users u ON u.id = tu.users_id
        WHERE t.is_deleted = 0 AND t.status = 2 $sql_ent
          AND u.is_active = 1
        GROUP BY tu.users_id
        ORDER BY total DESC
    ");
    $stmt->execute($params_ent);
    $result['em_andamento_por_atendente'] = array_map(function($r) {
        return [
            'nome'  => primeiro_nome($r['nome'] ?: 'Sem atendente'),
            'total' => (int)$r['total'],
        ];
    }, $stmt->fetchAll());

    // ═════════════════════════════════════════════════════════════
    // 4. Por Entidade
    // ═════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare("
        SELECT e.completename, COUNT(*) as total
        FROM glpi_tickets t
        LEFT JOIN glpi_entities e ON e.id = t.entities_id
        WHERE t.is_deleted = 0 AND t.date BETWEEN :ini AND :fim $sql_ent
        GROUP BY t.entities_id
        ORDER BY total DESC
    ");
    $stmt->execute($bind_periodo());
    $result['por_entidade'] = array_map(function($r) {
        return [
            'nome'  => apelido_entidade($r['completename'] ?? 'Raiz'),
            'total' => (int)$r['total'],
        ];
    }, $stmt->fetchAll());

    // ═════════════════════════════════════════════════════════════
    // 5. Por Categoria
    // ═════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare("
        SELECT c.completename, COUNT(*) as total
        FROM glpi_tickets t
        LEFT JOIN glpi_itilcategories c ON c.id = t.itilcategories_id
        WHERE t.is_deleted = 0 AND t.date BETWEEN :ini AND :fim $sql_ent
        GROUP BY t.itilcategories_id
        ORDER BY total DESC
    ");
    $stmt->execute($bind_periodo());
    $result['por_categoria'] = array_map(function($r) {
        return [
            'nome'  => $r['completename'] ?? '(Sem categoria)',
            'total' => (int)$r['total'],
        ];
    }, $stmt->fetchAll());

    // ═════════════════════════════════════════════════════════════
    // 6. Por Hora do Dia
    // ═════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare("
        SELECT HOUR(t.date) as hora, COUNT(*) as total
        FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.date BETWEEN :ini AND :fim $sql_ent
        GROUP BY HOUR(t.date)
        ORDER BY hora
    ");
    $stmt->execute($bind_periodo());
    $por_hora = array_fill(0, 24, 0);
    foreach ($stmt->fetchAll() as $r) {
        $por_hora[(int)$r['hora']] = (int)$r['total'];
    }
    $result['por_hora'] = $por_hora;

    // ═════════════════════════════════════════════════════════════
    // 7. Por Dia da Semana (0=Dom … 6=Sáb)
    // ═════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare("
        SELECT DAYOFWEEK(t.date) as dia, COUNT(*) as total
        FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.date BETWEEN :ini AND :fim $sql_ent
        GROUP BY DAYOFWEEK(t.date)
        ORDER BY dia
    ");
    $stmt->execute($bind_periodo());
    $por_dia = [0,0,0,0,0,0,0];
    $map_dow = [1=>0, 2=>1, 3=>2, 4=>3, 5=>4, 6=>5, 7=>6]; // DAYOFWEEK → index
    foreach ($stmt->fetchAll() as $r) {
        $idx = $map_dow[(int)$r['dia']] ?? 0;
        $por_dia[$idx] = (int)$r['total'];
    }
    $result['por_dia'] = $por_dia;

    // ═════════════════════════════════════════════════════════════
    // 7b. Heatmap: Hora × Dia da Semana
    // ═════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare("
        SELECT HOUR(t.date) as hora, DAYOFWEEK(t.date) as dia, COUNT(*) as total
        FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.date BETWEEN :ini AND :fim $sql_ent
        GROUP BY HOUR(t.date), DAYOFWEEK(t.date)
        ORDER BY hora, dia
    ");
    $stmt->execute($bind_periodo());
    $heatmap = [];
    $map_dow_rev = [0=>'Dom', 1=>'Seg', 2=>'Ter', 3=>'Qua', 4=>'Qui', 5=>'Sex', 6=>'Sáb'];
    foreach ($stmt->fetchAll() as $r) {
        $h = (int)$r['hora'];
        $d = $map_dow[(int)$r['dia']] ?? 0;
        $heatmap[] = ['hora' => $h, 'dia' => $d, 'label' => $map_dow_rev[$d], 'total' => (int)$r['total']];
    }
    $result['heatmap'] = $heatmap;

    // ═════════════════════════════════════════════════════════════
    // 8. Evolução Mensal (últimos 12 meses, excluindo rotinas)
    // ═════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(t.date, '%Y-%m') as mes, COUNT(*) as total
        FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.entities_id != 0
          AND t.date >= DATE_SUB(:fim, INTERVAL 12 MONTH)
        GROUP BY mes
        ORDER BY mes ASC
    ");
    $stmt->execute([':fim' => $dt_fim_full]);
    $result['evolucao_mensal'] = array_map(function($r) {
        return ['mes' => $r['mes'], 'total' => (int)$r['total']];
    }, $stmt->fetchAll());

    // Fechados por mês (últimos 12 meses)
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(t.closedate, '%Y-%m') as mes, COUNT(*) as total
        FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.status IN (5,6) AND t.entities_id != 0
          AND t.closedate >= DATE_SUB(:fim, INTERVAL 12 MONTH)
        GROUP BY mes
        ORDER BY mes ASC
    ");
    $stmt->execute([':fim' => $dt_fim_full]);
    $result['evolucao_fechados'] = array_map(function($r) {
        return ['mes' => $r['mes'], 'total' => (int)$r['total']];
    }, $stmt->fetchAll());

    // ═════════════════════════════════════════════════════════════
    // 9. SLA — tickets abertos com tempo decorrido
    // ═════════════════════════════════════════════════════════════
    $sla_thresh       = [1 => 24, 2 => 12, 3 => 8, 4 => 4, 5 => 2];
    $sla_status_label = [1=>'Novo', 2=>'Em atendimento', 3=>'Planejado'];
    $sla_urg_label    = [1=>'Muito baixa', 2=>'Baixa', 3=>'Média', 4=>'Alta', 5=>'Muito alta'];
    $agora_ts         = time();

    $stmt = $pdo->query("
        SELECT t.id, t.name, t.status, t.date, t.urgency, t.entities_id,
               e.completename as entity_name
        FROM glpi_tickets t
        LEFT JOIN glpi_entities e ON e.id = t.entities_id
        WHERE t.is_deleted = 0 AND t.status IN (1,2,3)
          AND t.entities_id != 0
        ORDER BY t.date ASC
    ");
    $sla_dados = [];
    $sla_tec_cache = [];
    foreach ($stmt->fetchAll() as $t) {
        $abertura = strtotime($t['date']);
        if (!$abertura) continue;
        $horas  = round(($agora_ts - $abertura) / 3600, 1);
        $urg    = max(1, min(5, (int)($t['urgency'] ?? 3)));
        $thresh = $sla_thresh[$urg];
        $cor    = $horas <= $thresh * 0.5 ? 'verde' : ($horas <= $thresh ? 'amarelo' : 'vermelho');

        // Busca atendente (com cache simples)
        $tid = (int)$t['id'];
        if (!array_key_exists($tid, $sla_tec_cache)) {
            $st2 = $pdo->prepare("
                SELECT CONCAT(COALESCE(u.realname,''),' ',COALESCE(u.firstname,'')) as nome
                FROM glpi_tickets_users tu
                LEFT JOIN glpi_users u ON u.id = tu.users_id
                WHERE tu.tickets_id = ? AND tu.type = 2 AND u.is_active = 1 LIMIT 1
            ");
            $st2->execute([$tid]);
            $row2 = $st2->fetch();
            $sla_tec_cache[$tid] = $row2 ? primeiro_nome($row2['nome']) : '—';
        }

        $sla_dados[] = [
            'id'        => $tid,
            'titulo'    => $t['name'] ?? '(sem título)',
            'status'    => $sla_status_label[(int)$t['status']] ?? (int)$t['status'],
            'urgencia'  => $sla_urg_label[$urg],
            'urg_n'     => $urg,
            'entidade'  => apelido_entidade($t['entity_name'] ?? ''),
            'abertura'  => substr($t['date'], 0, 16),
            'horas'     => $horas,
            'thresh'    => $thresh,
            'cor'       => $cor,
            'atendente' => $sla_tec_cache[$tid],
        ];
    }
    usort($sla_dados, fn($a,$b) =>
        ['vermelho'=>0,'amarelo'=>1,'verde'=>2][$a['cor']] <=>
        ['vermelho'=>0,'amarelo'=>1,'verde'=>2][$b['cor']] ?: $b['horas'] <=> $a['horas']
    );

    $result['sla'] = [
        'verde'    => count(array_filter($sla_dados, fn($x) => $x['cor'] === 'verde')),
        'amarelo'  => count(array_filter($sla_dados, fn($x) => $x['cor'] === 'amarelo')),
        'vermelho' => count(array_filter($sla_dados, fn($x) => $x['cor'] === 'vermelho')),
        'dados'    => array_slice($sla_dados, 0, 100),
    ];

    // ═════════════════════════════════════════════════════════════
    // 10. Rotinas (entidade raiz — tickets de rotina)
    // ═════════════════════════════════════════════════════════════
    $sql_rot = ' AND t.entities_id = 0';

    // KPIs
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.date BETWEEN :ini AND :fim $sql_rot");
    $stmt->execute([':ini' => $dt_ini_full, ':fim' => $dt_fim_full]);
    $rot_total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.status IN (5,6)
          AND t.closedate BETWEEN :ini AND :fim $sql_rot");
    $stmt->execute([':ini' => $dt_ini_full, ':fim' => $dt_fim_full]);
    $rot_fechados = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.status = 2 $sql_rot");
    $stmt->execute();
    $rot_andamento = (int)$stmt->fetchColumn();

    // SLA: concluídas dentro de 24h (prazo para rotinas diárias)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.status IN (5,6)
          AND t.closedate BETWEEN :ini AND :fim $sql_rot
          AND TIMESTAMPDIFF(HOUR, t.date, t.closedate) <= 24");
    $stmt->execute([':ini' => $dt_ini_full, ':fim' => $dt_fim_full]);
    $rot_no_prazo  = (int)$stmt->fetchColumn();
    $rot_pct_prazo = $rot_fechados > 0 ? round($rot_no_prazo / $rot_fechados * 100) : 0;

    $stmt = $pdo->prepare("SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR, t.date, t.closedate)), 0)
        FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.status IN (5,6)
          AND t.closedate IS NOT NULL AND t.closedate BETWEEN :ini AND :fim $sql_rot");
    $stmt->execute([':ini' => $dt_ini_full, ':fim' => $dt_fim_full]);
    $rot_tempo_medio = round((float)$stmt->fetchColumn(), 1);

    // Por nome (tipo de rotina)
    $stmt = $pdo->prepare("
        SELECT t.name, COUNT(*) as total
        FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.date BETWEEN :ini AND :fim $sql_rot
        GROUP BY t.name
        ORDER BY total DESC
        LIMIT 15
    ");
    $stmt->execute([':ini' => $dt_ini_full, ':fim' => $dt_fim_full]);
    $rot_por_nome = array_map(fn($r) => [
        'nome'  => $r['name'] ?? '(sem nome)',
        'total' => (int)$r['total'],
    ], $stmt->fetchAll());

    // Por atendente
    $stmt = $pdo->prepare("
        SELECT tu.users_id,
               CONCAT(COALESCE(u.realname,''),' ',COALESCE(u.firstname,'')) as nome,
               COUNT(DISTINCT t.id) as total
        FROM glpi_tickets t
        JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.type = 2
        LEFT JOIN glpi_users u ON u.id = tu.users_id
        WHERE t.is_deleted = 0 AND t.status IN (5,6)
          AND t.closedate BETWEEN :ini AND :fim $sql_rot
          AND u.is_active = 1
        GROUP BY tu.users_id
        ORDER BY total DESC
    ");
    $stmt->execute([':ini' => $dt_ini_full, ':fim' => $dt_fim_full]);
    $rot_por_atendente = array_map(fn($r) => [
        'nome'  => primeiro_nome($r['nome'] ?: 'Sem atendente'),
        'total' => (int)$r['total'],
    ], $stmt->fetchAll());

    // Evolução mensal
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(t.date, '%Y-%m') as mes, COUNT(*) as total
        FROM glpi_tickets t
        WHERE t.is_deleted = 0 AND t.entities_id = 0
        GROUP BY mes ORDER BY mes ASC
    ");
    $rot_evolucao = array_map(fn($r) => [
        'mes'   => $r['mes'],
        'total' => (int)$r['total'],
    ], $stmt->fetchAll());

    $result['rotinas'] = [
        'kpis' => [
            'total'       => $rot_total,
            'fechados'    => $rot_fechados,
            'andamento'   => $rot_andamento,
            'no_prazo'    => $rot_no_prazo,
            'pct_prazo'   => $rot_pct_prazo,
            'tempo_medio' => $rot_tempo_medio,
        ],
        'por_nome'      => $rot_por_nome,
        'por_atendente' => $rot_por_atendente,
        'evolucao'      => $rot_evolucao,
    ];

    // ═════════════════════════════════════════════════════════════
    // 11. Lista de entidades para o filtro
    // ═════════════════════════════════════════════════════════════
    $stmt = $pdo->query("SELECT id, completename FROM glpi_entities WHERE id > 0 ORDER BY completename");
    $result['entidades'] = array_map(function($r) {
        return [
            'id'   => (int)$r['id'],
            'nome' => apelido_entidade($r['completename']),
        ];
    }, $stmt->fetchAll());

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
