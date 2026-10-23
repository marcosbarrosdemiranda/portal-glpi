<?php

/**
 * Sincroniza o catálogo "Equipamento (Balança)" do plugin Fields do GLPI
 * (tabela glpi_plugin_fields_equipamentobalanafielddropdowns, gerada pelo
 * plugin pro campo dropdown do container "Reparo Equipamentos") com a
 * tabela própria do portal `portal_balancas`.
 *
 * Decisão de desenho: o id da linha do catálogo é SEMPRE igual ao id da
 * balança em `portal_balancas` (inserido explicitamente, não por
 * auto_increment) — é o que permite, nas Fases 2/3 (chamados vinculados +
 * BI), fazer o JOIN direto entre o valor salvo no campo do chamado e
 * `portal_balancas.id`, sem precisar de uma tabela de tradução.
 *
 * `$pdo` já conecta no banco `glpi2` (ver agenda/db.php) — mesma conexão
 * grava em `portal_balancas` e no catálogo do plugin, sem cross-database.
 *
 * Falha de sync nunca deve quebrar o fluxo principal de CRUD de balança
 * (cadastro/sync MGV continuam funcionando mesmo se o catálogo do GLPI
 * estiver indisponível) — por isso os erros são capturados e só vão pro
 * log, não pra resposta JSON do endpoint.
 */

function balanca_fields_sync_upsert(PDO $pdo, int $balanca_id, string $identificacao): void
{
    if (!$balanca_id || $identificacao === '') return;
    try {
        $pdo->prepare("
            INSERT INTO glpi_plugin_fields_equipamentobalanafielddropdowns
                (id, name, completename, entities_id, is_recursive)
            VALUES (:id, :nome1, :nome2, 0, 1)
            ON DUPLICATE KEY UPDATE name = :nome3, completename = :nome4
        ")->execute([
            ':id'    => $balanca_id,
            ':nome1' => $identificacao,
            ':nome2' => $identificacao,
            ':nome3' => $identificacao,
            ':nome4' => $identificacao,
        ]);
    } catch (Throwable $e) {
        error_log('[balancas_fields_sync] upsert falhou (balanca_id=' . $balanca_id . '): ' . $e->getMessage());
    }
}

function balanca_fields_sync_delete(PDO $pdo, int $balanca_id): void
{
    if (!$balanca_id) return;
    try {
        $pdo->prepare("DELETE FROM glpi_plugin_fields_equipamentobalanafielddropdowns WHERE id = ?")
            ->execute([$balanca_id]);
    } catch (Throwable $e) {
        error_log('[balancas_fields_sync] delete falhou (balanca_id=' . $balanca_id . '): ' . $e->getMessage());
    }
}
