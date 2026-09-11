<?php
// wpp/chatbot.php — máquina de estados da conversa do WhatsApp (Fase 3).
// Sem HTML, funções isoladas, nunca lançam (o corpo de wpp_chatbot_processar
// tem seu próprio try/catch — ver Task 5).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guardrails.php';
// Propositalmente NÃO faz require de evo_api.php/glpi_bot.php aqui: quem
// chama este arquivo (wpp/webhook.php em produção, Task 6) é responsável
// por isso. Assim os testes deste arquivo (Tasks 3, 5, 7) podem substituir
// evo_send_text()/bot_criar_chamado() por fakes sem "Cannot redeclare".

// --- Estado da conversa (portal_wpp_conversas) ---

function wpp_chatbot_estado_get(string $telefone): ?array {
    global $pdo;
    $st = $pdo->prepare("SELECT estado FROM portal_wpp_conversas WHERE telefone = ?");
    $st->execute([$telefone]);
    $v = $st->fetchColumn();
    if ($v === false) {
        return null;
    }
    $d = json_decode((string) $v, true);
    return is_array($d) ? $d : null;
}

function wpp_chatbot_estado_set(string $telefone, array $estado): void {
    global $pdo;
    $st = $pdo->prepare(
        "INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE estado = VALUES(estado), updated_at = NOW()"
    );
    $st->execute([$telefone, json_encode($estado, JSON_UNESCAPED_UNICODE)]);
}

function wpp_chatbot_estado_limpar(string $telefone): void {
    global $pdo;
    $st = $pdo->prepare("DELETE FROM portal_wpp_conversas WHERE telefone = ?");
    $st->execute([$telefone]);
}

// --- Vínculo telefone -> usuário GLPI ---

// null, ou ['glpi_user_id'=>int, 'entities_id'=>int, 'nome'=>string].
// Ordem: 1) aba de vínculos manual (ativo=1); 2) match automático por
// glpi_users.phone/mobile, só se achar EXATAMENTE 1 usuário ativo.
function wpp_chatbot_resolve_vinculo(string $telefone): ?array {
    global $pdo;
    $tel = wpp_norm_telefone($telefone);
    if ($tel === '') {
        return null;
    }

    $st = $pdo->prepare(
        "SELECT v.glpi_user_id, u.entities_id,
                COALESCE(NULLIF(TRIM(CONCAT(u.realname,' ',u.firstname)),''), u.name) AS nome
         FROM portal_wpp_vinculos v
         JOIN glpi_users u ON u.id = v.glpi_user_id
         WHERE v.telefone = ? AND v.ativo = 1
         LIMIT 1"
    );
    $st->execute([$tel]);
    $row = $st->fetch();
    if ($row) {
        return ['glpi_user_id' => (int) $row['glpi_user_id'], 'entities_id' => (int) $row['entities_id'], 'nome' => $row['nome']];
    }

    $st = $pdo->prepare(
        "SELECT id, entities_id,
                COALESCE(NULLIF(TRIM(CONCAT(realname,' ',firstname)),''), name) AS nome
         FROM glpi_users
         WHERE is_active = 1 AND is_deleted = 0
           AND (REGEXP_REPLACE(phone, '[^0-9]', '') = ? OR REGEXP_REPLACE(mobile, '[^0-9]', '') = ?)"
    );
    $st->execute([$tel, $tel]);
    $rows = $st->fetchAll();
    if (count($rows) === 1) {
        return ['glpi_user_id' => (int) $rows[0]['id'], 'entities_id' => (int) $rows[0]['entities_id'], 'nome' => $rows[0]['nome']];
    }

    return null; // nenhum match ou ambíguo (2+)
}
