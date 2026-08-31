<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin','super-admin','tecnico']);

// ── Cria tabela de grupos VNC ──────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_vnc_grupos (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nome      VARCHAR(60) NOT NULL UNIQUE,
        icone     VARCHAR(40) DEFAULT 'bi-display',
        cor_bg    VARCHAR(20) DEFAULT '#6366f1',
        cor_fundo VARCHAR(20) DEFAULT '#eef2ff',
        cor_badge VARCHAR(20) DEFAULT '#c7d2fe',
        cor_text  VARCHAR(20) DEFAULT '#3730a2',
        ordem     INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Popula defaults se vazio
if (!$pdo->query("SELECT COUNT(*) FROM portal_vnc_grupos")->fetchColumn()) {
    $ins = $pdo->prepare("INSERT INTO portal_vnc_grupos (nome,icone,cor_bg,cor_fundo,cor_badge,cor_text,ordem) VALUES (?,?,?,?,?,?,?)");
    $ins->execute(['Servidores','bi-server','#6366f1','#eef2ff','#c7d2fe','#3730a2',1]);
    $ins->execute(['Coletores','bi-cpu-fill','#8b5cf6','#f5f3ff','#ddd6fe','#5b21b6',2]);
    $ins->execute(['PCs Estratégicos','bi-pc-display-horizontal','#a855f7','#faf5ff','#e9d5ff','#6b21a8',3]);
}

