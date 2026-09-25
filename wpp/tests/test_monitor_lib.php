<?php
// Testes de monitor_lib.php — Monitor de rede (etapa 1: equipamentos do
// inventário, chave Monitorar, config por grupo). Roda com `php wpp/tests/run.php`.
// Regras puras primeiro; o bloco de banco só mexe em linhas de teste
// (manuais com nome '__teste_monitor__%' e grupo '__teste_grupo__') e limpa no final.
require_once __DIR__ . '/../../monitor_lib.php';
global $pdo;

// ---------------------------------------------------------------------------
// monitor_escolher_ip() — mesma regra do inventario_pc.php
// ---------------------------------------------------------------------------
t_eq(monitor_escolher_ip([['192.168.2.22', 'NetworkPortEthernet']]), '192.168.2.22', 'escolher_ip: 1 IP ethernet');
t_eq(monitor_escolher_ip([['192.168.2.50', 'NetworkPortWifi'], ['192.168.2.22', 'NetworkPortEthernet']]), '192.168.2.22', 'escolher_ip: ethernet antes de wifi');
t_eq(monitor_escolher_ip([['172.17.0.1', 'NetworkPortEthernet'], ['192.168.2.22', 'NetworkPortWifi']]), '192.168.2.22', 'escolher_ip: descarta Docker 172.16-31');
t_eq(monitor_escolher_ip([['100.101.1.2', 'NetworkPortEthernet'], ['169.254.3.3', 'NetworkPortEthernet'], ['192.168.56.1', 'NetworkPortEthernet']]), null, 'escolher_ip: Tailscale, APIPA e VirtualBox descartados -> null');
t_eq(monitor_escolher_ip([['fe80::1', 'NetworkPortEthernet'], ['127.0.0.1', 'NetworkPortEthernet']]), null, 'escolher_ip: IPv6 e loopback descartados');
t_eq(monitor_escolher_ip([['192.168.2.22', 'NetworkPortEthernet'], ['192.168.1.40', 'NetworkPortEthernet']]), '192.168.1.40', 'escolher_ip: mesmo tipo -> prefere a rede do servidor (192.168.1.)');
t_eq(monitor_escolher_ip([]), null, 'escolher_ip: sem IP -> null');

// ---------------------------------------------------------------------------
// monitor_loja_curta() — padroniza pro formato da Central ("Lj 003")
// ---------------------------------------------------------------------------
t_eq(monitor_loja_curta('Loja 001'), 'Lj 001', 'loja_curta: "Loja 001" -> "Lj 001"');
t_eq(monitor_loja_curta('MGV Loja 003'), 'Lj 003', 'loja_curta: "MGV Loja 003" -> "Lj 003"');
t_eq(monitor_loja_curta('Lj 030'), 'Lj 030', 'loja_curta: já curto fica igual');
t_eq(monitor_loja_curta('loja 10'), 'Lj 010', 'loja_curta: completa com zeros');
t_eq(monitor_loja_curta('Grupo Gmais'), 'Grupo Gmais', 'loja_curta: sem número de loja fica como veio');
t_eq(monitor_loja_curta(''), '', 'loja_curta: vazio fica vazio');

