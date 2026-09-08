<?php
/**
 * alertas_lib.php — queries de alerta do parque (dados do GLPI).
 *
 * Funções puras: sem HTML, sem sessão, sem require de entidade_alias.
 * Retornam as linhas cruas do banco (PDO::FETCH_ASSOC).
 *
 * Extraído de alertas.php (Central de Alertas) para reuso pelo worker
 * de notificações do WhatsApp (Fase 2).
 *
 * As SQLs abaixo são cópia exata das de alertas.php — só as constantes
 * ALERTA_INV_DIAS (7) e ALERTA_DISCO_PCT (90) viraram parâmetros, com
 * os mesmos defaults. Os valores entram na SQL por interpolação de
 * inteiro (cast para (int) antes, por segurança).
 */

// 1) Máquinas sem reportar inventário há +N dias (ou nunca)
function alertas_sem_inventario(PDO $pdo, int $dias = 7): array
{
    $dias = (int)$dias;
    return $pdo->query("
    SELECT c.name, c.last_inventory_update, e.completename AS loja,
           COALESCE(pc.categoria,'pcs-retaguarda') AS cat
    FROM glpi_computers c
    LEFT JOIN glpi_entities e ON e.id = c.entities_id
    LEFT JOIN portal_inv_pc_cat pc ON pc.computer_id = c.id
    LEFT JOIN portal_inv_baixas bx ON bx.itemtype='Computer' AND bx.items_id = c.id
    WHERE c.is_deleted = 0 AND c.is_template = 0 AND bx.id IS NULL
      AND (COALESCE(pc.categoria,'') <> '__ignorado__')
      AND (c.last_inventory_update IS NULL OR c.last_inventory_update < (NOW() - INTERVAL " . $dias . " DAY))
    ORDER BY c.last_inventory_update IS NULL DESC, c.last_inventory_update ASC
")->fetchAll(PDO::FETCH_ASSOC);
}

// 2) Discos quase cheios — só volumes de dados (> 30 GB); ignora partições de
//    recuperação/sistema (sempre ~99% cheias por natureza).
function alertas_disco_cheio(PDO $pdo, int $pct = 90): array
{
    $pct = (int)$pct;
    return $pdo->query("
    SELECT c.name, e.completename AS loja, d.name AS volume,
           d.totalsize, d.freesize,
           ROUND((d.totalsize - d.freesize) / d.totalsize * 100) AS pct
    FROM glpi_items_disks d
    JOIN glpi_computers c ON c.id = d.items_id AND d.itemtype='Computer' AND c.is_deleted = 0
    LEFT JOIN glpi_entities e ON e.id = c.entities_id
    WHERE d.totalsize > 30000
      AND (d.totalsize - d.freesize) / d.totalsize * 100 >= " . $pct . "
      AND d.name NOT REGEXP '(?i)(recov|image|reserv|winre|system|efi|pbr|oem)'
    ORDER BY pct DESC
")->fetchAll(PDO::FETCH_ASSOC);
}

// Snapshot completo — usado pelo worker pra comparar com o estado anterior.
function alertas_snapshot(PDO $pdo): array
{
    return [
        'sem_inventario' => alertas_sem_inventario($pdo),
        'disco_cheio'    => alertas_disco_cheio($pdo),
    ];
}
