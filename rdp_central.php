<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin','super-admin','tecnico']);

// ── Criptografia (AES-256-CBC — igual ao Cofre TI) ────────────
if (!defined('VAULT_KEY')) {
    define('VAULT_KEY', hash('sha256', GLPI_APP_TOKEN . 'cofre_ti_gmais'));
}
function rdp_encrypt(string $plain): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'aes-256-cbc', VAULT_KEY, 0, $iv);
    return base64_encode($iv . $enc);
}
function rdp_decrypt(string $data): string {
    $raw = base64_decode($data);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return openssl_decrypt($enc, 'aes-256-cbc', VAULT_KEY, 0, $iv) ?: '';
}

// ── Tabela ──────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_rdp_maquinas (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        nome        VARCHAR(150) NOT NULL,
        ip          VARCHAR(45)  NOT NULL,
        descricao   VARCHAR(255) DEFAULT '',
        usuario     VARCHAR(100) DEFAULT '',
        senha       TEXT         DEFAULT NULL COMMENT 'AES-256-CBC',
        protocolo   VARCHAR(5)   NOT NULL DEFAULT 'rdp' COMMENT 'rdp ou vnc',
        categoria   VARCHAR(30)  NOT NULL DEFAULT 'servidor',
        guac_id     INT          DEFAULT NULL COMMENT 'ID da conexão no Guacamole',
        ativo       TINYINT(1)   DEFAULT 1,
        ordem       INT          DEFAULT 0,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Colunas p/ versões anteriores
foreach (['usuario VARCHAR(100) DEFAULT ""', 'senha TEXT DEFAULT NULL', 'guac_id INT DEFAULT NULL', "protocolo VARCHAR(5) NOT NULL DEFAULT 'rdp'"] as $col) {
    try { $pdo->exec("ALTER TABLE portal_rdp_maquinas ADD COLUMN $col"); } catch (Exception $e) {}
}

// ── Se tabela vazia, insere exemplos ────────────────────────────
if ($pdo->query("SELECT COUNT(*) FROM portal_rdp_maquinas")->fetchColumn() == 0) {
    $st = $pdo->prepare("INSERT INTO portal_rdp_maquinas (nome,ip,descricao,usuario,categoria,ordem) VALUES (?,?,?,?,?,?)");
    $exemplos = [
        ['DC-01',           '192.168.1.10', 'Controlador de Domínio',       '',  'servidor',     1],
        ['SRV-APP',         '192.168.1.11', 'Servidor de Aplicações',       '',  'servidor',     2],
        ['SQL-SERVER',      '192.168.1.12', 'Banco de Dados SQL',           '',  'servidor',     3],
        ['COLETOR-01',      '192.168.1.20', 'Coletor de Dados NFE',          '',  'coletor',      1],
        ['COLETOR-02',      '192.168.1.21', 'Coletor SAT / PDV',            '',  'coletor',      2],
        ['PC-GERENCIA',     '192.168.1.30', 'Micro da Gerência',            '',  'pc',           1],
        ['PC-TI-DIAG',      '192.168.1.31', 'Micro do Suporte TI (WOL)',    '',  'pc',           2],
    ];
    foreach ($exemplos as $e) $st->execute($e);
}

// ── Garante que a TS - Marcos tem guac_id (ID 1 no Guacamole) ──
$pdo->prepare("UPDATE portal_rdp_maquinas SET guac_id=1 WHERE nome=? AND (guac_id IS NULL OR guac_id=0)")
    ->execute(['TS - Marcos']);
// Se não existir, insere
$chk = $pdo->query("SELECT COUNT(*) FROM portal_rdp_maquinas WHERE nome='TS - Marcos'")->fetchColumn();
if ($chk == 0) {
    $pdo->prepare("INSERT INTO portal_rdp_maquinas (nome,ip,descricao,usuario,categoria,ordem,guac_id) VALUES (?,?,?,?,?,?,?)")
        ->execute(['TS - Marcos','192.168.1.116','Micro do Marcos','marcos@grupogmais','pc',3,1]);
}

// ── Categorias (config visual) ─────────────────────────────────
$cats = [
    'servidor' => ['label'=>'Servidores',       'icon'=>'bi-server',        'bg'=>'#1e3a8a', 'color'=>'#dbeafe', 'badge'=>'#dbeafe', 'badge-text'=>'#1e3a8a'],
    'coletor'  => ['label'=>'Coletores',         'icon'=>'bi-cpu',           'bg'=>'#065f46', 'color'=>'#d1fae5', 'badge'=>'#d1fae5', 'badge-text'=>'#065f46'],
    'pc'       => ['label'=>'PCs Estratégicos',  'icon'=>'bi-pc-display',    'bg'=>'#7c3aed', 'color'=>'#ede9fe', 'badge'=>'#ede9fe', 'badge-text'=>'#7c3aed'],
];