// ---------------------------------------------------------------------------
// monitor_resolver_ip_duplicado() — mesmo IP em vários computadores do GLPI
// (registros antigos) -> vale o de inventário mais recente, os outros marcados
// ---------------------------------------------------------------------------
$itens = [
    ['origem' => 'glpi', 'origem_id' => 1, 'nome' => 'PDV03',        'ip' => '192.168.2.23', 'ultimo_inv' => '2026-05-01 10:00:00'],
    ['origem' => 'glpi', 'origem_id' => 2, 'nome' => 'PDV122-LJ003', 'ip' => '192.168.2.23', 'ultimo_inv' => '2026-09-24 10:00:00'],
    ['origem' => 'glpi', 'origem_id' => 3, 'nome' => 'PDV122-LJO3O', 'ip' => '192.168.2.23', 'ultimo_inv' => null],
    ['origem' => 'glpi', 'origem_id' => 4, 'nome' => 'PDV125-LJ003', 'ip' => '192.168.2.26', 'ultimo_inv' => '2026-09-20 10:00:00'],
    ['origem' => 'glpi', 'origem_id' => 5, 'nome' => 'SEM-IP',       'ip' => null,           'ultimo_inv' => '2026-09-20 10:00:00'],
];
$r = monitor_resolver_ip_duplicado($itens);
$porId = array_column($r, null, 'origem_id');
t_eq($porId[2]['duplicado_de'], null, 'ip_duplicado: o mais recente fica valendo');
t_eq($porId[1]['duplicado_de'], 'PDV122-LJ003', 'ip_duplicado: o antigo aponta pra quem vale');
t_eq($porId[3]['duplicado_de'], 'PDV122-LJ003', 'ip_duplicado: sem data de inventário perde');
t_eq($porId[4]['duplicado_de'], null, 'ip_duplicado: IP único não é afetado');
t_eq($porId[5]['duplicado_de'], null, 'ip_duplicado: sem IP não conta como duplicado');

// ---------------------------------------------------------------------------
// monitor_ip_valido()
// ---------------------------------------------------------------------------
t_ok(monitor_ip_valido('192.168.2.22'), 'ip_valido: IPv4 ok');
t_ok(!monitor_ip_valido('192.168.2'), 'ip_valido: incompleto recusado');
t_ok(!monitor_ip_valido('999.1.1.1'), 'ip_valido: octeto inválido recusado');
t_ok(!monitor_ip_valido('pdv121'), 'ip_valido: nome recusado');

// ---------------------------------------------------------------------------
// monitor_grupo_normalizar() — clamps dos parâmetros editados na tela
// ---------------------------------------------------------------------------
$g = monitor_grupo_normalizar(['intervalo_seg' => 5, 'falhas_para_cair' => 0, 'sucessos_para_voltar' => 99, 'queda_curta' => 'xyz', 'monitorar_novos' => '1']);
t_eq($g['intervalo_seg'], 30, 'grupo_normalizar: intervalo mínimo 30 s (ciclo do worker)');
t_eq($g['falhas_para_cair'], 1, 'grupo_normalizar: falhas mínimo 1');
t_eq($g['sucessos_para_voltar'], 10, 'grupo_normalizar: sucessos máximo 10');
t_eq($g['queda_curta'], 'registro', 'grupo_normalizar: queda_curta inválida -> registro');
t_eq($g['monitorar_novos'], 1, 'grupo_normalizar: monitorar_novos vira 0/1');
t_eq(monitor_grupo_normalizar(['intervalo_seg' => 99999])['intervalo_seg'], 3600, 'grupo_normalizar: intervalo máximo 1h');

