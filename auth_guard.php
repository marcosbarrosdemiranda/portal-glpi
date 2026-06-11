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
            // Polling em background → sinaliza para o cliente redirecionar
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
}
