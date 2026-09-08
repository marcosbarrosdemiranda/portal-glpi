<?php
// Gatilhos de notificação do worker WhatsApp (Fase 2).
//
// Cada função roda uma "passada" de um gatilho: detecta o que é novo desde a
// última execução (via watermark / portal_wpp_notificados) e envia. O toggle
// on_* de portal_wpp_config é CHECADO DENTRO de cada gatilho.
//
//   gat_novo      -> Task 6  (chamado novo aberto)          [IMPLEMENTADO]
//   gat_atribuido -> Task 7  (chamado atribuído a um técnico -> DM)
//   gat_alertas   -> Task 8  (digest de alertas do parque)
//   gat_sla       -> Task 9  (chamado parado / prevenção de estouro de SLA)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/evo_api.php';
require_once __DIR__ . '/../alertas_lib.php';
require_once __DIR__ . '/../entidade_alias.php';   // apelido_entidade() — alertas_lib não puxa

/**
 * Task 6 — chamado novo aberto no GLPI -> mensagem no grupo "Chamados".
 *
 * Watermark: portal_wpp_config.wm_novo guarda o date_creation do último chamado
 * já processado. A cada passada busca chamados com date_creation > wm_novo,
 * envia os que ainda não estão em portal_wpp_notificados('novo', <id>) e só
 * avança o watermark até o último efetivamente processado nesta passada.
 *
 * Envio bloqueado/falho NÃO marca o chamado nem trava o watermark além dele:
 * na próxima passada o mesmo chamado volta a ser tentado.
 */
function gat_novo(PDO $pdo): void
{
    if (wpp_cfg_get('on_novo', '1') !== '1') return;

    // Fase 2 sem grupo configurado: não há destino. Loga uma única vez
    // (dedup via portal_wpp_notificados) e sai sem tocar no watermark — quando
    // o grupo for cadastrado, só chamados a partir daí disparam (sem backfill).
    $grupo = (string) wpp_cfg_get('grupo_chamados_jid', '');
    if ($grupo === '') {
        if (!wpp_ja_notificado('sys', 'grupo_chamados_jid_ausente')) {
            wpp_log('sys', '', 'grupo_chamados_jid nao configurado', 'skip');
            wpp_marcar_notificado('sys', 'grupo_chamados_jid_ausente');
        }
        return;
    }

    // Primeira execução sem baseline: só marca o watermark e sai (anti-backfill).
    $wm = wpp_cfg_get('wm_novo');
    if (!$wm) {
        wpp_cfg_set('wm_novo', wpp_agora_db($pdo));
        return;
    }

    $st = $pdo->prepare("
        SELECT t.id, t.name, t.date_creation, t.type, e.completename AS loja
        FROM glpi_tickets t
        LEFT JOIN glpi_entities e ON e.id = t.entities_id
        WHERE t.is_deleted = 0 AND t.date_creation > ?
        ORDER BY t.date_creation ASC
        LIMIT 30
    ");
    $st->execute([$wm]);

    // Comparação de watermark é string pura ('YYYY-MM-DD HH:MM:SS' ordena igual
    // cronologicamente); nada de strtotime aqui.
    $maxData = $wm;
    while ($t = $st->fetch(PDO::FETCH_ASSOC)) {
        if ($t['date_creation'] > $maxData) $maxData = $t['date_creation'];
        if (wpp_ja_notificado('novo', (string) $t['id'])) continue;

        $r = evo_send_text($grupo, gat_msg_novo($t));
        if (!empty($r['ok'])) {
            wpp_marcar_notificado('novo', (string) $t['id']);
        }
        // envio falhou/bloqueou: NÃO marca — tenta de novo na próxima passada.
    }

    wpp_cfg_set('wm_novo', $maxData);   // só avança até o último processado
}

/**
 * Monta o texto da notificação de chamado novo.
 * Ex: "🆕 *Chamado #7* — Lj 003\nPC não liga\n_Incidente · aberto 07/09 14:30_"
 */
function gat_msg_novo(array $t): string
{
    $loja = function_exists('apelido_entidade')
        ? apelido_entidade($t['loja'] ?? '')
        : ($t['loja'] ?? '');
    $data = !empty($t['date_creation']) ? date('d/m H:i', strtotime($t['date_creation'])) : '';
    $tipo = ((int) ($t['type'] ?? 1)) === 2 ? 'Requisição' : 'Incidente';

    return "🆕 *Chamado #{$t['id']}* — " . ($loja ?: 'sem loja') . "\n"
         . ($t['name'] ?? '(sem título)') . "\n"
         . "_{$tipo} · aberto {$data}_";
}

function gat_atribuido(PDO $pdo): void {}  // Task 7

function gat_alertas(PDO $pdo): void {}    // Task 8

function gat_sla(PDO $pdo): void {}        // Task 9
