<?php
/**
 * Guarda de sessão do portal.
 * - Inicia a sessão.
 * - Aplica logout por inatividade (60 min). Só atividade real renova o relógio;
 *   requisições de fundo (polling) devem enviar ?bg=1 e NÃO renovam.
 *
 * Inclua no TOPO de cada página protegida, ANTES de checar $_SESSION['autenticado'].
 * NÃO incluir em auth.php (página de login).
 */
if (session_status() === PHP_SESSION_NONE) session_start();

if (!defined('PORTAL_INATIVIDADE')) define('PORTAL_INATIVIDADE', 60 * 60); // 60 minutos

if (!empty($_SESSION['autenticado'])) {
    $idle = isset($_SESSION['last_activity']) ? (time() - $_SESSION['last_activity']) : 0;

    if (!empty($_SESSION['last_activity']) && $idle > PORTAL_INATIVIDADE) {
        // Expirou: destrói a sessão
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();

        if (isset($_GET['bg'])) {
            http_response_code(440);
            header('Content-Type: application/json');
            echo json_encode(['timeout' => true]);
            exit;
        }
        header('Location: auth.php?timeout=1');
        exit;
    }

    // Só atividade real renova (polling em background não conta)
    if (!isset($_GET['bg'])) {
        $_SESSION['last_activity'] = time();
    }

    // ── Carrega perfil do portal (uma vez por sessão) ──────────────────────
    if (!array_key_exists('portal_perfil_cards', $_SESSION)) {
        $_SESSION['portal_perfil_cards'] = null; // null = sem perfil (vê tudo / respeita self-service)
        $uid_guard = (int)($_SESSION['user_id'] ?? 0);
        if ($uid_guard) {
            try {
                $db_host_g = 'localhost'; $db_user_g = 'root'; $db_pass_g = ''; $db_name_g = 'glpi2';
                $pdo_guard = new PDO("mysql:host={$db_host_g};dbname={$db_name_g};charset=utf8mb4",
                    $db_user_g, $db_pass_g, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $st = $pdo_guard->prepare("
                    SELECT pp.cards FROM portal_perfil_usuarios pu
                    JOIN portal_perfis pp ON pp.id = pu.perfil_id
                    WHERE pu.user_id = ?
                ");
                $st->execute([$uid_guard]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $_SESSION['portal_perfil_cards'] = json_decode($row['cards'] ?? '{}', true) ?: [];
                }
            } catch (Exception $e) { /* tabelas ainda não existem */ }
        }
    }

    // Se tem perfil do portal, não trata como self-service nas outras páginas
    if ($_SESSION['portal_perfil_cards'] !== null && ($_SESSION['perfil'] ?? '') === 'self-service') {
        $_SESSION['perfil_glpi'] = 'self-service'; // guarda perfil GLPI original
        $_SESSION['perfil'] = 'portal';
    }
}