// ── AJAX ────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {

    // ── List (NUNCA retorna a senha) ─────────────────────────
    if ($action === 'list') {
        $categoria = $_GET['categoria'] ?? '';
        $sql = "SELECT id, nome, ip, descricao, usuario,
                       CASE WHEN senha IS NOT NULL AND senha != '' THEN 1 ELSE 0 END as has_senha,
                       protocolo, categoria, ordem, guac_id
                FROM portal_rdp_maquinas WHERE ativo=1";
        $params = [];
        if ($categoria && in_array($categoria, ['servidor','coletor','pc'])) {
            $sql .= " AND categoria=?";
            $params[] = $categoria;
        }
        $sql .= " ORDER BY ordem, nome";
        $rows = $pdo->prepare($sql);
        $rows->execute($params);
        echo json_encode(['ok' => true, 'dados' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── Download .rdp ────────────────────────────────────────
    if ($action === 'rdp' && isset($_GET['id'])) {
        $st = $pdo->prepare("SELECT nome, ip, usuario FROM portal_rdp_maquinas WHERE id=? AND ativo=1");
        $st->execute([(int)$_GET['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Máquina não encontrada']); exit; }
        $host = $row['ip'];
        $user = $row['usuario'] ?? '';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $row['nome'] . '.rdp"');
        echo "full address:s:{$host}\r\n";
        echo "prompt for credentials:i:1\r\n";
        echo "username:s:{$user}\r\n";
        echo "session bpp:i:32\r\n";
        echo "connection type:i:2\r\n";
        echo "networkautodetect:i:1\r\n";
        echo "bandwidthautodetect:i:1\r\n";
        echo "displayconnectionbar:i:1\r\n";
        echo "audiomode:i:0\r\n";
        echo "audiocapturemode:i:0\r\n";
        echo "redirectprinters:i:0\r\n";
        echo "redirectcomports:i:0\r\n";
        echo "redirectsmartcards:i:0\r\n";
        echo "redirectclipboard:i:1\r\n";
        echo "redirectposdevices:i:0\r\n";
        echo "drivestoredirect:s:\r\n";
        echo "autoreconnection enabled:i:1\r\n";
        echo "authentication level:i:0\r\n";
        echo "gatewayhostname:s:\r\n";
        echo "gatewayusagemethod:i:0\r\n";
        echo "gatewaycredentialssource:i:0\r\n";
        echo "gatewayprofileusagemethod:i:0\r\n";
        exit;
    }

    // ── Launcher automático (.bat com cmdkey) ───────────────
    if ($action === 'launcher' && isset($_GET['id'])) {
        $st = $pdo->prepare("SELECT nome, ip, usuario, senha FROM portal_rdp_maquinas WHERE id=? AND ativo=1");
        $st->execute([(int)$_GET['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Máquina não encontrada']); exit; }
        $host = $row['ip'];
        $user = $row['usuario'] ?? '';
        $pass = $row['senha'] ? rdp_decrypt($row['senha']) : '';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $row['nome'] . '.bat"');
        echo "@echo off\r\n";
        echo "title Conectando a {$row['nome']}...\r\n";
        echo "cd /d \"%~dp0\"\r\n";
        echo "echo 🖥️  Conectando a {$host} ...\r\n";
        if ($user && $pass) {
            echo "cmdkey /generic:TERMSRV/{$host} /user:\"{$user}\" /pass:\"{$pass}\" >nul 2>&1\r\n";
        }
        echo "start \"\" mstsc /v:{$host}\r\n";
        echo "echo ✅ Conexao iniciada! A senha sera removida ao fechar.\r\n";
        if ($user && $pass) {
            echo ":aguardar\r\n";
            echo "timeout /t 3 /nobreak >nul\r\n";
            echo "tasklist /FI \"IMAGENAME eq mstsc.exe\" 2>nul | find /I \"mstsc.exe\" >nul && goto aguardar\r\n";
            echo "cmdkey /delete:TERMSRV/{$host} >nul 2>&1\r\n";
        }
        echo "exit\r\n";
        exit;
    }

    // ── Guacamole — API login + redirect ────────────────────
    if ($action === 'guac' && isset($_GET['id'])) {
        $debug = isset($_GET['debug']);
        $st = $pdo->prepare("SELECT nome, guac_id FROM portal_rdp_maquinas WHERE id=? AND ativo=1 AND guac_id IS NOT NULL");
        $st->execute([(int)$_GET['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo "<script>alert('Máquina sem conexão Guacamole configurada');history.back();</script>";
            exit;
        }

        $guac_url = rtrim(GUACAMOLE_URL, '/');
        $conn_id = (int)$row['guac_id'];

        // Login na API do Guacamole
        $ch = curl_init($guac_url . '/api/tokens');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['username' => GUACAMOLE_USER, 'password' => GUACAMOLE_PASS]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $token = '';
        if ($info['http_code'] === 200 && $resp) {
            $auth = json_decode($resp, true);
            $token = $auth['authToken'] ?? '';
        }

        // Constrói o identifier: base64(id + "\0" + "c" + "\0" + datasource)
        $clientId = base64_encode($conn_id . "\0" . 'c' . "\0" . 'mysql');

        // Monta URL com token (se conseguiu) ou sem (usa sessão existente)
        if ($token) {
            $redirectUrl = $guac_url . '/?token=' . urlencode($token) . '#/client/' . $clientId;
        } else {
            $redirectUrl = $guac_url . '/#/client/' . $clientId;
        }

        if ($debug) {
            echo "<h3>Debug Guacamole:</h3>";
            echo "<p><strong>Máquina:</strong> " . htmlspecialchars($row['nome']) . "</p>";
            echo "<p><strong>guac_id:</strong> {$conn_id}</p>";
            echo "<p><strong>Token obtido:</strong> " . ($token ? htmlspecialchars(substr($token, 0, 20)) . '...' : '⚠️ NÃO (fallback sem token)') . "</p>";
            echo "<p><strong>clientId:</strong> " . htmlspecialchars($clientId) . "</p>";
            echo "<p><strong>URL final:</strong></p>";
            echo "<pre style='background:#f4f4f4;padding:1rem;word-break:break-all;'>" . htmlspecialchars($redirectUrl) . "</pre>";
            echo "<p><a href='" . htmlspecialchars($redirectUrl) . "' target='_blank'>🔗 Abrir link</a></p>";
            exit;
        }

        // Redirect com JavaScript (preserva o hash)
        echo "<!DOCTYPE html><html><head><title>Conectando...</title></head><body>";
        echo "<script>location.href=" . json_encode($redirectUrl) . ";</script>";
        echo "<p style='font-family:sans-serif;padding:2rem;text-align:center;'>🖥️ Conectando a " . htmlspecialchars($row['nome']) . " via Guacamole...</p>";
        echo "</body></html>";
        exit;
    }

    // ── CRUD (admin apenas) ─────────────────────────────────
    if (!$is_admin) { echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit; }

    // ── Helper: cria conexão no Guacamole via API ──────────────
    function guac_criar_conexao(string $nome, string $ip, string $usuario = '', string $senha = ''): array {
        $result = ['id' => null, 'erro' => '', 'http' => 0, 'resposta' => ''];
        $guac_url = rtrim(GUACAMOLE_URL, '/');

        // Login
        $ch = curl_init($guac_url . '/api/tokens');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['username' => GUACAMOLE_USER, 'password' => GUACAMOLE_PASS]),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        if ($info['http_code'] !== 200 || !$resp) {
            $result['erro'] = 'Falha no login Guacamole API (HTTP ' . $info['http_code'] . ')';
            $result['http'] = $info['http_code'];
            return $result;
        }

        $auth = json_decode($resp, true);
        $token = $auth['authToken'] ?? '';
        $ds = $auth['dataSource'] ?? 'mysql';
        if (!$token) {
            $result['erro'] = 'Token do Guacamole inválido';
            return $result;
        }

        // Cria conexão RDP (formato correto da API)
        $payload = [
            'parentIdentifier' => 'ROOT',
            'name' => $nome,
            'protocol' => 'rdp',
            'attributes' => [
                'max-connections' => '',
                'max-connections-per-user' => '',
                'weight' => '',
                'failover-only' => '',
                'guacd-hostname' => '',
                'guacd-port' => '',
            ],
            'parameters' => [
                'hostname' => $ip,
                'port' => '3389',
                'username' => $usuario,
                'password' => $senha,
                'domain' => '',
                'ignore-cert' => 'true',
                'security' => 'any',
                'enable-wallpaper' => 'false',
                'enable-theming' => 'false',
                'enable-font-smoothing' => 'true',
                'enable-full-window-drag' => 'false',
                'enable-menu-animations' => 'false',
                'create-drive-path' => '',
            ],
        ];

        $ch2 = curl_init("{$guac_url}/api/session/data/{$ds}/connections");
        curl_setopt_array($ch2, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Guacamole-Token: {$token}",
            ],
        ]);
        $resp2 = curl_exec($ch2);
        $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        $result['http'] = $code;
        $result['resposta'] = $resp2;

        if ($code >= 200 && $code < 300) {
            // Tenta extrair ID da resposta (formato: {"identifier":"2","name":"DC-01",...})
            $novo = json_decode($resp2, true);
            if (!empty($novo['identifier'])) {
                $result['id'] = (int)$novo['identifier'];
                return $result;
            }

            // Fallback: busca por nome
            $ch3 = curl_init("{$guac_url}/api/session/data/{$ds}/connections");
            curl_setopt_array($ch3, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => ["Guacamole-Token: {$token}"],
            ]);
            $res = curl_exec($ch3);
            curl_close($ch3);
            $list = json_decode($res, true) ?? [];
            foreach ($list as $id => $c) {
                if (($c['name'] ?? '') === $nome) {
                    $result['id'] = (int)$id;
                    return $result;
                }
            }
            $result['erro'] = 'Conexão criada mas não encontrou o ID';
        } else {
            $result['erro'] = 'Erro HTTP ' . $code . ' ao criar conexão';
        }
        return $result;
    }

    if ($action === 'add') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $n = trim($body['nome']??''); $i = trim($body['ip']??'');
        $d = trim($body['descricao']??''); $u = trim($body['usuario']??'');
        $s = $body['senha'] ?? ''; $g = $body['guac_id'] ?? null;
        $p = $body['protocolo'] ?? 'rdp';
        $c = $body['categoria']??'servidor';
        if (!in_array($p, ['rdp','vnc'])) $p = 'rdp';
        if (!$n||!$i) { echo json_encode(['ok'=>false,'msg'=>'Preencha nome e IP']); exit; }
        if (!in_array($c, ['servidor','coletor','pc'])) $c = 'servidor';
        $senha_enc = $s ? rdp_encrypt($s) : null;

        // Se não informou guac_id, tenta criar automaticamente no Guacamole
        $guac_log = '';
        if ($g === null || $g === '' || $g === 0) {
            $guac_url = rtrim(GUACAMOLE_URL, '/');

            // 1. Login
            $ch = curl_init($guac_url . '/api/tokens');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['username' => GUACAMOLE_USER, 'password' => GUACAMOLE_PASS]),
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http === 200 && $resp) {
                $auth = json_decode($resp, true);
                $token = $auth['authToken'] ?? '';
                $ds = $auth['dataSource'] ?? 'mysql';

                if ($token) {
                    // 2. Verifica se já existe conexão com esse nome
                    $ch3 = curl_init("{$guac_url}/api/session/data/{$ds}/connections");
                    curl_setopt_array($ch3, [
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                        CURLOPT_HTTPHEADER => ["Guacamole-Token: {$token}"],
                    ]);
                    $resLista = curl_exec($ch3);
                    curl_close($ch3);
                    $lista = json_decode($resLista, true) ?? [];
                    // Função auxiliar: monta payload RDP/VNC
                    $guac_payload = function() use ($n, $i, $u, $s, $p) {
                        $proto = $p ?? 'rdp';
                        $params = ($proto === 'vnc')
                            ? [
                                'hostname' => $i, 'port' => '5900',
                                'password' => $s, 'color-depth' => '16',
                                'read-only' => '', 'swap-red-blue' => '',
                                'cursor' => '',
                              ]
                            : [
                                'hostname' => $i, 'port' => '3389',
                                'username' => $u, 'password' => $s,
                                'ignore-cert' => 'true', 'security' => 'any',
                              ];
                        return [
                            'parentIdentifier' => 'ROOT',
                            'name' => $n,
                            'protocol' => $proto,
                            'attributes' => [
                                'max-connections' => '', 'max-connections-per-user' => '',
                                'weight' => '', 'failover-only' => '',
                                'guacd-hostname' => '', 'guacd-port' => '',
                            ],
                            'parameters' => $params,
                        ];
                    };

                    foreach ($lista as $connId => $conn) {
                        if (($conn['name'] ?? '') === $n) {
                            $g = (int)$connId;
                            $guac_log = 'guac_existente:' . $g;
                            // Atualiza parâmetros da conexão existente (IP/credenciais podem ter mudado)
                            $ch_upd = curl_init("{$guac_url}/api/session/data/{$ds}/connections/{$g}");
                            curl_setopt_array($ch_upd, [
                                CURLOPT_CUSTOMREQUEST => 'PUT',
                                CURLOPT_POSTFIELDS => json_encode($guac_payload()),
                                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                                CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Guacamole-Token: {$token}"],
                            ]);
                            curl_exec($ch_upd);
                            $upd_code = curl_getinfo($ch_upd, CURLINFO_HTTP_CODE);
                            curl_close($ch_upd);
                            $guac_log .= ($upd_code >= 200 && $upd_code < 300) ? '|atualizado' : '|upd_http_' . $upd_code;
                            break;
                        }
                    }

                    // 3. Se não existe, cria nova
                    if (!$g) {
                        $ch2 = curl_init("{$guac_url}/api/session/data/{$ds}/connections");
                        curl_setopt_array($ch2, [
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => json_encode($guac_payload()),
                            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Guacamole-Token: {$token}"],
                        ]);
                        $resp2 = curl_exec($ch2);
                        $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                        curl_close($ch2);

                        if ($code2 >= 200 && $code2 < 300) {
                            $novo = json_decode($resp2, true);
                            if (!empty($novo['identifier'])) {
                                $g = (int)$novo['identifier'];
                                $guac_log = 'guac_novo:' . $g;
                            } else {
                                $guac_log = 'guac_sem_id_na_resposta';
                            }
                        } else {
                            $guac_log = 'guac_http_' . $code2;
                        }
                    }
                } else {
                    $guac_log = 'guac_sem_token';
                }
            } else {
                $guac_log = 'guac_login_' . $http;
            }
        }

        $guac_val = ($g !== '' && $g !== null) ? (int)$g : null;
        $mo = $pdo->query("SELECT COALESCE(MAX(ordem),0)+1 FROM portal_rdp_maquinas")->fetchColumn();
        $pdo->prepare("INSERT INTO portal_rdp_maquinas (nome,ip,descricao,usuario,senha,protocolo,guac_id,categoria,ordem) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$n,$i,$d,$u,$senha_enc,$p,$guac_val,$c,$mo]);
        echo json_encode(['ok'=>true, 'id'=>$pdo->lastInsertId(), 'guac_id'=>$g, 'guac_auto'=>($g !== null && $g > 0), 'guac_log'=>$guac_log]); exit;
    }

    if ($action === 'edit') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id=(int)($body['id']??0); $n=trim($body['nome']??''); $i=trim($body['ip']??'');
        $d=trim($body['descricao']??''); $u=trim($body['usuario']??'');
        $s = $body['senha'] ?? ''; $g = $body['guac_id'] ?? null;
        $p = $body['protocolo'] ?? 'rdp';
        $c=$body['categoria']??'servidor';
        if (!in_array($p, ['rdp','vnc'])) $p = 'rdp';
        if (!$id||!$n||!$i) { echo json_encode(['ok'=>false,'msg'=>'Preencha nome e IP']); exit; }
        if (!in_array($c, ['servidor','coletor','pc'])) $c = 'servidor';

        // Se não tem guac_id, tenta buscar/criar no Guacamole
        $guac_log = '';
        if ($g === null || $g === '' || $g === 0) {
            $guac_url = rtrim(GUACAMOLE_URL, '/');
            $ch = curl_init($guac_url . '/api/tokens');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['username' => GUACAMOLE_USER, 'password' => GUACAMOLE_PASS]),
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http === 200 && $resp) {
                $auth = json_decode($resp, true);
                $token = $auth['authToken'] ?? '';
                $ds = $auth['dataSource'] ?? 'mysql';
                if ($token) {
                    // Função auxiliar: monta payload RDP/VNC
                    $guac_payload = function() use ($n, $i, $u, $s, $p) {
                        $proto = $p ?? 'rdp';
                        $params = ($proto === 'vnc')
                            ? [
                                'hostname' => $i, 'port' => '5900',
                                'password' => $s, 'color-depth' => '16',
                                'read-only' => '', 'swap-red-blue' => '',
                                'cursor' => '',
                              ]
                            : [
                                'hostname' => $i, 'port' => '3389',
                                'username' => $u, 'password' => $s,
                                'ignore-cert' => 'true', 'security' => 'any',
                              ];
                        return [
                            'parentIdentifier' => 'ROOT',
                            'name' => $n,
                            'protocol' => $proto,
                            'attributes' => [
                                'max-connections' => '', 'max-connections-per-user' => '',
                                'weight' => '', 'failover-only' => '',
                                'guacd-hostname' => '', 'guacd-port' => '',
                            ],
                            'parameters' => $params,
                        ];
                    };

                    // Verifica se já existe conexão com esse nome
                    $ch3 = curl_init("{$guac_url}/api/session/data/{$ds}/connections");
                    curl_setopt_array($ch3, [
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                        CURLOPT_HTTPHEADER => ["Guacamole-Token: {$token}"],
                    ]);
                    $resLista = curl_exec($ch3);
                    curl_close($ch3);
                    $lista = json_decode($resLista, true) ?? [];
                    foreach ($lista as $connId => $conn) {
                        if (($conn['name'] ?? '') === $n) {
                            $g = (int)$connId;
                            $guac_log = 'guac_existente:' . $g;
                            // Atualiza parâmetros
                            $ch_upd = curl_init("{$guac_url}/api/session/data/{$ds}/connections/{$g}");
                            curl_setopt_array($ch_upd, [
                                CURLOPT_CUSTOMREQUEST => 'PUT',
                                CURLOPT_POSTFIELDS => json_encode($guac_payload()),
                                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                                CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Guacamole-Token: {$token}"],
                            ]);
                            curl_exec($ch_upd);
                            $upd_code = curl_getinfo($ch_upd, CURLINFO_HTTP_CODE);
                            curl_close($ch_upd);
                            $guac_log .= ($upd_code >= 200 && $upd_code < 300) ? '|atualizado' : '|upd_http_' . $upd_code;
                            break;
                        }
                    }
                    if (!$g) {
                        $ch2 = curl_init("{$guac_url}/api/session/data/{$ds}/connections");
                        curl_setopt_array($ch2, [
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => json_encode($guac_payload()),
                            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Guacamole-Token: {$token}"],
                        ]);
                        $resp2 = curl_exec($ch2);
                        $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                        curl_close($ch2);
                        if ($code2 >= 200 && $code2 < 300) {
                            $novo = json_decode($resp2, true);
                            if (!empty($novo['identifier'])) {
                                $g = (int)$novo['identifier'];
                                $guac_log = 'guac_novo:' . $g;
                            }
                        } else {
                            $guac_log = 'guac_http_' . $code2;
                        }
                    }
                }
            }
        }

        $guac_val = ($g !== '' && $g !== null) ? (int)$g : null;
        if ($s !== '') {
            $senha_enc = $s ? rdp_encrypt($s) : null;
            $pdo->prepare("UPDATE portal_rdp_maquinas SET nome=?,ip=?,descricao=?,usuario=?,senha=?,protocolo=?,guac_id=?,categoria=? WHERE id=?")
                ->execute([$n,$i,$d,$u,$senha_enc,$p,$guac_val,$c,$id]);
        } else {
            $pdo->prepare("UPDATE portal_rdp_maquinas SET nome=?,ip=?,descricao=?,usuario=?,protocolo=?,guac_id=?,categoria=? WHERE id=?")
                ->execute([$n,$i,$d,$u,$p,$guac_val,$c,$id]);
        }
        echo json_encode(['ok'=>true, 'guac_id'=>$guac_val, 'guac_log'=>$guac_log, 'protocolo'=>$p]); exit;
    }

    if ($action === 'delete' && isset($_GET['id'])) {
        $del_id = (int)$_GET['id'];
        // Pega o guac_id antes de deletar
        $st_del = $pdo->prepare("SELECT guac_id FROM portal_rdp_maquinas WHERE id=?");
        $st_del->execute([$del_id]);
        $row_del = $st_del->fetch(PDO::FETCH_ASSOC);
        $del_guac = ($row_del && !empty($row_del['guac_id'])) ? (int)$row_del['guac_id'] : 0;

        // Deleta do banco local
        $pdo->prepare("DELETE FROM portal_rdp_maquinas WHERE id=?")->execute([$del_id]);

        // Deleta do Guacamole se existia
        $del_guac_log = '';
        if ($del_guac > 0) {
            $guac_url = rtrim(GUACAMOLE_URL, '/');
            $ch = curl_init($guac_url . '/api/tokens');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['username' => GUACAMOLE_USER, 'password' => GUACAMOLE_PASS]),
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http === 200 && $resp) {
                $auth = json_decode($resp, true);
                $token = $auth['authToken'] ?? '';
                $ds = $auth['dataSource'] ?? 'mysql';
                if ($token) {
                    $ch2 = curl_init("{$guac_url}/api/session/data/{$ds}/connections/{$del_guac}");
                    curl_setopt_array($ch2, [
                        CURLOPT_CUSTOMREQUEST => 'DELETE',
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                        CURLOPT_HTTPHEADER => ["Guacamole-Token: {$token}"],
                    ]);
                    curl_exec($ch2);
                    $del_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                    curl_close($ch2);
                    $del_guac_log = ($del_code >= 200 && $del_code < 300) ? 'guac_removido' : 'guac_del_http_' . $del_code;
                }
            }
        }
        echo json_encode(['ok'=>true, 'guac_del'=>$del_guac_log ?: 'sem_guac']); exit;
    }

    if ($action === 'batch') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!empty($body['itens'])) {
            $st = $pdo->prepare("UPDATE portal_rdp_maquinas SET ordem=? WHERE id=?");
            foreach ($body['itens'] as $idx => $item) {
                $st->execute([$idx, (int)$item['id']]);
            }
        }
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação inválida']); exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Central RDP</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root{--primary:#1d4ed8;}*{box-sizing:border-box;}
    body{background:#f0f4f9;font-family:'Segoe UI',sans-serif;min-height:100vh;margin:0;}
    .topbar{background:linear-gradient(135deg,#1e3a8a,var(--primary));color:white;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(0,0,0,.25);position:sticky;top:0;z-index:100;}
    .topbar .brand{font-weight:700;font-size:1rem;display:flex;align-items:center;gap:.5rem;}
    .topbar a{color:white;text-decoration:none;font-size:.82rem;background:rgba(255,255,255,.15);border-radius:6px;padding:.3rem .75rem;}
    .topbar a:hover{background:rgba(255,255,255,.25);}
    .hero{background:linear-gradient(135deg,#1e3a8a,var(--primary));color:white;padding:2rem 1rem 4rem;text-align:center;}
    .hero h1{font-size:1.5rem;font-weight:700;margin:0}
    .hero p{opacity:.8;margin-top:.5rem}
    .wrap{max-width:900px;margin:-2.5rem auto 3rem;padding:0 1rem;}
    section{margin-bottom:2rem;}
    .section-header{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border-radius:10px;margin-bottom:0;font-weight:700;font-size:.95rem;cursor:pointer;user-select:none;transition:opacity .1s;}
    .section-header:hover{opacity:.85;}
    .section-header .badge-cat{background:rgba(255,255,255,.25);border-radius:20px;padding:.15rem .6rem;font-size:.7rem;font-weight:400;}
    .section-header .chevron{font-size:.65rem;margin-left:auto;transition:transform .2s;}
    .section-header.expanded{border-radius:10px 10px 0 0;}
    .section-header.expanded .chevron{transform:rotate(180deg);}
    .section-body{overflow:hidden;max-height:0;transition:max-height .35s ease;margin-bottom:.75rem;}
    .section-body.open{max-height:5000px;}
    .section-body-inner{padding-top:.5rem;}
    .maq-card{background:white;border-radius:10px;border:1px solid #e5e7eb;padding:.9rem 1.25rem;margin-bottom:.5rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;transition:box-shadow .15s;}
    .maq-card:hover{box-shadow:0 3px 12px rgba(0,0,0,.07);}
    .maq-info{display:flex;align-items:center;gap:.9rem;flex:1;min-width:0;}
    .maq-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
    .maq-nome{font-weight:700;font-size:.95rem;color:#111;}
    .maq-ip{font-size:.78rem;color:#6b7280;font-family:Consolas,monospace;margin-top:-1px;}
    .maq-desc{font-size:.78rem;color:#9ca3af;margin-top:1px;}
    .maq-actions{display:flex;align-items:center;gap:.4rem;flex-shrink:0;}
    .btn-rdp{border:none;border-radius:7px;padding:.4rem .8rem;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:.3rem;white-space:nowrap;}
    .btn-rdp:hover{filter:brightness(1.1);transform:translateY(-1px);}
    .btn-auto{background:#059669;color:white;}
    .btn-auto:hover{background:#047857;}
    .btn-manual{background:#6b7280;color:white;}
    .btn-manual:hover{background:#4b5563;}
    .btn-config{background:transparent;border:none;color:#9ca3af;cursor:pointer;padding:.3rem;border-radius:6px;font-size:.85rem;}
    .btn-config:hover{background:#f3f4f6;color:#374151;}
    .card-add{border:2px dashed #d1d5db;background:transparent;border-radius:10px;padding:1.25rem;text-align:center;color:#9ca3af;cursor:pointer;margin-bottom:.5rem;transition:all .15s;}
    .card-add:hover{border-color:#6b7280;color:#374151;background:#f9fafb;}
    .filtro-bar{display:flex;align-items:center;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap;}
    .filtro-bar .btn-filtro{border:1px solid #d1d5db;background:white;border-radius:20px;padding:.3rem .85rem;font-size:.78rem;cursor:pointer;transition:all .12s;color:#374151;display:flex;align-items:center;gap:.35rem;}
    .filtro-bar .btn-filtro:hover{border-color:#6b7280;background:#f9fafb;}
    .filtro-bar .btn-filtro.ativo{border-color:var(--primary);background:#dbeafe;color:#1d4ed8;font-weight:600;}
    .filtro-bar .btn-filtro .qtd{border-radius:10px;background:rgba(0,0,0,.08);padding:.05rem .4rem;font-size:.65rem;margin-left:.2rem;}
    .stats-row{display:flex;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap;}
    .stat-pill{background:white;border:1px solid #e5e7eb;border-radius:8px;padding:.4rem .85rem;font-size:.78rem;display:flex;align-items:center;gap:.4rem;}
    #toast-container{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;}
    .badge-senha{display:inline-block;background:#fef3c7;color:#92400e;border-radius:10px;padding:.08rem .45rem;font-size:.6rem;font-weight:600;margin-left:.35rem;vertical-align:middle;}
    .badge-proto{display:inline-block;border-radius:8px;padding:.08rem .4rem;font-size:.58rem;font-weight:700;margin-left:.35rem;vertical-align:middle;letter-spacing:.3px;}
    .badge-rdp{background:#dbeafe;color:#1d4ed8;}
    .badge-vnc{background:#ede9fe;color:#5b21b6;}
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-display-fill me-1"></i> Central RDP</div>
  <div style="display:flex;gap:.5rem">
    <?php if ($is_admin): ?>
    <button onclick="abrirModal()" style="background:rgba(255,255,255,.15);border:none;color:white;border-radius:6px;padding:.3rem .75rem;font-size:.82rem;cursor:pointer"><i class="bi bi-plus-lg me-1"></i>Nova Máquina</button>
    <?php endif; ?>
    <a href="acessos.php"><i class="bi bi-grid-3x3-gap-fill me-1"></i>Acessos</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>
<div class="hero">
  <h1><i class="bi bi-display-fill me-2"></i>Área de Trabalho Remota</h1>
  <p>Clique em <strong>Conectar</strong> para acesso imediato sem digitar senha</p>
</div>

<div class="wrap" id="app">
  <div class="stats-row" id="stats"></div>
  <div class="filtro-bar" id="filtro-bar"></div>
  <div id="lista-categorias"></div>
</div>

<!-- Modal CRUD -->
<div class="modal fade" id="modalMaq" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8);color:white">
        <h5 class="modal-title fw-bold" id="modal-label"><i class="bi bi-display-fill me-2"></i>Nova Máquina</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-id"/>
        <div class="mb-3"><label class="form-label fw-semibold">Nome</label><input type="text" class="form-control" id="edit-nome" placeholder="SRV-APP"/></div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Protocolo <span class="text-muted small">(tipo de acesso)</span></label>
          <select class="form-select" id="edit-protocolo" onchange="toggleProtocolo()">
            <option value="rdp">RDP — Remote Desktop (Windows)</option>
            <option value="vnc">VNC — Virtual Network Computing (Linux/qualquer)</option>
          </select>
        </div>
        <div class="mb-3"><label class="form-label fw-semibold">IP / Hostname</label><input type="text" class="form-control font-monospace" id="edit-ip" placeholder="192.168.1.x"/></div>
        <div class="mb-3"><label class="form-label fw-semibold">Descrição <span class="text-muted small">(opcional)</span></label><input type="text" class="form-control" id="edit-desc" placeholder="Servidor de aplicações..."/></div>
        <div class="mb-3" id="campo-usuario">
          <label class="form-label fw-semibold">Usuário <span class="text-muted small">(para login automático)</span></label>
          <input type="text" class="form-control" id="edit-usuario" placeholder="marcos@grupogmais"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Senha <span class="text-muted small">(criptografada — para login automático)</span></label>
          <div class="input-group">
            <input type="password" class="form-control" id="edit-senha" placeholder="Deixe em branco para não salvar"/>
            <button class="btn btn-outline-secondary" type="button" onclick="toggleSenha()" style="font-size:.75rem"><i class="bi bi-eye-fill"></i></button>
          </div>
          <div class="form-text text-muted small" id="senha-help">Senha fica criptografada no banco. Só é descriptografada na hora de gerar o lançador automático.</div>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Categoria</label>
          <select class="form-select" id="edit-categoria">
            <option value="servidor">Servidor</option>
            <option value="coletor">Coletor</option>
            <option value="pc">PC Estratégico</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">ID Conexão Guacamole <span class="text-muted small">(opcional)</span></label>
          <input type="number" class="form-control" id="edit-guac-id" placeholder="Deixe 0 se não tiver" min="0"/>
          <div class="form-text text-muted small">ID da conexão criada no Guacamole para esta máquina.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="salvar()" style="background:#1d4ed8;border-color:#1d4ed8"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </div>
  </div>
</div>
<div id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CATS = <?= json_encode($cats) ?>;
let modalMaq;
let filtroAtivo = '';
const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', () => {
  modalMaq = new bootstrap.Modal(document.getElementById('modalMaq'));
  carregar();
});

function getLabel(cat) { return CATS[cat]?.label || cat; }
function getIcon(cat) { return CATS[cat]?.icon || 'bi-display'; }
function getBg(cat) { return CATS[cat]?.bg || '#6b7280'; }
function getColor(cat) { return CATS[cat]?.color || '#f3f4f6'; }
function getBadgeText(cat) { return CATS[cat]?.['badge-text'] || '#374151'; }
function getBadge(cat) { return CATS[cat]?.badge || '#e5e7eb'; }

async function carregar() {
  const url = filtroAtivo ? 'rdp_central.php?action=list&categoria=' + filtroAtivo : 'rdp_central.php?action=list';
  const r = await fetch(url), d = await r.json();
  const maqs = d.dados || [];
  renderStats(maqs);
  renderFiltro(maqs);
  renderLista(maqs);
}

function renderStats(maqs) {
  const total = maqs.length;
  const cats = ['servidor','coletor','pc'];
  const qtds = {};
  cats.forEach(c => qtds[c] = 0);
  maqs.forEach(m => qtds[m.categoria] = (qtds[m.categoria]||0) + 1);
  let h = `<div class="stat-pill"><i class="bi bi-display-fill text-primary"></i>${total} máquina(s)</div>`;
  cats.forEach(c => {
    if (qtds[c]) h += `<div class="stat-pill"><i class="${getIcon(c)}" style="color:${getBg(c)}"></i>${getLabel(c)}: ${qtds[c]}</div>`;
  });
  document.getElementById('stats').innerHTML = h;
}

function renderFiltro(maqs) {
  const cats = ['servidor','coletor','pc'];
  let h = `<button class="btn-filtro ${!filtroAtivo ? 'ativo' : ''}" onclick="setFiltro('')"><i class="bi bi-funnel"></i>Todas</button>`;
  cats.forEach(c => {
    const qtd = maqs.filter(m => m.categoria === c).length;
    h += `<button class="btn-filtro ${filtroAtivo === c ? 'ativo' : ''}" onclick="setFiltro('${c}')"><i class="${getIcon(c)}"></i>${getLabel(c)}<span class="qtd">${qtd}</span></button>`;
  });
  document.getElementById('filtro-bar').innerHTML = h;
}

function setFiltro(cat) {
  filtroAtivo = cat;
  carregar();
}

function renderLista(maqs) {
  const cats = ['servidor','coletor','pc'];
  const agrupado = {};
  cats.forEach(c => agrupado[c] = []);
  maqs.forEach(m => { if (agrupado[m.categoria]) agrupado[m.categoria].push(m); });

  if (typeof window._catAberto === 'undefined') window._catAberto = {};
  const aberto = window._catAberto;

  const el = document.getElementById('lista-categorias');
  let html = '';
  cats.forEach(c => {
    const itens = agrupado[c];
    if (!itens.length) return;
    const isOpen = aberto[c] === true; // default: fechado
    html += `<section>
      <div class="section-header ${isOpen?'expanded':''}" style="background:${getColor(c)};color:${getBg(c)}" onclick="toggleCategoria('${c}')">
        <i class="${getIcon(c)}"></i>${getLabel(c)}
        <span class="badge-cat" style="background:${getBadge(c)};color:${getBadgeText(c)}">${itens.length}</span>
        <span class="chevron">${isOpen?'▴':'▾'}</span>
      </div>
      <div class="section-body ${isOpen?'open':''}" id="corpo-${c}">
        <div class="section-body-inner">`;
    itens.forEach(m => {
      const temSenha = m.has_senha == 1;
      const temGuac = m.guac_id > 0;
      html += `<div class="maq-card" id="maq-${m.id}">
        <div class="maq-info">
          <div class="maq-icon" style="background:${getColor(c)};color:${getBg(c)}"><i class="${getIcon(c)}"></i></div>
          <div>
            <div class="maq-nome">${esc(m.nome)} ${temSenha ? '<span class="badge-senha"><i class="bi bi-lock-fill"></i> auto</span>' : ''} ${m.protocolo === 'vnc' ? '<span class="badge-proto badge-vnc">VNC</span>' : '<span class="badge-proto badge-rdp">RDP</span>'}</div>
            <div class="maq-ip">${esc(m.ip)}</div>
            ${m.descricao ? `<div class="maq-desc">${esc(m.descricao)}</div>` : ''}
            ${m.usuario ? `<div class="maq-desc" style="color:#6b7280"><i class="bi bi-person-fill me-1"></i>${esc(m.usuario)}</div>` : ''}
            ${temGuac ? `<div class="maq-desc" style="color:#059669"><i class="bi bi-globe2 me-1"></i>Guacamole</div>` : ''}
          </div>
        </div>
        <div class="maq-actions">
          ${temGuac
            ? `<a class="btn-rdp btn-auto" href="guacamole_conectar.php?id=${m.id}"><i class="bi bi-lightning-charge-fill"></i>Conectar</a>`
            : (temSenha
                ? `<a class="btn-rdp btn-manual" href="rdp_central.php?action=launcher&id=${m.id}"><i class="bi bi-download"></i>Conectar (.bat)</a>`
                : `<a class="btn-rdp btn-manual" href="rdp_central.php?action=rdp&id=${m.id}"><i class="bi bi-download"></i>Conectar (.rdp)</a>`)
          }
          ${isAdmin ? `<button class="btn-config" onclick="editar(${m.id})"><i class="bi bi-pencil-fill"></i></button><button class="btn-config" onclick="excluir(${m.id})" style="color:#ef4444"><i class="bi bi-trash-fill"></i></button>` : ''}
        </div>
      </div>`;
    });
    html += `</div></div></section>`;
  });

  if (isAdmin) {
    html += `<div class="card-add" onclick="abrirModal()"><i class="bi bi-plus-circle" style="font-size:1.3rem;display:block;margin-bottom:.35rem"></i><strong>Adicionar nova máquina</strong></div>`;
  }

  if (!maqs.length) {
    html = `<div style="text-align:center;padding:3rem 1rem;color:#9ca3af"><i class="bi bi-pc-display-horizontal" style="font-size:3rem;display:block;margin-bottom:1rem"></i><p>Nenhuma máquina cadastrada.</p>${isAdmin ? '<button class="btn btn-primary btn-sm" onclick="abrirModal()" style="background:#1d4ed8;border-color:#1d4ed8">Adicionar</button>' : ''}</div>`;
  }

  el.innerHTML = html;
}

function toggleCategoria(cat) {
  if (typeof window._catAberto === 'undefined') window._catAberto = {};
  const aberto = window._catAberto;
  aberto[cat] = !aberto[cat];
  const corpo = document.getElementById('corpo-' + cat);
  if (corpo) corpo.classList.toggle('open');
  // Atualiza chevron no header
  const secao = corpo?.closest('section');
  if (secao) {
    const hdr = secao.querySelector('.section-header');
    if (hdr) {
      hdr.classList.toggle('expanded');
      const ch = hdr.querySelector('.chevron');
      if (ch) ch.textContent = aberto[cat] ? '▴' : '▾';
    }
  }
}

function abrirModal() {
  ['edit-id','edit-nome','edit-ip','edit-desc','edit-usuario','edit-senha','edit-guac-id'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('edit-protocolo').value = 'rdp';
  document.getElementById('edit-categoria').value = 'servidor';
  document.getElementById('modal-label').innerHTML = '<i class="bi bi-plus-circle-fill me-2"></i>Nova Máquina';
  toggleProtocolo();
  modalMaq.show();
}

async function editar(id) {
  const r = await fetch('rdp_central.php?action=list'), d = await r.json();
  const item = (d.dados||[]).find(x => x.id == id);
  if (!item) { toast('Máquina não encontrada', 'danger'); return; }
  document.getElementById('edit-id').value = id;
  document.getElementById('edit-nome').value = item.nome;
  document.getElementById('edit-ip').value = item.ip;
  document.getElementById('edit-desc').value = item.descricao || '';
  document.getElementById('edit-usuario').value = item.usuario || '';
  document.getElementById('edit-senha').value = '';
  document.getElementById('edit-guac-id').value = item.guac_id || '0';
  document.getElementById('edit-protocolo').value = item.protocolo || 'rdp';
  document.getElementById('edit-categoria').value = item.categoria;
  document.getElementById('modal-label').innerHTML = '<i class="bi bi-pencil-fill me-2"></i>' + esc(item.nome);
  toggleProtocolo();
  if (item.has_senha == 1) {
    document.getElementById('edit-senha').placeholder = '🔒 Mantenha em branco para não alterar';
  }
  modalMaq.show();
}

async function salvar() {
  const id = document.getElementById('edit-id').value;
  const nome = document.getElementById('edit-nome').value.trim();
  const ip = document.getElementById('edit-ip').value.trim();
  const desc = document.getElementById('edit-desc').value.trim();
  const usuario = document.getElementById('edit-usuario').value.trim();
  const senha = document.getElementById('edit-senha').value;
  const guac_id = document.getElementById('edit-guac-id').value.trim();
  const protocolo = document.getElementById('edit-protocolo').value;
  const categoria = document.getElementById('edit-categoria').value;
  if (!nome || !ip) { toast('Preencha nome e IP', 'danger'); return; }
  const action = id ? 'edit' : 'add';
  const r = await fetch('rdp_central.php?action=' + action, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id: parseInt(id)||0, nome, ip, descricao: desc, usuario, senha, guac_id: parseInt(guac_id)||null, protocolo, categoria})
  });
  const d = await r.json();
  if (d.ok) {
    modalMaq.hide();
    if (d.guac_auto) toast('✅ ' + (id ? 'Atualizada!' : 'Adicionada!') + ' Guacamole (' + d.guac_log + ')', 'success');
    else if (d.guac_log && d.guac_log.indexOf('http_') > -1) toast('⚠️ ' + (id ? 'Atualizada' : 'Adicionada') + ' — Guacamole: ' + d.guac_log, 'warning');
    else if (d.guac_log && d.guac_log.indexOf('sem_') > -1) toast('⚠️ ' + (id ? 'Atualizada' : 'Adicionada') + ' — Guacamole: ' + d.guac_log, 'warning');
    else if (!id) toast('✅ Adicionada!', 'success');
    else toast('✅ Atualizada!', 'success');
    carregar();
  } else toast(d.msg || 'Erro', 'danger');
}

async function excluir(id) {
  if (!confirm('Excluir esta máquina?')) return;
  const r = await fetch('rdp_central.php?action=delete&id=' + id), d = await r.json();
  if (d.ok) { toast('🗑️ Excluída'); carregar(); }
  else toast(d.msg || 'Erro', 'danger');
}

function toggleSenha() {
  const el = document.getElementById('edit-senha');
  el.type = el.type === 'password' ? 'text' : 'password';
}

function toggleProtocolo() {
  const proto = document.getElementById('edit-protocolo').value;
  const campoUser = document.getElementById('campo-usuario');
  const helpSenha = document.getElementById('senha-help');
  if (proto === 'vnc') {
    campoUser.style.display = 'none';
    if (helpSenha) helpSenha.textContent = 'Senha VNC (opcional — se deixar em branco, o Guacamole vai pedir ao conectar).';
  } else {
    campoUser.style.display = '';
    if (helpSenha) helpSenha.textContent = 'Senha fica criptografada no banco. Só é descriptografada na hora de gerar o lançador automático.';
  }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function toast(msg, type = 'success') {
  const id = 't-' + Date.now(), bg = type === 'success' ? 'bg-success' : 'bg-danger';
  document.getElementById('toast-container').insertAdjacentHTML('beforeend',
    `<div id="${id}" class="toast align-items-center text-white ${bg} border-0 show mb-2"><div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="document.getElementById('${id}').remove()"></button></div></div>`);
  setTimeout(() => document.getElementById(id)?.remove(), 4000);
}
</script>
</body>
</html>
