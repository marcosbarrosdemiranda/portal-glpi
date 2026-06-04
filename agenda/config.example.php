<?php
// ─────────────────────────────────────────────────────────────
//  config.example.php — TEMPLATE de configuração
//  Copie este arquivo para config.php e preencha os valores.
//  NUNCA commite o config.php — ele contém credenciais reais.
// ─────────────────────────────────────────────────────────────

// Fuso horário
date_default_timezone_set('America/Campo_Grande');

// URL base do GLPI (sem barra no final)
define('GLPI_URL', 'http://localhost/glpi2');

// App Token gerado em: GLPI → Configuração → Geral → API
define('GLPI_APP_TOKEN', 'SEU_APP_TOKEN_AQUI');

// Usuário e senha do GLPI com permissão de API
define('GLPI_USER', 'glpi');
define('GLPI_PASS', 'sua_senha_aqui');

// Caminho absoluto no filesystem para o diretório raiz do GLPI
// Necessário para servir anexos direto do disco (XAMPP padrão abaixo)
// Deixe '' para usar fallback via sessão web
define('GLPI_ABSPATH', 'C:/xampp/htdocs/glpi2');

// ── Atendente padrão para o CRON de rotinas ──────────────────
// ID e nome exato do técnico responsável pelas rotinas recorrentes
define('SYNC_ATENDENTE_ID',   0);   // ex: 42
define('SYNC_ATENDENTE_NOME', '');  // ex: 'Barros Miranda'
