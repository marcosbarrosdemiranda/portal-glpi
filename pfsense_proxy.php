<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';

// ── Chave de criptografia ──────────────────────────────────────
if (!defined('VAULT_KEY')) {
    define('VAULT_KEY', hash('sha256', GLPI_APP_TOKEN . 'cofre_ti_gmais'));
}
function vault_encrypt(string $plain): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'aes-256-cbc', VAULT_KEY, 0, $iv);
    return base64_encode($iv . $enc);
}
function vault_decrypt(string $data): string {
    $raw = base64_decode($data);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return openssl_decrypt($enc, 'aes-256-cbc', VAULT_KEY, 0, $iv) ?: '';
}

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin','super-admin','tecnico']);

$loja_id = (int)($_GET['loja'] ?? 0);
$path    = $_GET['path'] ?? '/';

// ── Cria tabela se vazia ───────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_pfsense_lojas (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        loja        VARCHAR(100) NOT NULL,
        ip          VARCHAR(45)  NOT NULL,
        usuario     VARCHAR(100) NOT NULL,
        senha_enc   TEXT         NOT NULL,
        ativo       TINYINT(1)   DEFAULT 1,
        ordem       INT          DEFAULT 0,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($pdo->query("SELECT COUNT(*) FROM portal_pfsense_lojas")->fetchColumn() == 0) {
    $st = $pdo->prepare("INSERT INTO portal_pfsense_lojas (loja,ip,usuario,senha_enc,ordem) VALUES (?,?,?,?,?)");
    $st->execute(['Loja 001', '192.168.1.1', 'admin', vault_encrypt('gm560max2005'), 1]);
}

// ── Modo debug ──────────────────────────────────────────────────
$debug = isset($_GET['debug']);
if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    $loja = null;
    if ($loja_id) {
        $st = $pdo->prepare("SELECT * FROM portal_pfsense_lojas WHERE id=? AND ativo=1");
        $st->execute([$loja_id]);
        $loja = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$loja) { echo "Loja não encontrada\n"; exit; }

    $ip   = $loja['ip'];
    $user = $loja['usuario'];
    $pass = vault_decrypt($loja['senha_enc']);

    echo "=== DEBUG pfSense Proxy ===\n";
    echo "Loja: {$loja['loja']}\n";
    echo "IP: $ip\n";
    echo "Usuário: $user\n\n";

    // Tenta HTTPS
    echo "--- Tentando HTTPS ---\n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://$ip/",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    echo "HTTP Code: {$info['http_code']}\n";
    echo "Content-Type: {$info['content_type']}\n";
    echo "Error: $err\n";
    echo "Resposta (" . strlen($resp) . " bytes):\n";
    echo substr($resp, 0, 2000) . "\n\n";

    // Tenta HTTP se HTTPS falhou
    if ($info['http_code'] >= 400 || !$resp) {
        echo "--- Tentando HTTP ---\n";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "http://$ip/",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $info = curl_getinfo($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        echo "HTTP Code: {$info['http_code']}\n";
        echo "Content-Type: {$info['content_type']}\n";
        echo "Error: $err\n";
        echo "Resposta (" . strlen($resp) . " bytes):\n";
        echo substr($resp, 0, 2000) . "\n";
    }
    exit;
}

