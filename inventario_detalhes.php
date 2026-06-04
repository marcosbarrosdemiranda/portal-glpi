<?php
session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit; }

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['hw' => [], 'software' => []]); exit; }

require_once __DIR__ . '/agenda/config.php';

// ── Abre sessão GLPI ──────────────────────────────────────────
$auth  = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch    = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
$r     = json_decode(curl_exec($ch), true); curl_close($ch);
$token = $r['session_token'] ?? '';
if (!$token) { echo json_encode(['hw' => [], 'software' => []]); exit; }

$h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];

// ── Helper: cURL multi — busca vários URLs em paralelo ────────
function curl_multi_batch(array $urls, array $headers): array {
    $mh      = curl_multi_init();
    $handles = [];
    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $handles[$key] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running);

    $results = [];
    foreach ($handles as $key => $ch) {
        $decoded        = json_decode(curl_multi_getcontent($ch), true);
        $results[$key]  = (is_array($decoded) && !isset($decoded['ERROR'])) ? $decoded : [];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

// ── Fase 1: hardware + computador + lista de versões instaladas ──
$base = GLPI_URL . '/apirest.php/';
$data = curl_multi_batch([
    'computer' => $base.'Computer/'.$id.'?expand_dropdowns=true',
    'procs'    => $base.'Computer/'.$id.'/Item_DeviceProcessor?expand_dropdowns=true',
    'mems'     => $base.'Computer/'.$id.'/Item_DeviceMemory?expand_dropdowns=true',
    'discos'   => $base.'Computer/'.$id.'/Item_DeviceHardDrive?expand_dropdowns=true',
    'nets'     => $base.'Computer/'.$id.'/Item_DeviceNetworkCard?expand_dropdowns=true',
    'sw_items' => $base.'Computer/'.$id.'/Item_SoftwareVersion?range=0-500',  // sem expand: IDs brutos
], $h);

// ── Fase 2: busca SoftwareVersion/{id}?expand_dropdowns=true ─────
// softwares_id (expandido) = nome do software; designation = versão
$sw_items = $data['sw_items'];
$sv_ids   = array_values(array_unique(array_filter(
    array_map(fn($s) => (int)($s['softwareversions_id'] ?? 0), $sw_items)
)));

$sv_map = [];
foreach (array_chunk($sv_ids, 30) as $lote) {   // lotes de 30 para não sobrecarregar o servidor
    $urls = [];
    foreach ($lote as $sv_id) {
        $urls[$sv_id] = $base.'SoftwareVersion/'.$sv_id.'?expand_dropdowns=true';
    }
    foreach (curl_multi_batch($urls, $h) as $sv_id => $sv) {
        $nome = trim((string)($sv['softwares_id'] ?? ''));
        if (!$nome) continue;
        $sv_map[(int)$sv_id] = [
            'nome'   => $nome,
            'versao' => trim((string)($sv['designation'] ?? '')),
        ];
    }
}

// ── Encerra sessão ─────────────────────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
curl_exec($ch); curl_close($ch);

// ── Monta hardware ─────────────────────────────────────────────
$computer = $data['computer'];
$procs    = $data['procs'];
$mems     = $data['mems'];
$discos   = $data['discos'];
$nets     = $data['nets'];

$hw = [];

// Sistema Operacional (da API individual — mais completo que o campo do card)
$so      = $computer['operatingsystems_id']              ?? '';
$so_ver  = $computer['operatingsystemversions_id']       ?? '';
$so_arch = $computer['operatingsystemarchitectures_id']  ?? '';
$so_kern = $computer['operatingsystemkernelversions_id'] ?? '';
if ($so)      $hw['Sistema Operacional'] = $so;
if ($so_ver)  $hw['Versão SO']           = $so_ver;
if ($so_arch) $hw['Arquitetura']         = $so_arch;
if ($so_kern) $hw['Kernel']              = $so_kern;

// Processador
if (!empty($procs)) {
    $nomes = array_unique(array_filter(array_map(
        fn($p) => $p['designation'] ?? ($p['deviceprocessors_id'] ?? ''), $procs
    )));
    if ($nomes) $hw['Processador'] = implode(', ', array_slice($nomes, 0, 2));
    $freq  = $procs[0]['frequence'] ?? 0;
    $cores = $procs[0]['nbcores']   ?? 0;
    if ($freq)  $hw['Frequência CPU'] = round($freq / 1000, 1).' GHz';
    if ($cores) $hw['Núcleos']        = $cores.' cores';
}

// Memória RAM
if (!empty($mems)) {
    $total_ram = array_sum(array_map(fn($m) => (int)($m['size'] ?? 0), $mems));
    if ($total_ram) $hw['Memória RAM'] = round($total_ram / 1024, 1).' GB ('.count($mems).' módulo(s))';
    $tipo_ram = $mems[0]['devicememorytypes_id'] ?? '';
    if ($tipo_ram) $hw['Tipo RAM'] = $tipo_ram;
}

// Armazenamento
if (!empty($discos)) {
    $total_disco = array_sum(array_map(fn($d) => (int)($d['capacity'] ?? 0), $discos));
    if ($total_disco) $hw['Armazenamento'] = round($total_disco / 1024, 0).' GB ('.count($discos).' disco(s))';
    $tipo_disco = $discos[0]['deviceharddrivemodels_id'] ?? ($discos[0]['designation'] ?? '');
    if ($tipo_disco) $hw['Modelo Disco'] = $tipo_disco;
}

// MAC Address
if (!empty($nets)) {
    $mac = $nets[0]['mac'] ?? '';
    if ($mac) $hw['MAC Address'] = strtoupper($mac);
}

// ── Monta lista de software (deduplicada, ordenada) ────────────
$software     = [];
$nomes_vistos = [];
foreach ($sw_items as $sv) {
    $sv_id = (int)($sv['softwareversions_id'] ?? 0);
    if (!$sv_id || !isset($sv_map[$sv_id])) continue;
    $nome = $sv_map[$sv_id]['nome'];
    if (isset($nomes_vistos[$nome])) continue;   // remove duplicatas
    $nomes_vistos[$nome] = true;
    $software[] = $sv_map[$sv_id];
}
usort($software, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));

echo json_encode(['hw' => $hw, 'software' => $software]);
