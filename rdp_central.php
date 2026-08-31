<?php
require_once __DIR__ . '/auth_guard.php';
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
// Compatibilidade: registros antigos sem protocolo viram rdp
try { $pdo->exec("UPDATE portal_rdp_maquinas SET protocolo='rdp' WHERE protocolo IS NULL"); } catch (Exception $e) {}

// ── Tabela de grupos RDP (criar ANTES dos exemplos) ────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_rdp_grupos (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nome      VARCHAR(60) NOT NULL UNIQUE,
        icone     VARCHAR(40) DEFAULT 'bi-display',
        cor_bg    VARCHAR(20) DEFAULT '#1e3a8a',
        cor_fundo VARCHAR(20) DEFAULT '#dbeafe',
        cor_badge VARCHAR(20) DEFAULT '#dbeafe',
        cor_text  VARCHAR(20) DEFAULT '#1e3a8a',
        ordem     INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Popula defaults se vazio
if (!$pdo->query("SELECT COUNT(*) FROM portal_rdp_grupos")->fetchColumn()) {
    $ins = $pdo->prepare("INSERT INTO portal_rdp_grupos (nome,icone,cor_bg,cor_fundo,cor_badge,cor_text,ordem) VALUES (?,?,?,?,?,?,?)");
    $ins->execute(['Servidores','bi-server','#1e3a8a','#dbeafe','#dbeafe','#1e3a8a',1]);
    $ins->execute(['Coletores','bi-cpu','#065f46','#d1fae5','#d1fae5','#065f46',2]);
    $ins->execute(['PCs Estratégicos','bi-pc-display','#7c3aed','#ede9fe','#ede9fe','#7c3aed',3]);
}

