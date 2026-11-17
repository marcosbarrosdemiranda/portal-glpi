<?php
/**
 * agenda/projetos_db.php — catálogo de Projetos do Orçamento de TI.
 *
 * Dimensão de agrupamento ACIMA da categoria (Hardware/Software/...):
 * um projeto como "Upgrade do CFTV" reúne itens de categorias diferentes
 * (câmera, DVR, cabo). Ortogonal à categoria — não mexe nela.
 *
 * A tabela glpi_portal_projetos é criada em agenda/orcamento_db.php
 * (mesmo padrão das tabelas de backup/alertas: guard no include).
 *
 * Edição de nome (rename) liberada a pedido do usuário em 2026-10-18.
 * Exclusão continua de fora por ora — decisão consciente, mesma já tomada
 * pra categoria: o que fazer com itens já vinculados a um projeto excluído
 * é pergunta em aberto, não resolver sem perguntar primeiro.
 */
require_once __DIR__ . '/db.php';

function listarProjetos(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM glpi_portal_projetos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
}

// Ação POST (ex: criar projeto novo direto do modal de orçamento/despesa).
// Guard de sessão própria — diferente do agenda/tipos_db.php original
// (que nasceu sem guard por ser código morto na época), este arquivo já
// nasce com alcance real.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_projeto'])) {
    if (empty($_SESSION['autenticado'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'erro' => 'não autenticado']);
        exit;
    }

    if ($_POST['action_projeto'] === 'add') {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        if ($nome === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'erro' => 'nome vazio']);
            exit;
        }
        try {
            $pdo->prepare("INSERT INTO glpi_portal_projetos (nome) VALUES (?)")->execute([$nome]);
            $id = (int) $pdo->lastInsertId();
        } catch (\PDOException $e) {
            // UNIQUE (nome) — já existe, devolve o id existente em vez de erro
            if (!str_contains($e->getMessage(), 'nome')) throw $e;
            $st = $pdo->prepare("SELECT id FROM glpi_portal_projetos WHERE nome = ?");
            $st->execute([$nome]);
            $id = (int) $st->fetchColumn();
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id, 'nome' => $nome]);
        exit;
    }

    // Renomear projeto existente. Só o nome muda — nenhum item é
    // revinculado, porque o vínculo é por projeto_id, não pelo texto.
    if ($_POST['action_projeto'] === 'rename') {
        $id   = (int) ($_POST['id'] ?? 0);
        $nome = trim((string) ($_POST['nome'] ?? ''));
        if ($id <= 0 || $nome === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'erro' => 'dados inválidos']);
            exit;
        }
        try {
            $pdo->prepare("UPDATE glpi_portal_projetos SET nome = ? WHERE id = ?")->execute([$nome, $id]);
        } catch (\PDOException $e) {
            // UNIQUE (nome) — aqui não dá pra "reaproveitar" como no add:
            // renomear pra um nome já usado por outro projeto é erro real.
            if (!str_contains($e->getMessage(), 'nome')) throw $e;
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'erro' => 'já existe um projeto com esse nome']);
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id, 'nome' => $nome]);
        exit;
    }
}
