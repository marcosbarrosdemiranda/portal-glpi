<?php
/**
 * monitor_mapa_api.php — API JSON do Mapa de Rede (etapa 4).
 *
 * Chamado pelo JS da página inventario_mapa_rede.php a cada 30 s.
 * Não gera HTML; responde JSON com todos os dados necessários para
 * renderizar o mapa sem recarregar a página inteira.
 *
 * Parâmetros GET:
 *   bg=1       → não renova o timer de inatividade do auth_guard
 *
 * Resposta:
 * {
 *   dispositivos: [...],   // monitor_listar() completo
 *   links: [...],          // monitor_links_listar()
 *   vpn_fora: {...},       // monitor_vpn_fora()  loja => {nome, ip, desde}
 *   rodada: {...},         // monitor_resumo_rodada()
 *   gerado_em: "Y-m-d H:i:s"
 * }
 */

require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { http_response_code(401); echo '{"erro":"nao autenticado"}'; exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { http_response_code(403); echo '{"erro":"acesso negado"}'; exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/monitor_lib.php';
require_once __DIR__ . '/monitor_links_lib.php';

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'dispositivos' => monitor_listar($pdo),
    'links'        => monitor_links_listar($pdo),
    'vpn_fora'     => monitor_vpn_fora($pdo),
    'rodada'       => monitor_resumo_rodada(),
    'gerado_em'    => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
