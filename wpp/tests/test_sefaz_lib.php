<?php
// Testes de sefaz_lib.php — parser do HTML (puro, com amostra fixa) e cache
// por serviço. Roda com `php wpp/tests/run.php` (bloco de cache precisa de banco).
require_once __DIR__ . '/../../sefaz_lib.php';
global $pdo;

// ---------------------------------------------------------------------------
// sefaz_parsear_html_ms() — puro, amostra de HTML fixa (mesma estrutura real
// confirmada em hom.cte.fazenda.gov.br em 2026-09-13: table id
// ctl00_ContentPlaceHolder1_gdvDisponibilidade, linhas <td>UF</td> + 6
// <td><img src="imagens/bola_COR_P.png"></td> + <td>Tempo Médio</td>).
// ---------------------------------------------------------------------------
function html_amostra(string $corMs1, string $corMs4): string
{
    $linha = fn($uf, $c1, $c2, $c3, $c4, $c5, $c6) =>
        "<tr><td>{$uf}</td>"
        . "<td><img src=\"imagens/bola_{$c1}_P.png\" /></td>"
        . "<td><img src=\"imagens/bola_{$c2}_P.png\" /></td>"
        . "<td><img src=\"imagens/bola_{$c3}_P.png\" /></td>"
        . "<td><img src=\"imagens/bola_{$c4}_P.png\" /></td>"
        . "<td><img src=\"imagens/bola_{$c5}_P.png\" /></td>"
        . "<td><img src=\"imagens/bola_{$c6}_P.png\" /></td>"
        . "<td>-</td></tr>";
    return '<html><body><table id="ctl00_ContentPlaceHolder1_gdvDisponibilidade">'
        . '<tr><th scope="col">Autorizador</th><th scope="col">Recepção Sinc</th></tr>'
        . $linha('MT', 'verde', 'verde', 'verde', 'verde', 'verde', 'verde')
        . $linha('MS', $corMs1, 'verde', 'verde', $corMs4, 'verde', 'verde')
        . '</table></body></html>';
}

$tudoVerde = sefaz_parsear_html_ms(html_amostra('verde', 'verde'));
t_ok($tudoVerde !== null, 'parsear_html_ms: acha a linha MS');
t_eq(count($tudoVerde), 6, 'parsear_html_ms: retorna os 6 serviços');
t_eq($tudoVerde['Recepção Sinc'], 'verde', 'parsear_html_ms: tudo verde -> cada serviço verde');

$comAmarelo = sefaz_parsear_html_ms(html_amostra('amarelo', 'verde'));
t_eq($comAmarelo['Recepção Sinc'], 'amarelo', 'parsear_html_ms: identifica o serviço certo como amarelo');
t_eq($comAmarelo['Status Serviço'], 'verde', 'parsear_html_ms: os outros serviços continuam verde (independentes)');

$doisRuins = sefaz_parsear_html_ms(html_amostra('amarelo', 'vermelho'));
t_eq($doisRuins['Recepção Sinc'], 'amarelo', 'parsear_html_ms: 2 serviços ruins - o 1o fica amarelo');
t_eq($doisRuins['Recepção GTVE'], 'vermelho', 'parsear_html_ms: 2 serviços ruins - o outro fica vermelho (não "pior geral")');

t_ok(sefaz_parsear_html_ms('') === null, 'parsear_html_ms: string vazia -> null, não lança');
t_ok(sefaz_parsear_html_ms('<html><body>sem a tabela</body></html>') === null, 'parsear_html_ms: sem a tabela certa -> null');
t_ok(sefaz_parsear_html_ms('<html><body><table id="ctl00_ContentPlaceHolder1_gdvDisponibilidade"><tr><td>SP</td></tr></table></body></html>') === null, 'parsear_html_ms: tabela sem linha MS -> null');

