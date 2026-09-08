<?php
// wpp/worker.php — worker de notificações do WhatsApp (Fase 2).
//
// Roda UMA passada e sai. O loop fica no container:
//   while true; do php .../wpp/worker.php; sleep 30; done
//
// Sem HTML, sem session_start. A única "saída" é wpp_log() (tabela portal_wpp_log)
// e, se necessário, fwrite(STDERR, ...).
//
// ANTI-BACKFILL: na primeira subida (ou depois de muito tempo offline) o worker
// SEMEIA UMA BASELINE — marca tudo que já existe como "já notificado" SEM ENVIAR
// nada — e só a partir da próxima passada os gatilhos disparam mensagens. Assim
// subir o container nunca despeja um histórico inteiro nos grupos/DMs.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/evo_api.php';
require_once __DIR__ . '/../alertas_lib.php';
require_once __DIR__ . '/gatilhos.php';

// --- Constantes de operação (lidas de portal_wpp_config, com default) ---
// Definidas como variáveis locais de propósito (não define()) pra facilitar
// teste e permitir recarregar a cada passada.
$offlineResetMin  = (int) wpp_cfg_get('cfg_offline_reset_min', '30');
$delayDmMin       = (int) wpp_cfg_get('cfg_delay_dm_min', '5');
$digestAlertasMin = (int) wpp_cfg_get('cfg_digest_alertas_min', '15');
$slaHoras         = (int) wpp_cfg_get('cfg_sla_horas', '4');
$slaPrevencMin    = (int) wpp_cfg_get('cfg_sla_prevenc_min', '30');
// Toggles on_novo / on_atribuido / on_alertas / on_sla (default '1') são
// checados DENTRO de cada gatilho (Task 6-9), não aqui.

/**
 * Executa uma passada completa do worker.
 */
function wpp_worker_passada(): void
{
    global $pdo, $offlineResetMin;

    // 1. A instância está conectada?
    $st = evo_status();
    if (($st['estado'] ?? '') !== 'open') {
        wpp_log('sys', '', 'instancia ' . ($st['estado'] ?? '?'), 'skip');
        return;
    }

    // 2. Reset por offline longo: se ficou mais de cfg_offline_reset_min minutos
    //    sem rodar com sucesso, re-semeia a baseline (pra não despejar tudo que
    //    aconteceu enquanto o worker esteve fora do ar).
    $lastOk = wpp_cfg_get('wpp_last_ok');
    if ($lastOk && (time() - strtotime($lastOk)) > $offlineResetMin * 60) {
        wpp_cfg_set('wpp_baseline_ok', '');
        wpp_log('sys', '', 'offline > ' . $offlineResetMin . 'min, re-semeando baseline', 'reset');
    }

    // 3. Baseline (primeira vez OU após reset). Esta passada NÃO roda gatilhos.
    //    Re-semeia também se portal_wpp_notificados está VAZIA (spec §Cold-start
    //    item 2): sem a baseline os gatilhos sem watermark tratam tudo como novo.
    $notifVazia = (int) $pdo->query("SELECT COUNT(*) FROM portal_wpp_notificados")->fetchColumn() === 0;
    if (wpp_cfg_get('wpp_baseline_ok') !== '1' || $notifVazia) {
        wpp_semear_baseline($pdo);
        wpp_cfg_set('wpp_baseline_ok', '1');
        wpp_cfg_set('wpp_last_ok', wpp_agora_db($pdo));
        return;
    }

    // 4. Gatilhos — cada um isolado num try/catch que loga e segue.
    foreach (['gat_novo', 'gat_atribuido', 'gat_alertas', 'gat_sla'] as $g) {
        try {
            $g($pdo);
        } catch (\Throwable $e) {
            wpp_log('sys', '', $g . ': ' . $e->getMessage(), 'erro');
        }
    }

    // 5. Retenção: poda portal_wpp_log com mais de 30 dias (uma DM presa em
    //    'pendente' gera ~2880 linhas/dia; nada mais varre essa tabela).
    try {
        $pdo->exec("DELETE FROM portal_wpp_log WHERE criado_em < NOW() - INTERVAL 30 DAY");
    } catch (\Throwable $e) {
        // silencioso: falha de limpeza não pode derrubar a passada
    }

    // 6. Marca a passada como bem-sucedida (relógio do banco).
    wpp_cfg_set('wpp_last_ok', wpp_agora_db($pdo));
}

/**
 * Semeia a baseline: marca todo o estado atual como "já notificado" SEM ENVIAR
 * nada, e grava o snapshot de alertas + o watermark de chamados novos.
 */
function wpp_semear_baseline(PDO $pdo): void
{
    // Chamados abertos (status Novo/Em atendimento(atribuído)/(planejado)/Pendente).
    $abertos = $pdo->query(
        "SELECT id FROM glpi_tickets WHERE is_deleted = 0 AND status IN (1,2,3,4)"
    )->fetchAll(PDO::FETCH_COLUMN);

    // Data pelo relógio do BANCO (glpi-db em -04:00). O PHP do container também
    // roda em America/Campo_Grande (docker/php-custom.ini, mesma imagem do
    // portal-wpp-worker via build: .), então date()/strtotime() e o NOW() do
    // banco já batem sem shift. Ainda assim a chave é derivada do relógio do
    // banco pra casar EXATAMENTE com a que o gat_sla (Task 9) calcula.
    // "parado:<data-do-banco>" é o formato canônico.
    $hoje = substr(wpp_agora_db($pdo), 0, 10);
    foreach ($abertos as $id) {
        $id = (string) $id;
        wpp_marcar_notificado('novo', $id);
        // SLA: as duas chaves que o gat_sla (Task 9) usa — "parado" do dia e prevenção.
        wpp_marcar_notificado('sla', $id, 'parado:' . $hoje);
        wpp_marcar_notificado('sla', $id, 'prevenc');
    }

    // Atribuições atuais (type=2 = técnico atribuído) de chamados abertos.
    $atribs = $pdo->query(
        "SELECT tu.tickets_id, tu.users_id
         FROM glpi_tickets_users tu
         JOIN glpi_tickets t ON t.id = tu.tickets_id
              AND t.is_deleted = 0 AND t.status IN (1,2,3,4)
         WHERE tu.type = 2"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($atribs as $a) {
        wpp_marcar_notificado('atribuido', $a['tickets_id'] . ':' . $a['users_id']);
    }

    // Snapshot dos alertas do parque (o gat_alertas compara com este estado).
    wpp_cfg_set('wpp_snap_alertas', json_encode(alertas_snapshot($pdo)));

    // Watermark de "chamado novo": só chamados abertos DEPOIS deste instante
    // disparam gat_novo.
    wpp_cfg_set('wm_novo', wpp_agora_db($pdo));

    $n = count($abertos);
    wpp_log('sys', '', 'baseline semeada: ' . $n . ' chamados', 'baseline');
}

// --- Require-safe: só roda uma passada se worker.php for o script invocado. ---
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    wpp_worker_passada();
}