// ── Carrega grupos do banco ────────────────────────────────────────
$grupos_rows = $pdo->query("SELECT * FROM portal_vnc_grupos ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
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

// ── Migra categorias antigas (lowercase) para os novos grupos ──
try { $pdo->exec("UPDATE portal_rdp_maquinas SET categoria='Servidores' WHERE protocolo='vnc' AND categoria='servidor'"); } catch (Exception $e) {}
try { $pdo->exec("UPDATE portal_rdp_maquinas SET categoria='Coletores' WHERE protocolo='vnc' AND categoria='coletor'"); } catch (Exception $e) {}
try { $pdo->exec("UPDATE portal_rdp_maquinas SET categoria='PCs Estratégicos' WHERE protocolo='vnc' AND categoria='pc'"); } catch (Exception $e) {}

// ── Criptografia ───────────────────────────────────────────────
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

// ── Action handler ──────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    try {

    // ── List (só VNC) ──────────────────────────────────────────
    if ($action === 'list') {
        $sql = "SELECT id, nome, ip, descricao, usuario,
                       CASE WHEN senha IS NOT NULL AND senha != '' THEN 1 ELSE 0 END as has_senha,
                       categoria, ordem, guac_id
                FROM portal_rdp_maquinas WHERE ativo=1 AND protocolo='vnc'";
        $params = [];
        $categoria = $_GET['categoria'] ?? '';
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

    // ── Adicionar ──────────────────────────────────────────────
    if ($action === 'add') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $n = trim($body['nome']??''); $i = trim($body['ip']??'');
        $d = trim($body['descricao']??''); $s = $body['senha'] ?? '';
        $g = $body['guac_id'] ?? null;
        $c = $body['categoria']??'';
        if (!$n||!$i) { echo json_encode(['ok'=>false,'msg'=>'Preencha nome e IP']); exit; }
        if (!in_array($c, $cat_lista)) $c = $cat_lista[0] ?? 'Servidores';
        $senha_enc = $s ? rdp_encrypt($s) : null;

        // Se não informou guac_id, tenta criar automaticamente no Guacamole
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
                    // Função auxiliar: monta payload VNC
                    $guac_payload = function() use ($n, $i, $s) {
                        return [
                            'parentIdentifier' => 'ROOT',
                            'name' => $n,
                            'protocol' => 'vnc',
                            'attributes' => [
                                'max-connections' => '', 'max-connections-per-user' => '',
                                'weight' => '', 'failover-only' => '',
                                'guacd-hostname' => '', 'guacd-port' => '',
                            ],
                            'parameters' => [
                                'hostname' => $i, 'port' => '5900',
                                'password' => $s, 'color-depth' => '16',
                                'read-only' => '', 'swap-red-blue' => '',
                                'cursor' => '',
                            ],
                        ];
                    };

                    // Busca se já existe conexão com esse nome
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
        $mo = $pdo->query("SELECT COALESCE(MAX(ordem),0)+1 FROM portal_rdp_maquinas WHERE protocolo='vnc'")->fetchColumn();
        $pdo->prepare("INSERT INTO portal_rdp_maquinas (nome,ip,descricao,usuario,senha,protocolo,guac_id,categoria,ordem) VALUES (?,?,?,?,?,'vnc',?,?,?)")
            ->execute([$n,$i,$d,'',$senha_enc,$guac_val,$c,$mo]);
        echo json_encode(['ok'=>true, 'id'=>$pdo->lastInsertId(), 'guac_id'=>$g, 'guac_auto'=>($g !== null && $g > 0), 'guac_log'=>$guac_log]); exit;
    }

    // ── Editar ─────────────────────────────────────────────────
    if ($action === 'edit') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id=(int)($body['id']??0); $n=trim($body['nome']??''); $i=trim($body['ip']??'');
        $d=trim($body['descricao']??''); $s = $body['senha'] ?? ''; $g = $body['guac_id'] ?? null;
        $c=$body['categoria']??'';
        if (!$id||!$n||!$i) { echo json_encode(['ok'=>false,'msg'=>'Preencha nome e IP']); exit; }
        if (!in_array($c, $cat_lista)) $c = $cat_lista[0] ?? 'Servidores';

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
                    $guac_payload = function() use ($n, $i, $s) {
                        return [
                            'parentIdentifier' => 'ROOT',
                            'name' => $n,
                            'protocol' => 'vnc',
                            'attributes' => [
                                'max-connections' => '', 'max-connections-per-user' => '',
                                'weight' => '', 'failover-only' => '',
                                'guacd-hostname' => '', 'guacd-port' => '',
                            ],
                            'parameters' => [
                                'hostname' => $i, 'port' => '5900',
                                'password' => $s, 'color-depth' => '16',
                                'read-only' => '', 'swap-red-blue' => '',
                                'cursor' => '',
                            ],
                        ];
                    };
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
            $pdo->prepare("UPDATE portal_rdp_maquinas SET nome=?,ip=?,descricao=?,senha=?,guac_id=?,categoria=? WHERE id=?")
                ->execute([$n,$i,$d,$senha_enc,$guac_val,$c,$id]);
        } else {
            $pdo->prepare("UPDATE portal_rdp_maquinas SET nome=?,ip=?,descricao=?,guac_id=?,categoria=? WHERE id=?")
                ->execute([$n,$i,$d,$guac_val,$c,$id]);
        }
        echo json_encode(['ok'=>true, 'guac_id'=>$guac_val, 'guac_log'=>$guac_log]); exit;
    }

    // ── Excluir ────────────────────────────────────────────────
    if ($action === 'delete' && isset($_GET['id'])) {
        $del_id = (int)$_GET['id'];
        $st_del = $pdo->prepare("SELECT guac_id FROM portal_rdp_maquinas WHERE id=?");
        $st_del->execute([$del_id]);
        $row_del = $st_del->fetch(PDO::FETCH_ASSOC);
        $del_guac = ($row_del && !empty($row_del['guac_id'])) ? (int)$row_del['guac_id'] : 0;
        $pdo->prepare("DELETE FROM portal_rdp_maquinas WHERE id=?")->execute([$del_id]);
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

    // ── Listar grupos ───────────────────────────────────────────
    if ($action === 'list_grupos') {
        $rows = $pdo->query("SELECT * FROM portal_vnc_grupos ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true, 'dados'=>$rows]); exit;
    }

    // ── Salvar grupo ────────────────────────────────────────────
    if ($action === 'save_grupo') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($body['id']??0);
        // Em edição, campos não enviados mantêm o valor atual (não reseta ícone/cor)
        $cur = [];
        if ($id) {
            $q = $pdo->prepare("SELECT * FROM portal_vnc_grupos WHERE id=?"); $q->execute([$id]);
            $cur = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $nome = trim($body['nome'] ?? $cur['nome'] ?? '');
        if (!$nome) { echo json_encode(['ok'=>false,'msg'=>'Nome obrigatório']); exit; }
        $icone = trim($body['icone'] ?? $cur['icone'] ?? 'bi-display');
        $cor_bg = trim($body['cor_bg'] ?? $cur['cor_bg'] ?? '#6366f1');
        $cor_fundo = trim($body['cor_fundo'] ?? $cur['cor_fundo'] ?? '#eef2ff');
        $cor_badge = trim($body['cor_badge'] ?? $cur['cor_badge'] ?? '#c7d2fe');
        $cor_text = trim($body['cor_text'] ?? $cur['cor_text'] ?? '#3730a2');
        $ordem = (int)($body['ordem'] ?? $cur['ordem'] ?? 0);
        if ($id) {
            // renomeou? move as máquinas do nome antigo pro novo
            if (!empty($cur['nome']) && $cur['nome'] !== $nome) {
                $pdo->prepare("UPDATE portal_rdp_maquinas SET categoria=? WHERE protocolo='vnc' AND categoria=?")
                    ->execute([$nome, $cur['nome']]);
            }
            $pdo->prepare("UPDATE portal_vnc_grupos SET nome=?,icone=?,cor_bg=?,cor_fundo=?,cor_badge=?,cor_text=?,ordem=? WHERE id=?")
                ->execute([$nome,$icone,$cor_bg,$cor_fundo,$cor_badge,$cor_text,$ordem,$id]);
        } else {
            $pdo->prepare("INSERT INTO portal_vnc_grupos (nome,icone,cor_bg,cor_fundo,cor_badge,cor_text,ordem) VALUES (?,?,?,?,?,?,?)")
                ->execute([$nome,$icone,$cor_bg,$cor_fundo,$cor_badge,$cor_text,$ordem]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    // ── Excluir grupo ───────────────────────────────────────────
    if ($action === 'delete_grupo' && isset($_GET['id'])) {
        $del_id = (int)$_GET['id'];
        $del_nome = $pdo->prepare("SELECT nome FROM portal_vnc_grupos WHERE id=?");
        $del_nome->execute([$del_id]);
        $del_row = $del_nome->fetch();
        if ($del_row) {
            // Move máquinas desse grupo pro primeiro grupo
            $primeiro = $cat_lista[0] ?? 'Servidores';
            $pdo->prepare("UPDATE portal_rdp_maquinas SET categoria=? WHERE protocolo='vnc' AND categoria=?")
                ->execute([$primeiro, $del_row['nome']]);
            $pdo->prepare("DELETE FROM portal_vnc_grupos WHERE id=?")->execute([$del_id]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação inválida']); exit;
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'msg'=>'Erro interno: '.$e->getMessage()]); exit;
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Central VNC</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root{--primary:#6d28d9;}*{box-sizing:border-box;}
    body{background:#f0f4f9;font-family:'Segoe UI',sans-serif;min-height:100vh;margin:0;}
    .topbar{background:linear-gradient(135deg,#4c1d95,var(--primary));color:white;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(0,0,0,.25);position:sticky;top:0;z-index:100;}
    .topbar .brand{font-weight:700;font-size:1rem;display:flex;align-items:center;gap:.5rem;}
    .topbar a{color:white;text-decoration:none;font-size:.82rem;background:rgba(255,255,255,.15);border-radius:6px;padding:.3rem .75rem;}
    .topbar a:hover{background:rgba(255,255,255,.25);}
    .hero{background:linear-gradient(135deg,#4c1d95,var(--primary));color:white;padding:2rem 1rem 4rem;text-align:center;}
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
    .btn-auto{background:#7c3aed;color:white;}
    .btn-auto:hover{background:#6d28d9;}
    .btn-manual{background:#6b7280;color:white;}
    .btn-manual:hover{background:#4b5563;}
    .btn-nativo{background:#059669;color:white;text-decoration:none;}
    .btn-nativo:hover{background:#047857;}
    .btn-config{background:transparent;border:none;color:#9ca3af;cursor:pointer;padding:.3rem;border-radius:6px;font-size:.85rem;}
    .btn-config:hover{background:#f3f4f6;color:#374151;}
    .card-add{border:2px dashed #d1d5db;background:transparent;border-radius:10px;padding:1.25rem;text-align:center;color:#9ca3af;cursor:pointer;margin-bottom:.5rem;transition:all .15s;}
    .card-add:hover{border-color:#6b7280;color:#374151;background:#f9fafb;}
    .filtro-bar{display:flex;align-items:center;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap;}
    .filtro-bar .btn-filtro{border:1px solid #d1d5db;background:white;border-radius:20px;padding:.3rem .85rem;font-size:.78rem;cursor:pointer;transition:all .12s;color:#374151;display:flex;align-items:center;gap:.35rem;}
    .filtro-bar .btn-filtro:hover{border-color:#6b7280;background:#f9fafb;}
    .filtro-bar .btn-filtro.ativo{border-color:var(--primary);background:#ede9fe;color:#5b21b6;font-weight:600;}
    .filtro-bar .btn-filtro .qtd{border-radius:10px;background:rgba(0,0,0,.08);padding:.05rem .4rem;font-size:.65rem;margin-left:.2rem;}
    .stats-row{display:flex;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap;}
    .stat-pill{background:white;border:1px solid #e5e7eb;border-radius:8px;padding:.4rem .85rem;font-size:.78rem;display:flex;align-items:center;gap:.4rem;}
    #toast-container{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;}
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-camera-video-fill me-1"></i> Central VNC</div>
  <div style="display:flex;gap:.5rem">
    <a href="vnc_setup.php"><i class="bi bi-gear-fill me-1"></i>Configurar este PC</a>
    <a href="acessos.php"><i class="bi bi-grid-3x3-gap-fill me-1"></i>Acessos</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>
<div class="hero">
  <h1><i class="bi bi-camera-video-fill me-2"></i>Acesso Remoto VNC</h1>
  <p>Clique em <strong>Conectar</strong> para acesso via Guacamole no navegador</p>
</div>

<div class="wrap" id="app">
  <div class="d-flex justify-content-between align-items-start flex-wrap" style="gap:.5rem;margin-bottom:.5rem">
    <div class="stats-row" id="stats"></div>
    <button class="btn btn-sm btn-outline-secondary" onclick="abrirModalGrupo()" title="Gerenciar grupos VNC"><i class="bi bi-tags-fill me-1"></i>Grupos</button>
  </div>
  <div class="filtro-bar" id="filtro-bar"></div>
  <div id="lista-categorias"></div>
</div>

<!-- Modal CRUD -->
<div class="modal fade" id="modalMaq" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#4c1d95,#6d28d9);color:white">
        <h5 class="modal-title fw-bold" id="modal-label"><i class="bi bi-camera-video-fill me-2"></i>Nova Máquina VNC</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-id"/>
        <div class="mb-3"><label class="form-label fw-semibold">Nome</label><input type="text" class="form-control" id="edit-nome" placeholder="SRV-LINUX"/></div>
        <div class="mb-3"><label class="form-label fw-semibold">IP / Hostname</label><input type="text" class="form-control font-monospace" id="edit-ip" placeholder="192.168.1.x"/></div>
        <div class="mb-3"><label class="form-label fw-semibold">Descrição <span class="text-muted small">(opcional)</span></label><input type="text" class="form-control" id="edit-desc" placeholder="Servidor Linux..."/></div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Senha VNC <span class="text-muted small">(opcional)</span></label>
          <div class="input-group">
            <input type="password" class="form-control" id="edit-senha" placeholder="Deixe em branco para pedir ao conectar"/>
            <button class="btn btn-outline-secondary" type="button" onclick="toggleSenha()" style="font-size:.75rem"><i class="bi bi-eye-fill"></i></button>
          </div>
          <div class="form-text text-muted small">Senha criptografada. Se deixar em branco, o Guacamole vai solicitar ao conectar.</div>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Categoria</label>
          <div style="display:flex;gap:.35rem">
            <select class="form-select" id="edit-categoria" style="flex:1"></select>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="abrirModalGrupo()" title="Gerenciar grupos" style="border-color:#d1d5db;font-size:.78rem;padding:.25rem .6rem">
              <i class="bi bi-gear-fill"></i>
            </button>
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
        <button type="button" class="btn btn-primary" onclick="salvar()" style="background:#6d28d9;border-color:#6d28d9"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Gerenciar Grupos -->
<div class="modal fade" id="modalGrupos" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#4c1d95,#6d28d9);color:white">
        <h5 class="modal-title fw-bold"><i class="bi bi-gear-fill me-2"></i>Gerenciar Grupos VNC</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="lista-grupos-modal"></div>
        <hr class="my-2"/>
        <div class="row g-2 align-items-end">
          <div class="col-4"><label class="form-label small">Nome</label><input type="text" class="form-control form-control-sm" id="grp-nome" placeholder="Novo grupo"/></div>
          <div class="col-2"><label class="form-label small">Ícone</label><select class="form-select form-select-sm" id="grp-icone"><option value="bi-display">🖥️</option><option value="bi-server">🖧</option><option value="bi-cpu-fill">💻</option><option value="bi-pc-display-horizontal">🖵</option><option value="bi-hdd-stack">💾</option><option value="bi-laptop">💻</option><option value="bi-printer">🖨️</option><option value="bi-router-fill">📡</option></select></div>
          <div class="col-2"><label class="form-label small">Cor</label><input type="color" class="form-control form-control-color form-control-sm" id="grp-cor" value="#6366f1" style="padding:1px"/></div>
          <div class="col-4"><button class="btn btn-primary btn-sm w-100" onclick="salvarGrupo()" style="background:#6d28d9;border-color:#6d28d9"><i class="bi bi-plus-lg me-1"></i>Adicionar</button></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<div id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let CATS = {};
let GRUPOS = [];
let modalMaq, modalGrupos;
let filtroAtivo = '';
const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', () => {
  modalMaq    = new bootstrap.Modal(document.getElementById('modalMaq'));
  modalGrupos = new bootstrap.Modal(document.getElementById('modalGrupos'));
  carregarTudo();
});

async function carregarTudo() {
  await carregarGrupos();
  await carregarMaquinas();
}

async function carregarGrupos() {
  const r = await fetch('vnc_central.php?action=list_grupos');
  const d = await r.json();
  GRUPOS = d.dados || [];
  CATS = {};
  GRUPOS.forEach(g => {
    CATS[g.nome] = {
      label: g.nome, icon: g.icone, bg: g.cor_bg, color: g.cor_fundo,
      badge: g.cor_badge, 'badge-text': g.cor_text,
    };
  });
  renderizarSelectCategoria();
}

function renderizarSelectCategoria() {
  const sel = document.getElementById('edit-categoria');
  sel.innerHTML = GRUPOS.map(g => `<option value="${escAttr(g.nome)}">${esc(g.nome)}</option>`).join('');
}

function getLabel(cat) { return CATS[cat]?.label || cat; }
function getIcon(cat) { return CATS[cat]?.icon || 'bi-display'; }
function getBg(cat) { return CATS[cat]?.bg || '#6b7280'; }
function getColor(cat) { return CATS[cat]?.color || '#f3f4f6'; }
function getBadgeText(cat) { return CATS[cat]?.['badge-text'] || '#374151'; }
function getBadge(cat) { return CATS[cat]?.badge || '#e5e7eb'; }

async function carregarMaquinas() {
  const url = filtroAtivo ? 'vnc_central.php?action=list&categoria=' + encodeURIComponent(filtroAtivo) : 'vnc_central.php?action=list';
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
  let h = `<div class="stat-pill"><i class="bi bi-camera-video-fill text-primary"></i>${total} máquina(s)</div>`;
  GRUPOS.forEach(g => {
    if (qtds[g.nome]) h += `<div class="stat-pill"><i class="${g.icone}" style="color:${g.cor_bg}"></i>${esc(g.nome)}: ${qtds[g.nome]}</div>`;
  });
  document.getElementById('stats').innerHTML = h;
}

function renderFiltro(maqs) {
  let h = `<button class="btn-filtro ${!filtroAtivo ? 'ativo' : ''}" onclick="setFiltro('')"><i class="bi bi-funnel"></i>Todas</button>`;
  GRUPOS.forEach(g => {
    const qtd = maqs.filter(m => m.categoria === g.nome).length;
    h += `<button class="btn-filtro ${filtroAtivo === g.nome ? 'ativo' : ''}" onclick="setFiltro('${escAttr(g.nome)}')"><i class="${g.icone}"></i>${esc(g.nome)}<span class="qtd">${qtd}</span></button>`;
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
    if (!itens.length) return;
    const isOpen = aberto[c] === true; // default: fechado
    html += `<section>
      <div class="section-header ${isOpen?'expanded':''}" style="background:${getColor(c)};color:${getBg(c)}" onclick="toggleCategoria('${escAttr(c)}')">
        <i class="${getIcon(c)}"></i>${getLabel(c)}
        <span class="badge-cat" style="background:${getBadge(c)};color:${getBadgeText(c)}">${itens.length}</span>
        <span class="chevron">${isOpen?'▴':'▾'}</span>
      </div>
      <div class="section-body ${isOpen?'open':''}" id="corpo-${c}">
        <div class="section-body-inner">`;
    itens.forEach(m => {
      const temGuac = m.guac_id > 0;
      html += `<div class="maq-card" id="maq-${m.id}">
        <div class="maq-info">
          <div class="maq-icon" style="background:${getColor(c)};color:${getBg(c)}"><i class="${getIcon(c)}"></i></div>
          <div>
            <div class="maq-nome">${esc(m.nome)}</div>
            <div class="maq-ip">${esc(m.ip)}</div>
            ${m.descricao ? `<div class="maq-desc">${esc(m.descricao)}</div>` : ''}
            ${temGuac ? `<div class="maq-desc" style="color:#7c3aed"><i class="bi bi-globe2 me-1"></i>Guacamole VNC</div>` : ''}
          </div>
        </div>
        <div class="maq-actions">
          <a class="btn-rdp btn-nativo" href="vnc_launch.php?id=${m.id}" title="Abre o VNC Viewer instalado no seu PC (C:\\Util\\VNCViewer.exe)"><i class="bi bi-display-fill"></i>Abrir no VNC</a>
          ${temGuac
            ? `<a class="btn-rdp btn-auto" href="guacamole_conectar.php?id=${m.id}"><i class="bi bi-globe2"></i>Guacamole</a>`
            : ''
          }
          ${isAdmin ? `<button class="btn-config" onclick="editar(${m.id})"><i class="bi bi-pencil-fill"></i></button><button class="btn-config" onclick="excluir(${m.id})" style="color:#ef4444"><i class="bi bi-trash-fill"></i></button>` : ''}
        </div>
      </div>`;
    });
    html += `</div></div></section>`;
  });

  if (isAdmin) {
    html += `<div class="card-add" onclick="abrirModal()"><i class="bi bi-plus-circle" style="font-size:1.3rem;display:block;margin-bottom:.35rem"></i><strong>Adicionar nova máquina VNC</strong></div>`;
  }

  if (!maqs.length) {
    html = `<div style="text-align:center;padding:3rem 1rem;color:#9ca3af"><i class="bi bi-camera-video-off-fill" style="font-size:3rem;display:block;margin-bottom:1rem"></i><p>Nenhuma máquina VNC cadastrada.</p>${isAdmin ? '<button class="btn btn-primary btn-sm" onclick="abrirModal()" style="background:#6d28d9;border-color:#6d28d9">Adicionar</button>' : ''}</div>`;
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
  ['edit-id','edit-nome','edit-ip','edit-desc','edit-senha','edit-guac-id'].forEach(id => document.getElementById(id).value = '');
  if (GRUPOS.length) document.getElementById('edit-categoria').value = GRUPOS[0].nome;
  document.getElementById('modal-label').innerHTML = '<i class="bi bi-plus-circle-fill me-2"></i>Nova Máquina VNC';
  modalMaq.show();
}

async function editar(id) {
  const r = await fetch('vnc_central.php?action=list'), d = await r.json();
  const item = (d.dados||[]).find(x => x.id == id);
  if (!item) { toast('Máquina não encontrada', 'danger'); return; }
  document.getElementById('edit-id').value = id;
  document.getElementById('edit-nome').value = item.nome;
  document.getElementById('edit-ip').value = item.ip;
  document.getElementById('edit-desc').value = item.descricao || '';
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
  try {
  const id = document.getElementById('edit-id').value;
  const nome = document.getElementById('edit-nome').value.trim();
  const ip = document.getElementById('edit-ip').value.trim();
  const desc = document.getElementById('edit-desc').value.trim();
  const senha = document.getElementById('edit-senha').value;
  const guac_id = document.getElementById('edit-guac-id').value.trim();
  const categoria = document.getElementById('edit-categoria').value;
  if (!nome || !ip) { toast('Preencha nome e IP', 'danger'); return; }
  const action = id ? 'edit' : 'add';
  const r = await fetch('vnc_central.php?action=' + action, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id: parseInt(id)||0, nome, ip, descricao: desc, senha, guac_id: parseInt(guac_id)||null, categoria})
  });
  const d = await r.json();
  if (d.ok) {
    modalMaq.hide();
    if (d.guac_auto) toast('✅ ' + (id ? 'Atualizada!' : 'Adicionada!') + ' Guacamole (' + d.guac_log + ')', 'success');
    else if (d.guac_log && d.guac_log.indexOf('http_') > -1) toast('⚠️ ' + (id ? 'Atualizada' : 'Adicionada') + ' — Guacamole: ' + d.guac_log, 'warning');
    else if (d.guac_log && d.guac_log.indexOf('sem_') > -1) toast('⚠️ ' + (id ? 'Atualizada' : 'Adicionada') + ' — Guacamole: ' + d.guac_log, 'warning');
    else if (!id) toast('✅ Adicionada!', 'success');
    else toast('✅ Atualizada!', 'success');
    carregarMaquinas();
  } else toast(d.msg || 'Erro', 'danger');
  } catch(e) {
    toast('Erro ao salvar: ' + e.message, 'danger');
    console.error(e);
  }
}

async function excluir(id) {
  if (!confirm('Excluir esta máquina VNC?')) return;
  const r = await fetch('vnc_central.php?action=delete&id=' + id), d = await r.json();
  if (d.ok) { toast('🗑️ Excluída' + (d.guac_del === 'guac_removido' ? ' + Guacamole removido' : '')); carregarMaquinas(); }
  else toast('Erro', 'danger');
}

// ── Gerenciamento de Grupos ────────────────────────────────────────
async function abrirModalGrupo() {
  modalMaq.hide();
  await listarGrupos();
  document.getElementById('grp-nome').value = '';
  document.getElementById('grp-icone').value = 'bi-display';
  document.getElementById('grp-cor').value = '#6366f1';
  modalGrupos.show();
}

async function listarGrupos() {
  const r = await fetch('vnc_central.php?action=list_grupos');
  const d = await r.json();
  const grupos = d.dados || [];
  const el = document.getElementById('lista-grupos-modal');
  if (!grupos.length) {
    el.innerHTML = '<p class="text-muted small">Nenhum grupo cadastrado.</p>';
    return;
  }
  el.innerHTML = grupos.map((g, i) =>
    `<div style="display:flex;align-items:center;gap:.4rem;padding:.35rem .5rem;border-radius:6px;background:${i%2===0?'#f9fafb':'transparent'};margin-bottom:2px">
      <i class="${g.icone}" style="color:${g.cor_bg};font-size:1rem"></i>
      <input class="form-control form-control-sm grp-edit" data-id="${g.id}" value="${escAttr(g.nome)}" style="flex:1;font-size:.85rem;height:28px"/>
      <button class="btn btn-sm btn-primary" style="padding:.1rem .5rem;font-size:.72rem;background:#6d28d9;border-color:#6d28d9" onclick="renomearGrupo(${g.id})"><i class="bi bi-check-lg"></i></button>
      <button class="btn btn-sm btn-outline-danger" style="padding:.1rem .4rem;font-size:.7rem" onclick="excluirGrupo(${g.id})"><i class="bi bi-trash"></i></button>
    </div>`).join('');
}

async function renomearGrupo(id) {
  const inp = document.querySelector(`.grp-edit[data-id="${id}"]`);
  const nome = inp.value.trim();
  if (!nome) { toast('Nome obrigatório', 'danger'); return; }
  const r = await fetch('vnc_central.php?action=save_grupo', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ id, nome })
  });
  const d = await r.json();
  if (d.ok) { toast('✅ Grupo renomeado'); await listarGrupos(); await carregarGrupos(); }
  else toast(d.msg || 'Erro', 'danger');
}

async function salvarGrupo() {
  const nome = document.getElementById('grp-nome').value.trim();
  if (!nome) { toast('Nome do grupo obrigatório', 'danger'); return; }
  const icone = document.getElementById('grp-icone').value;
  const cor = document.getElementById('grp-cor').value;
  const r = await fetch('vnc_central.php?action=save_grupo', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({nome, icone, cor_bg: cor, cor_fundo: cor+'22', cor_badge: cor+'44', cor_text: cor, ordem: 0})
  });
  const d = await r.json();
  if (d.ok) {
    document.getElementById('grp-nome').value = '';
    toast('✅ Grupo adicionado!');
    await listarGrupos();
    await carregarGrupos();
  } else toast(d.msg || 'Erro', 'danger');
}

async function excluirGrupo(id) {
  if (!confirm('Excluir este grupo? Máquinas dele serão movidas pro primeiro grupo.')) return;
  const r = await fetch('vnc_central.php?action=delete_grupo&id=' + id), d = await r.json();
  if (d.ok) {
    toast('🗑️ Grupo excluído');
    await listarGrupos();
    await carregarGrupos();
  } else toast('Erro', 'danger');
}

function toggleSenha() {
  const el = document.getElementById('edit-senha');
  el.type = el.type === 'password' ? 'text' : 'password';
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function escAttr(s) { return esc(s).replace(/'/g,'&#39;').replace(/"/g,'&quot;'); }

function toast(msg, type = 'success') {
  const id = 't-' + Date.now(), bg = type === 'success' ? 'bg-success' : 'bg-danger';
  document.getElementById('toast-container').insertAdjacentHTML('beforeend',
    `<div id="${id}" class="toast align-items-center text-white ${bg} border-0 show mb-2"><div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="document.getElementById('${id}').remove()"></button></div></div>`);
  setTimeout(() => document.getElementById(id)?.remove(), 4000);
}
</script>
</body>
</html>