// ── Debug de login ─────────────────────────────────────────────
$debugLogin = isset($_GET['debuglogin']);
if ($debugLogin) {
    header('Content-Type: text/plain; charset=utf-8');
    $loja = null;
    if ($loja_id) {
        $st = $pdo->prepare("SELECT * FROM portal_pfsense_lojas WHERE id=? AND ativo=1");
        $st->execute([$loja_id]);
        $loja = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$loja) { echo "Loja não encontrada\n"; exit; }

    $ip   = $loja['ip'];
    $user = $loja['usuario'];
    $pass = vault_decrypt($loja['senha_enc']);

    echo "=== DEBUG LOGIN pfSense ===\n";
    echo "Loja: {$loja['loja']}\nIP: $ip\nUsuário: $user\n\n";

    foreach (['https','http'] as $proto) {
        echo "--- $proto ---\n";
        $ckfile = sys_get_temp_dir() . '/pfs_debug_' . session_id() . '.txt';
        @unlink($ckfile);

        // GET login page
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "$proto://$ip/",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_COOKIEJAR => $ckfile,
            CURLOPT_COOKIEFILE => $ckfile,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);
        $page1 = curl_exec($ch);
        $code1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err1  = curl_error($ch);
        echo "GET / → HTTP $code1 | " . strlen($page1) . " bytes | Erro: $err1\n";
        if ($code1 >= 400 || !$page1) { curl_close($ch); echo "  → Falhou\n\n"; continue; }

        // CSRF
        $csrf = '';
        if (preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/i', $page1, $m)) {
            $csrf = $m[1];
            echo "  CSRF (JS): $csrf\n";
        } elseif (preg_match('/__csrf_magic[^v]*value="([^"]+)"/is', $page1, $m)) {
            $csrf = $m[1];
            echo "  CSRF (input): $csrf\n";
        } else {
            echo "  CSRF: NÃO ENCONTRADO\n";
        }
        echo "  Login page contém usernamefld? " . (stripos($page1, 'usernamefld') !== false ? "SIM" : "NÃO") . "\n";

        // POST login
        $pf = ['usernamefld'=>$user,'passwordfld'=>$pass,'login'=>'Login'];
        if ($csrf) $pf['__csrf_magic'] = $csrf;

        curl_setopt_array($ch, [
            CURLOPT_URL => "$proto://$ip/index.php",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($pf),
            CURLOPT_COOKIEFILE => $ckfile,
            CURLOPT_COOKIEJAR => $ckfile,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADER => true,
        ]);
        $resp2 = curl_exec($ch);
        $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        echo "POST login → HTTP $code2\n";
        echo "  Content-Type: " . ($contentType ?? 'N/A') . "\n";

        // Split headers from body
        $hdEnd = strpos($resp2, "\r\n\r\n");
        $headers = $hdEnd !== false ? substr($resp2, 0, $hdEnd) : '';
        $body2   = $hdEnd !== false ? substr($resp2, $hdEnd + 4) : $resp2;

        echo "  Headers:\n";
        foreach (explode("\r\n", $headers) as $h) {
            if (stripos($h, 'Location') !== false || stripos($h, 'Set-Cookie') !== false) {
                echo "    $h\n";
            }
        }

        // Check if login succeeded
        $temForm = stripos($body2, 'usernamefld') !== false;
        echo "  Body contém usernamefld? " . ($temForm ? "SIM (ainda na página de login)" : "NÃO (login OK!)") . "\n";
        echo "  Tamanho body: " . strlen($body2) . " bytes\n";
        echo "  Primeiros 1000 chars do body:\n" . substr($body2, 0, 1000) . "\n";

        if (!$temForm) {
            echo "\n✅ LOGIN FUNCIONOU com $proto!\n";
        } else {
            echo "\n❌ Login falhou com $proto\n";
        }
        echo "\n";
    }
    exit;
}

