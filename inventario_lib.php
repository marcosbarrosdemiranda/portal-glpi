<?php
/**
 * inventario_lib.php — base do módulo de Inventário do portal.
 *
 * Modelo híbrido:
 *  - O ativo em si mora no GLPI (glpi_peripherals / glpi_phones), gravado via API REST.
 *  - A taxonomia (cards, subcategorias) e os campos personalizados moram em
 *    tabelas portal_inv_* deste banco.
 *
 * Requer: $pdo (agenda/db.php) e as constantes GLPI_* (agenda/config.php).
 */

require_once __DIR__ . '/agenda/config.php';

/* ───────────────────────────── Tabelas ───────────────────────────── */

function inv_bootstrap(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS portal_inv_cards (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            slug       VARCHAR(40)  NOT NULL UNIQUE,
            titulo     VARCHAR(80)  NOT NULL,
            descricao  VARCHAR(160) DEFAULT '',
            icone      VARCHAR(40)  DEFAULT 'bi-box',
            cor        VARCHAR(9)   DEFAULT '#0097a7',
            fonte      ENUM('peripheral','phone','computer') NOT NULL DEFAULT 'peripheral',
            ordem      INT     DEFAULT 100,
            ativo      TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS portal_inv_subcats (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            card_id      INT NOT NULL,
            nome         VARCHAR(80) NOT NULL,
            glpi_type_id INT DEFAULT NULL COMMENT 'id em glpi_peripheraltypes ou glpi_phonetypes',
            ordem        INT DEFAULT 100,
            FOREIGN KEY (card_id) REFERENCES portal_inv_cards(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS portal_inv_fields (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            card_id     INT DEFAULT NULL COMMENT 'NULL = campo global (todos os cards)',
            chave       VARCHAR(40) NOT NULL,
            label       VARCHAR(80) NOT NULL,
            tipo        ENUM('text','number','date','select','textarea','checkbox') NOT NULL DEFAULT 'text',
            opcoes      TEXT DEFAULT NULL COMMENT 'select: uma opção por linha',
            obrigatorio TINYINT(1) DEFAULT 0,
            ordem       INT DEFAULT 100
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS portal_inv_values (
            id       INT AUTO_INCREMENT PRIMARY KEY,
            itemtype VARCHAR(20) NOT NULL COMMENT 'Peripheral | Phone',
            items_id INT NOT NULL,
            field_id INT NOT NULL,
            valor    TEXT DEFAULT NULL,
            UNIQUE KEY uniq_item_field (itemtype, items_id, field_id),
            FOREIGN KEY (field_id) REFERENCES portal_inv_fields(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS portal_inv_baixas (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            itemtype    VARCHAR(20) NOT NULL,
            items_id    INT NOT NULL,
            motivo      ENUM('quebrado','vendido','descartado','outro') NOT NULL DEFAULT 'quebrado',
            observacao  TEXT DEFAULT NULL,
            baixado_em  DATE DEFAULT NULL,
            baixado_por VARCHAR(120) DEFAULT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_item (itemtype, items_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS portal_inv_pc_cat (
            computer_id   INT NOT NULL PRIMARY KEY,
            categoria     VARCHAR(40) NOT NULL,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            atualizado_por VARCHAR(120) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Migrações leves (rodam sempre)
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM portal_inv_fields") as $r) $cols[] = $r['Field'];
    if (!in_array('na_lista', $cols, true)) {
        $pdo->exec("ALTER TABLE portal_inv_fields ADD COLUMN na_lista TINYINT(1) DEFAULT 0 COMMENT 'mostra como coluna na listagem'");
    }
    // fonte 'computer' no enum
    $ef = $pdo->query("SHOW COLUMNS FROM portal_inv_cards LIKE 'fonte'")->fetch();
    if ($ef && !str_contains($ef['Type'], "'computer'")) {
        $pdo->exec("ALTER TABLE portal_inv_cards MODIFY fonte ENUM('peripheral','phone','computer') NOT NULL DEFAULT 'peripheral'");
    }

    // Garante os cards de computadores (mesmo que a semente geral já tenha rodado)
    $temCard = $pdo->prepare("SELECT COUNT(*) FROM portal_inv_cards WHERE slug = ?");
    $insC2   = $pdo->prepare("INSERT INTO portal_inv_cards (slug,titulo,descricao,icone,cor,fonte,ordem) VALUES (?,?,?,?,?,'computer',?)");
    foreach ([
        ['pcs-retaguarda',  'PCs Retaguarda',    'Computadores de escritório e back-office', 'bi-pc-display', '#0097a7', 5],
        ['notebooks',       'Notebooks',         'Notebooks e ultrabooks',                   'bi-laptop',     '#00838f', 6],
        ['pdvs',            'PDVs',              'Frentes de caixa / pontos de venda',       'bi-cart-check', '#00796b', 7],
        ['maquinas-virtuais','Servidores / VMs', 'Servidores físicos e máquinas virtuais',   'bi-hdd-stack',  '#5e35b1', 8],
    ] as [$sl,$ti,$de,$ic,$co,$or]) {
        $temCard->execute([$sl]);
        if (!$temCard->fetchColumn()) $insC2->execute([$sl,$ti,$de,$ic,$co,$or]);
    }

    // Notebooks: classifica pelo tipo de hardware do GLPI (idempotente — só toca máquina sem categoria)
    $insNb = $pdo->prepare("INSERT IGNORE INTO portal_inv_pc_cat (computer_id, categoria, atualizado_por) VALUES (?, 'notebooks', 'classificacao-notebook')");
    foreach ($pdo->query("SELECT c.id FROM glpi_computers c JOIN glpi_computertypes t ON t.id = c.computertypes_id
                          WHERE t.name = 'Notebook' AND c.is_deleted = 0 AND c.is_template = 0") as $r) {
        $insNb->execute([(int)$r['id']]);
    }

    // Lixo do agente (containers Docker / sem tipo de hardware): joga em "Ignorado".
    // INSERT IGNORE = só toca máquina sem categoria; se o usuário mover, não volta.
    $insIg = $pdo->prepare("INSERT IGNORE INTO portal_inv_pc_cat (computer_id, categoria, atualizado_por) VALUES (?, '__ignorado__', 'classificacao-ignorados')");
    foreach ($pdo->query("SELECT c.id, c.name, t.name AS tipo
                          FROM glpi_computers c LEFT JOIN glpi_computertypes t ON t.id = c.computertypes_id
                          WHERE c.is_deleted = 0 AND c.is_template = 0") as $r) {
        if (inv_pc_regra($r['name'], $r['tipo']) === '__ignorado__') $insIg->execute([(int)$r['id']]);
    }

    // Classificação inicial dos computadores (só na 1ª vez que a tabela está vazia)
    if ((int)$pdo->query("SELECT COUNT(*) FROM portal_inv_pc_cat")->fetchColumn() === 0) {
        $ins = $pdo->prepare("INSERT IGNORE INTO portal_inv_pc_cat (computer_id, categoria, atualizado_por) VALUES (?,?,'classificacao-inicial')");
        $q = "SELECT c.id, c.name, t.name AS tipo
              FROM glpi_computers c LEFT JOIN glpi_computertypes t ON t.id = c.computertypes_id
              WHERE c.is_deleted = 0 AND c.is_template = 0";
        foreach ($pdo->query($q) as $r) {
            $slug = inv_pc_regra($r['name'], $r['tipo']);
            if ($slug !== 'pcs-retaguarda') $ins->execute([(int)$r['id'], $slug]);  // retaguarda é o default, não precisa gravar
        }
    }

    // Semente inicial geral (só roda se ainda não há nenhum card)
    if ((int)$pdo->query("SELECT COUNT(*) FROM portal_inv_cards")->fetchColumn() > 3) return;

    $seed = [
        ['celulares',  'Celulares',            'Smartphones e linhas corporativas',            'bi-phone',              '#1565c0', 'phone',      ['Celular']],
        ['tablets',    'Tablets',              'Tablets corporativos e acessórios',            'bi-tablet',             '#7b1fa2', 'peripheral', ['Tablet']],
        ['coletores',  'Coletores',            'Coletores de dados e leitores de código',       'bi-upc-scan',           '#2e7d32', 'peripheral', ['Coletor']],
        ['pdvmobile',  'PDV Mobile',           'Terminais de venda móveis e PDV portátil',      'bi-credit-card-2-back', '#e65100', 'peripheral', ['PDV Mobile']],
        ['pinpads',    'Pinpads',              'Pinpads e leitores de cartão',                  'bi-credit-card',        '#3949ab', 'peripheral', ['Pinpad']],
        ['pos',        'POS',                  'Terminais POS e maquininhas de pagamento',      'bi-shop-window',        '#00796b', 'peripheral', ['POS']],
        ['termometros','Termômetros',          'Termômetros e sensores de temperatura',         'bi-thermometer-half',   '#c62828', 'peripheral', ['Termômetro']],
        ['radios',     'Rádios Comunicação',   'Rádios comunicadores e HTs',                    'bi-walkie-talkie',      '#0277bd', 'peripheral', ['Rádio Comunicador']],
        ['som',        'Equipamentos de Som',  'Caixas de som, amplificadores e microfones',    'bi-speaker-fill',       '#8e24aa', 'peripheral', ['Equipamento de Som']],
        ['acessorios', 'Acessórios Celulares', 'Fones, carregadores, cabos e capas',            'bi-headphones',         '#f9a825', 'peripheral', ['Acessório Celular']],
        ['triturador', 'Triturador de Papel',  'Fragmentadoras e trituradoras de documentos',   'bi-scissors',           '#546e7a', 'peripheral', ['Triturador de Papel']],
        ['videoconf',  'Videoconferência',     'Câmeras, barras de som e equipamentos de VC',   'bi-camera-video-fill',  '#1a73e8', 'peripheral', ['Videoconferência']],
        ['cftv',       'CFTV',                 'Câmeras, gravadores e infraestrutura de CFTV',  'bi-camera-reels-fill',  '#d84315', 'peripheral', ['Câmera', 'DVR', 'NVR', 'PowerBalun']],
    ];

    $insCard = $pdo->prepare("INSERT INTO portal_inv_cards (slug,titulo,descricao,icone,cor,fonte,ordem) VALUES (?,?,?,?,?,?,?)");
    $insSub  = $pdo->prepare("INSERT INTO portal_inv_subcats (card_id,nome,ordem) VALUES (?,?,?)");
    $ordem = 10;
    foreach ($seed as $c) {
        [$slug,$titulo,$desc,$icone,$cor,$fonte,$subs] = $c;
        $insCard->execute([$slug,$titulo,$desc,$icone,$cor,$fonte,$ordem]);
        $cardId = (int)$pdo->lastInsertId();
        $o = 10;
        foreach ($subs as $s) { $insSub->execute([$cardId,$s,$o]); $o += 10; }
        $ordem += 10;
    }
}

/* ───────────────────────────── Taxonomia ───────────────────────────── */

function inv_cards(PDO $pdo, bool $todos = false): array {
    $sql = "SELECT * FROM portal_inv_cards" . ($todos ? '' : " WHERE ativo = 1") . " ORDER BY ordem, titulo";
    return $pdo->query($sql)->fetchAll();
}

function inv_card(PDO $pdo, string $slug): ?array {
    $st = $pdo->prepare("SELECT * FROM portal_inv_cards WHERE slug = ?");
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function inv_subcats(PDO $pdo, int $cardId): array {
    $st = $pdo->prepare("SELECT * FROM portal_inv_subcats WHERE card_id = ? ORDER BY ordem, nome");
    $st->execute([$cardId]);
    return $st->fetchAll();
}

/** Campos personalizados aplicáveis a um card (globais + do card). */
function inv_fields(PDO $pdo, int $cardId): array {
    $st = $pdo->prepare("SELECT * FROM portal_inv_fields WHERE card_id IS NULL OR card_id = ? ORDER BY ordem, label");
    $st->execute([$cardId]);
    return $st->fetchAll();
}

/** valores personalizados de um ativo → [field_id => valor] */
function inv_values(PDO $pdo, string $itemtype, int $itemsId): array {
    $st = $pdo->prepare("SELECT field_id, valor FROM portal_inv_values WHERE itemtype = ? AND items_id = ?");
    $st->execute([$itemtype, $itemsId]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** valores de vários ativos de uma vez → [items_id => [field_id => valor]] */
function inv_values_bulk(PDO $pdo, string $itemtype, array $itemsIds): array {
    $itemsIds = array_values(array_filter(array_map('intval', $itemsIds)));
    if (!$itemsIds) return [];
    $ph = implode(',', array_fill(0, count($itemsIds), '?'));
    $st = $pdo->prepare("SELECT items_id, field_id, valor FROM portal_inv_values WHERE itemtype = ? AND items_id IN ($ph)");
    $st->execute(array_merge([$itemtype], $itemsIds));
    $out = [];
    foreach ($st as $r) $out[(int)$r['items_id']][(int)$r['field_id']] = $r['valor'];
    return $out;
}

function inv_save_values(PDO $pdo, string $itemtype, int $itemsId, array $porFieldId): void {
    $up = $pdo->prepare("INSERT INTO portal_inv_values (itemtype,items_id,field_id,valor)
                         VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    foreach ($porFieldId as $fid => $val) {
        $up->execute([$itemtype, $itemsId, (int)$fid, ($val === '' ? null : $val)]);
    }
}

/* ───────────────────────────── GLPI REST API ───────────────────────────── */

function glpi_session(): ?string {
    $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . base64_encode(GLPI_USER . ':' . GLPI_PASS),
            'App-Token: ' . GLPI_APP_TOKEN,
        ],
    ]);
    $r = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $r['session_token'] ?? null;
}

function glpi_kill(?string $token): void {
    if (!$token) return;
    $ch = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Session-Token: ' . $token, 'App-Token: ' . GLPI_APP_TOKEN],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Chamada genérica à API. Retorna [httpcode, corpo-decodificado].
 * $method: GET|POST|PUT|DELETE
 */
function glpi_call(string $method, string $endpoint, ?array $body, string $token): array {
    $ch = curl_init(GLPI_URL . '/apirest.php/' . ltrim($endpoint, '/'));
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Session-Token: ' . $token,
            'App-Token: ' . GLPI_APP_TOKEN,
            'Content-Type: application/json',
        ],
    ];
    if ($body !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opt);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true)];
}

/**
 * Garante que existe uma linha de dropdown do GLPI com esse nome (Manufacturer,
 * PeripheralModel, PhoneModel, PeripheralType…). Cria via API se faltar.
 */
function glpi_ensure_dropdown(PDO $pdo, string $tabela, string $endpoint, string $nome, string $token): ?int {
    $nome = trim($nome);
    if ($nome === '') return null;
    $st = $pdo->prepare("SELECT id FROM `$tabela` WHERE name = ?");
    $st->execute([$nome]);
    $id = $st->fetchColumn();
    if ($id !== false) return (int)$id;

    [$code, $resp] = glpi_call('POST', $endpoint, ['input' => ['name' => $nome]], $token);
    if ($code >= 200 && $code < 300) {
        if (isset($resp['id'])) return (int)$resp['id'];
        if (isset($resp[0]['id'])) return (int)$resp[0]['id'];
    }
    return null;
}

/** Idem, para o "tipo" (PeripheralType / PhoneType) conforme a fonte do card. */
function glpi_ensure_type(PDO $pdo, string $fonte, string $nome, string $token): ?int {
    return $fonte === 'phone'
        ? glpi_ensure_dropdown($pdo, 'glpi_phonetypes', 'PhoneType', $nome, $token)
        : glpi_ensure_dropdown($pdo, 'glpi_peripheraltypes', 'PeripheralType', $nome, $token);
}

/** Resolve/atualiza o glpi_type_id de todas as subcategorias de um card. */
function inv_sync_types(PDO $pdo, array $card, string $token): void {
    $up = $pdo->prepare("UPDATE portal_inv_subcats SET glpi_type_id = ? WHERE id = ?");
    foreach (inv_subcats($pdo, (int)$card['id']) as $sc) {
        if ($sc['glpi_type_id']) continue;
        $tid = glpi_ensure_type($pdo, $card['fonte'], $sc['nome'], $token);
        if ($tid) $up->execute([$tid, $sc['id']]);
    }
}

/**
 * Lista os ativos de um card direto do GLPI (sem filtros de loja/busca).
 * $view = 'ativos' | 'baixados'. Usado pelo relatório e pode alimentar a listagem.
 */
function inv_ativos_do_card(PDO $pdo, array $card, array $subcats, string $view = 'ativos'): array {
    $fonte    = $card['fonte'];
    if ($fonte === 'computer') {
        $rows = inv_computers_do_card($pdo, $card['slug'], $view);
        foreach ($rows as &$r) { $r['subcategoria'] = $r['tipo_hw'] ?: '—'; }
        return $rows;
    }
    $itemtype = $fonte === 'phone' ? 'Phone' : 'Peripheral';
    $tbl      = $fonte === 'phone' ? 'glpi_phones' : 'glpi_peripherals';
    $tblType  = $fonte === 'phone' ? 'glpi_phonetypes' : 'glpi_peripheraltypes';
    $tblModel = $fonte === 'phone' ? 'glpi_phonemodels' : 'glpi_peripheralmodels';
    $colType  = $fonte === 'phone' ? 'phonetypes_id' : 'peripheraltypes_id';
    $colModel = $fonte === 'phone' ? 'phonemodels_id' : 'peripheralmodels_id';

    $typeIds    = array_values(array_filter(array_map(fn($s) => (int)$s['glpi_type_id'], $subcats)));
    $aplicaTipo = ($fonte === 'peripheral') || count($subcats) > 1;

    $where  = ["p.is_deleted = 0", "p.is_template = 0"];
    $params = [];
    if ($fonte === 'peripheral') $where[] = "p.is_dynamic = 0";
    $where[] = $view === 'baixados' ? "bx.id IS NOT NULL" : "bx.id IS NULL";
    if ($aplicaTipo) {
        if (!$typeIds) { $where[] = "1 = 0"; }
        else {
            $ph = [];
            foreach ($typeIds as $i => $t) { $ph[] = ":t$i"; $params[":t$i"] = $t; }
            $where[] = "p.$colType IN (" . implode(',', $ph) . ")";
        }
    }
    $sql = "SELECT p.id, p.name, p.serial, p.otherserial, p.contact, p.entities_id,
                   e.completename AS entidade, m.name AS fabricante, md.name AS modelo,
                   t.name AS subcategoria, bx.motivo AS baixa_motivo, bx.baixado_em AS baixa_data
            FROM `$tbl` p
            LEFT JOIN portal_inv_baixas bx ON bx.itemtype = " . $pdo->quote($itemtype) . " AND bx.items_id = p.id
            LEFT JOIN glpi_entities e      ON e.id  = p.entities_id
            LEFT JOIN glpi_manufacturers m ON m.id  = p.manufacturers_id
            LEFT JOIN `$tblModel` md       ON md.id = p.$colModel
            LEFT JOIN `$tblType` t         ON t.id  = p.$colType
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.completename, p.name";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/* ───────────────────────────── Computadores (PCs / PDVs / VMs) ───────────────────────────── */

const INV_PC_CATS = [
    'pcs-retaguarda'    => 'PC Retaguarda',
    'notebooks'         => 'Notebook',
    'pdvs'              => 'PDV',
    'maquinas-virtuais' => 'Servidor / VM',
    '__ignorado__'      => 'Ignorado (não é PC)',
];

/** Palpite de categoria só para a classificação inicial. Depois é manual. */
function inv_pc_regra(string $nome, ?string $tipoGlpi): string {
    $n = mb_strtoupper(trim($nome));
    // Lixo do agente: containers Docker / WSL. Sem tipo de hardware = quase certeza que não é PC.
    if (!$tipoGlpi
        || str_contains($n, 'DOCKER')
        || preg_match('/\bON .+ ACCOUNT$/', $n)            // "docker-desktop on ti1gr account", "Ubuntu on ... account"
        || preg_match('/^(SENSOR-|HOMEASSISTANT|PORTAINER)/', $n)) return '__ignorado__';
    if (str_starts_with($n, 'PDV')) return 'pdvs';
    if ($tipoGlpi === 'VMware' || $n === 'ARQFUNC'
        || preg_match('/(SERVER|SERVIDOR|\bSRV\b|HYPER-?V|ESXI|VCENTER|DELPHOS|GUNNEBO|\bTS\b|DOMINIO)/', $n)) return 'maquinas-virtuais';
    if ($tipoGlpi === 'Notebook') return 'notebooks';
    return 'pcs-retaguarda';
}

function inv_pc_categoria(PDO $pdo, int $computerId): string {
    $st = $pdo->prepare("SELECT categoria FROM portal_inv_pc_cat WHERE computer_id = ?");
    $st->execute([$computerId]);
    return $st->fetchColumn() ?: 'pcs-retaguarda';
}

function inv_pc_set_categoria(PDO $pdo, int $computerId, string $slug, string $por): void {
    if (!array_key_exists($slug, INV_PC_CATS)) $slug = 'pcs-retaguarda';
    $pdo->prepare("INSERT INTO portal_inv_pc_cat (computer_id, categoria, atualizado_por) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE categoria = VALUES(categoria), atualizado_por = VALUES(atualizado_por)")
        ->execute([$computerId, $slug, $por]);
}

/** Todos os computadores de uma categoria (slug do card), já filtrados por view. */
function inv_computers_do_card(PDO $pdo, string $cardSlug, string $view = 'ativos'): array {
    $cond = $view === 'baixados' ? 'bx.id IS NOT NULL' : 'bx.id IS NULL';
    $sql = "SELECT c.id, c.name, c.serial, c.otherserial, c.contact, c.entities_id,
                   e.completename AS entidade, t.name AS tipo_hw,
                   m.name AS fabricante, md.name AS modelo, pc.categoria AS cat_salva,
                   bx.motivo AS baixa_motivo, bx.observacao AS baixa_obs,
                   bx.baixado_em AS baixa_data, bx.baixado_por AS baixa_por
            FROM glpi_computers c
            LEFT JOIN portal_inv_pc_cat pc ON pc.computer_id = c.id
            LEFT JOIN portal_inv_baixas bx ON bx.itemtype = 'Computer' AND bx.items_id = c.id
            LEFT JOIN glpi_entities e       ON e.id  = c.entities_id
            LEFT JOIN glpi_computertypes t  ON t.id  = c.computertypes_id
            LEFT JOIN glpi_manufacturers m  ON m.id  = c.manufacturers_id
            LEFT JOIN glpi_computermodels md ON md.id = c.computermodels_id
            WHERE c.is_deleted = 0 AND c.is_template = 0 AND $cond
            ORDER BY e.completename, c.name";
    $rows = $pdo->query($sql)->fetchAll();
    return array_values(array_filter($rows, fn($r) => ($r['cat_salva'] ?: 'pcs-retaguarda') === $cardSlug));
}

/* ───────────────────────────── Baixa / desativação ───────────────────────────── */

const INV_MOTIVOS = [
    'quebrado'   => 'Quebrado definitivamente',
    'vendido'    => 'Vendido',
    'descartado' => 'Descartado',
    'outro'      => 'Outro',
];

function inv_baixa_set(PDO $pdo, string $itemtype, int $itemsId, string $motivo, string $obs, ?string $data, string $por): void {
    $motivo = array_key_exists($motivo, INV_MOTIVOS) ? $motivo : 'quebrado';
    $pdo->prepare("
        INSERT INTO portal_inv_baixas (itemtype, items_id, motivo, observacao, baixado_em, baixado_por)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE motivo=VALUES(motivo), observacao=VALUES(observacao),
                                baixado_em=VALUES(baixado_em), baixado_por=VALUES(baixado_por)
    ")->execute([$itemtype, $itemsId, $motivo, $obs ?: null, $data ?: null, $por ?: null]);
}

function inv_baixa_clear(PDO $pdo, string $itemtype, int $itemsId): void {
    $pdo->prepare("DELETE FROM portal_inv_baixas WHERE itemtype = ? AND items_id = ?")->execute([$itemtype, $itemsId]);
}

function inv_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