// ---------------------------------------------------------------------------
// sefaz_garantir_cache_fresco() + alerta_check_sefaz_ms() — precisa de banco
// ---------------------------------------------------------------------------
if (isset($pdo) && $pdo instanceof PDO) {
    $FONTE_TESTE = '__teste_sefaz__';
    $limpa = function () use ($pdo, $FONTE_TESTE) {
        $pdo->prepare("DELETE FROM portal_sefaz_status WHERE fonte = ?")->execute([$FONTE_TESTE]);
    };

    try {
        $limpa();

        // sem cache ainda -> chama o buscador
        $chamadas = 0;
        $buscar = function () use (&$chamadas) { $chamadas++; return ['Serviço A' => 'amarelo', 'Serviço B' => 'verde']; };
        sefaz_garantir_cache_fresco($pdo, $FONTE_TESTE, 5, $buscar);
        t_eq($chamadas, 1, 'garantir_cache_fresco: sem cache -> chama o buscador 1x');
        $linhas = $pdo->prepare("SELECT servico, status FROM portal_sefaz_status WHERE fonte = ? ORDER BY servico");
        $linhas->execute([$FONTE_TESTE]);
        $mapa = array_column($linhas->fetchAll(PDO::FETCH_ASSOC), 'status', 'servico');
        t_eq($mapa['Serviço A'], 'amarelo', 'garantir_cache_fresco: grava o status de cada serviço');
        t_eq($mapa['Serviço B'], 'verde', 'garantir_cache_fresco: inclusive os verdes (precisa pra saber que resolveu depois)');

        // cache fresco -> NÃO chama o buscador de novo
        sefaz_garantir_cache_fresco($pdo, $FONTE_TESTE, 5, $buscar);
        t_eq($chamadas, 1, 'garantir_cache_fresco: cache fresco -> não chama o buscador de novo');

        // cache velho -> chama de novo e atualiza (Serviço A resolve, vira verde)
        $pdo->prepare("UPDATE portal_sefaz_status SET atualizado_em = NOW() - INTERVAL 10 MINUTE WHERE fonte = ?")->execute([$FONTE_TESTE]);
        $buscar2 = function () use (&$chamadas) { $chamadas++; return ['Serviço A' => 'verde', 'Serviço B' => 'verde']; };
        sefaz_garantir_cache_fresco($pdo, $FONTE_TESTE, 5, $buscar2);
        t_eq($chamadas, 2, 'garantir_cache_fresco: cache velho -> chama o buscador de novo');
        $linhas->execute([$FONTE_TESTE]);
        $mapa2 = array_column($linhas->fetchAll(PDO::FETCH_ASSOC), 'status', 'servico');
        t_eq($mapa2['Serviço A'], 'verde', 'garantir_cache_fresco: atualiza o status quando muda');

        // buscador falha (null) com cache velho existente -> mantém o cache antigo (não apaga)
        $pdo->prepare("UPDATE portal_sefaz_status SET status='amarelo', atualizado_em = NOW() - INTERVAL 10 MINUTE WHERE fonte = ? AND servico = 'Serviço A'")->execute([$FONTE_TESTE]);
        $buscarFalha = function () { return null; };
        sefaz_garantir_cache_fresco($pdo, $FONTE_TESTE, 5, $buscarFalha);
        $linhas->execute([$FONTE_TESTE]);
        $mapa3 = array_column($linhas->fetchAll(PDO::FETCH_ASSOC), 'status', 'servico');
        t_eq($mapa3['Serviço A'], 'amarelo', 'garantir_cache_fresco: fetch falhou -> mantém o último valor cacheado (não some, não falsifica resolvido)');

        // --- render ---
        t_ok(strpos(alerta_render_sefaz([]), 'vazio') !== false, 'render_sefaz([]) tem a msg vazia');
        $ocorrExemplo = [['chave' => 'sefaz:cte_ms:Recepção Sinc', 'titulo' => 'CT-e MS — Recepção Sinc', 'loja' => '', 'detalhe' => 'Instável']];
        t_ok(strlen(alerta_render_sefaz($ocorrExemplo)) > 20, 'render_sefaz(ocorr) devolve HTML');

        // alerta_check_sefaz_ms usa a fonte fixa 'cte_ms' internamente — não dá pra
        // injetar $FONTE_TESTE nela sem mudar a assinatura pública; cobertura desse
        // fio (chave real 'cte_ms' fim-a-fim) fica pro teste manual/E2E.
    } finally {
        $limpa();
    }
} else {
    echo "  -- testes de banco (sefaz_lib): banco indisponível, pulados\n";
}
