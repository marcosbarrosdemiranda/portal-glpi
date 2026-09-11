<?php
// Acesso ao banco pro módulo WhatsApp. Reusa o $pdo do portal.
require_once __DIR__ . '/../agenda/db.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_config (
    chave VARCHAR(64) PRIMARY KEY,
    valor TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// --- Fase 2: tabelas de notificações outbound ---

// Contatos WhatsApp por usuário GLPI (destino das DMs)
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_contatos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    glpi_user_id INT NOT NULL,
    telefone VARCHAR(20) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user (glpi_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Números autorizados a receber/interagir (allowlist)
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_autorizados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telefone VARCHAR(20) NOT NULL,
    entities_id INT NULL,
    nome VARCHAR(120) DEFAULT '',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_tel (telefone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Dedup de eventos já notificados
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_notificados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(24) NOT NULL,
    ref_id VARCHAR(64) NOT NULL,
    hash VARCHAR(40) NOT NULL DEFAULT '',
    enviado_em DATETIME NOT NULL,
    UNIQUE KEY uq_evento (tipo, ref_id, hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// DMs agendadas (envio com atraso)
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_dm_agendado (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    glpi_user_id INT NOT NULL,
    telefone VARCHAR(20) NOT NULL,
    enviar_em DATETIME NOT NULL,
    status ENUM('pendente','enviado','cancelado') NOT NULL DEFAULT 'pendente',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dm (ticket_id, glpi_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Log de mensagens/eventos do módulo
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    direcao ENUM('out','in','sys') NOT NULL DEFAULT 'out',
    destino VARCHAR(64) DEFAULT '',
    resumo VARCHAR(255) DEFAULT '',
    status VARCHAR(24) DEFAULT '',
    criado_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function wpp_cfg_get(string $chave, ?string $default = null): ?string {
    global $pdo;
    $st = $pdo->prepare("SELECT valor FROM portal_wpp_config WHERE chave = ?");
    $st->execute([$chave]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function wpp_cfg_set(string $chave, string $valor): void {
    global $pdo;
    $st = $pdo->prepare(
        "INSERT INTO portal_wpp_config (chave, valor) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
    );
    $st->execute([$chave, $valor]);
}

// Retorna o NOW() do banco como string ('YYYY-MM-DD HH:MM:SS').
// Usado por tasks/worker pra manter horário consistente com o servidor de BD.
function wpp_agora_db(PDO $pdo): string {
    return (string) $pdo->query("SELECT NOW()")->fetchColumn();
}

// Grava uma linha em portal_wpp_log. Nunca lança — falha de log não pode
// derrubar o fluxo de notificação.
function wpp_log(string $direcao, string $destino, string $resumo, string $status): void {
    global $pdo;
    try {
        $st = $pdo->prepare(
            "INSERT INTO portal_wpp_log (direcao, destino, resumo, status, criado_em)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $st->execute([$direcao, $destino, $resumo, $status]);
    } catch (\Throwable $e) {
        // silencioso de propósito
    }
}

// True se o evento (tipo, ref_id, hash) já foi notificado.
function wpp_ja_notificado(string $tipo, string $ref_id, string $hash = ''): bool {
    global $pdo;
    $st = $pdo->prepare(
        "SELECT 1 FROM portal_wpp_notificados
         WHERE tipo = ? AND ref_id = ? AND hash = ? LIMIT 1"
    );
    $st->execute([$tipo, $ref_id, $hash]);
    return (bool) $st->fetchColumn();
}

// Marca o evento como notificado. INSERT IGNORE — chamar 2x não quebra.
function wpp_marcar_notificado(string $tipo, string $ref_id, string $hash = ''): void {
    global $pdo;
    $st = $pdo->prepare(
        "INSERT IGNORE INTO portal_wpp_notificados (tipo, ref_id, hash, enviado_em)
         VALUES (?, ?, ?, NOW())"
    );
    $st->execute([$tipo, $ref_id, $hash]);
}

// --- Fase 3: dedup de mensagens recebidas via webhook ---

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_msgs_vistas (
    message_id VARCHAR(128) PRIMARY KEY,
    visto_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// True se esse message.id da Evolution já foi processado (webhook reentregue).
function wpp_msg_ja_vista(string $message_id): bool {
    global $pdo;
    $st = $pdo->prepare("SELECT 1 FROM portal_wpp_msgs_vistas WHERE message_id = ? LIMIT 1");
    $st->execute([$message_id]);
    return (bool) $st->fetchColumn();
}

// Marca o message_id como visto. INSERT IGNORE — chamar 2x não quebra.
// Poda oportunista (1 em 20 chamadas): apaga vistos com mais de 7 dias, sem
// precisar de cron dedicado pra uma tabela que só cresce.
function wpp_marcar_msg_vista(string $message_id): void {
    global $pdo;
    $st = $pdo->prepare(
        "INSERT IGNORE INTO portal_wpp_msgs_vistas (message_id, visto_em) VALUES (?, NOW())"
    );
    $st->execute([$message_id]);
    if (mt_rand(1, 20) === 1) {
        $pdo->exec("DELETE FROM portal_wpp_msgs_vistas WHERE visto_em < NOW() - INTERVAL 7 DAY");
    }
}

// --- Fase 3 Etapa 2: chatbot (FSM + vínculo + histórico de chamados) ---

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_conversas (
    telefone VARCHAR(20) PRIMARY KEY,
    estado JSON NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_vinculos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telefone VARCHAR(20) NOT NULL,
    glpi_user_id INT NOT NULL,
    rotulo VARCHAR(120) DEFAULT '',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_tel (telefone),
    KEY idx_user (glpi_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_chamados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telefone VARCHAR(20) NOT NULL,
    ticket_id INT NOT NULL,
    origem ENUM('vinculado','pendencia') NOT NULL,
    criado_em DATETIME NOT NULL,
    UNIQUE KEY uq_tel_ticket (telefone, ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
