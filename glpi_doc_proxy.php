<?php
/**
 * Proxy autenticado para servir documentos do GLPI.
 *
 * Estratégia (em ordem de prioridade):
 *  1. Leitura direta do filesystem (mais confiável para localhost).
 *     Auto-detecta a raiz do GLPI via DOCUMENT_ROOT + path da URL.
 *     Pode ser forçado via constante GLPI_ABSPATH em config.php.
 *  2. Sessão web via cookie (fallback para GLPI em servidor remoto).
 *     Faz login web real (cookie jar) para acessar /front/document.send.php,
 *     que exige sessão PHP — o token REST não funciona nesse endpoint.
 *     Armazena o cookie por até 30 min para evitar login a cada requisição.
 */
session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(403); exit; }

$docid = (int)($_GET['docid'] ?? 0);
if (!$docid) { http_response_code(400); exit; }

require_once __DIR__ . '/agenda/config.php';

// ── Arquivo de cache do cookie de sessão web ──────────────────────────────
$cookie_file = sys_get_temp_dir() . '/glpi_web_session_' . md5(GLPI_URL . GLPI_USER) . '.txt';

// ── Função auxiliar: baixa o documento usando o cookie de sessão web ──────
function baixar_documento(int $docid, string $cookie_file): array {
    $ch = curl_init(GLPI_URL . '/front/document.send.php?docid=' . $docid);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE     => $cookie_file,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);
    $blob = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['blob' => $blob, 'code' => $code, 'ct' => $ct ?? ''];
}

// ── Função: faz login web e salva o cookie ────────────────────────────────
function fazer_login_web(string $cookie_file): bool {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // Passo 1: GET na página de login para pegar CSRF se necessário
    $ch1 = curl_init(GLPI_URL . '/front/login.php');
    curl_setopt_array($ch1, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookie_file,
        CURLOPT_COOKIEFILE     => $cookie_file,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => $ua,
    ]);
    $html = curl_exec($ch1);
    curl_close($ch1);

    // Extrai CSRF token se presente (GLPI 10+)
    // Suporta name= antes ou depois de value= no atributo do input
    $csrf = '';
    if (preg_match('/name=["\']_glpi_csrf_token["\'][^>]*value=["\'](.*?)["\']/i', $html ?? '', $m)) {
        $csrf = $m[1];
    } elseif (preg_match('/value=["\'](.*?)["\'][^>]*name=["\']_glpi_csrf_token["\']/i', $html ?? '', $m)) {
        $csrf = $m[1];
    }

    // Passo 2: POST com credenciais
    $fields = ['login_name' => GLPI_USER, 'login_password' => GLPI_PASS, 'submit' => '1'];
    if ($csrf) $fields['_glpi_csrf_token'] = $csrf;

    $ch2 = curl_init(GLPI_URL . '/front/login.php');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => $cookie_file,
        CURLOPT_COOKIEFILE     => $cookie_file,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => $ua,
    ]);
    curl_exec($ch2);
    $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    return $code === 200;
}

// ── Busca metadados via REST: mime type e filepath ────────────────────────
$auth  = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch0   = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch0, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Basic '.$auth, 'App-Token: '.GLPI_APP_TOKEN],
    CURLOPT_TIMEOUT        => 10,
]);
$sess = json_decode(curl_exec($ch0), true);
curl_close($ch0);
$token = $sess['session_token'] ?? '';

$mime     = 'application/octet-stream';
$filepath = '';
if ($token) {
    $hdr  = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];
    $chM  = curl_init(GLPI_URL . '/apirest.php/Document/'.$docid);
    curl_setopt_array($chM, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdr, CURLOPT_TIMEOUT => 10]);
    $meta = json_decode(curl_exec($chM), true);
    curl_close($chM);
    $mime     = $meta['mime']     ?? $mime;
    $filepath = $meta['filepath'] ?? '';

    $chK = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($chK, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdr, CURLOPT_TIMEOUT => 5]);
    curl_exec($chK); curl_close($chK);
}

// ── MÉTODO 1: leitura direta do filesystem (confiável para localhost) ─────
//
// GLPI está em localhost → o PHP pode ler os arquivos direto do disco,
// sem precisar de sessão web. Evita o problema de cookie/cURL para localhost.
//
// Detecção automática: GLPI_URL='http://localhost/glpi2'
//   → tenta DOCUMENT_ROOT + '/glpi2' → verifica se o diretório existe
//
// Configuração manual (opcional): defina GLPI_ABSPATH em agenda/config.php
//   Ex: define('GLPI_ABSPATH', 'C:/xampp/htdocs/glpi2');
//
if ($filepath) {
    // Raiz manual via constante ou auto-detecção via DOCUMENT_ROOT
    $glpi_root = defined('GLPI_ABSPATH') && GLPI_ABSPATH ? GLPI_ABSPATH : '';

    if (!$glpi_root && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $url_path = parse_url(GLPI_URL, PHP_URL_PATH); // ex: '/glpi2'
        if ($url_path && $url_path !== '/') {
            $candidate = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\')
                       . str_replace('/', DIRECTORY_SEPARATOR, $url_path);
            if (is_dir($candidate)) {
                $glpi_root = $candidate;
            }
        }
    }

    if ($glpi_root) {
        $full = rtrim($glpi_root, '/\\')
              . DIRECTORY_SEPARATOR . 'files'
              . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $filepath), '/\\');

        if (file_exists($full) && is_readable($full)) {
            header('Content-Type: ' . $mime);
            header('Cache-Control: private, max-age=3600');
            readfile($full);
            exit;
        }
    }
}

// ── MÉTODO 2: sessão web via cookie (fallback para GLPI remoto) ───────────
$precisa_login = !file_exists($cookie_file) || (filemtime($cookie_file) < time() - 1800);

if ($precisa_login) {
    fazer_login_web($cookie_file);
}

$res = baixar_documento($docid, $cookie_file);

// Se redirecionou para login (resposta é HTML), re-autentica e tenta novamente
if ($res['code'] !== 200 || stripos($res['ct'], 'text/html') !== false) {
    fazer_login_web($cookie_file);
    $res = baixar_documento($docid, $cookie_file);
}

if ($res['code'] !== 200 || !$res['blob'] || stripos($res['ct'], 'text/html') !== false) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=3600');
echo $res['blob'];