// ── Se tabela vazia, insere exemplos ────────────────────────────
if ($pdo->query("SELECT COUNT(*) FROM portal_rdp_maquinas")->fetchColumn() == 0) {
    $st = $pdo->prepare("INSERT INTO portal_rdp_maquinas (nome,ip,descricao,usuario,categoria,ordem) VALUES (?,?,?,?,?,?)");
    $exemplos = [
        ['DC-01',           '192.168.1.10', 'Controlador de Domínio',       '',  'Servidores',     1],
        ['SRV-APP',         '192.168.1.11', 'Servidor de Aplicações',       '',  'Servidores',     2],
        ['SQL-SERVER',      '192.168.1.12', 'Banco de Dados SQL',           '',  'Servidores',     3],
        ['COLETOR-01',      '192.168.1.20', 'Coletor de Dados NFE',          '',  'Coletores',      1],
        ['COLETOR-02',      '192.168.1.21', 'Coletor SAT / PDV',            '',  'Coletores',      2],
        ['PC-GERENCIA',     '192.168.1.30', 'Micro da Gerência',            '',  'PCs Estratégicos',1],
        ['PC-TI-DIAG',      '192.168.1.31', 'Micro do Suporte TI (WOL)',    '',  'PCs Estratégicos',2],
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
        ->execute(['TS - Marcos','192.168.1.116','Micro do Marcos','marcos@grupogmais','PCs Estratégicos',3,1]);
}

// ── Migra categorias antigas (lowercase) para os novos grupos ──
$pdo->exec("UPDATE portal_rdp_maquinas SET categoria='Servidores' WHERE (protocolo='rdp' OR protocolo IS NULL) AND categoria='servidor'");
$pdo->exec("UPDATE portal_rdp_maquinas SET categoria='Coletores' WHERE (protocolo='rdp' OR protocolo IS NULL) AND categoria='coletor'");
$pdo->exec("UPDATE portal_rdp_maquinas SET categoria='PCs Estratégicos' WHERE (protocolo='rdp' OR protocolo IS NULL) AND categoria='pc'");

// ── Carrega grupos do banco ────────────────────────────────────────
$grupos_rows = $pdo->query("SELECT * FROM portal_rdp_grupos ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
$cats = [];
$cat_lista = [];
foreach ($grupos_rows as $gr) {
    $key = $gr['nome'];
    $cats[$key] = [
        'label'      => $gr['nome'],
        'icon'       => $gr['icone'],
        'bg'         => $gr['cor_bg'],
        'color'      => $gr['cor_fundo'],
        'badge'      => $gr['cor_badge'],
        'badge-text' => $gr['cor_text'],
    ];
    $cat_lista[] = $key;
}

// ── AJAX ────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {

    // ── List (NUNCA retorna a senha) ─────────────────────────
    if ($action === 'list') {
        $categoria = $_GET['categoria'] ?? '';
        $sql = "SELECT id, nome, ip, descricao, usuario,
                       CASE WHEN senha IS NOT NULL AND senha != '' THEN 1 ELSE 0 END as has_senha,
                       categoria, ordem, guac_id
                FROM portal_rdp_maquinas WHERE ativo=1 AND (protocolo='rdp' OR protocolo IS NULL)";
        $params = [];
        if ($categoria && in_array($categoria, $cat_lista)) {
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
        $c = $body['categoria']??($cat_lista[0]??'Servidores');
        if (!$n||!$i) { echo json_encode(['ok'=>false,'msg'=>'Preencha nome e IP']); exit; }
        if (!in_array($c, $cat_lista)) $c = $cat_lista[0]??'Servidores';
        $senha_enc = $s ? rdp_encrypt($s) : null;

        // Se não informou guac_id, tenta criar automaticamente no Guacamole
        $guac_log = '';
        if (false) { // Guacamole desativado — RDP é sempre nativo (.rdp / mstsc) agora
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
                    // Função auxiliar: monta payload RDP
                    $guac_payload = function() use ($n, $i, $u, $s) {
                        return [
                            'parentIdentifier' => 'ROOT',
                            'name' => $n,
                            'protocol' => 'rdp',
                            'attributes' => [
                                'max-connections' => '', 'max-connections-per-user' => '',
                                'weight' => '', 'failover-only' => '',
                                'guacd-hostname' => '', 'guacd-port' => '',
                            ],
                            'parameters' => [
                                'hostname' => $i, 'port' => '3389',
                                'username' => $u, 'password' => $s,
                                'ignore-cert' => 'true', 'security' => 'any',
                            ],
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
        $mo = $pdo->query("SELECT COALESCE(MAX(ordem),0)+1 FROM portal_rdp_maquinas WHERE protocolo='rdp' OR protocolo IS NULL")->fetchColumn();
        $pdo->prepare("INSERT INTO portal_rdp_maquinas (nome,ip,descricao,usuario,senha,guac_id,categoria,ordem) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$n,$i,$d,$u,$senha_enc,$guac_val,$c,$mo]);
        echo json_encode(['ok'=>true, 'id'=>$pdo->lastInsertId(), 'guac_id'=>$g, 'guac_auto'=>($g !== null && $g > 0), 'guac_log'=>$guac_log]); exit;
    }

    if ($action === 'edit') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id=(int)($body['id']??0); $n=trim($body['nome']??''); $i=trim($body['ip']??'');
        $d=trim($body['descricao']??''); $u=trim($body['usuario']??'');
        $s = $body['senha'] ?? ''; $g = $body['guac_id'] ?? null;
        $c=$body['categoria']??($cat_lista[0]??'Servidores');
        if (!$id||!$n||!$i) { echo json_encode(['ok'=>false,'msg'=>'Preencha nome e IP']); exit; }
        if (!in_array($c, $cat_lista)) $c = $cat_lista[0]??'Servidores';

        // Se não tem guac_id, tenta buscar/criar no Guacamole
        $guac_log = '';
        if (false) { // Guacamole desativado — RDP é sempre nativo (.rdp / mstsc) agora
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
                    // Função auxiliar: monta payload RDP
                    $guac_payload = function() use ($n, $i, $u, $s) {
                        return [
                            'parentIdentifier' => 'ROOT',
                            'name' => $n,
                            'protocol' => 'rdp',
                            'attributes' => [
                                'max-connections' => '', 'max-connections-per-user' => '',
                                'weight' => '', 'failover-only' => '',
                                'guacd-hostname' => '', 'guacd-port' => '',
                            ],
                            'parameters' => [
                                'hostname' => $i, 'port' => '3389',
                                'username' => $u, 'password' => $s,
                                'ignore-cert' => 'true', 'security' => 'any',
                            ],
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
            $pdo->prepare("UPDATE portal_rdp_maquinas SET nome=?,ip=?,descricao=?,usuario=?,senha=?,guac_id=?,categoria=? WHERE id=?")
                ->execute([$n,$i,$d,$u,$senha_enc,$guac_val,$c,$id]);
        } else {
            $pdo->prepare("UPDATE portal_rdp_maquinas SET nome=?,ip=?,descricao=?,usuario=?,guac_id=?,categoria=? WHERE id=?")
                ->execute([$n,$i,$d,$u,$guac_val,$c,$id]);
        }
        echo json_encode(['ok'=>true, 'guac_id'=>$guac_val, 'guac_log'=>$guac_log]); exit;
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

    // ── Listar grupos ────────────────────────────────────────
    if ($action === 'list_grupos') {
        $rows = $pdo->query("SELECT * FROM portal_rdp_grupos ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true, 'dados'=>$rows]); exit;
    }

    // ── Salvar grupo ────────────────────────────────────────
    if ($action === 'save_grupo') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($body['id'] ?? 0);
        $nome = trim($body['nome'] ?? '');
        $icone = trim($body['icone'] ?? 'bi-display');
        $cor_bg = trim($body['cor_bg'] ?? '#1e3a8a');
        $cor_fundo = trim($body['cor_fundo'] ?? '#dbeafe');
        $cor_badge = trim($body['cor_badge'] ?? '#dbeafe');
        $cor_text = trim($body['cor_text'] ?? '#1e3a8a');
        if (!$nome) { echo json_encode(['ok'=>false,'msg'=>'Nome obrigatório']); exit; }
        if ($id) {
            $st = $pdo->prepare("UPDATE portal_rdp_grupos SET nome=?,icone=?,cor_bg=?,cor_fundo=?,cor_badge=?,cor_text=? WHERE id=?");
            $st->execute([$nome,$icone,$cor_bg,$cor_fundo,$cor_badge,$cor_text,$id]);
        } else {
            $st = $pdo->prepare("INSERT INTO portal_rdp_grupos (nome,icone,cor_bg,cor_fundo,cor_badge,cor_text) VALUES (?,?,?,?,?,?)");
            $st->execute([$nome,$icone,$cor_bg,$cor_fundo,$cor_badge,$cor_text]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    // ── Excluir grupo ────────────────────────────────────────
    if ($action === 'delete_grupo') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'ID obrigatório']); exit; }
        $st = $pdo->prepare("SELECT nome FROM portal_rdp_grupos WHERE id=?");
        $st->execute([$id]);
        $g = $st->fetch();
        if (!$g) { echo json_encode(['ok'=>false,'msg'=>'Grupo não encontrado']); exit; }
        // Move máquinas para o primeiro grupo disponível
        $primeiro = $pdo->query("SELECT nome FROM portal_rdp_grupos WHERE id!={$id} ORDER BY ordem, nome LIMIT 1")->fetchColumn();
        if ($primeiro) {
            $pdo->prepare("UPDATE portal_rdp_maquinas SET categoria=? WHERE categoria=?")->execute([$primeiro, $g['nome']]);
        }
        $pdo->prepare("DELETE FROM portal_rdp_grupos WHERE id=?")->execute([$id]);
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
  <div class="d-flex justify-content-between align-items-start flex-wrap" style="gap:.5rem;margin-bottom:.5rem">
    <div class="stats-row" id="stats"></div>
    <button class="btn btn-sm btn-outline-secondary" onclick="abrirModalGrupo()" title="Gerenciar grupos RDP"><i class="bi bi-tags-fill me-1"></i>Grupos</button>
  </div>
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
        <div class="mb-3"><label class="form-label fw-semibold">IP / Hostname</label><input type="text" class="form-control font-monospace" id="edit-ip" placeholder="192.168.1.x"/></div>
        <div class="mb-3"><label class="form-label fw-semibold">Descrição <span class="text-muted small">(opcional)</span></label><input type="text" class="form-control" id="edit-desc" placeholder="Servidor de aplicações..."/></div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Usuário <span class="text-muted small">(para login automático)</span></label>
          <input type="text" class="form-control" id="edit-usuario" placeholder="marcos@grupogmais"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Senha <span class="text-muted small">(criptografada — para login automático)</span></label>
          <div class="input-group">
            <input type="password" class="form-control" id="edit-senha" placeholder="Deixe em branco para não salvar"/>
            <button class="btn btn-outline-secondary" type="button" onclick="toggleSenha()" style="font-size:.75rem"><i class="bi bi-eye-fill"></i></button>
          </div>
          <div class="form-text text-muted small">Senha fica criptografada no banco. Só é descriptografada na hora de gerar o lançador automático.</div>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Categoria</label>
          <div class="input-group">
            <select class="form-select" id="edit-categoria"></select>
            <button class="btn btn-outline-secondary" type="button" onclick="abrirModalGrupo()" title="Gerenciar grupos RDP"><i class="bi bi-gear-fill"></i></button>
          </div>
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

<!-- ── Modal Gerenciar Grupos RDP ── -->
<div class="modal fade" id="modalGrupos" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1e3a8a,#0f172a);color:white">
        <h5 class="modal-title fw-bold"><i class="bi bi-tags-fill me-2"></i>Gerenciar Grupos RDP</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="grupo-list">
        <p class="text-muted small mb-3">Os grupos aparecem como abas na tela principal. Máquinas de um grupo excluído são movidas para o primeiro grupo.</p>
        <div id="grupos-container"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        <button class="btn btn-primary" onclick="salvarGrupo()" style="background:#1d4ed8;border-color:#1d4ed8"><i class="bi bi-plus-circle me-1"></i>Novo Grupo</button>
      </div>
    </div>
  </div>
</div>

<div id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CATS = <?= json_encode($cats) ?>;
let GRUPOS = [];
let modalMaq, modalGrupos;
let filtroAtivo = '';
const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', () => {
  modalMaq = new bootstrap.Modal(document.getElementById('modalMaq'));
  if (document.getElementById('modalGrupos')) modalGrupos = new bootstrap.Modal(document.getElementById('modalGrupos'));
  carregarTudo();
});

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function escAttr(s) { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function getLabel(cat) { return CATS[cat]?.label || cat; }
function getIcon(cat) { return CATS[cat]?.icon || 'bi-display'; }
function getBg(cat) { return CATS[cat]?.bg || '#6b7280'; }
function getColor(cat) { return CATS[cat]?.color || '#f3f4f6'; }
function getBadgeText(cat) { return CATS[cat]?.['badge-text'] || '#374151'; }
function getBadge(cat) { return CATS[cat]?.badge || '#e5e7eb'; }

async function carregarTudo() {
  await carregarGrupos();
  await carregarMaquinas();
}

async function carregarGrupos() {
  const r = await fetch('rdp_central.php?action=list_grupos');
  const d = await r.json();
  GRUPOS = d.dados || [];
  renderizarSelectCategoria();
}

function renderizarSelectCategoria() {
  const sel = document.getElementById('edit-categoria');
  if (!sel) return;
  sel.innerHTML = GRUPOS.map(g => `<option value="${escAttr(g.nome)}">${esc(g.nome)}</option>`).join('');
}

async function carregarMaquinas() {
  const url = filtroAtivo ? 'rdp_central.php?action=list&categoria=' + filtroAtivo : 'rdp_central.php?action=list';
  const r = await fetch(url), d = await r.json();
  const maqs = d.dados || [];
  renderStats(maqs);
  renderFiltro(maqs);
  renderLista(maqs);
}

function renderStats(maqs) {
  const total = maqs.length;
  const qtds = {};
  GRUPOS.forEach(g => qtds[g.nome] = 0);
  maqs.forEach(m => qtds[m.categoria] = (qtds[m.categoria]||0) + 1);
  let h = `<div class="stat-pill"><i class="bi bi-display-fill text-primary"></i>${total} máquina(s)</div>`;
  GRUPOS.forEach(g => {
    const c = g.nome;
    if (qtds[c]) h += `<div class="stat-pill"><i class="${getIcon(c)}" style="color:${getBg(c)}"></i>${getLabel(c)}: ${qtds[c]}</div>`;
  });
  document.getElementById('stats').innerHTML = h;
}

function renderFiltro(maqs) {
  let h = `<button class="btn-filtro ${!filtroAtivo ? 'ativo' : ''}" onclick="setFiltro('')"><i class="bi bi-funnel"></i>Todas</button>`;
  GRUPOS.forEach(g => {
    const c = g.nome;
    const qtd = maqs.filter(m => m.categoria === c).length;
    h += `<button class="btn-filtro ${filtroAtivo === c ? 'ativo' : ''}" onclick="setFiltro('${escAttr(c)}')"><i class="${getIcon(c)}"></i>${getLabel(c)}<span class="qtd">${qtd}</span></button>`;
  });
  document.getElementById('filtro-bar').innerHTML = h;
}

function setFiltro(cat) {
  filtroAtivo = cat;
  carregarMaquinas();
}

function renderLista(maqs) {
  const agrupado = {};
  GRUPOS.forEach(g => agrupado[g.nome] = []);
  maqs.forEach(m => { if (agrupado[m.categoria]) agrupado[m.categoria].push(m); });

  if (typeof window._catAberto === 'undefined') window._catAberto = {};
  const aberto = window._catAberto;

  const el = document.getElementById('lista-categorias');
  let html = '';
  GRUPOS.forEach(g => {
    const c = g.nome;
    const itens = agrupado[c];
    if (!itens || !itens.length) return;
    const isOpen = aberto[c] === true;
    html += `<section>
      <div class="section-header ${isOpen?'expanded':''}" style="background:${getColor(c)};color:${getBg(c)}" onclick="toggleCategoria('${escAttr(c)}')">
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
            <div class="maq-nome">${esc(m.nome)} ${temSenha ? '<span class="badge-senha"><i class="bi bi-lock-fill"></i> auto</span>' : ''}</div>
            <div class="maq-ip">${esc(m.ip)}</div>
            ${m.descricao ? `<div class="maq-desc">${esc(m.descricao)}</div>` : ''}
            ${m.usuario ? `<div class="maq-desc" style="color:#6b7280"><i class="bi bi-person-fill me-1"></i>${esc(m.usuario)}</div>` : ''}
          </div>
        </div>
        <div class="maq-actions">
          ${temSenha
            ? `<a class="btn-rdp btn-manual" href="rdp_central.php?action=launcher&id=${m.id}"><i class="bi bi-download"></i>Conectar (.bat)</a>`
            : `<a class="btn-rdp btn-manual" href="rdp_central.php?action=rdp&id=${m.id}"><i class="bi bi-download"></i>Conectar (.rdp)</a>`}
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
  if (GRUPOS.length) document.getElementById('edit-categoria').value = GRUPOS[0].nome;
  document.getElementById('modal-label').innerHTML = '<i class="bi bi-plus-circle-fill me-2"></i>Nova Máquina';
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
  document.getElementById('edit-categoria').value = item.categoria;
  document.getElementById('modal-label').innerHTML = '<i class="bi bi-pencil-fill me-2"></i>' + esc(item.nome);
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
  const categoria = document.getElementById('edit-categoria').value;
  if (!nome || !ip) { toast('Preencha nome e IP', 'danger'); return; }
  const action = id ? 'edit' : 'add';
  const r = await fetch('rdp_central.php?action=' + action, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id: parseInt(id)||0, nome, ip, descricao: desc, usuario, senha, guac_id: parseInt(guac_id)||null, categoria})
  });
  const d = await r.json();
  if (d.ok) {
    modalMaq.hide();
    if (d.guac_auto) toast('✅ ' + (id ? 'Atualizada!' : 'Adicionada!') + ' Guacamole (' + d.guac_log + ')', 'success');
    else if (d.guac_log && d.guac_log.indexOf('http_') > -1) toast('⚠️ ' + (id ? 'Atualizada' : 'Adicionada') + ' — Guacamole: ' + d.guac_log, 'warning');
    else if (d.guac_log && d.guac_log.indexOf('sem_') > -1) toast('⚠️ ' + (id ? 'Atualizada' : 'Adicionada') + ' — Guacamole: ' + d.guac_log, 'warning');
    else if (!id) toast('✅ Adicionada!', 'success');
    else toast('✅ Atualizada!', 'success');
    carregarTudo();
  } else toast(d.msg || 'Erro', 'danger');
}

async function excluir(id) {
  if (!confirm('Excluir esta máquina?')) return;
  const r = await fetch('rdp_central.php?action=delete&id=' + id), d = await r.json();
  if (d.ok) { toast('🗑️ Excluída'); carregarTudo(); }
  else toast(d.msg || 'Erro', 'danger');
}

function toggleSenha() {
  const el = document.getElementById('edit-senha');
  el.type = el.type === 'password' ? 'text' : 'password';
}

function toast(msg, type = 'success') {
  const id = 't-' + Date.now(), bg = type === 'success' ? 'bg-success' : 'bg-danger';
  document.getElementById('toast-container').insertAdjacentHTML('beforeend',
    `<div id="${id}" class="toast align-items-center text-white ${bg} border-0 show mb-2"><div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="document.getElementById('${id}').remove()"></button></div></div>`);
  setTimeout(() => document.getElementById(id)?.remove(), 4000);
}

// ── Modal Grupos ────────────────────────────────────────
function abrirModalGrupo() {
  listarGrupos();
  modalGrupos.show();
}

async function listarGrupos() {
  const r = await fetch('rdp_central.php?action=list_grupos');
  const d = await r.json();
  const grupos = d.dados || [];
  let h = '';
  grupos.forEach(g => {
    h += `<div class="card mb-2" style="border-left:4px solid ${g.cor_bg}">
      <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between">
        <div>
          <i class="${g.icone} me-2" style="color:${g.cor_bg}"></i>
          <strong>${esc(g.nome)}</strong>
          <span class="ms-2 badge" style="background:${g.cor_badge};color:${g.cor_text}">#${g.id}</span>
        </div>
        <div>
          <button class="btn btn-sm btn-outline-primary me-1" onclick="editarGrupo(${g.id},'${escAttr(g.nome)}','${escAttr(g.icone)}','${escAttr(g.cor_bg)}','${escAttr(g.cor_fundo)}','${escAttr(g.cor_badge)}','${escAttr(g.cor_text)}')"><i class="bi bi-pencil-fill"></i></button>
          <button class="btn btn-sm btn-outline-danger" onclick="excluirGrupo(${g.id},'${escAttr(g.nome)}')"><i class="bi bi-trash-fill"></i></button>
        </div>
      </div>
    </div>`;
  });
  if (!grupos.length) h = '<p class="text-muted">Nenhum grupo cadastrado.</p>';
  document.getElementById('grupos-container').innerHTML = h;
}

async function salvarGrupo() {
  const nome = prompt('Nome do grupo:');
  if (!nome) return;
  const icone = prompt('Ícone Bootstrap (ex: bi-server, bi-cpu, bi-pc-display):', 'bi-display');
  const cor_bg = prompt('Cor do background (ex: #1e3a8a):', '#1e3a8a');
  const cor_fundo = prompt('Cor de fundo do card (ex: #dbeafe):', '#dbeafe');
  const r = await fetch('rdp_central.php?action=save_grupo', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id: 0, nome, icone, cor_bg, cor_fundo, cor_badge: cor_fundo, cor_text: cor_bg})
  });
  const d = await r.json();
  if (d.ok) { toast('✅ Grupo adicionado!', 'success'); listarGrupos(); carregarTudo(); }
  else toast(d.msg || 'Erro', 'danger');
}

async function editarGrupo(id, nome, icone, cor_bg, cor_fundo, cor_badge, cor_text) {
  const novoNome = prompt('Nome do grupo:', nome);
  if (!novoNome) return;
  const novoIcone = prompt('Ícone Bootstrap:', icone);
  const novaCorBg = prompt('Cor do background:', cor_bg);
  const novaCorFundo = prompt('Cor de fundo do card:', cor_fundo);
  const r = await fetch('rdp_central.php?action=save_grupo', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id, nome: novoNome, icone: novoIcone, cor_bg: novaCorBg, cor_fundo: novaCorFundo, cor_badge: novaCorFundo, cor_text: novaCorBg})
  });
  const d = await r.json();
  if (d.ok) { toast('✅ Grupo atualizado!', 'success'); listarGrupos(); carregarTudo(); }
  else toast(d.msg || 'Erro', 'danger');
}

async function excluirGrupo(id, nome) {
  if (!confirm(`Excluir grupo "${nome}"? Máquinas serão movidas para o primeiro grupo.`)) return;
  const r = await fetch('rdp_central.php?action=delete_grupo', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.ok) { toast('🗑️ Grupo excluído!', 'success'); listarGrupos(); carregarTudo(); }
  else toast(d.msg || 'Erro', 'danger');
}
</script>
</body>
</html>
