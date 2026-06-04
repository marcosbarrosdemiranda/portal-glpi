<?php
/**
 * Verifica eventos/chamados atrasados (não concluídos com data passada)
 * - Chamados com ticket_id: remove da agenda e reseta no GLPI (volta para sidebar)
 * - Outros (evento, reuniao, requisicao): apenas retorna a lista para aviso visual
 *
 * Chamado automaticamente ao carregar a agenda
 */
header('Content-Type: application/json');
ob_start();
error_reporting(0);
require_once 'config.php';
require_once 'db.php';

$agora = date('Y-m-d H:i:s');

// Regra:
// - Se hoje é DOMINGO: remove todos os chamados não concluídos com end < agora
//   (encerramento semanal — tudo que não foi concluído até domingo volta para sidebar)
// - Outros dias: remove apenas chamados de semanas anteriores (end < último domingo 00:00)
//   (limpeza de chamados muito antigos que ficaram esquecidos)

$hoje      = new DateTime();
$dia_semana = (int)$hoje->format('N'); // 1=Seg ... 7=Dom

if ($dia_semana === 7) {
    // É domingo → corte = agora (todos não concluídos até este momento)
    $corte = $agora;
} else {
    // Outro dia → corte = último domingo às 00:00
    $ultimo_domingo = clone $hoje;
    $ultimo_domingo->modify('-' . $dia_semana . ' days'); // volta para o domingo anterior
    $ultimo_domingo->setTime(0, 0, 0);
    $corte = $ultimo_domingo->format('Y-m-d H:i:s');
}

$stmt = $pdo->prepare("
    SELECT * FROM glpi_plugin_agenda_events
    WHERE concluido = 0 AND ticket_id IS NOT NULL AND end < ?
    ORDER BY end ASC
");
$stmt->execute([$corte]);
$chamados_semana_passada = $stmt->fetchAll();

// Eventos/reuniões/requisições atrasados: só para aviso visual (qualquer data passada)
$stmt2 = $pdo->prepare("
    SELECT id FROM glpi_plugin_agenda_events
    WHERE concluido = 0 AND ticket_id IS NULL AND end < ?
");
$stmt2->execute([$agora]);
$para_avisar = array_column($stmt2->fetchAll(), 'id');

$removidos = $chamados_semana_passada;

// Remove chamados atrasados da agenda
if (!empty($removidos)) {
    $ids = array_map(fn($e) => $e['id'], $removidos);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM glpi_plugin_agenda_events WHERE id IN ($placeholders)")->execute($ids);

    // Reseta cada ticket no GLPI
    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $ch   = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $auth,
            'App-Token: ' . GLPI_APP_TOKEN,
        ],
    ]);
    $r     = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $token = $r['session_token'] ?? '';

    if ($token) {
        $headers = [
            'Content-Type: application/json',
            'Session-Token: ' . $token,
            'App-Token: ' . GLPI_APP_TOKEN,
        ];

        // IDs de tickets únicos (pode haver múltiplos eventos do mesmo ticket)
        $tickets_resetados = [];
        foreach ($removidos as $ev) {
            $tid = (int)$ev['ticket_id'];
            if ($tid && !in_array($tid, $tickets_resetados)) {
                // Status → Novo (1), remove técnicos
                $ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $tid);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST  => 'PUT',
                    CURLOPT_POSTFIELDS     => json_encode(['input' => ['status' => 1]]),
                    CURLOPT_HTTPHEADER     => $headers,
                ]);
                curl_exec($ch);
                curl_close($ch);

                // Remove técnicos (type=2)
                $ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $tid . '/Ticket_User');
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
                $users = json_decode(curl_exec($ch), true) ?? [];
                curl_close($ch);
                foreach ($users as $u) {
                    if (!isset($u['id']) || (int)($u['type'] ?? 0) !== 2) continue;
                    $ch = curl_init(GLPI_URL . '/apirest.php/Ticket_User/' . $u['id']);
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_HTTPHEADER => $headers]);
                    curl_exec($ch);
                    curl_close($ch);
                }
                $tickets_resetados[] = $tid;
            }
        }

        // Encerra sessão
        $ch = curl_init(GLPI_URL . '/apirest.php/killSession');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
        curl_exec($ch);
        curl_close($ch);
    }
}

ob_end_clean();
echo json_encode([
    'ok'           => true,
    'removidos'    => count($removidos),
    'tickets'      => array_unique(array_column($removidos, 'ticket_id')),
    'para_avisar'  => $para_avisar, // IDs de eventos não-chamado atrasados (aviso visual)
]);
