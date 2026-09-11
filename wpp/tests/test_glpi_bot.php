<?php
// Teste da montagem do payload de criação de chamado (função pura, sem
// rede). A chamada real (bot_criar_chamado) é verificada no deploy — ver
// Global Constraints do plano da Etapa 2.
require_once __DIR__ . '/../glpi_bot.php';

$p = bot_criar_chamado_payload(42, 7, 'PC não liga', 'Tentei ligar e não acontece nada.');
t_eq($p['name'], 'PC não liga', 'payload: name = titulo');
t_eq($p['content'], 'Tentei ligar e não acontece nada.', 'payload: content = descricao');
t_eq($p['_users_id_requester'], 42, 'payload: requerente');
t_eq($p['entities_id'], 7, 'payload: entidade');
t_eq($p['type'], 1, 'payload: tipo Incidente');
t_eq($p['status'], 1, 'payload: status Novo (sem atendente)');

$p2 = bot_criar_chamado_payload(1, 1, 'Título', '');
t_eq($p2['content'], 'Título', 'payload: descricao vazia cai pro titulo');
