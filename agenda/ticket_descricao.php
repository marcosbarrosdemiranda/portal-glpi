<?php
header('Content-Type: application/json');
require_once 'config.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['descricao'=>'','entidade'=>'','entidade_id'=>0,'categoria'=>'','categoria_id'=>0,'requerente'=>'']); exit; }

$auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
$r = json_decode(curl_exec($ch), true); curl_close($ch);
$token = $r['session_token'] ?? '';
if (!$token) { echo json_encode(['descricao'=>'']); exit; }

$h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];

// Busca ticket com expand_dropdowns para nomes legíveis
$ch2 = curl_init(GLPI_URL . '/apirest.php/Ticket/'.$id.'?expand_dropdowns=true');
curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
$t = json_decode(curl_exec($ch2), true) ?? [];
curl_close($ch2);

// Busca ticket sem expand para IDs numéricos (entidade_id, categoria_id)
$ch4 = curl_init(GLPI_URL . '/apirest.php/Ticket/'.$id);
curl_setopt_array($ch4, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
$t_raw = json_decode(curl_exec($ch4), true) ?? [];
curl_close($ch4);

// Busca requerente via Ticket_User type=1
// SEM expand_dropdowns para preservar users_id como inteiro (com expand, vira string/nome)
$ch5 = curl_init(GLPI_URL . '/apirest.php/Ticket/'.$id.'/Ticket_User');
curl_setopt_array($ch5, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
$users = json_decode(curl_exec($ch5), true) ?? [];
curl_close($ch5);

// Busca followups do chamado
$ch6 = curl_init(GLPI_URL . '/apirest.php/Ticket/'.$id.'/ITILFollowup?expand_dropdowns=true&order=ASC&sort=date_creation');
curl_setopt_array($ch6, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
$followups_raw = json_decode(curl_exec($ch6), true) ?? [];
curl_close($ch6);

// Busca documentos por followup (anexos enviados via Responder ficam em ITILFollowup)
// Document_Item do Ticket normalmente retorna [] — os docs ficam no followup.
$docs_por_fu = [];
if (is_array($followups_raw)) {
    foreach ($followups_raw as $f) {
        $fu_id = (int)($f['id'] ?? 0);
        if (!$fu_id) continue;
        $chD = curl_init(GLPI_URL . '/apirest.php/ITILFollowup/'.$fu_id.'/Document_Item');
        curl_setopt_array($chD, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
        $di_list = json_decode(curl_exec($chD), true) ?? [];
        curl_close($chD);
        foreach ((array)$di_list as $di) {
            $docid = (int)($di['documents_id'] ?? 0);
            if (!$docid) continue;
            $chDoc = curl_init(GLPI_URL . '/apirest.php/Document/'.$docid);
            curl_setopt_array($chDoc, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
            $docData = json_decode(curl_exec($chDoc), true) ?? [];
            curl_close($chDoc);
            if (empty($docData['id'])) continue;
            $fname = $docData['filename'] ?? $docData['name'] ?? 'arquivo';
            $ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
            $docs_por_fu[] = [
                'id'    => $docid,
                'nome'  => $fname,
                'isImg' => in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg']),
                'size'  => (int)($docData['filesize'] ?? 0),
            ];
        }
    }
}

$ch3 = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
curl_exec($ch3); curl_close($ch3);

// Requerente (type=1)
// users_id agora é inteiro (sem expand_dropdowns)
// users_id_friendlyname contém o nome legível do usuário
$requerente    = '';
$requerente_id = 0;
if (is_array($users)) {
    foreach ($users as $u) {
        if ((int)($u['type'] ?? 0) === 1) {
            $requerente_id = (int)($u['users_id'] ?? 0);
            $requerente    = $u['users_id_friendlyname'] ?? '';
            // fallback: se friendlyname estiver vazio, busca pelo login
            if (!$requerente && $requerente_id) {
                $requerente = 'Usuário #' . $requerente_id;
            }
            break;
        }
    }
}

$raw = html_entity_decode($t['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$descricao = trim(strip_tags($raw));

$followups = [];
if (is_array($followups_raw)) {
    foreach ($followups_raw as $f) {
        if (!isset($f['id'])) continue;
        $followups[] = [
            'autor' => $f['users_id_friendlyname'] ?? $f['users_id'] ?? 'Sistema',
            'data'  => substr($f['date_creation'] ?? $f['date'] ?? '', 0, 16),
            'texto' => trim(strip_tags(html_entity_decode($f['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
        ];
    }
}

$docs = $docs_por_fu; // todos os docs dos followups, lista plana

echo json_encode([
    'descricao'    => $descricao,
    'entidade'     => html_entity_decode($t['entities_id'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    'entidade_id'  => (int)($t_raw['entities_id'] ?? 0),
    'categoria'    => $t['itilcategories_id']   ?? '',
    'categoria_id' => (int)($t_raw['itilcategories_id'] ?? 0),
    'requerente'   => $requerente,
    'requerente_id'=> $requerente_id,
    'followups'    => $followups,
    'docs'         => $docs,
]);