// ── AJAX: list/reveal/add/edit/delete ──────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $rows = $pdo->query("SELECT id, loja, ip, usuario, ordem FROM portal_pfsense_lojas WHERE ativo=1 ORDER BY ordem, loja")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'dados' => $rows]);
        exit;
    }

    if ($action === 'reveal' && isset($_GET['id'])) {
        $id  = (int)$_GET['id'];
        $st  = $pdo->prepare("SELECT loja, ip, usuario, senha_enc FROM portal_pfsense_lojas WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok' => false, 'msg' => 'Loja não encontrada']); exit; }
        echo json_encode([
            'ok' => true, 'loja' => $row['loja'], 'ip' => $row['ip'],
            'usuario' => $row['usuario'], 'senha' => vault_decrypt($row['senha_enc']),
        ]);
        exit;
    }

    if (!$is_admin) { echo json_encode(['ok' => false, 'msg' => 'Sem permissão']); exit; }

    if ($action === 'add') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $l = trim($body['loja']??''); $i = trim($body['ip']??'');
        $u = trim($body['usuario']??''); $s = $body['senha']??'';
        if (!$l||!$i||!$u||!$s) { echo json_encode(['ok'=>false,'msg'=>'Preencha todos os campos']); exit; }
        $mo = $pdo->query("SELECT COALESCE(MAX(ordem),0)+1 FROM portal_pfsense_lojas")->fetchColumn();
        $pdo->prepare("INSERT INTO portal_pfsense_lojas (loja,ip,usuario,senha_enc,ordem) VALUES (?,?,?,?,?)")->execute([$l,$i,$u,vault_encrypt($s),$mo]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]); exit;
    }

    if ($action === 'edit') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id=(int)($body['id']??0); $l=trim($body['loja']??''); $i=trim($body['ip']??'');
        $u=trim($body['usuario']??''); $s=$body['senha']??'';
        if (!$id||!$l||!$i||!$u) { echo json_encode(['ok'=>false,'msg'=>'Preencha todos os campos']); exit; }
        if ($s) $pdo->prepare("UPDATE portal_pfsense_lojas SET loja=?,ip=?,usuario=?,senha_enc=? WHERE id=?")->execute([$l,$i,$u,vault_encrypt($s),$id]);
        else    $pdo->prepare("UPDATE portal_pfsense_lojas SET loja=?,ip=?,usuario=? WHERE id=?")->execute([$l,$i,$u,$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'delete' && isset($_GET['id'])) {
        $pdo->prepare("DELETE FROM portal_pfsense_lojas WHERE id=?")->execute([(int)$_GET['id']]);
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação inválida']); exit;
}

// ── Busca loja ──────────────────────────────────────────────────
$loja = null;
if ($loja_id) {
    $st = $pdo->prepare("SELECT * FROM portal_pfsense_lojas WHERE id=? AND ativo=1");
    $st->execute([$loja_id]);
    $loja = $st->fetch(PDO::FETCH_ASSOC);
    if (!$loja) { http_response_code(404); echo 'Loja não encontrada'; exit; }
}

// ── Login via cURL ──────────────────────────────────────────────
if ($loja && !isset($_SESSION['pfsense_logged_' . $loja_id])) {
    $ip   = $loja['ip'];
    $user = $loja['usuario'];
    $pass = vault_decrypt($loja['senha_enc']);
    $ckfile = sys_get_temp_dir() . '/pfs_' . session_id() . '_' . $loja_id . '.txt';

    // Tenta HTTPS, fallback HTTP
    $loginOk = false;
    foreach (['https','http'] as $proto) {
        if ($loginOk) break;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "$proto://$ip/",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_COOKIEJAR => $ckfile,
            CURLOPT_COOKIEFILE => $ckfile,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);
        $page1 = curl_exec($ch);
        $code1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code1 >= 400 || !$page1) { curl_close($ch); continue; }

        // Extrai CSRF — pfSense 2.7+ usa JS var, versões antigas usam hidden input
        $csrf = '';
        if (preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/i', $page1, $m)) $csrf = $m[1];
        elseif (preg_match('/__csrf_magic[^v]*value="([^"]+)"/is', $page1, $m)) $csrf = $m[1];
        elseif (preg_match('/__csrf_magic[^>]*>\s*([^<]+)</is', $page1, $m)) $csrf = trim($m[1]);
        $csrf = html_entity_decode($csrf, ENT_QUOTES|ENT_HTML5);

        // POST login
        $pf = ['usernamefld'=>$user,'passwordfld'=>$pass,'login'=>'Login'];
        if ($csrf) $pf['__csrf_magic'] = $csrf;

        curl_setopt_array($ch, [
            CURLOPT_URL => "$proto://$ip/index.php",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($pf),
            CURLOPT_COOKIEFILE => $ckfile,
            CURLOPT_COOKIEJAR => $ckfile,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp2 = curl_exec($ch);
        $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code2==200||$code2==302) {
            // Verifica se saiu da página de login
            if (stripos($resp2,'usernamefld')===false) {
                $loginOk = true;
                $_SESSION['pfsense_proto_' . $loja_id] = $proto;
                break;
            }
        }
    }

    if (!$loginOk) {
        unset($_SESSION['pfsense_logged_' . $loja_id]);
        @unlink($ckfile);
        http_response_code(502);
        echo "Falha no login do pfSense ($ip). Verifique usuário e senha.";
        exit;
    }

    $_SESSION['pfsense_logged_' . $loja_id] = true;
    $_SESSION['pfsense_ck_' . $loja_id] = $ckfile;
    $_SESSION['pfsense_ip_' . $loja_id] = $ip;
}

// ── Proxy de página ─────────────────────────────────────────────
if ($loja && isset($_GET['path'])) {
    $ip    = $loja['ip'];
    $proto = $_SESSION['pfsense_proto_' . $loja_id] ?? 'https';
    $ckfile = $_SESSION['pfsense_ck_' . $loja_id] ?? null;

    if (!$ckfile || !file_exists($ckfile)) {
        unset($_SESSION['pfsense_logged_' . $loja_id]);
        header('Location: pfsense_proxy.php?loja=' . $loja_id . '&path=' . urlencode($path));
        exit;
    }

    $ch = curl_init();
    $opts = [
        CURLOPT_URL => "$proto://$ip$path",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIEFILE => $ckfile,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_HEADER => true,
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($_POST);
    }

    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode >= 400) {
        unset($_SESSION['pfsense_logged_' . $loja_id]);
        @unlink($ckfile);
        header('Location: pfsense_proxy.php?loja=' . $loja_id . '&path=' . urlencode($path));
        exit;
    }

    // Extrai body
    $body = $resp;
    $hdEnd = strpos($resp, "\r\n\r\n");
    if ($hdEnd !== false) $body = substr($resp, $hdEnd + 4);

    $qs = 'loja=' . $loja_id . '&path=';

    // Se HTML, faz rewrite + injeção de barra
    if ($contentType && stripos($contentType, 'text/html') !== false) {
        // Bloqueia frame-busting JS do pfSense
        $body = preg_replace('/<script[^>]*>[\s\S]*?if\s*\(\s*top\s*!=\s*self\s*\)[\s\S]*?<\/script>/i', '<!-- frame-busting blocked -->', $body);
        $body = preg_replace('/\b(top|parent|window\.top)\.location(\.href)?\s*=[^;]+;?/i', '/* blocked */', $body);

        // Rewrite URLs absolutas e relativas em HTML
        $body = preg_replace(
            '/(<(?:a|link|script|img|form|iframe|source)\s[^>]*?(?:href|src|action)\s*=\s*["\'])\/([^"\']*)(["\'])/i',
            '$1pfsense_proxy.php?' . $qs . '/$2$3', $body
        );
        $body = preg_replace(
            '/(<(?:a|link|script|img|form|iframe|source)\s[^>]*?(?:href|src|action)\s*=\s*["\'])(?!http|https|\/|#|[a-z]+:|pfsense_proxy\.php)([^"\']*)(["\'])/i',
            '$1pfsense_proxy.php?' . $qs . '/$2$3', $body
        );

        // Injeta barra superior + styles no pfSense
        $topbar = '
<style>
.pfs-topbar{background:#1f2937;color:white;padding:.65rem 1rem;display:flex;align-items:center;gap:.7rem;flex-wrap:wrap;font-size:1rem;font-weight:500;position:sticky;top:0;z-index:9999;box-shadow:0 2px 8px rgba(0,0,0,.4);font-family:"Segoe UI",sans-serif;}
.pfs-topbar a,.pfs-topbar button{background:rgba(255,255,255,.12);border:none;color:white;border-radius:6px;padding:.35rem .75rem;font-size:.85rem;cursor:pointer;transition:all .12s;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;font-family:inherit;white-space:nowrap;}
.pfs-topbar a:hover,.pfs-topbar button:hover{background:rgba(255,255,255,.25);}
.pfs-topbar .pfs-sep{color:#6b7280;font-size:.7rem;}
.pfs-topbar .pfs-badge{background:#374151;border-radius:4px;padding:.25rem .55rem;font-size:.8rem;font-family:Consolas,monospace;}
.pfs-topbar .pfs-lnome{font-weight:700;}
</style>
<div class="pfs-topbar">
  <a href="pfsense_proxy.php"><i class="bi bi-arrow-left"></i> Voltar</a><span class="pfs-sep">|</span>
  <i class="bi bi-shield-fill-check" style="color:#ef4444"></i>
  <span class="pfs-lnome">' . htmlspecialchars($loja['loja']) . '</span>
  <span class="pfs-badge">' . htmlspecialchars($ip) . '</span>
  <span class="pfs-sep">|</span>
  <a href="pfsense_proxy.php?loja=' . $loja_id . '&path=/"><i class="bi bi-arrow-clockwise"></i> Recarregar</a>
  <a href="' . $proto . '://' . htmlspecialchars($ip) . '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Externo</a>
</div>';
        $body = str_replace('</head>', '<script>if(typeof events==="undefined"){var events=[];}</script>' . "\n" . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">' . "\n" . '</head>', $body);
        // Injeta barra superior no início do body
        $body = preg_replace('/<body[^>]*>/i', '$0' . $topbar, $body);
    }

    // Se CSS, faz rewrite de url() e @import — inclusive paths relativos
    if ($contentType && stripos($contentType, 'text/css') !== false) {
        $cssBase = dirname($path);
        $body = preg_replace_callback('/url\(\s*["\']?([^"\')\s]+)["\']?\s*\)/i', function($m) use ($qs, $cssBase) {
            $url = trim($m[1]);
            if (preg_match('/^(https?:|data:|#)/i', $url)) return $m[0];
            if (strpos($url, '/') === 0) return "url('pfsense_proxy.php?" . $qs . "/" . ltrim($url, '/') . "')";
            // Resolve path relativo contra o diretório do CSS
            $resolved = $cssBase . '/' . $url;
            $parts = explode('/', $resolved);
            $out = [];
            foreach ($parts as $p) {
                if ($p === '' || $p === '.') continue;
                if ($p === '..') array_pop($out);
                else $out[] = $p;
            }
            return "url('pfsense_proxy.php?" . $qs . "/" . implode('/', $out) . "')";
        }, $body);
        $body = preg_replace_callback('/@import\s+["\']([^"\']+)["\']/i', function($m) use ($qs) {
            $url = $m[1];
            if (strpos($url, '/') === 0) return '@import "pfsense_proxy.php?' . $qs . '/' . ltrim($url, '/') . '"';
            return $m[0];
        }, $body);
    }

    if ($contentType) header("Content-Type: $contentType");
    echo $body;
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Central pfSense</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root{--primary:#b91c1c;}*{box-sizing:border-box;}
    body{background:#f0f4f9;font-family:'Segoe UI',sans-serif;min-height:100vh;margin:0;}
    .topbar{background:linear-gradient(135deg,#7f1d1d,var(--primary));color:white;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(0,0,0,.25);position:sticky;top:0;z-index:100;}
    .topbar .brand{font-weight:700;font-size:1rem;display:flex;align-items:center;gap:.5rem;}
    .topbar a{color:white;text-decoration:none;font-size:.82rem;background:rgba(255,255,255,.15);border-radius:6px;padding:.3rem .75rem;}
    .topbar a:hover{background:rgba(255,255,255,.25);}
    .hero{background:linear-gradient(135deg,#7f1d1d,var(--primary));color:white;padding:2rem 1rem 4rem;text-align:center;}
    .wrap{max-width:800px;margin:-2.5rem auto 3rem;padding:0 1rem;}
    .loja-card{background:white;border-radius:12px;border:1px solid #e5e7eb;padding:1.25rem 1.5rem;margin-bottom:.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;transition:box-shadow .18s;}
    .loja-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
    .loja-info{display:flex;align-items:center;gap:1rem;flex:1;min-width:0;}
    .loja-icon{width:48px;height:48px;border-radius:12px;background:#fee2e2;color:#b91c1c;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;}
    .loja-nome{font-weight:700;font-size:1rem;color:#111;}
    .loja-ip{font-size:.8rem;color:#6b7280;font-family:'Consolas','Courier New',monospace;}
    .loja-user{font-size:.75rem;color:#9ca3af;}
    .loja-actions{display:flex;align-items:center;gap:.5rem;flex-shrink:0;}
    .btn-pfsense{border:none;border-radius:8px;padding:.5rem .9rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:.35rem;}
    .btn-pfsense:hover{filter:brightness(1.1);transform:translateY(-1px);}
    .btn-reveal{background:#f3f4f6;color:#374151;border:none;border-radius:8px;padding:.5rem .65rem;font-size:.85rem;cursor:pointer;transition:all .15s;}
    .btn-reveal:hover{background:#e5e7eb;}
    .btn-reveal.revealed{background:#fef3c7;color:#92400e;}
    .pwd-display{font-family:'Consolas','Courier New',monospace;font-size:.85rem;color:#059669;font-weight:700;background:#f0fdf4;border-radius:6px;padding:.2rem .6rem;display:none;align-items:center;gap:.5rem;}
    .pwd-display.show{display:inline-flex;}
    .pwd-display .btn-copy{border:none;background:#d1fae5;border-radius:4px;padding:.1rem .4rem;cursor:pointer;font-size:.7rem;color:#065f46;}
    .pwd-display .btn-copy:hover{background:#a7f3d0;}
    .btn-config{background:transparent;border:none;color:#9ca3af;cursor:pointer;padding:.3rem;border-radius:6px;font-size:.9rem;}
    .btn-config:hover{background:#f3f4f6;color:#374151;}
    .card-add{border:2px dashed #d1d5db;background:transparent;border-radius:12px;padding:1.5rem;text-align:center;color:#9ca3af;cursor:pointer;margin-bottom:.75rem;transition:all .15s;}
    .card-add:hover{border-color:#6b7280;color:#374151;background:#f9fafb;}
    .stats-row{display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;}
    .stat-pill{background:white;border:1px solid #e5e7eb;border-radius:10px;padding:.5rem 1rem;font-size:.8rem;display:flex;align-items:center;gap:.5rem;box-shadow:0 1px 4px rgba(0,0,0,.04);}
    #toast-container{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;}
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-shield-fill-check me-1"></i> Central pfSense</div>
  <div style="display:flex;gap:.5rem">
    <?php if ($is_admin): ?>
    <button onclick="abrirModalLoja()" style="background:rgba(255,255,255,.15);border:none;color:white;border-radius:6px;padding:.3rem .75rem;font-size:.82rem;cursor:pointer"><i class="bi bi-plus-lg me-1"></i>Nova Loja</button>
    <?php endif; ?>
    <a href="acessos.php"><i class="bi bi-grid-3x3-gap-fill me-1"></i>Acessos</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>
<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0"><i class="bi bi-shield-fill-check me-2"></i>pfSense — Todas as Lojas</h1>
  <p style="opacity:.8;margin-top:.5rem">Clique em <strong>Abrir</strong> para acessar sem digitar senha</p>
</div>

<div id="view-list"><div class="wrap" id="lista-lojas"></div></div>

<!-- Modal -->
<div class="modal fade" id="modalLoja" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#7f1d1d,#b91c1c);color:white">
        <h5 class="modal-title fw-bold" id="modal-loja-label"><i class="bi bi-shield-fill-check me-2"></i>Nova Loja</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-id"/>
        <div class="mb-3"><label class="form-label fw-semibold">Loja</label><input type="text" class="form-control" id="edit-loja" placeholder="Loja 001"/></div>
        <div class="mb-3"><label class="form-label fw-semibold">IP</label><input type="text" class="form-control font-monospace" id="edit-ip" placeholder="192.168.1.1"/></div>
        <div class="mb-3"><label class="form-label fw-semibold">Usuário</label><input type="text" class="form-control font-monospace" id="edit-usuario" placeholder="admin"/></div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Senha <span class="text-muted small">(em branco mantém ao editar)</span></label>
          <input type="password" class="form-control font-monospace" id="edit-senha" placeholder="••••••••"/>
          <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="edit-mostrar-senha" onchange="alternarVisibilidadeSenha()"><label class="form-check-label small" for="edit-mostrar-senha">Mostrar senha</label></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="salvarLoja()" style="background:#b91c1c;border-color:#b91c1c"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </div>
  </div>
</div>
<div id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modalLoja; const isAdmin=<?= $is_admin?'true':'false' ?>;
document.addEventListener('DOMContentLoaded',()=>{modalLoja=new bootstrap.Modal(document.getElementById('modalLoja'));carregarLojas();});

async function carregarLojas(){
  const r=await fetch('pfsense_proxy.php?action=list'), d=await r.json(), el=document.getElementById('lista-lojas'), lojas=d.dados||[];
  if(!lojas.length){el.innerHTML=`<div style="text-align:center;padding:3rem 1rem;color:#9ca3af"><i class="bi bi-shield-slash" style="font-size:3rem;display:block;margin-bottom:1rem"></i><p>Nenhuma loja cadastrada.</p>${isAdmin?'<button class="btn btn-outline-danger btn-sm" onclick="abrirModalLoja()">Adicionar</button>':''}</div>`;return;}
  let html=`<div class="stats-row"><div class="stat-pill"><i class="bi bi-shield-fill-check text-danger"></i>${lojas.length} loja(s)</div><div class="stat-pill"><i class="bi bi-layers text-muted"></i>Clique em Abrir para acessar automático</div></div>`;
  lojas.forEach(l=>{html+=`<div class="loja-card" id="loja-${l.id}"><div class="loja-info"><div class="loja-icon"><i class="bi bi-shield-fill-check"></i></div><div><div class="loja-nome">${esc(l.loja)}</div><div class="loja-ip">${esc(l.ip)}</div><div class="loja-user">${esc(l.usuario)}</div></div></div><div class="loja-actions"><div class="pwd-display" id="pwd-${l.id}"><span id="pwd-val-${l.id}"></span><button class="btn-copy" onclick="copiarSenha(${l.id})" title="Copiar senha"><i class="bi bi-clipboard"></i></button></div><button class="btn-reveal" id="reveal-${l.id}" onclick="revelarSenha(${l.id})" title="Ver senha"><i class="bi bi-eye"></i></button><button class="btn-pfsense" style="background:#059669;color:white" onclick="abrirPlayer(${l.id})"><i class="bi bi-play-fill"></i>Abrir</button>${isAdmin?`<button class="btn-config" onclick="editarLoja(${l.id})"><i class="bi bi-pencil-fill"></i></button><button class="btn-config" onclick="excluirLoja(${l.id})" style="color:#ef4444"><i class="bi bi-trash-fill"></i></button>`:''}</div></div>`;});
  if(isAdmin) html+=`<div class="card-add" onclick="abrirModalLoja()"><i class="bi bi-plus-circle" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i><strong>Adicionar nova loja</strong></div>`;
  el.innerHTML=html;
}

function abrirPlayer(lojaId){
  window.location.href = 'pfsense_proxy.php?loja=' + lojaId + '&path=/';
}

async function revelarSenha(id){
  const btn=document.getElementById('reveal-'+id),pwdEl=document.getElementById('pwd-'+id),valEl=document.getElementById('pwd-val-'+id);
  if(pwdEl.classList.contains('show')){pwdEl.classList.remove('show');btn.classList.remove('revealed');btn.innerHTML='<i class="bi bi-eye"></i>';return;}
  const r=await fetch('pfsense_proxy.php?action=reveal&id='+id),d=await r.json();
  if(!d.ok){toast(d.msg||'Erro','danger');return;}
  valEl.textContent=d.senha;pwdEl.classList.add('show');btn.classList.add('revealed');btn.innerHTML='<i class="bi bi-eye-slash"></i>';
}
async function copiarSenha(id){
  const v=document.getElementById('pwd-val-'+id).textContent;
  try{await navigator.clipboard.writeText(v)}catch{const ta=document.createElement('textarea');ta.value=v;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta)}
  toast('📋 Copiada!');
}
function abrirModalLoja(){
  ['edit-id','edit-loja','edit-ip','edit-usuario','edit-senha'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('edit-mostrar-senha').checked=false;document.getElementById('edit-senha').type='password';
  document.getElementById('modal-loja-label').innerHTML='<i class="bi bi-plus-circle-fill me-2"></i>Nova Loja';modalLoja.show();
}
async function editarLoja(id){
  const r=await fetch('pfsense_proxy.php?action=reveal&id='+id),d=await r.json();
  if(!d.ok){toast(d.msg||'Erro','danger');return;}
  document.getElementById('edit-id').value=id;document.getElementById('edit-loja').value=d.loja;
  document.getElementById('edit-ip').value=d.ip;document.getElementById('edit-usuario').value=d.usuario;
  document.getElementById('edit-senha').value='';document.getElementById('edit-mostrar-senha').checked=false;
  document.getElementById('edit-senha').type='password';
  document.getElementById('modal-loja-label').innerHTML='<i class="bi bi-pencil-fill me-2"></i>'+esc(d.loja);modalLoja.show();
}
async function salvarLoja(){
  const id=document.getElementById('edit-id').value,loja=document.getElementById('edit-loja').value.trim(),
        ip=document.getElementById('edit-ip').value.trim(),usuario=document.getElementById('edit-usuario').value.trim(),
        senha=document.getElementById('edit-senha').value;
  if(!loja||!ip||!usuario){toast('Preencha loja, IP e usuário','danger');return;}
  if(!id&&!senha){toast('Informe a senha','danger');return;}
  const action=id?'edit':'add';
  const r=await fetch('pfsense_proxy.php?action='+action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:parseInt(id)||0,loja,ip,usuario,senha})});
  const d=await r.json();
  if(d.ok){modalLoja.hide();toast(id?'✅ Atualizada!':'✅ Adicionada!');carregarLojas();}else toast(d.msg||'Erro','danger');
}
async function excluirLoja(id){
  if(!confirm('Excluir esta loja?'))return;
  const r=await fetch('pfsense_proxy.php?action=delete&id='+id),d=await r.json();
  if(d.ok){toast('🗑️ Excluída');carregarLojas();}else toast(d.msg||'Erro','danger');
}
function alternarVisibilidadeSenha(){document.getElementById('edit-senha').type=document.getElementById('edit-mostrar-senha').checked?'text':'password';}
function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
function toast(msg,type='success'){const id='t-'+Date.now(),bg=type==='success'?'bg-success':'bg-danger';document.getElementById('toast-container').insertAdjacentHTML('beforeend',`<div id="${id}" class="toast align-items-center text-white ${bg} border-0 show mb-2"><div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="document.getElementById('${id}').remove()"></button></div></div>`);setTimeout(()=>document.getElementById(id)?.remove(),4000);}
</script>
</body>
</html>
