<?php
require_once 'config.php';
require_once __DIR__ . '/../entidade_alias.php';

function glpi_session(): string {
    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $auth,
            'App-Token: ' . GLPI_APP_TOKEN,
        ],
    ]);
    $r = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $r['session_token'] ?? '';
}

function glpi_kill(string $token): void {
    $ch = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Session-Token: ' . $token,
            'App-Token: ' . GLPI_APP_TOKEN,
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function glpi_get_tickets(): array {
    $token = glpi_session();
    if (!$token) return [];

    // Busca TODOS os chamados abertos (status 1,2,3,4) sem limite de data
    $tickets = [];

    foreach ([1, 2, 3, 4] as $status) {
        $offset = 0;
        $limit  = 100;
        while (true) {
            $range = $offset . '-' . ($offset + $limit - 1);
            $url   = GLPI_URL . '/apirest.php/Ticket?range=' . $range . '&order=DESC&sort=date&expand_dropdowns=true&searchText[status]=' . $status;
            $ch    = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN],
            ]);
            $res = json_decode(curl_exec($ch), true) ?? [];
            curl_close($ch);

            if (!is_array($res) || isset($res['ERROR']) || empty($res)) break;

            foreach ($res as $t) {
                if (isset($t['id'])) $tickets[] = $t;
            }

            // Se retornou menos que o limite, não há mais páginas
            if (count($res) < $limit) break;

            $offset += $limit;

            // Segurança: máximo 1000 por status
            if ($offset >= 1000) break;
        }
    }

    glpi_kill($token);

    // Mapeamento correto dos status do GLPI:
    // 1=Novo, 2=Em atendimento (atribuído), 3=Em atendimento (planejado),
    // 4=Pendente, 5=Solucionado, 6=Fechado
    $urgency_map = [1=>'muito baixa',2=>'baixa',3=>'média',4=>'alta',5=>'muito alta'];
    $status_map  = [1=>'Novo',2=>'Atribuído',3=>'Planejado',4=>'Pendente',5=>'Solucionado',6=>'Fechado'];

    // Status que NÃO devem aparecer na sidebar para agendamento
    $status_excluidos = [5, 6]; // 5=Solucionado, 6=Fechado

    $result = [];
    foreach ($tickets as $t) {
        if (!isset($t['id'])) continue;
        // Ignora fechados e resolvidos
        if (in_array((int)($t['status'] ?? 0), $status_excluidos)) continue;

        $result[] = [
            'id'         => $t['id'],
            'titulo'     => $t['name'] ?? 'Sem título',
            'status'     => $status_map[$t['status']] ?? 'Desconhecido',
            'urgencia'   => $urgency_map[$t['urgency']] ?? 'média',
            'urgencia_n' => $t['urgency'] ?? 3,
            'setor'       => apelido_entidade($t['entities_id'] ?? ''),
            'data'        => substr($t['date'] ?? '', 0, 10),
            'date_mod'    => $t['date_mod'] ?? '',
            'solicitante' => $t['_users_id_requester'] ?? '',
            'descricao'   => trim(strip_tags(html_entity_decode($t['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
        ];
    }

    // Ordena por última atualização (mais recente primeiro) — padrão
    usort($result, fn($a, $b) => strcmp($b['date_mod'], $a['date_mod']));

    return $result;
}
