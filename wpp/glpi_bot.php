<?php
// wpp/glpi_bot.php — chamadas à API do GLPI usadas pelo chatbot. Sem HTML,
// funções isoladas, retorno em array, nunca lançam. Reusa o padrão
// Basic-auth de agenda/criar_ticket.php (sem token por usuário GLPI).
require_once __DIR__ . '/../agenda/config.php';

// Monta o array "input" do POST /apirest.php/Ticket. Pura, sem rede —
// testável sem precisar de um GLPI de verdade.
function bot_criar_chamado_payload(int $requerente_id, int $entities_id, string $titulo, string $descricao): array {
    return [
        'name'                => $titulo,
        'content'             => $descricao !== '' ? $descricao : $titulo,
        'type'                => 1, // Incidente
        'urgency'             => 3,
        'priority'            => 3,
        'status'              => 1, // Novo — sem atendente ainda
        '_users_id_requester' => $requerente_id,
        'entities_id'         => $entities_id,
    ];
}

// Cria o chamado de verdade via REST. Nunca lança — qualquer falha de rede
// ou da API volta como ['ok'=>false,'erro'=>...].
function bot_criar_chamado(int $requerente_id, int $entities_id, string $titulo, string $descricao): array {
    try {
        $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
        $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth, 'App-Token: ' . GLPI_APP_TOKEN],
        ]);
        $r = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        $token = $r['session_token'] ?? '';
        if (!$token) {
            return ['ok' => false, 'ticket_id' => null, 'erro' => 'falha ao autenticar na API do GLPI'];
        }

        $headers = ['Content-Type: application/json', 'Session-Token: ' . $token, 'App-Token: ' . GLPI_APP_TOKEN];
        $input   = bot_criar_chamado_payload($requerente_id, $entities_id, $titulo, $descricao);

        $ch = curl_init(GLPI_URL . '/apirest.php/Ticket');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => json_encode(['input' => $input]),
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $res = json_decode((string) curl_exec($ch), true);
        curl_close($ch);

        // best-effort: encerra a sessão, sem deixar isso afetar o resultado
        $ch = curl_init(GLPI_URL . '/apirest.php/killSession');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
        curl_exec($ch);
        curl_close($ch);

        if (!empty($res['id'])) {
            return ['ok' => true, 'ticket_id' => (int) $res['id'], 'erro' => null];
        }
        return ['ok' => false, 'ticket_id' => null, 'erro' => 'GLPI nao retornou id: ' . json_encode($res)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'ticket_id' => null, 'erro' => $e->getMessage()];
    }
}