// ---------------------------------------------------------------------------
// Banco: grupos, manuais, sincronização, chave Monitorar
// ---------------------------------------------------------------------------
if ($pdo instanceof PDO) {
    $GRUPO = '__teste_grupo__';
    $limpa = function () use ($pdo, $GRUPO) {
        $pdo->prepare("DELETE FROM portal_monitor_dispositivos WHERE origem = 'manual' AND nome LIKE '__teste_monitor__%'")->execute();
        $pdo->prepare("DELETE FROM portal_monitor_grupos WHERE grupo = ?")->execute([$GRUPO]);
    };
    try {
        $limpa();

        // grupos semeados com os padrões do roteiro
        $grupos = array_column(monitor_grupos_listar($pdo), null, 'grupo');
        t_ok(isset($grupos['pdvs']), 'grupos: pdvs semeado');
        t_ok(isset($grupos['balancas'], $grupos['firewalls']), 'grupos: balancas e firewalls semeados');

        // salvar grupo (com clamp)
        monitor_grupo_salvar($pdo, $GRUPO, 'Grupo de teste', ['intervalo_seg' => 10, 'falhas_para_cair' => 4, 'sucessos_para_voltar' => 2, 'queda_curta' => 'na_hora', 'monitorar_novos' => 0]);
        $gt = monitor_grupo($pdo, $GRUPO);
        t_eq((int) $gt['intervalo_seg'], 30, 'grupo_salvar: grava com clamp');
        t_eq($gt['queda_curta'], 'na_hora', 'grupo_salvar: queda_curta gravada');

        // manual: IP inválido recusado, válido criado
        $erro = null;
        try { monitor_manual_criar($pdo, '__teste_monitor__ruim', '300.1.1.1', 'Lj 003', $GRUPO); } catch (\InvalidArgumentException $e) { $erro = $e->getMessage(); }
        t_ok($erro !== null, 'manual_criar: IP inválido recusado');

        $id = monitor_manual_criar($pdo, '__teste_monitor__a', '10.255.255.1', 'Lj 003', $GRUPO);
        $d = monitor_dispositivo($pdo, $id);
        t_eq($d['origem'], 'manual', 'manual_criar: origem manual');
        t_eq((int) $d['monitorar'], 0, 'manual_criar: herda monitorar_novos do grupo (0)');

        // chave Monitorar e IP fixo
        monitor_set_monitorar($pdo, $id, true);
        t_eq((int) monitor_dispositivo($pdo, $id)['monitorar'], 1, 'set_monitorar: liga');
        monitor_set_ip_fixo($pdo, $id, '10.255.255.2');
        t_eq(monitor_dispositivo($pdo, $id)['ip_fixo'], '10.255.255.2', 'set_ip_fixo: grava');
        monitor_set_ip_fixo($pdo, $id, '');
        t_eq(monitor_dispositivo($pdo, $id)['ip_fixo'], null, 'set_ip_fixo: vazio limpa');

        // ligar/desligar o grupo inteiro
        $id2 = monitor_manual_criar($pdo, '__teste_monitor__b', '10.255.255.3', 'Lj 003', $GRUPO);
        monitor_grupo_set_monitorar_todos($pdo, $GRUPO, true);
        t_eq((int) monitor_dispositivo($pdo, $id2)['monitorar'], 1, 'grupo_set_monitorar_todos: liga todos do grupo');

        // sincronização: item novo do inventário entra com o padrão do grupo;
        // item que já existe não tem a chave sobrescrita
        $fake = [
            ['origem' => 'glpi', 'origem_id' => 999999001, 'nome' => '__teste_monitor__inv', 'ip' => '10.255.255.9', 'loja' => 'Lj 003', 'grupo' => $GRUPO, 'duplicado_de' => null],
            ['origem' => 'glpi', 'origem_id' => 999999002, 'nome' => '__teste_monitor__dup', 'ip' => '10.255.255.9', 'loja' => 'Lj 003', 'grupo' => $GRUPO, 'duplicado_de' => '__teste_monitor__inv'],
        ];
        monitor_grupo_salvar($pdo, $GRUPO, 'Grupo de teste', ['monitorar_novos' => 1]);
        monitor_sincronizar($pdo, $fake);
        $st = $pdo->prepare("SELECT origem_id, monitorar FROM portal_monitor_dispositivos WHERE origem='glpi' AND origem_id IN (999999001, 999999002)");
        $st->execute();
        $sinc = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'monitorar', 'origem_id');
        t_eq((int) ($sinc[999999001] ?? -1), 1, 'sincronizar: novo entra com monitorar_novos do grupo');
        t_eq((int) ($sinc[999999002] ?? -1), 0, 'sincronizar: IP duplicado entra desligado');

        $pdo->exec("UPDATE portal_monitor_dispositivos SET monitorar = 0 WHERE origem='glpi' AND origem_id = 999999001");
        monitor_sincronizar($pdo, $fake);
        $st->execute();
        $sinc = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'monitorar', 'origem_id');
        t_eq((int) $sinc[999999001], 0, 'sincronizar: não sobrescreve a chave de quem já existe');
    } finally {
        $pdo->exec("DELETE FROM portal_monitor_dispositivos WHERE origem='glpi' AND origem_id IN (999999001, 999999002)");
        $limpa();
    }
}
