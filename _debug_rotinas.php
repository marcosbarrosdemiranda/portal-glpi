<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }

require_once __DIR__ . '/agenda/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== TABELAS EXISTENTES ===\n";
$tabs = $pdo->query("SHOW TABLES LIKE '%recurrent%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($tabs);

$tabs2 = $pdo->query("SHOW TABLES LIKE '%template%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($tabs2);

echo "\n=== glpi_ticketrecurrents — COLUNAS REAIS ===\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM glpi_ticketrecurrents")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  - {$c['Field']} ({$c['Type']})\n";

    echo "\n=== glpi_ticketrecurrents — TODOS OS DADOS ===\n";
    $dados = $pdo->query("SELECT * FROM glpi_ticketrecurrents")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dados as $d) {
        echo "ID:{$d['id']} | name:{$d['name']} | periodicity:{$d['periodicity']} | template_id:{$d['tickettemplates_id']} | is_active:{$d['is_active']}\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

echo "\n=== glpi_tickettemplatepredefinedfields — TODOS (num DISTINCT) ===\n";
try {
    $nums = $pdo->query("SELECT DISTINCT num FROM glpi_tickettemplatepredefinedfields ORDER BY num")->fetchAll(PDO::FETCH_COLUMN);
    echo "Valores de 'num' encontrados: " . implode(', ', $nums) . "\n\n";

    // Agrupa por num
    foreach ($nums as $n) {
        $q = $pdo->prepare("SELECT * FROM glpi_tickettemplatepredefinedfields WHERE num = ?");
        $q->execute([$n]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        echo "--- num=$n (" . count($rows) . " registros) ---\n";
        foreach ($rows as $r) {
            echo "  id={$r['id']} | tickettemplates_id={$r['tickettemplates_id']} | valor=" . mb_substr($r['valor'] ?? $r['value'] ?? '', 0, 80) . "\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

echo "\n=== glpi_tickettemplates — TODOS ===\n";
try {
    $tpls = $pdo->query("SELECT id, nome FROM glpi_tickettemplates ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tpls as $t) echo "  id={$t['id']} | nome={$t['nome']}\n";
} catch (Exception $e) {
    echo "ERRO (tentando name em vez de nome): " . $e->getMessage() . "\n";
    try {
        $tpls = $pdo->query("SELECT id, name FROM glpi_tickettemplates ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tpls as $t) echo "  id={$t['id']} | name={$t['name']}\n";
    } catch (Exception $e2) {
        echo "ERRO 2: " . $e2->getMessage() . "\n";
    }
}

echo "\n=== BUSCA num=5 (users_id_assign) em glpi_tickettemplatepredefinedfields ===\n";
try {
    $q5 = $pdo->prepare("SELECT * FROM glpi_tickettemplatepredefinedfields WHERE num = 5");
    $q5->execute();
    $r5 = $q5->fetchAll(PDO::FETCH_ASSOC);
    if (count($r5) > 0) {
        print_r($r5);
    } else {
        echo "(vazio — não há registros com num=5)\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

echo "\n=== BUSCA num=24 (users_id_assign alternativo) ===\n";
try {
    $q24 = $pdo->prepare("SELECT * FROM glpi_tickettemplatepredefinedfields WHERE num = 24");
    $q24->execute();
    $r24 = $q24->fetchAll(PDO::FETCH_ASSOC);
    if (count($r24) > 0) print_r($r24);
    else echo "(vazio)\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

echo "\n=== VERIFICANDO TABELA glpi_users ===\n";
try {
    $users = $pdo->query("SELECT id, nome, realname, firstname, name FROM glpi_users WHERE id IN (2,3,4,5,6,7,8,9,10)")->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) > 0) {
        foreach ($users as $u) echo "  id={$u['id']} | nome={$u['nome']} | realname={$u['realname']} | firstname={$u['firstname']} | name={$u['name']}\n";
    } else {
        // Tenta com realname/firstname apenas
        $users2 = $pdo->query("SELECT id, realname, firstname, name FROM glpi_users LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users2 as $u) echo "  id={$u['id']} | realname={$u['realname']} | firstname={$u['firstname']} | name={$u['name']}\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

// Qual o ID do usuário atual?
echo "\n=== Usuário da sessão atual ===\n";
echo "Session user: " . ($_SESSION['autenticado'] ?? 'N/A') . "\n";
if (!empty($_SESSION['user_id'])) echo "Session user_id: {$_SESSION['user_id']}\n";
