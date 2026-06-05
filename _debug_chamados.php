<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }

require_once __DIR__ . '/agenda/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== TESTE DA API GLPI ===\n\n";

function glpi_request(string $method, string $endpoint, array $headers = [], $body = null): array {
    $ch = curl_init(GLPI_URL . '/apirest.php/' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    return ['code' => $code, 'data' => $data, 'raw' => $resp];
}

echo "1. Init Session...\n";
$init = glpi_request('GET', 'initSession', [
    'Content-Type: application/json',
    'App-Token: ' . GLPI_APP_TOKEN,
    'Authorization: Basic ' . base64_encode(GLPI_USER . ':' . GLPI_PASS),
]);
echo "   Code: {$init['code']}\n";
echo "   Response: " . json_encode($init['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$token = $init['data']['session_token'] ?? '';
if (!$token) {
    echo "FALHA: token vazio\n";
    exit;
}

$hdrs = [
    'Content-Type: application/json',
    'App-Token: ' . GLPI_APP_TOKEN,
    'Session-Token: ' . $token,
];

// UsuÃ¡rio da sessÃ£o
$uid = $_SESSION['user_id'] ?? 7;
echo "2. Buscando chamados abertos do tÃ©cnico ID=$uid...\n";

$search = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=12&criteria[1][searchtype]=lessthan&criteria[1][value]=5' .
    '&range=0-20&expand_dropdowns=true';
echo "   URL: /apirest.php/$search\n\n";

$res = glpi_request('GET', $search, $hdrs);
echo "   HTTP Code: {$res['code']}\n";
echo "   Totalcount: " . ($res['data']['totalcount'] ?? 'N/A') . "\n";
echo "   Count: " . ($res['data']['count'] ?? 'N/A') . "\n";
echo "   Response completo:\n";
echo json_encode($res['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "3. Testando com field=2 (tÃ©cnico atribuÃ­do alternativo)...\n";
$search2 = 'search/Ticket' .
    '?criteria[0][field]=2&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=12&criteria[1][searchtype]=lessthan&criteria[1][value]=5' .
    '&range=0-20&expand_dropdowns=true';
$res2 = glpi_request('GET', $search2, $hdrs);
echo "   Totalcount: " . ($res2['data']['totalcount'] ?? 'N/A') . "\n\n";

echo "4. Testando com field=4 (requester)...\n";
$search3 = 'search/Ticket' .
    '?criteria[0][field]=4&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=12&criteria[1][searchtype]=lessthan&criteria[1][value]=5' .
    '&range=0-20&expand_dropdowns=true';
$res3 = glpi_request('GET', $search3, $hdrs);
echo "   Totalcount: " . ($res3['data']['totalcount'] ?? 'N/A') . "\n\n";

echo "4b. Testando com field=3 (STATUS) com lessthan 5...\n";
$search3b = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=3&criteria[1][searchtype]=lessthan&criteria[1][value]=5' .
    '&range=0-20&expand_dropdowns=true';
$res3b = glpi_request('GET', $search3b, $hdrs);
echo "   Totalcount: " . ($res3b['data']['totalcount'] ?? 'N/A') . "\n";
echo "   Count: " . ($res3b['data']['count'] ?? 'N/A') . "\n";
if (!empty($res3b['data']['data'])) {
    foreach ($res3b['data']['data'] as $t) {
        echo "   #{$t[2]} | status={$t[3]} | name={$t[1]}\n";
    }
}
echo "\n";

echo "4c. Testando com field=3 (STATUS) morethan 4...\n";
$search3c = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=3&criteria[1][searchtype]=morethan&criteria[1][value]=4' .
    '&range=0-20&expand_dropdowns=true';
$res3c = glpi_request('GET', $search3c, $hdrs);
echo "   Totalcount: " . ($res3c['data']['totalcount'] ?? 'N/A') . "\n";
echo "   Count: " . ($res3c['data']['count'] ?? 'N/A') . "\n";
if (!empty($res3c['data']['data'])) {
    foreach ($res3c['data']['data'] as $t) {
        echo "   #{$t[2]} | status={$t[3]} | name={$t[1]}\n";
    }
}
echo "\n";

echo "5. Testando sem filtro de status (todos os tickets do tÃ©cnico)...\n";
$search4 = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&range=0-10&expand_dropdowns=true';
$res4 = glpi_request('GET', $search4, $hdrs);
echo "   Totalcount: " . ($res4['data']['totalcount'] ?? 'N/A') . "\n";
echo "   Amostra (primeiros 10):\n";
if (!empty($res4['data']['data'])) {
    foreach ($res4['data']['data'] as $t) {
        @$id = $t[2] ?? $t['id'] ?? '?';
        @$st = $t[3] ?? $t['status'] ?? '?';
        @$tp = $t[12] ?? $t['type'] ?? '?';
        @$nm = $t[1] ?? $t['name'] ?? '?';
        echo "   #{$id} | status={$st} | type={$tp} | name={$nm}\n";
    }
} else {
    echo "   (sem dados de amostra)\n";
}
// Mostra as chaves do primeiro ticket (se houver)
if (!empty($res4['data']['data'][0])) {
    echo "\n   CHAVES do primeiro ticket (com expand_dropdowns=true):\n";
    foreach ($res4['data']['data'][0] as $k => $v) {
        echo "     [$k] => " . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v) . "\n";
    }
}
echo "\n";

echo "5b. Testando com field=17 (tentativa - field possivel para status)...\n";
$search4b = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=17&criteria[1][searchtype]=lessthan&criteria[1][value]=5' .
    '&range=0-10&expand_dropdowns=true';
$res4b = glpi_request('GET', $search4b, $hdrs);
echo "   Totalcount: " . ($res4b['data']['totalcount'] ?? 'N/A') . "\n\n";

echo "5c. Testando com field=80 (tentativa - pode ser status em GLPI 10 custom)...\n";
$search4c = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=80&criteria[1][searchtype]=lessthan&criteria[1][value]=5' .
    '&range=0-10&expand_dropdowns=true';
$res4c = glpi_request('GET', $search4c, $hdrs);
echo "   Totalcount: " . ($res4c['data']['totalcount'] ?? 'N/A') . "\n\n";

echo "5d. Testando field=3 com equals=3 (status=Planejado)...\n";
$search5d = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=3&criteria[1][searchtype]=equals&criteria[1][value]=3' .
    '&range=0-10&expand_dropdowns=true';
$res5d = glpi_request('GET', $search5d, $hdrs);
echo "   Totalcount: " . ($res5d['data']['totalcount'] ?? 'N/A') . "\n\n";

echo "5e. Testando field=12 com equals=2 (type=RequisiÃ§Ã£o)...\n";
$search5e = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=12&criteria[1][searchtype]=equals&criteria[1][value]=2' .
    '&range=0-10&expand_dropdowns=true';
$res5e = glpi_request('GET', $search5e, $hdrs);
echo "   Totalcount: " . ($res5e['data']['totalcount'] ?? 'N/A') . "\n\n";

echo "5f. Testando field=3 com lessthan 5 SEM expand_dropdowns...\n";
$search5f = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=3&criteria[1][searchtype]=lessthan&criteria[1][value]=5' .
    '&range=0-10';
$res5f = glpi_request('GET', $search5f, $hdrs);
echo "   Totalcount: " . ($res5f['data']['totalcount'] ?? 'N/A') . "\n\n";

echo "5g. Testando field=3 equals 3 SEM expand_dropdowns...\n";
$search5g = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=3&criteria[1][searchtype]=equals&criteria[1][value]=3' .
    '&range=0-10';
$res5g = glpi_request('GET', $search5g, $hdrs);
echo "   Totalcount: " . ($res5g['data']['totalcount'] ?? 'N/A') . "\n\n";

echo "5h. Testando field=3 NOT equals 5 (excluir Solucionado)...\n";
$search5h = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=3&criteria[1][searchtype]=notequals&criteria[1][value]=5' .
    '&range=0-10&expand_dropdowns=true';
$res5h = glpi_request('GET', $search5h, $hdrs);
echo "   Totalcount: " . ($res5h['data']['totalcount'] ?? 'N/A') . "\n\n";

echo "5i. Testando field=3 NOT equals 6 (excluir Fechado)...\n";
$search5i = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=3&criteria[1][searchtype]=notequals&criteria[1][value]=6' .
    '&range=0-10&expand_dropdowns=true';
$res5i = glpi_request('GET', $search5i, $hdrs);
echo "   Totalcount: " . ($res5i['data']['totalcount'] ?? 'N/A') . "\n\n";

echo "5j. Testando field=3 OR equals 1+2+3+4 (abertos com OR)...\n";
$search5j = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=3&criteria[1][searchtype]=equals&criteria[1][value]=1' .
    '&criteria[2][link]=OR&criteria[2][field]=3&criteria[2][searchtype]=equals&criteria[2][value]=2' .
    '&criteria[3][link]=OR&criteria[3][field]=3&criteria[3][searchtype]=equals&criteria[3][value]=3' .
    '&criteria[4][link]=OR&criteria[4][field]=3&criteria[4][searchtype]=equals&criteria[4][value]=4' .
    '&range=0-10&expand_dropdowns=true';
$res5j = glpi_request('GET', $search5j, $hdrs);
echo "   Totalcount: " . ($res5j['data']['totalcount'] ?? 'N/A') . "\n\n";

echo "5k. Testando field=3 OR equals 5+6 (concluidos com OR)...\n";
$search5k = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=3&criteria[1][searchtype]=equals&criteria[1][value]=5' .
    '&criteria[2][link]=OR&criteria[2][field]=3&criteria[2][searchtype]=equals&criteria[2][value]=6' .
    '&range=0-10&expand_dropdowns=true';
$res5k = glpi_request('GET', $search5k, $hdrs);
echo "   Totalcount: " . ($res5k['data']['totalcount'] ?? 'N/A') . "\n\n";

echo "6. Testando com glpi_users para ver ID/nome...\n";
$users = glpi_request('GET', 'User?range=0-50&expand_dropdowns=true', $hdrs);
echo "   Total: " . (count($users['data'] ?? [])) . "\n";
if (!empty($users['data'])) {
    foreach ($users['data'] as $u) {
        if (isset($u['id'])) {
            echo "   ID={$u['id']} | name={$u['name']} | firstname={$u['firstname']} | realname={$u['realname']}\n";
        }
    }
}

echo "7. Testando chamados fechados (status=6) SEM filtro de tecnico...\n";
$search7 = 'search/Ticket' .
    '?criteria[0][field]=3&criteria[0][searchtype]=equals&criteria[0][value]=6' .
    '&range=0-20&expand_dropdowns=true';
$res7 = glpi_request('GET', $search7, $hdrs);
echo "   Totalcount: " . ($res7['data']['totalcount'] ?? 'N/A') . "\n";
if (!empty($res7['data']['data'])) {
    foreach ($res7['data']['data'] as $t) {
        $id = $t[2] ?? '?';
        $nome = $t[1] ?? '?';
        $tech_val = is_array($t[5] ?? '') ? ($t[5]['name'] ?? json_encode($t[5])) : ($t[5] ?? 'VAZIO');
        $tech_id_field = is_array($t[5] ?? '') ? ($t[5]['id'] ?? '?') : $t[5];
        echo "   #{$id} | '{$nome}' | tech(5)=" . (is_array($t[5] ?? '') ? 'ARRAY:' . json_encode($t[5]) : $t[5] ?? 'VAZIO') . "\n";
    }
} else {
    echo "   (sem fechados no sistema)\n";
}

echo "\n8. Testando chamados fechados (status=6) COM filtro tecnico (field=5) para ID=$uid...\n";
$search8 = 'search/Ticket' .
    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid .
    '&criteria[1][link]=AND&criteria[1][field]=3&criteria[1][searchtype]=equals&criteria[1][value]=6' .
    '&range=0-20&expand_dropdowns=true';
$res8 = glpi_request('GET', $search8, $hdrs);
echo "   Totalcount: " . ($res8['data']['totalcount'] ?? 'N/A') . "\n";
if (!empty($res8['data']['data'])) {
    foreach ($res8['data']['data'] as $t) {
        echo "   #{$t[2]}| {$t[1]} | status={$t[3]} | tech=" . (is_array($t[5] ?? '') ? 'ARRAY' : ($t[5] ?? 'VAZIO')) . "\n";
    }
} else {
    echo "   (nenhum fechado atribuido)\n";
}

echo "\n9. Testando chamados SOLUCIONADOS (status=5) para todos os tecnicos...\n";
$search9 = 'search/Ticket' .
    '?criteria[0][field]=3&criteria[0][searchtype]=equals&criteria[0][value]=5' .
    '&range=0-10&expand_dropdowns=true';
$res9 = glpi_request('GET', $search9, $hdrs);
echo "   Totalcount: " . ($res9['data']['totalcount'] ?? 'N/A') . "\n";
if (!empty($res9['data']['data'])) {
    foreach ($res9['data']['data'] as $t) {
        $id = $t[2] ?? '?';
        $nome = $t[1] ?? '?';
        echo "   #{$id} | '{$nome}' | tech(5)=" . (is_array($t[5] ?? '') ? 'ARRAY' : ($t[5] ?? 'VAZIO')) . " | created=" . substr($t[15] ?? '', 0, 10) . "\n";
    }
} else {
    echo "   (nenhum solucionado no sistema)\n";
}

echo "10. Buscando chamado #9094 diretamente por ID...\n";
$ticket9094 = glpi_request('GET', 'Ticket/9094?expand_dropdowns=true', $hdrs);
if (!empty($ticket9094['data']['id'])) {
    $t = $ticket9094['data'];
    echo "   ID={$t['id']}\n";
    echo "   name={$t['name']}\n";
    echo "   status_raw=" . json_encode($t['status'] ?? 'N/A') . "\n";
    echo "   status_num=" . ((int)($t['status'] ?? -1)) . "\n";
    echo "   is_deleted=" . ($t['is_deleted'] ?? 'N/A') . "\n";
    echo "   type=" . json_encode($t['type'] ?? 'N/A') . "\n";
    echo "   entities_id=" . json_encode($t['entities_id'] ?? 'N/A') . "\n";
    echo "   users_id_assign=" . json_encode($t['_users_id_assign'] ?? 'N/A') . "\n";
    echo "   Ticket_User links:\n";
    $tu = glpi_request('GET', 'Ticket/9094/Ticket_User', $hdrs);
    if (!empty($tu['data'])) {
        foreach ($tu['data'] as $tu_entry) {
            echo "     users_id={$tu_entry['users_id']} | type={$tu_entry['type']} | use_notification={$tu_entry['use_notification']}\n";
        }
    } else {
        echo "     (nenhum) " . json_encode($tu) . "\n";
    }
    echo "   Buscando #9094 via search API...\n";
    $search9094 = glpi_request('GET', 'search/Ticket?criteria[0][field]=2&criteria[0][searchtype]=equals&criteria[0][value]=9094&expand_dropdowns=true&range=0-5', $hdrs);
    echo "     Totalcount: " . ($search9094['data']['totalcount'] ?? 0) . "\n";
    if (!empty($search9094['data']['data'])) {
        foreach ($search9094['data']['data'] as $t) {
            echo "     [#{$t[2]}] status={$t[3]} tech(5)=" . (is_array($t[5]??'') ? json_encode($t[5]) : ($t[5]??'VAZIO')) . "\n";
        }
    }
    echo "   Buscando todos status=6 (0-50)...\n";
    $allS6 = glpi_request('GET', 'search/Ticket?criteria[0][field]=3&criteria[0][searchtype]=equals&criteria[0][value]=6&expand_dropdowns=true&range=0-50', $hdrs);
    echo "     Totalcount: " . ($allS6['data']['totalcount'] ?? 0) . "\n";
    if (!empty($allS6['data']['data'])) {
        foreach ($allS6['data']['data'] as $t) {
            echo "     [#{$t[2]}] status={$t[3]} tech(5)=" . (is_array($t[5]??'') ? json_encode($t[5]) : ($t[5]??'VAZIO')) . " name={$t[1]}\n";
        }
    } else { echo "     (vazio - 9094 nao via search)\n"; }

    echo "   CHAVES COMPLETAS:\n";
    foreach ($t as $k => $v) {
        echo "     [{$k}] => " . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v) . "\n";
    }
} else {
    echo "   ERRO: " . json_encode($ticket9094, JSON_UNESCAPED_UNICODE) . "\n";
}
echo "\n";

// Kill session
glpi_request('GET', 'killSession', $hdrs);
echo "\n=== FIM ===\n";

