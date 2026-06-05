<?php
/**
 * API de dados para o relatório de Projetos
 * Lê arquivos markdown do Obsidian em Docs/wiki/projects/
 */
session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit; }

header('Content-Type: application/json');

// ── Helper functions (extraídas do equipe_detalhe.php) ──────────
function parsePrazoRapido(string $prazo): int {
    if (preg_match('/\d{1,2}\/\d{1,2}\/\d{4}.*\d{1,2}\/\d{1,2}\/\d{4}/', $prazo, $m)) {
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})\D*$/', $prazo, $m2))
            return mktime(0,0,0,(int)$m2[2],(int)$m2[1],(int)$m2[3]);
    }
    if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $prazo, $m))
        return mktime(0,0,0,(int)$m[2],(int)$m[1],(int)$m[3]);
    return 0;
}

function parseDataBRDetalhe(string $d): int {
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($d), $m))
        return mktime(0,0,0,(int)$m[2],(int)$m[1],(int)$m[3]);
    return 0;
}

function parseProjetoDetalhe(string $filepath): ?array {
    $content = @file_get_contents($filepath);
    if (!$content) return null;
    $lines   = explode("\n", str_replace("\r", '', $content));
    $proj    = ['titulo'=>'','objetivo'=>'','equipe'=>'','prazo'=>'','modulos'=>[]];
    $modo    = 'header';
    $moduloAtual = null;
    $inCodeBlock = false;
    foreach ($lines as $line) {
        $l = rtrim($line);
        if (preg_match('/^```/', $l)) { $inCodeBlock = !$inCodeBlock; continue; }
        if ($inCodeBlock) continue;
        if (preg_match('/^# (.+)$/u', $l, $m)) { $proj['titulo'] = trim($m[1]); $modo='header'; continue; }
        if ($modo === 'header' && preg_match('/^> \*\*(.+?):\*\*\s*(.+)/u', $l, $m)) {
            $k = mb_strtolower($m[1]);
            if (str_contains($k,'objetivo')) $proj['objetivo'] = $m[2];
            elseif (str_contains($k,'equipe')) $proj['equipe']  = $m[2];
            elseif (str_contains($k,'prazo'))  $proj['prazo']   = $m[2];
            continue;
        }
        if (preg_match('/^## (.+)$/u', $l, $m)) {
            if ($moduloAtual !== null) $proj['modulos'][] = $moduloAtual;
            $nome = trim($m[1]);
            if (preg_match('/cronograma|progresso/iu', $nome)) { $moduloAtual = null; continue; }
            $modo = 'modulo';
            $moduloAtual = ['nome'=>$nome,'descricao'=>'','prazo'=>'','tarefas'=>[]];
            continue;
        }
        if ($modo === 'modulo' && $moduloAtual !== null) {
            if (preg_match('/^- \[x\] (.+)/iu', $l, $m))
                $moduloAtual['tarefas'][] = ['done'=>true, 'texto'=>$m[1]];
            elseif (preg_match('/^- \[ \] (.+)/u', $l, $m))
                $moduloAtual['tarefas'][] = ['done'=>false, 'texto'=>$m[1]];
            elseif (preg_match('/^> \*\*Prazo:\*\*\s*(.+)/ui', $l, $m))
                $moduloAtual['prazo'] = trim($m[1]);
        }
    }
    if ($moduloAtual !== null) $proj['modulos'][] = $moduloAtual;
    $tot = $done = 0;
    foreach ($proj['modulos'] as &$mod) {
        $mt = count($mod['tarefas']);
        $md = count(array_filter($mod['tarefas'], fn($t) => $t['done']));
        $mod['pct'] = $mt > 0 ? round($md / $mt * 100) : 0;
        $mod['done'] = $md; $mod['tot'] = $mt;
        $tot += $mt; $done += $md;
    }
    unset($mod);
    $proj['pct']   = $tot > 0 ? round($done / $tot * 100) : 0;
    $proj['done']  = $done;
    $proj['total'] = $tot;
    return $proj['titulo'] ? $proj : null;
}

// ── Lê projetos ────────────────────────────────────────────────
$pastaProj = __DIR__ . '/Docs/wiki/projects';
$projetos = [];

if (is_dir($pastaProj)) {
    $arquivos = glob($pastaProj . '/*.md');
    if ($arquivos === false) $arquivos = [];
    sort($arquivos);
    $hoje  = time();

    foreach ($arquivos as $arq) {
        $p = parseProjetoDetalhe($arq);
        if (!$p) continue;

        $pct = $p['pct'];
        $prazo = $p['prazo'] ? parsePrazoRapido($p['prazo']) : 0;

        // Define status
        if ($pct >= 100) {
            $status = 'Concluído';
        } elseif ($prazo && $prazo < $hoje) {
            $status = 'Em atraso';
        } elseif ($prazo && $prazo < $hoje + 7 * 86400) {
            $status = 'Atenção';
        } else {
            $status = 'No prazo';
        }

        // Conta módulos em atraso
        $mod_atraso = 0;
        foreach ($p['modulos'] as $mod) {
            if ($mod['pct'] >= 100) continue;
            if (!empty($mod['prazo'])) {
                $mp = parseDataBRDetalhe($mod['prazo']);
                if ($mp && $mp < $hoje) $mod_atraso++;
            }
        }

        // Formata prazo para exibição
        $prazo_label = '';
        if ($p['prazo']) {
            $pt = parsePrazoRapido($p['prazo']);
            $prazo_label = $pt ? date('d/m/Y', $pt) : $p['prazo'];
        }

        // Primeiro nome da equipe
        $equipe_nomes = $p['equipe'] ? explode(',', $p['equipe']) : [];
        $equipe_curta = array_map(function($n) {
            $partes = explode(' ', trim($n));
            return end($partes);
        }, $equipe_nomes);

        $projetos[] = [
            'nome'         => $p['titulo'],
            'progresso'    => $pct,
            'status'       => $status,
            'prazo'        => $prazo_label,
            'modulos'      => count($p['modulos']),
            'mod_atraso'   => $mod_atraso,
            'tarefas_done' => $p['done'],
            'tarefas_total'=> $p['total'],
            'equipe'       => implode(' · ', $equipe_curta),
        ];
    }
}

echo json_encode($projetos);
