<?php
// Testa alertas_tipos.php — catálogo, criação da tabela, config por tipo e checks/renders.
// Precisa de banco → roda no deploy (php wpp/tests/run.php).
//
// ATENÇÃO: 'sem_inventario' e 'disco_cheio' são slugs REAIS do catálogo e o run.php
// roda contra o banco de produção. Por isso guardamos o estado original das duas
// linhas no começo e restauramos EXATAMENTE no finally — inclusive apagando a linha
// se ela não existia antes. Os asserts do harness (t_ok/t_eq) nunca lançam, então o
// finally sempre roda depois deles; uma PDOException também dispara o finally.
require_once __DIR__ . '/../../alertas_tipos.php';
global $pdo;

// snapshot do estado atual das linhas reais (null = linha ausente)
$orig = [];
foreach (['sem_inventario', 'disco_cheio'] as $t) {
    $st = $pdo->prepare("SELECT ativo,params,notif_whatsapp,lembrete_min,abre_chamado
                         FROM portal_alertas_config WHERE tipo = ?");
    $st->execute([$t]);
    $orig[$t] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

try {
    // catálogo
    $cat = alertas_catalogo();
    t_ok(isset($cat['sem_inventario'], $cat['disco_cheio']), 'catálogo tem os 2 tipos do GLPI');
    t_ok(is_callable($cat['sem_inventario']['check']) && is_callable($cat['sem_inventario']['render']),
         'sem_inventario tem check e render chamáveis');

    // tabela criada
    t_ok((bool) $pdo->query("SHOW TABLES LIKE 'portal_alertas_config'")->fetch(), 'portal_alertas_config existe');

    // config sem linha = defaults do catálogo
    $pdo->exec("DELETE FROM portal_alertas_config WHERE tipo = 'sem_inventario'");
    $c = alertas_config_do_tipo($pdo, 'sem_inventario');
    t_eq($c['ativo'], true, 'sem linha: ativo=true');
    t_eq($c['params']['dias'], 7, 'sem linha: params.dias = default 7');
    t_eq($c['lembrete_min'], 0, 'sem linha: lembrete_min = 0');

    // config com linha = mescla + clamp
    $pdo->prepare("INSERT INTO portal_alertas_config (tipo,ativo,params,notif_whatsapp,lembrete_min)
                   VALUES ('sem_inventario',0,'{\"dias\":999}',0,120)")->execute();
    $c = alertas_config_do_tipo($pdo, 'sem_inventario');
    t_eq($c['ativo'], false, 'com linha: ativo=false');
    t_eq($c['params']['dias'], 90, 'com linha: params.dias clampado no max 90');
    t_eq($c['notif_whatsapp'], false, 'com linha: notif_whatsapp=false');
    t_eq($c['lembrete_min'], 120, 'com linha: lembrete_min=120');
    $pdo->exec("DELETE FROM portal_alertas_config WHERE tipo = 'sem_inventario'");

    // params não-objeto -> defaults (MariaDB valida json_valid na coluna JSON, então usamos JSON escalar válido).
    // disco_cheio pode já ter linha real de produção (bug corrigido 2026-09-13: esse
    // INSERT direto colidia com PRIMARY KEY quando disco_cheio já estava configurado
    // de verdade) — apaga antes, mesmo padrão de segurança do bloco sem_inventario acima.
    $pdo->exec("DELETE FROM portal_alertas_config WHERE tipo = 'disco_cheio'");
    $pdo->prepare("INSERT INTO portal_alertas_config (tipo,params) VALUES ('disco_cheio','123')")->execute();
    $c = alertas_config_do_tipo($pdo, 'disco_cheio');
    t_eq($c['params']['pct'], 90, 'params não-objeto cai no default');
    $pdo->exec("DELETE FROM portal_alertas_config WHERE tipo = 'disco_cheio'");

    // checks retornam array de ocorrências com as chaves obrigatórias
    foreach (['sem_inventario', 'disco_cheio'] as $tipo) {
        $oc = call_user_func($cat[$tipo]['check'], $pdo, alertas_config_do_tipo($pdo, $tipo)['params']);
        t_ok(is_array($oc), "$tipo check retorna array");
        foreach ($oc as $o) {
            t_ok(isset($o['chave'], $o['titulo'], $o['detalhe']), "$tipo ocorrência tem chave, titulo e detalhe");
            t_ok(is_string($o['detalhe']) && $o['detalhe'] !== '', "$tipo detalhe é string não-vazia");
            break; // basta a primeira
        }
        // render de lista vazia -> mensagem "ok"
        t_ok(strpos(call_user_func($cat[$tipo]['render'], []), 'vazio') !== false, "$tipo render([]) tem a msg vazia");
        // render com dados -> string não vazia
        if ($oc) t_ok(strlen(call_user_func($cat[$tipo]['render'], $oc)) > 20, "$tipo render(ocorr) devolve HTML");
    }

    // ── alertas_historico_registrar/listar — tipo sintético, isolado dos dados reais ──
    $TH = '__teste_historico__';
    $pdo->prepare("DELETE FROM portal_alertas_historico WHERE tipo = ?")->execute([$TH]);
    t_ok((bool) $pdo->query("SHOW TABLES LIKE 'portal_alertas_historico'")->fetch(), 'portal_alertas_historico existe');

    alertas_historico_registrar($pdo, $TH, 'chave1', 'nova', 'Título 1', 'Loja X', 'detalhe 1');
    alertas_historico_registrar($pdo, $TH, 'chave1', 'resolvida', 'Título 1');
    alertas_historico_registrar($pdo, $TH, 'chave2', 'nova', 'Título 2', 'Loja Y', 'detalhe 2');

    $r = alertas_historico_listar($pdo, $TH);
    t_eq($r['total'], 3, 'historico_listar: sem filtro, conta as 3 linhas gravadas');
    t_eq(count($r['linhas']), 3, 'historico_listar: devolve as 3 linhas');
    t_eq($r['linhas'][0]['evento'], 'nova', 'historico_listar: mais recente primeiro (chave2/nova)');

    $rNova = alertas_historico_listar($pdo, $TH, 'nova');
    t_eq($rNova['total'], 2, 'historico_listar: filtro evento=nova -> 2');

    $rResolvida = alertas_historico_listar($pdo, $TH, 'resolvida');
    t_eq($rResolvida['total'], 1, 'historico_listar: filtro evento=resolvida -> 1');

    $rLimite = alertas_historico_listar($pdo, $TH, '', 0, 1, 1);
    t_eq(count($rLimite['linhas']), 1, 'historico_listar: limite=1 devolve 1 linha');
    t_eq($rLimite['total'], 3, 'historico_listar: total ignora o limite da página');

    $pdo->prepare("DELETE FROM portal_alertas_historico WHERE tipo = ?")->execute([$TH]);

    // ── alerta_dispensar / alertas_filtrar_dispensados — chave sintética num tipo real ──
    // usa 'sem_inventario' (tipo real do catálogo) mas com uma chave que nunca existe
    // de verdade, pra não mexer em dado de produção. Trava o envio real via
    // __wpp_fake_send (mesmo seam de gat_enviar) e restaura no finally.
    $TIPO_D = 'sem_inventario';
    $CHAVE_D = '__teste_dispensar__:PC-FAKE';
    $pdo->prepare("DELETE FROM portal_alertas_ocorrencias WHERE tipo = ? AND chave = ?")
        ->execute([$TIPO_D, $CHAVE_D]);
    $pdo->prepare("DELETE FROM portal_alertas_historico WHERE tipo = ? AND chave = ?")
        ->execute([$TIPO_D, $CHAVE_D]);

    $enviados = [];
    $GLOBALS['__wpp_fake_send'] = function ($destino, $texto) use (&$enviados) {
        $enviados[] = ['destino' => $destino, 'texto' => $texto];
        return ['ok' => true];
    };
    try {
        $r = alerta_dispensar($pdo, $TIPO_D, $CHAVE_D, 'obs qualquer', 'Tester');
        t_eq($r['ok'], false, 'dispensar: ocorrência inexistente -> erro');

        $pdo->prepare("INSERT INTO portal_alertas_ocorrencias (tipo, chave, primeiro_visto) VALUES (?, ?, NOW())")
            ->execute([$TIPO_D, $CHAVE_D]);

        $ocFake = [['chave' => $CHAVE_D, 'titulo' => 'PC-FAKE', 'cat' => '', 'loja' => 'Loja Teste', 'dias' => 30, 'quando' => '2026-01-01', 'nunca' => false]];
        $filtrado = alertas_filtrar_dispensados($pdo, $TIPO_D, $ocFake);
        t_eq(count($filtrado), 1, 'filtrar_dispensados: ainda não dispensada -> continua na lista');

        $r2 = alerta_dispensar($pdo, $TIPO_D, $CHAVE_D, 'verificado manualmente', 'Tester');
        t_eq($r2['ok'], true, 'dispensar: ocorrência existente -> ok');
        t_eq(count($enviados), 1, 'dispensar: disparou 1 envio via seam de teste (não WhatsApp real)');
        t_ok(strpos($enviados[0]['texto'], 'Resolvido (manual)') !== false, 'dispensar: mensagem menciona resolução manual');

        $st = $pdo->prepare("SELECT dispensado_em, dispensado_obs, dispensado_por FROM portal_alertas_ocorrencias WHERE tipo=? AND chave=?");
        $st->execute([$TIPO_D, $CHAVE_D]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        t_ok($row && $row['dispensado_em'] !== null, 'dispensar: grava dispensado_em');
        t_eq($row['dispensado_obs'], 'verificado manualmente', 'dispensar: grava a observação');
        t_eq($row['dispensado_por'], 'Tester', 'dispensar: grava quem marcou');

        $filtrado2 = alertas_filtrar_dispensados($pdo, $TIPO_D, $ocFake);
        t_eq(count($filtrado2), 0, 'filtrar_dispensados: dispensada -> some da lista ativa');

        $histD = alertas_historico_listar($pdo, $TIPO_D, '', 0, 10);
        $achou = false;
        foreach ($histD['linhas'] as $lin) {
            if ($lin['chave'] === $CHAVE_D && $lin['evento'] === 'resolvida') $achou = true;
        }
        t_ok($achou, 'dispensar: fica registrada no histórico como resolvida');

        $r3 = alerta_dispensar($pdo, '__tipo_inexistente__', $CHAVE_D, '', 'Tester');
        t_eq($r3['ok'], false, 'dispensar: tipo desconhecido -> erro');

        $htmlBtn = alerta_botao_dispensar_html($TIPO_D, $CHAVE_D);
        t_ok(strpos($htmlBtn, 'btn-dispensar') !== false, 'botao_dispensar_html: gera botão com a classe certa');
        t_ok(strpos($htmlBtn, htmlspecialchars($CHAVE_D, ENT_QUOTES, 'UTF-8')) !== false, 'botao_dispensar_html: chave escapada vai no data-chave');
    } finally {
        unset($GLOBALS['__wpp_fake_send']);
        $pdo->prepare("DELETE FROM portal_alertas_ocorrencias WHERE tipo = ? AND chave = ?")
            ->execute([$TIPO_D, $CHAVE_D]);
        $pdo->prepare("DELETE FROM portal_alertas_historico WHERE tipo = ? AND chave = ?")
            ->execute([$TIPO_D, $CHAVE_D]);
    }
} finally {
    // restaura as duas linhas reais exatamente como estavam antes do teste
    foreach (['sem_inventario', 'disco_cheio'] as $t) {
        if ($orig[$t] === null) {
            $pdo->prepare("DELETE FROM portal_alertas_config WHERE tipo = ?")->execute([$t]);
        } else {
            $r = $orig[$t];
            $pdo->prepare(
                "INSERT INTO portal_alertas_config (tipo,ativo,params,notif_whatsapp,lembrete_min,abre_chamado)
                 VALUES (:tipo,:ativo,:params,:notif,:lembrete,:abre)
                 ON DUPLICATE KEY UPDATE ativo=VALUES(ativo), params=VALUES(params),
                     notif_whatsapp=VALUES(notif_whatsapp), lembrete_min=VALUES(lembrete_min),
                     abre_chamado=VALUES(abre_chamado)"
            )->execute([
                ':tipo'     => $t,
                ':ativo'    => $r['ativo'],
                ':params'   => $r['params'],
                ':notif'    => $r['notif_whatsapp'],
                ':lembrete' => $r['lembrete_min'],
                ':abre'     => $r['abre_chamado'],
            ]);
        }
    }
}
