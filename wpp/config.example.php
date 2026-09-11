<?php
// Copie para wpp/config.php e preencha. wpp/config.php é gitignored.

// URL interna da Evolution API (nome do container na rede glpi-net).
define('EVO_URL', 'http://evolution-api:8080');

// = EVOLUTION_API_KEY do docker/.env
define('EVO_API_KEY', 'troque-por-uma-chave-aleatoria');

// Nome da instância única do portal.
define('EVO_INSTANCE', 'portal_ti');

// URL do webhook, alcançável DE DENTRO do container evolution-api até o
// glpi-web. Ajuste o path se a montagem do portal no container for outra.
define('WPP_WEBHOOK_URL', 'http://glpi-web/glpi2/portal-glpi/wpp/webhook.php');
