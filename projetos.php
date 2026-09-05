<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/vault_crypto.php';
require_once __DIR__ . '/github_client.php';
require_once __DIR__ . '/github_cache.php';

$uid = (int)($_SESSION['user_id'] ?? 0);

// Botão "Atualizar" da seção GitHub → zera o cache e recarrega
if (isset($_GET['gh_refresh'])) {
    gh_cache_limpar($pdo);
    header('Location: projetos.php');
    exit;
}

// ── Tabelas de contas GitHub e status manual dos projetos ──────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_github_contas (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        user_id            INT           NOT NULL,
        apelido            VARCHAR(60)   NOT NULL,
        usuario_github     VARCHAR(100)  NOT NULL,
        token_enc          TEXT          NOT NULL,
        ativo              TINYINT(1)    DEFAULT 1,
        ultimo_teste_ok    TINYINT(1)    DEFAULT NULL,
        ultima_verificacao DATETIME      DEFAULT NULL,
        criado_em          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_projetos_status (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        conta_id      INT           NOT NULL,
        repo_nome     VARCHAR(255)  NOT NULL,
        status        ENUM('futuro','em_execucao','concluido') NOT NULL DEFAULT 'em_execucao',
        atualizado_em TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_conta_repo (conta_id, repo_nome),
        FOREIGN KEY (conta_id) REFERENCES portal_github_contas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("ALTER TABLE portal_projetos_status ADD COLUMN IF NOT EXISTS visivel TINYINT(1) NOT NULL DEFAULT 1");

// ── AJAX: contas GitHub (cada usuário só mexe nas próprias) ────
$ghAction = $_GET['gh_action'] ?? '';
if ($ghAction) {
    header('Content-Type: application/json');
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        echo json_encode(['ok'=>false,'msg'=>'Requisição inválida']); exit;
    }
    if (!$uid) { echo json_encode(['ok'=>false,'msg'=>'Sessão inválida']); exit; }

    if ($ghAction === 'conta_add' || $ghAction === 'conta_save') {
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $apelido = trim($body['apelido'] ?? '');
        $usuario = trim($body['usuario_github'] ?? '');
        $token   = trim($body['token'] ?? '');
        $id      = (int)($body['id'] ?? 0);

        if (!$apelido || !$usuario) { echo json_encode(['ok'=>false,'msg'=>'Apelido e usuário são obrigatórios']); exit; }

        // Edição sem novo token = mantém o token atual, não re-testa
        if ($ghAction === 'conta_save' && $id && $token === '') {
            $st = $pdo->prepare("UPDATE portal_github_contas SET apelido=?, usuario_github=? WHERE id=? AND user_id=?");
            $st->execute([$apelido, $usuario, $id, $uid]);
            echo json_encode(['ok'=>true]); exit;
        }

        if (!$token) { echo json_encode(['ok'=>false,'msg'=>'Token é obrigatório']); exit; }
        $teste = github_testar_token($token);
        if (!$teste['ok']) { echo json_encode(['ok'=>false,'msg'=>'Token inválido: '.$teste['msg']]); exit; }

        $tokenEnc = vault_encrypt($token);
        if ($ghAction === 'conta_add') {
            $st = $pdo->prepare("INSERT INTO portal_github_contas (user_id,apelido,usuario_github,token_enc,ultimo_teste_ok,ultima_verificacao) VALUES (?,?,?,?,1,NOW())");
            $st->execute([$uid, $apelido, $usuario, $tokenEnc]);
            echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
        } else {
            $st = $pdo->prepare("UPDATE portal_github_contas SET apelido=?, usuario_github=?, token_enc=?, ultimo_teste_ok=1, ultima_verificacao=NOW() WHERE id=? AND user_id=?");
            $st->execute([$apelido, $usuario, $tokenEnc, $id, $uid]);
            echo json_encode(['ok'=>true]);
        }
        exit;
    }

    if ($ghAction === 'conta_testar') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        $st = $pdo->prepare("SELECT token_enc FROM portal_github_contas WHERE id=? AND user_id=?");
        $st->execute([$id, $uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Conta não encontrada']); exit; }

        $teste = github_testar_token(vault_decrypt($row['token_enc']));
        $pdo->prepare("UPDATE portal_github_contas SET ultimo_teste_ok=?, ultima_verificacao=NOW() WHERE id=?")
            ->execute([$teste['ok'] ? 1 : 0, $id]);
        echo json_encode($teste);
        exit;
    }

    if ($ghAction === 'status_set') {
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $contaId  = (int)($body['conta_id'] ?? 0);
        $repoNome = trim($body['repo_nome'] ?? '');
        $status   = $body['status'] ?? '';
        if (!in_array($status, ['futuro','em_execucao','concluido'], true)) {
            echo json_encode(['ok'=>false,'msg'=>'Status inválido']); exit;
        }
        if (!$repoNome) { echo json_encode(['ok'=>false,'msg'=>'Repositório inválido']); exit; }

        // Confirma que a conta pertence ao usuário logado antes de gravar
        $st = $pdo->prepare("SELECT id FROM portal_github_contas WHERE id=? AND user_id=?");
        $st->execute([$contaId, $uid]);
        if (!$st->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Conta não encontrada']); exit; }

        $pdo->prepare("
            INSERT INTO portal_projetos_status (conta_id, repo_nome, status)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE status = VALUES(status)
        ")->execute([$contaId, $repoNome, $status]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($ghAction === 'conta_repos') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM portal_github_contas WHERE id=? AND user_id=?");
        $st->execute([$id, $uid]);
        $conta = $st->fetch(PDO::FETCH_ASSOC);
        if (!$conta) { echo json_encode(['ok'=>false,'msg'=>'Conta não encontrada']); exit; }

        $resultado = github_listar_repos(vault_decrypt($conta['token_enc']));
        if (isset($resultado['erro'])) { echo json_encode(['ok'=>false,'msg'=>$resultado['erro']]); exit; }

        $st2 = $pdo->prepare("SELECT repo_nome, visivel FROM portal_projetos_status WHERE conta_id=?");
        $st2->execute([$id]);
        $visMap = [];
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) $visMap[$row['repo_nome']] = (int)$row['visivel'];

        $repos = [];
        foreach ($resultado as $repo) {
            $repos[] = ['nome' => $repo['nome'], 'visivel' => $visMap[$repo['nome']] ?? 1];
        }
        echo json_encode(['ok'=>true,'repos'=>$repos]);
        exit;
    }

    if ($ghAction === 'visibilidade_set_lote') {
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $contaId = (int)($body['conta_id'] ?? 0);
        $repos   = $body['repos'] ?? [];

        $st = $pdo->prepare("SELECT id FROM portal_github_contas WHERE id=? AND user_id=?");
        $st->execute([$contaId, $uid]);
        if (!$st->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Conta não encontrada']); exit; }

        $upsert = $pdo->prepare("
            INSERT INTO portal_projetos_status (conta_id, repo_nome, visivel)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE visivel = VALUES(visivel)
        ");
        foreach ($repos as $r) {
            $nome = trim($r['nome'] ?? '');
            if ($nome === '') continue;
            $vis = !empty($r['visivel']) ? 1 : 0;
            $upsert->execute([$contaId, $nome, $vis]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($ghAction === 'readme_html') {
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $contaId  = (int)($body['conta_id'] ?? 0);
        $repoNome = trim($body['repo_nome'] ?? '');
        if (!$repoNome) { echo json_encode(['ok'=>false,'msg'=>'Repositório inválido']); exit; }

        $st = $pdo->prepare("SELECT * FROM portal_github_contas WHERE id=? AND user_id=?");
        $st->execute([$contaId, $uid]);
        $conta = $st->fetch(PDO::FETCH_ASSOC);
        if (!$conta) { echo json_encode(['ok'=>false,'msg'=>'Conta não encontrada']); exit; }

        $resultado = github_obter_readme_html(vault_decrypt($conta['token_enc']), $conta['usuario_github'], $repoNome);
        if (isset($resultado['erro'])) { echo json_encode(['ok'=>false,'msg'=>$resultado['erro']]); exit; }

        echo json_encode([
            'ok'      => true,
            'html'    => $resultado['html'],
            'repoUrl' => 'https://github.com/' . rawurlencode($conta['usuario_github']) . '/' . rawurlencode($repoNome),
        ]);
        exit;
    }

    if ($ghAction === 'commits_full') {
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $contaId  = (int)($body['conta_id'] ?? 0);
        $repoNome = trim($body['repo_nome'] ?? '');
        if (!$repoNome) { echo json_encode(['ok'=>false,'msg'=>'Repositório inválido']); exit; }

        $st = $pdo->prepare("SELECT * FROM portal_github_contas WHERE id=? AND user_id=?");
        $st->execute([$contaId, $uid]);
        $conta = $st->fetch(PDO::FETCH_ASSOC);
        if (!$conta) { echo json_encode(['ok'=>false,'msg'=>'Conta não encontrada']); exit; }

        require_once __DIR__ . '/github_cache.php';
        $token  = vault_decrypt($conta['token_enc']);
        $ghUser = $conta['usuario_github'];
        $commits = gh_cached($pdo, "commits_full:$contaId:$repoNome", 600,
            fn() => github_commits_completo($token, $ghUser, $repoNome, 3));

        echo json_encode([
            'ok'      => true,
            'commits' => $commits,
            'total'   => count($commits),
            'repoUrl' => 'https://github.com/' . rawurlencode($ghUser) . '/' . rawurlencode($repoNome) . '/commits',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ghAction === 'conta_delete') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        $pdo->prepare("DELETE FROM portal_github_contas WHERE id=? AND user_id=?")->execute([$id, $uid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação inválida']);
    exit;
}

// ── Minhas contas GitHub (para o painel e, na próxima etapa, a listagem) ──
$minhasContas = [];
if ($uid) {
    $st = $pdo->prepare("SELECT * FROM portal_github_contas WHERE user_id=? ORDER BY criado_em");
    $st->execute([$uid]);
    $minhasContas = $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── Parser de projeto Markdown ─────────────────────────────────────────────
function parseProjeto(string $filepath): ?array {
    $content = @file_get_contents($filepath);
    if (!$content) return null;

    $lines  = explode("\n", str_replace("\r", '', $content));
    $proj   = [
        'titulo'    => '',
        'objetivo'  => '',
        'equipe'    => '',
        'prazo'     => '',
        'repo'      => '',
        'modulos'   => [],
        'cronograma'=> [],
    ];

    $modo        = 'header';
    $moduloAtual = null;
    $subSecao    = null;
    $inCodeBlock = false;

    foreach ($lines as $line) {
        $l = rtrim($line);

        if (preg_match('/^```/', $l)) { $inCodeBlock = !$inCodeBlock; continue; }

        if ($inCodeBlock) {
            if ($modo === 'cronograma' &&
                preg_match('/Semana\s*(\d+)\s*\(([^)]+)\)\s*[→\-]+\s*(.+)/u', $l, $m)) {
                $proj['cronograma'][] = [
                    'semana'   => 'S'.$m[1],
                    'periodo'  => trim($m[2]),
                    'descricao'=> trim($m[3]),
                ];
            }
            continue;
        }

        if (preg_match('/^# (.+)$/u', $l, $m)) {
            $proj['titulo'] = trim($m[1]); $modo = 'header'; continue;
        }
        if ($modo === 'header' && preg_match('/^> \*\*(.+?):\*\*\s*(.+)/u', $l, $m)) {
            $k = mb_strtolower($m[1]);
            if (str_contains($k,'objetivo'))    $proj['objetivo'] = $m[2];
            elseif (str_contains($k,'equipe'))  $proj['equipe']   = $m[2];
            elseif (str_contains($k,'prazo'))   $proj['prazo']    = $m[2];
            elseif (str_contains($k,'reposit')) $proj['repo']     = $m[2];
            continue;
        }
        if (preg_match('/^## (.+)$/u', $l, $m)) {
            if ($moduloAtual !== null) $proj['modulos'][] = $moduloAtual;
            $nome = trim($m[1]);
            if (preg_match('/cronograma/iu', $nome))      { $modo='cronograma'; $moduloAtual=null; }
            elseif (preg_match('/progresso/iu', $nome))   { $modo='tabprog';    $moduloAtual=null; }
            else {
                $modo = 'modulo';
                $moduloAtual = ['nome'=>$nome,'descricao'=>'','prazo'=>'','tarefas'=>[]];
                $subSecao = null;
            }
            continue;
        }
        if ($modo === 'modulo' && preg_match('/^### (.+)$/u', $l, $m)) {
            $subSecao = trim($m[1]); continue;
        }
        if ($modo === 'modulo' && $moduloAtual !== null) {
            if (preg_match('/^- \[x\] (.+)/iu', $l, $m))
                $moduloAtual['tarefas'][] = ['done'=>true,  'texto'=>$m[1], 'sub'=>$subSecao];
            elseif (preg_match('/^- \[ \] (.+)/u', $l, $m))
                $moduloAtual['tarefas'][] = ['done'=>false, 'texto'=>$m[1], 'sub'=>$subSecao];
            elseif (preg_match('/^> \*\*Prazo:\*\*\s*(.+)/ui', $l, $m)) {
                $v = trim($m[1]);
                // 4+ barras = duas datas = prazo do projeto; 2 barras = prazo do módulo
                if (!$proj['prazo'] && substr_count($v, '/') >= 4)
                    $proj['prazo'] = $v;
                else
                    $moduloAtual['prazo'] = $v;
            }
            elseif (preg_match('/^> (.+)/u', $l, $m) && !count($moduloAtual['tarefas']))
                $moduloAtual['descricao'] = trim($m[1]);
        }
    }
    if ($moduloAtual !== null) $proj['modulos'][] = $moduloAtual;

    $tot = $done = 0;
    foreach ($proj['modulos'] as &$mod) {
        $mt = count($mod['tarefas']);
        $md = count(array_filter($mod['tarefas'], fn($t) => $t['done']));
        $mod['pct']  = $mt > 0 ? round($md / $mt * 100) : 0;
        $mod['done'] = $md;
        $mod['tot']  = $mt;
        $tot  += $mt;
        $done += $md;
    }
    unset($mod);

    $proj['pct']   = $tot > 0 ? round($done / $tot * 100) : 0;
    $proj['done']  = $done;
    $proj['total'] = $tot;

    return $proj['titulo'] ? $proj : null;
}

function parsePeriodo(string $periodo, int $ano = 2026): array {
    $p = trim($periodo);
    $p = preg_replace('/[–—-]/', '-', $p);
    if (preg_match('/^(\d{1,2})\/(\d{1,2})-(\d{1,2})\/(\d{1,2})$/', $p, $m))
        return [mktime(0,0,0,(int)$m[2],(int)$m[1],$ano), mktime(0,0,0,(int)$m[4],(int)$m[3],$ano)];
    if (preg_match('/^(\d{1,2})-(\d{1,2})\/(\d{1,2})$/', $p, $m))
        return [mktime(0,0,0,(int)$m[3],(int)$m[1],$ano), mktime(0,0,0,(int)$m[3],(int)$m[2],$ano)];
    return [0, 0];
}

function parseDataBR(string $d): int {
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($d), $m))
        return mktime(0,0,0,(int)$m[2],(int)$m[1],(int)$m[3]);
    return 0;
}

function parsePrazoRange(string $prazo): array {
    // Duas datas separadas por qualquer coisa não-numérica (→, —, >, espaço, etc.)
    if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})\D+(\d{1,2}\/\d{1,2}\/\d{4})/', $prazo, $m))
        return [parseDataBR($m[1]), parseDataBR($m[2])];
    if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $prazo, $m))
        return [0, parseDataBR($m[1])];
    return [0, 0];
}

function corPct(int $pct): string {
    if ($pct >= 80) return '#1e8e3e';
    if ($pct >= 40) return '#f57c00';
    return '#1a73e8';
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function dataRelativa(?string $iso): string {
    if (!$iso) return '—';
    $ts = strtotime($iso);
    if (!$ts) return '—';
    $diff = time() - $ts;
    if ($diff < 3600)     return 'há ' . max(1, (int)($diff / 60)) . ' min';
    if ($diff < 86400)    return 'há ' . (int)($diff / 3600) . 'h';
    if ($diff < 86400*30) return 'há ' . (int)($diff / 86400) . 'd';
    return date('d/m/Y', $ts);
}

// ── Card de projeto (lista) — usado nas seções "Em andamento" e "Concluídos" ──
function renderProjCard(array $p): void {
    $modsVisiveis = array_values(array_filter($p['modulos'], fn($m) => $m['tot'] > 0));
    $exibir = array_slice($modsVisiveis, 0, 5);
    $extras = max(0, count($modsVisiveis) - 5);
    ?>
    <div class="col-md-6 col-xl-4">
      <a href="projetos.php?proj=<?= urlencode($p['arquivo']) ?>" class="proj-card h-100">

        <!-- Título + % -->
        <div class="proj-card-title">
          <span><?= esc($p['titulo']) ?></span>
          <span style="font-size:1.1rem;font-weight:800;color:<?= corPct($p['pct']) ?>;flex-shrink:0">
            <?= $p['pct'] ?>%
          </span>
        </div>

        <!-- Descrição -->
        <?php if ($p['objetivo']): ?>
          <div class="proj-card-desc"><?= esc($p['objetivo']) ?></div>
        <?php endif; ?>

        <!-- Barra geral -->
        <div class="d-flex align-items-center gap-2">
          <div class="prog-bar flex-grow-1">
            <div class="prog-fill" style="width:<?= $p['pct'] ?>%;background:<?= corPct($p['pct']) ?>"></div>
          </div>
          <span class="prog-label" style="color:<?= corPct($p['pct']) ?>">
            <?= $p['done'] ?>/<?= $p['total'] ?>
          </span>
        </div>

        <!-- Mini módulos -->
        <?php if ($exibir): ?>
        <div class="mod-mini">
          <?php foreach ($exibir as $mod): ?>
            <div class="mod-mini-row">
              <span class="mod-mini-name"><?= esc($mod['nome']) ?></span>
              <div class="mod-mini-bar">
                <div class="mod-mini-fill" style="width:<?= $mod['pct'] ?>%;background:<?= corPct($mod['pct']) ?>"></div>
              </div>
              <span class="mod-mini-pct" style="color:<?= corPct($mod['pct']) ?>"><?= $mod['pct'] ?>%</span>
            </div>
          <?php endforeach; ?>
          <?php if ($extras > 0): ?>
            <div class="mod-mais">+ <?= $extras ?> módulos</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Meta info + botão -->
        <div class="card-meta">
          <?php if ($p['equipe']): ?>
            <span class="meta-pill"><i class="bi bi-people"></i><?= esc($p['equipe']) ?></span>
          <?php endif; ?>
          <?php if ($p['prazo']): ?>
            <span class="meta-pill"><i class="bi bi-calendar-check"></i><?= esc($p['prazo']) ?></span>
          <?php endif; ?>
          <span class="btn-detalhe">
            Ver detalhes <i class="bi bi-arrow-right"></i>
          </span>
        </div>

      </a>
    </div>
    <?php
}

// ── Carrega todos os projetos ──────────────────────────────────────────────
$pastaProj = __DIR__ . '/Docs/wiki/projects';
$projetos  = [];

/**
 * Carrega projetos da pasta — UMA subpasta = UM card
 * Todos os .md dentro de uma subpasta são mesclados em um único projeto
 * Pastas iniciadas com _ (underscore) são ignoradas (ex: _Documentação)
 */
function carregarProjetosDaPasta(string $pasta): array {
    $result = [];
    if (!is_dir($pasta)) return $result;

    // Subpastas = UM card por pasta (com todos .md recursivos)
    foreach (glob($pasta . '/*', GLOB_ONLYDIR) as $subPasta) {
        $nomeProj = basename($subPasta);

        // Ignora pastas iniciadas com _ (documentação, arquivos auxiliares)
        if (str_starts_with($nomeProj, '_')) continue;

        // Busca recursiva por .md
        $mdFiles = [];
        $rdi = new RecursiveDirectoryIterator($subPasta, RecursiveDirectoryIterator::SKIP_DOTS);
        $rit = new RecursiveIteratorIterator($rdi);
        foreach ($rit as $file) {
            if ($file->getExtension() === 'md') $mdFiles[] = $file->getPathname();
        }
        sort($mdFiles);
        if (empty($mdFiles)) continue;

        // Separa o .md principal (nome parecido com a pasta, README, index, ou o primeiro)
        $mainMd = null;
        $extraMds = [];
        $pastaNorm = mb_strtolower(str_replace(['-', '_', ' '], '', $nomeProj));
        foreach ($mdFiles as $md) {
            $base = mb_strtolower(basename($md, '.md'));
            $baseNorm = str_replace(['-', '_', ' '], '', $base);
            if ($mainMd === null && (str_contains($baseNorm, $pastaNorm) || $base === 'readme' || $base === 'index')) {
                $mainMd = $md;
            } else {
                $extraMds[] = $md;
            }
        }
        if (!$mainMd) {
            $mainMd = $mdFiles[0];
            $extraMds = array_slice($mdFiles, 1);
        }

        // Parse principal
        $proj = parseProjeto($mainMd);
        if (!$proj) continue;

        // Título = nome da pasta (consistência)
        $proj['titulo'] = $nomeProj;

        // Mescla módulos de .md extras
        foreach ($extraMds as $extraMd) {
            $extra = parseProjeto($extraMd);
            if ($extra && !empty($extra['modulos'])) {
                $proj['modulos'] = array_merge($proj['modulos'], $extra['modulos']);
            }
            // Aproveita campos que o principal não tem
            if (!$proj['objetivo'] && !empty($extra['objetivo'])) $proj['objetivo'] = $extra['objetivo'];
            if (!$proj['prazo']    && !empty($extra['prazo']))    $proj['prazo']    = $extra['prazo'];
            if (!$proj['equipe']   && !empty($extra['equipe']))   $proj['equipe']   = $extra['equipe'];
            if (!$proj['repo']     && !empty($extra['repo']))     $proj['repo']     = $extra['repo'];
        }

        // Recalcula percentuais
        $tot = $done = 0;
        foreach ($proj['modulos'] as &$mod) {
            $mt = count($mod['tarefas']);
            $md = count(array_filter($mod['tarefas'], fn($t) => $t['done']));
            $mod['pct']  = $mt > 0 ? round($md / $mt * 100) : 0;
            $mod['done'] = $md;
            $mod['tot']  = $mt;
            $tot  += $mt;
            $done += $md;
        }
        unset($mod);
        $proj['pct']   = $tot > 0 ? round($done / $tot * 100) : 0;
        $proj['done']  = $done;
        $proj['total'] = $tot;

        $proj['arquivo']  = $nomeProj . '/' . basename($mainMd);
        $proj['pasta']    = $nomeProj;
        $proj['filepath'] = $mainMd;
        $proj['md_files'] = $mdFiles; // todos os .md para merge no download
        $result[] = $proj;
    }

    // .md na raiz (legado) — só inclui se não tiver subpasta correspondente
    $pastas = array_map('basename', glob($pasta . '/*', GLOB_ONLYDIR));
    foreach (glob($pasta . '/*.md') as $arq) {
        $base = basename($arq, '.md');
        if (in_array($base, $pastas)) continue; // já tem subpasta → pula duplicata

        $p = parseProjeto($arq);
        if ($p) {
            $p['arquivo']  = basename($arq);
            $p['pasta']    = '';
            $p['filepath'] = $arq;
            $p['md_files'] = [$arq];
            $result[] = $p;
        }
    }

    return $result;
}

// 1. Tenta carregar da rede primeiro (fonte única — contém projetos de todos os técnicos)
$configLocal = __DIR__ . '/config_projetos.local.php';
$origemUsada = 'local';
if (file_exists($configLocal)) {
    require_once $configLocal;
    if (defined('ORIGEM_PROJETOS') && ORIGEM_PROJETOS && is_dir(ORIGEM_PROJETOS)) {
        $projetos = carregarProjetosDaPasta(ORIGEM_PROJETOS);
        $origemUsada = 'rede';
    }
}

// 2. Fallback: local se rede não estiver disponível
if ($origemUsada === 'local') {
    $projetos = carregarProjetosDaPasta($pastaProj);
}

// ── Modo: lista (padrão) ou detalhe (?proj=arquivo.md) ────────────────────
$selArq  = $_GET['proj'] ?? null;
$projeto = null;

// ── Ação: sincronizar projetos da rede ───────────────────────────
$mensagemSync = '';
if (isset($_GET['sync']) && $_GET['sync'] === '1') {
    $syncScript = __DIR__ . '/sync_projetos.php';
    if (file_exists($syncScript)) {
        $output = @shell_exec('php "' . $syncScript . '" 2>&1');
        $mensagemSync = 'Projetos sincronizados da rede!';
        // Recarrega os projetos após sync (sempre da rede se disponível)
        $configLocal = __DIR__ . '/config_projetos.local.php';
        $origemSync = 'local';
        if (file_exists($configLocal)) {
            require_once $configLocal;
            if (defined('ORIGEM_PROJETOS') && ORIGEM_PROJETOS && is_dir(ORIGEM_PROJETOS)) {
                $projetos = carregarProjetosDaPasta(ORIGEM_PROJETOS);
                $origemSync = 'rede';
            }
        }
        if ($origemSync === 'local') {
            $projetos = carregarProjetosDaPasta($pastaProj);
        }
    } else {
        $mensagemSync = 'Script de sync não encontrado.';
    }
}

if ($selArq) {
    foreach ($projetos as $p) {
        if ($p['arquivo'] === $selArq) { $projeto = $p; break; }
    }
}
$modoDetalhe = $projeto !== null;

// ── Gantt (só no detalhe) ──────────────────────────────────────────────────
$ganttBars  = [];
$dataInicio = 0;
$dataFim    = 0;
if ($modoDetalhe && $projeto['cronograma']) {
    foreach ($projeto['cronograma'] as $cr) {
        [$ini, $fim] = parsePeriodo($cr['periodo']);
        if ($ini && $fim) {
            if (!$dataInicio || $ini < $dataInicio) $dataInicio = $ini;
            if ($fim > $dataFim) $dataFim = $fim;
            $ganttBars[] = array_merge($cr, ['ini'=>$ini,'fim'=>$fim]);
        }
    }
}
$totalDias = ($dataInicio && $dataFim) ? max(1,($dataFim-$dataInicio)/86400) : 0;
$hoje      = mktime(0,0,0,date('n'),date('j'),date('Y'));

// ── Status e Previsão de Término ──────────────────────────────────────────
$projInicio = $projFim2 = 0;
$pctEsperado = $diasDecorridos = $totalDiasPrazo = 0;
$statusProj  = 'sem_data';
$dataForecast = $diasForecast = null;

if ($modoDetalhe && $projeto['prazo']) {
    [$projInicio, $projFim2] = parsePrazoRange($projeto['prazo']);
    if ($projInicio && $projFim2) {
        $totalDiasPrazo = max(1, ($projFim2 - $projInicio) / 86400);
        $diasDecorridos = max(0, ($hoje - $projInicio) / 86400);
        $pctEsperado    = min(100, round($diasDecorridos / $totalDiasPrazo * 100));
        $pctAtual       = $projeto['pct'];

        if ($diasDecorridos >= 1) {
            $taxa         = $pctAtual / max(1, $diasDecorridos);
            $diasForecast = $taxa > 0 ? (int) ceil((100 - $pctAtual) / $taxa) : null;
        } else {
            $diasForecast = $totalDiasPrazo > 0
                ? (int) ceil($totalDiasPrazo * (100 - $pctAtual) / 100)
                : null;
        }
        $dataForecast = $diasForecast !== null ? $hoje + $diasForecast * 86400 : null;

        $diff = $pctAtual - $pctEsperado;
        if ($pctAtual >= 100)    $statusProj = 'concluido';
        elseif ($diff >= 10)     $statusProj = 'adiantado';
        elseif ($diff >= -10)    $statusProj = 'no_prazo';
        elseif ($diff >= -25)    $statusProj = 'atencao';
        else                     $statusProj = 'atrasado';
    }
}

function barPct(int $ts, int $inicio, int $total): float {
    return $total > 0 ? round(($ts-$inicio)/86400/$total*100,2) : 0;
}

// ── Export sections (usado pelo modal) ──────────────────────────
$exportModulos = [];
if ($modoDetalhe) {
    foreach ($projeto['modulos'] as $mi => $mod) {
        if ($mod['tot'] > 0) {
            $exportModulos[] = ['idx' => $mi, 'nome' => $mod['nome'], 'pct' => $mod['pct'], 'done' => $mod['done'], 'tot' => $mod['tot']];
        }
    }
}

// ── Action: download .md com seções selecionadas ────────────────
if ($modoDetalhe && ($_GET['action'] ?? '') === 'download' && isset($_GET['sections'])) {
    // Lê de TODOS os .md do projeto (merge para multi-arquivo)
    $mdFiles = $projeto['md_files'] ?? [$projeto['filepath']];
    $rawAll  = '';
    foreach ($mdFiles as $md) {
        if (file_exists($md)) {
            $rawAll .= "\n" . @file_get_contents($md);
        }
    }
    $rawAll = trim($rawAll);
    if (!$rawAll) { http_response_code(404); echo 'Conteúdo não encontrado.'; exit; }

    $selected = array_filter(explode(',', $_GET['sections']), 'trim');
    $lines = explode("\n", str_replace("\r", '', $rawAll));

    // Separa seções do markdown por heading ##
    $secMarkdown = [];
    $curKey = 'header';
    $curLines = [];

    foreach ($lines as $line) {
        if (preg_match('/^## (.+)$/u', $line, $m)) {
            if ($curLines) $secMarkdown[$curKey] = implode("\n", $curLines);
            $curLines = [$line];
            $heading = trim($m[1]);
            if (preg_match('/cronograma/iu', $heading)) {
                $curKey = 'gantt';
            } elseif (preg_match('/progresso/iu', $heading)) {
                $curKey = '_skip';
            } else {
                // Encontra o índice do módulo pelo nome
                $foundIdx = null;
                foreach ($projeto['modulos'] as $mi => $mod) {
                    if ($mod['nome'] === $heading) { $foundIdx = $mi; break; }
                }
                $curKey = $foundIdx !== null ? 'modulo_' . $foundIdx : '_skip';
            }
        } else {
            $curLines[] = $line;
        }
    }
    if ($curLines) $secMarkdown[$curKey] = implode("\n", $curLines);

    // Monta output só com seções selecionadas
    $output = '';
    $hasTitle = false;
    foreach ($selected as $sel) {
        if ($sel === 'header' && isset($secMarkdown['header'])) {
            $output .= trim($secMarkdown['header']) . "\n\n";
            $hasTitle = true;
        } elseif (str_starts_with($sel, 'modulo_') && isset($secMarkdown[$sel])) {
            // Garante título se ainda não foi incluído
            if (!$hasTitle) {
                if (preg_match('/^# .+$/m', $secMarkdown['header'] ?? '', $t)) {
                    $output .= $t[0] . "\n\n";
                }
                $hasTitle = true;
            }
            $output .= trim($secMarkdown[$sel]) . "\n\n";
        } elseif ($sel === 'gantt' && isset($secMarkdown['gantt'])) {
            if (!$hasTitle) {
                if (preg_match('/^# .+$/m', $secMarkdown['header'] ?? '', $t)) {
                    $output .= $t[0] . "\n\n";
                }
                $hasTitle = true;
            }
            $output .= trim($secMarkdown['gantt']) . "\n\n";
        }
    }

    if (!trim($output)) {
        $output = "# " . ($projeto['titulo'] ?? 'Projeto') . "\n\n*(nenhuma seção selecionada)*\n";
    }

    $filename = str_replace('/', '-', $selArq);
    $filename = basename($filename, '.md') . '_exportado.md';
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $output;
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Projetos de TI</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
<style>
:root { --primary:#1a237e; }
body  { background:#f0f4f9; font-family:'Segoe UI',sans-serif; font-size:.9rem; }
.topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff;
          padding:.75rem 1.5rem; display:flex; align-items:center;
          justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
.topbar a { color:#fff; text-decoration:none; font-size:.82rem;
            background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
.topbar a:hover { background:rgba(255,255,255,.25); }
.hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff;
        padding:1.75rem 1rem 3.5rem; text-align:center; }
.wrap { max-width:1200px; margin:-2rem auto 3rem; padding:0 1rem; }

/* ── CARDS ─────────────────────────────────────────────────── */
.proj-card { background:#fff; border-radius:14px; border:1px solid #e5e7eb;
             box-shadow:0 2px 10px rgba(0,0,0,.06); padding:1.35rem;
             cursor:pointer; transition:all .18s; text-decoration:none; color:inherit;
             display:block; }
.proj-card:hover { box-shadow:0 6px 24px rgba(26,35,126,.13);
                   border-color:#1a237e; transform:translateY(-2px); color:inherit; }
.proj-card-title { font-size:1rem; font-weight:700; margin-bottom:.2rem;
                   display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
.proj-card-desc  { font-size:.78rem; color:#6b7280; margin-bottom:.75rem;
                   display:-webkit-box; -webkit-line-clamp:2;
                   -webkit-box-orient:vertical; overflow:hidden; }
.prog-bar  { height:8px; border-radius:4px; background:#e5e7eb; overflow:hidden; }
.prog-fill { height:100%; border-radius:4px; transition:width .6s ease; }
.prog-label { font-size:.75rem; font-weight:700; min-width:36px; text-align:right; }

/* Mini módulos no card */
.mod-mini { margin-top:.85rem; display:flex; flex-direction:column; gap:.35rem; }
.mod-mini-row { display:flex; align-items:center; gap:.5rem; }
.mod-mini-name { font-size:.76rem; color:#374151; flex:1;
                 white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.mod-mini-bar  { width:80px; height:5px; border-radius:3px;
                 background:#e5e7eb; flex-shrink:0; overflow:hidden; }
.mod-mini-fill { height:100%; border-radius:3px; }
.mod-mini-pct  { font-size:.68rem; font-weight:700; min-width:28px; text-align:right; }
.mod-mais      { font-size:.72rem; color:#9ca3af; margin-top:.25rem; }

/* Meta info */
.card-meta { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.85rem;
             padding-top:.75rem; border-top:1px solid #f3f4f6; }
.meta-pill { font-size:.72rem; color:#6b7280; display:flex; align-items:center; gap:.25rem; }
.btn-detalhe { font-size:.75rem; font-weight:700; color:#1a237e;
               display:flex; align-items:center; gap:.25rem; margin-left:auto; }

/* ── DETALHE ───────────────────────────────────────────────── */
.card-box { background:#fff; border-radius:12px; border:1px solid #e5e7eb;
            box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1.25rem; margin-bottom:1rem; }
.gantt-wrap  { overflow-x:auto; }
.gantt       { min-width:600px; }
.gantt-header { display:flex; border-bottom:2px solid #e5e7eb; margin-bottom:.25rem; }
.gantt-col-label { width:190px; flex-shrink:0; font-size:.75rem; font-weight:700;
                   color:#6b7280; padding:.25rem 0; }
.gantt-timeline { flex:1; display:flex; }
.gantt-week { flex:1; font-size:.68rem; font-weight:600; color:#9ca3af;
              text-align:center; border-left:1px dashed #e5e7eb; padding:.2rem 2px; }
.gantt-row { display:flex; align-items:center; margin-bottom:.4rem; min-height:32px; }
.gantt-label { width:190px; flex-shrink:0; font-size:.76rem; color:#374151;
               font-weight:600; padding-right:.5rem;
               white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gantt-track { flex:1; position:relative; height:22px; background:#f3f4f6;
               border-radius:4px; overflow:hidden; }
.gantt-bar { position:absolute; top:2px; height:18px; border-radius:4px;
             display:flex; align-items:center; padding:0 6px;
             font-size:.65rem; font-weight:700; color:#fff;
             white-space:nowrap; overflow:hidden; }
.gantt-bar:hover { opacity:.85; }
.gantt-today { position:absolute; top:0; bottom:0; width:2px;
               background:#ef4444; z-index:5; }
.gantt-today-label { position:absolute; top:-15px; font-size:.6rem;
                     color:#ef4444; font-weight:700; transform:translateX(-50%); }
.mod-header { display:flex; align-items:center; gap:.5rem; cursor:pointer;
              padding:.5rem 0; border-bottom:1px solid #f3f4f6; user-select:none; }
.mod-header:hover { color:#1a237e; }
.mod-body { padding:.5rem 0 .25rem 1rem; }
.task-item { display:flex; align-items:flex-start; gap:.5rem;
             padding:.2rem 0; font-size:.8rem; color:#374151; }
.task-item.done { color:#9ca3af; text-decoration:line-through; }
.sub-label { font-size:.68rem; font-weight:700; color:#9ca3af;
             text-transform:uppercase; letter-spacing:.04em; margin:.5rem 0 .2rem; }
.stat-pill { background:#fff; border:1px solid #e5e7eb; border-radius:8px;
             padding:.4rem .9rem; font-size:.8rem; font-weight:600;
             display:inline-flex; align-items:center; gap:.35rem;
             box-shadow:0 1px 3px rgba(0,0,0,.04); }
.badge-obsidian { background:#7c3aed; color:#fff; font-size:.65rem;
                  padding:.15rem .5rem; border-radius:8px; font-weight:600; }

/* ── Contas GitHub ─────────────────────────────────────────── */
.gh-contas-section { margin-bottom: 1.5rem; margin-top: 2rem; }
.gh-contas-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:.75rem; }
.gh-conta-card { background:#fff; border:2px solid #e5e7eb; border-radius:12px;
                 padding:1rem; position:relative; transition:all .15s; }
.gh-conta-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
.gh-conta-topo { display:flex; justify-content:space-between; align-items:center; margin-bottom:.5rem; }
.gh-conta-badge.ok   { color:#1e8e3e; }
.gh-conta-badge.erro { color:#d93025; }
.gh-conta-cfg { background:none; border:none; color:#9ca3af; cursor:pointer; padding:0; }
.gh-conta-cfg:hover { color:#1a237e; }
.gh-conta-apelido { font-weight:700; font-size:.88rem; }
.gh-conta-usuario { font-size:.75rem; color:#6b7280; }
.gh-conta-add { display:flex; flex-direction:column; align-items:center; justify-content:center;
                gap:.35rem; min-height:64px; border:2px dashed #d1d5db; color:#9ca3af;
                cursor:pointer; border-radius:12px; }
.gh-conta-add:hover { border-color:#1a237e; color:#1a237e; }

.gh-erro-conta { background:#fff3e0; color:#854d0e; border:1px solid #fde68a;
                 border-radius:8px; padding:.6rem 1rem; font-size:.82rem; margin-bottom:.75rem; }

.gh-grupo-section { margin-bottom:1.5rem; }
.gh-grupo-header { border-radius:12px 12px 0 0; padding:.65rem 1.1rem;
                   display:flex; align-items:center; justify-content:space-between;
                   color:#fff; font-weight:700; font-size:.85rem; }
.gh-grupo-count { font-size:.72rem; opacity:.85; font-weight:400; }
.gh-grupo-body { background:#fff; border:1px solid #e5e7eb; border-top:none;
                 border-radius:0 0 12px 12px; padding:1rem; }
.gh-proj-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:.85rem; }
.gh-proj-card { display:flex; flex-direction:column; border:1px solid #e5e7eb; border-radius:12px; padding:1rem;
                transition:all .15s; background:#fff; }
.gh-proj-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); border-color:#1a237e; }
.gh-proj-topo { display:flex; align-items:flex-start; justify-content:space-between; gap:.5rem; }
.gh-proj-nome { font-weight:700; font-size:.9rem; color:#1a237e; text-decoration:none; }
.gh-proj-nome:hover { text-decoration:underline; }
.gh-proj-acoes { display:flex; align-items:center; gap:.15rem; flex-shrink:0; }
.gh-proj-icon-btn { background:none; border:none; color:#9ca3af; cursor:pointer; padding:.15rem .3rem;
                     text-decoration:none; font-size:.85rem; display:inline-flex; }
.gh-proj-icon-btn:hover { color:#374151; }
.gh-proj-menu { background:none; border:none; color:#9ca3af; cursor:pointer; padding:0 .25rem; }
.gh-proj-menu:hover { color:#374151; }
.gh-proj-desc { font-size:.78rem; color:#6b7280; margin:.4rem 0;
                display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.gh-proj-progress { margin:.3rem 0; }
.gh-proj-progress-bar { height:6px; border-radius:3px; background:#e5e7eb; overflow:hidden; }
.gh-proj-progress-fill { height:100%; border-radius:3px; background:#1a237e; transition:width .4s ease; }
.gh-proj-progress-label { font-size:.68rem; color:#9ca3af; margin-top:.2rem; display:block; }
.gh-proj-previsao { font-size:.72rem; color:#6b7280; margin-top:.2rem; }
.gh-proj-det { margin-top:.4rem; font-size:.75rem; }
.gh-proj-det > summary { cursor:pointer; color:#374151; font-weight:600; list-style:none; padding:.15rem 0;
                         display:flex; align-items:center; gap:.35rem; }
.gh-proj-det > summary::-webkit-details-marker { display:none; }
.gh-proj-det[open] > summary { margin-bottom:.25rem; }
.gh-proj-det-list { list-style:none; margin:0; padding:0 0 0 .2rem; display:flex; flex-direction:column; gap:.2rem;
                    max-height:180px; overflow-y:auto; }
.gh-proj-det-list li { color:#4b5563; line-height:1.35; }
.gh-proj-det-list a { color:#1a237e; text-decoration:none; font-weight:600; margin-right:.25rem; }
.gh-proj-det-list .gh-commit-data { color:#9ca3af; font-size:.68rem; margin-right:.3rem; white-space:nowrap; }
.gh-proj-det-list .gh-det-mais { color:#9ca3af; font-style:italic; }
.gh-lbl { display:inline-block; background:#eef2ff; color:#3730a3; border-radius:8px;
          padding:0 .4rem; font-size:.62rem; font-weight:600; margin-left:.25rem; }
.commit-hist { list-style:none; margin:0; padding:0; font-size:.8rem; }
.commit-hist-dia { position:sticky; top:57px; background:#f3f4f6; color:#374151; font-weight:700;
                   font-size:.72rem; padding:.3rem .9rem; border-bottom:1px solid #e5e7eb; }
.commit-hist-item { display:flex; align-items:baseline; gap:.5rem; padding:.4rem .9rem;
                    border-bottom:1px solid #f3f4f6; flex-wrap:wrap; }
.commit-hist-item:hover { background:#f9fafb; }
.commit-hist-hora { color:#9ca3af; font-size:.72rem; white-space:nowrap; font-variant-numeric:tabular-nums; }
.commit-hist-sha { font-family:monospace; font-size:.72rem; color:#1a237e; text-decoration:none; }
.commit-hist-sha:hover { text-decoration:underline; }
.commit-hist-msg { flex:1; min-width:200px; color:#374151; }
.commit-hist-autor { color:#9ca3af; font-size:.72rem; white-space:nowrap; }
.gh-proj-meta { display:flex; flex-wrap:wrap; gap:.6rem; margin-top:auto;
                padding-top:.6rem; border-top:1px solid #f3f4f6; }

/* ── Conteúdo do README (renderizado pelo GitHub) dentro do modal ── */
.gh-readme-conteudo { font-size:.88rem; line-height:1.6; color:#1f2328; }
.gh-readme-conteudo h1, .gh-readme-conteudo h2 { font-size:1.2rem; font-weight:700; margin:1rem 0 .5rem; padding-bottom:.3rem; border-bottom:1px solid #e5e7eb; }
.gh-readme-conteudo h3, .gh-readme-conteudo h4 { font-size:1rem; font-weight:700; margin:.85rem 0 .4rem; }
.gh-readme-conteudo p { margin:0 0 .75rem; }
.gh-readme-conteudo ul, .gh-readme-conteudo ol { margin:0 0 .75rem; padding-left:1.5rem; }
.gh-readme-conteudo img { max-width:100%; height:auto; }
.gh-readme-conteudo code { background:#f3f4f6; border-radius:4px; padding:.1rem .35rem; font-size:.85em; }
.gh-readme-conteudo pre { background:#f6f8fa; border-radius:8px; padding:.75rem 1rem; overflow-x:auto; margin:0 0 .75rem; }
.gh-readme-conteudo pre code { background:none; padding:0; }
.gh-readme-conteudo blockquote { border-left:3px solid #d1d5db; color:#6b7280; margin:0 0 .75rem; padding:.1rem 1rem; }
.gh-readme-conteudo table { border-collapse:collapse; margin:0 0 .75rem; width:100%; }
.gh-readme-conteudo th, .gh-readme-conteudo td { border:1px solid #e5e7eb; padding:.4rem .6rem; font-size:.85rem; }
.gh-readme-conteudo a { color:#1a237e; }

/* ── Status e Previsão ─────────────────────────────────────────── */
.status-badge { display:inline-flex; align-items:center; gap:.35rem;
                padding:.28rem .75rem; border-radius:20px; font-size:.75rem; font-weight:700; }
.status-concluido { background:#d1fae5; color:#065f46; }
.status-adiantado { background:#dbeafe; color:#1e40af; }
.status-no_prazo  { background:#dcfce7; color:#166534; }
.status-atencao   { background:#fef9c3; color:#854d0e; }
.status-atrasado  { background:#fee2e2; color:#991b1b; }
.mod-prazo { font-size:.67rem; margin:.2rem 0 .28rem; }

/* ── Print ─────────────────────────────────────────────── */
@media print {
  .topbar, .hero, .stat-pill.border-0, .badge-obsidian { display:none !important; }
  .card-box [onclick*="toggleMod"] { display:none !important; }
  .mod-body-content { display:block !important; }
  body  { background:#fff !important; font-size:10pt !important; }
  .wrap { max-width:100% !important; margin:0 !important; padding:0 !important; }
  .card-box { break-inside:avoid; border:1px solid #ddd !important;
              box-shadow:none !important; border-radius:6px !important;
              padding:.8rem !important; margin-bottom:.5rem !important; }
  .gantt-wrap { overflow:visible !important; }
  svg { max-width:100% !important; }
  .status-badge, .prog-fill, .prog-bar, .gantt-bar, .gantt-today
    { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
</style>
</head>
<body>

<div class="topbar">
  <div style="font-weight:700;font-size:1rem;display:flex;align-items:center;gap:.5rem">
    <i class="bi bi-kanban-fill"></i>
    <?php if ($modoDetalhe): ?>
      <a href="projetos.php" style="background:none;padding:0;font-weight:400;font-size:.85rem;opacity:.8">
        Projetos
      </a>
      <i class="bi bi-chevron-right" style="font-size:.7rem;opacity:.6"></i>
      <?= esc(mb_substr($projeto['titulo'],0,40)) ?>
    <?php else: ?>
      Projetos de TI
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:.5rem">
    <?php if ($modoDetalhe): ?>
      <a href="projetos.php"><i class="bi bi-grid-3x3-gap me-1"></i>Todos os projetos</a>
    <?php endif; ?>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0">
    <i class="bi bi-kanban-fill me-2"></i>
    <?= $modoDetalhe ? esc($projeto['titulo']) : 'Projetos de TI' ?>
  </h1>
  <p style="opacity:.8;margin-top:.35rem;font-size:.85rem">
    <?php if ($modoDetalhe): ?>
      <?= esc($projeto['objetivo']) ?>
    <?php else: ?>
      Acompanhe o progresso de cada projeto
    <?php endif; ?>
  </p>
</div>

<div class="wrap">

<?php if ($mensagemSync): ?>
  <div class="sync-toast" style="background:#1b6d4a;color:#fff;padding:8px 16px;border-radius:8px;margin-bottom:1rem;display:inline-flex;align-items:center;gap:8px;font-size:.9rem">
    <i class="bi bi-check-circle-fill"></i> <?= esc($mensagemSync) ?>
  </div>
<?php endif; ?>

<?php if (!$modoDetalhe): ?>
  <!-- ═══════════════ CONTAS GITHUB ═══════════════ -->
  <div class="gh-contas-section">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.5rem">
      <h6 class="fw-bold mb-0" style="color:#374151;cursor:pointer" onclick="toggleContas()">
        <i class="bi bi-github me-2"></i>Minhas Contas GitHub
        <span class="text-muted fw-normal">(<?= count($minhasContas) ?>)</span>
        <i class="bi bi-chevron-down ms-1" id="chvContas" style="font-size:.75rem;transition:transform .2s"></i>
      </h6>
      <?php if ($minhasContas): ?>
      <select id="filtroConta" class="form-select form-select-sm" style="max-width:200px" onclick="event.stopPropagation()">
        <option value="">Todos os técnicos</option>
        <?php foreach ($minhasContas as $c): ?>
          <option value="<?= esc($c['apelido']) ?>"><?= esc($c['apelido']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
    </div>
    <div class="gh-contas-grid" id="gridContas" style="display:none">
      <?php foreach ($minhasContas as $c): ?>
        <div class="gh-conta-card">
          <div class="gh-conta-topo">
            <span class="gh-conta-badge <?= $c['ultimo_teste_ok'] ? 'ok' : 'erro' ?>">
              <i class="bi <?= $c['ultimo_teste_ok'] ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
            </span>
            <button type="button" class="gh-conta-cfg" onclick='editarConta(<?= json_encode(['id'=>$c['id'],'apelido'=>$c['apelido'],'usuario_github'=>$c['usuario_github']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>)' title="Editar">
              <i class="bi bi-gear-fill"></i>
            </button>
          </div>
          <div class="gh-conta-apelido"><?= esc($c['apelido']) ?></div>
          <div class="gh-conta-usuario">@<?= esc($c['usuario_github']) ?></div>
        </div>
      <?php endforeach; ?>
      <div class="gh-conta-card gh-conta-add" onclick="abrirModalConta()">
        <i class="bi bi-plus-circle" style="font-size:1.5rem"></i>
        <div class="gh-conta-apelido">Adicionar</div>
      </div>
    </div>
  </div>

  <!-- ═══════════════ PROJETOS (GitHub, 3 seções) ═══════════════ -->

  <?php
  $reposPorSecao = ['futuro' => [], 'em_execucao' => [], 'concluido' => []];
  $errosContas   = [];

  if ($minhasContas) {
      $contaIds   = array_column($minhasContas, 'id');
      $statusMap  = [];
      $visivelMap = [];
      $ph = implode(',', array_fill(0, count($contaIds), '?'));
      $st = $pdo->prepare("SELECT conta_id, repo_nome, status, visivel FROM portal_projetos_status WHERE conta_id IN ($ph)");
      $st->execute($contaIds);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $chaveMap = $row['conta_id'] . ':' . $row['repo_nome'];
          $statusMap[$chaveMap]  = $row['status'];
          $visivelMap[$chaveMap] = (int)$row['visivel'];
      }

      foreach ($minhasContas as $conta) {
          if (!$conta['ativo']) continue;
          $token      = vault_decrypt($conta['token_enc']);
          $resultado  = gh_cached($pdo, "repos:{$conta['id']}", 300, fn() => github_listar_repos($token));
          if (isset($resultado['erro'])) {
              $errosContas[] = ['apelido' => $conta['apelido'], 'msg' => $resultado['erro']];
              $pdo->prepare("UPDATE portal_github_contas SET ultimo_teste_ok=0, ultima_verificacao=NOW() WHERE id=?")
                  ->execute([$conta['id']]);
              continue;
          }
          $pdo->prepare("UPDATE portal_github_contas SET ultimo_teste_ok=1, ultima_verificacao=NOW() WHERE id=?")
              ->execute([$conta['id']]);
          foreach ($resultado as $repo) {
              $chave = $conta['id'] . ':' . $repo['nome'];
              if (($visivelMap[$chave] ?? 1) === 0) continue; // ocultado pelo usuário
              $status = $statusMap[$chave] ?? 'em_execucao';

              $repo['conta_id']      = $conta['id'];
              $repo['conta_apelido'] = $conta['apelido'];

              $ghUser = $conta['usuario_github'];
              $ck = "{$conta['id']}:{$repo['nome']}";

              $analiseReadme = gh_cached($pdo, "readme:$ck", 600, fn() => github_analisar_readme($token, $ghUser, $repo['nome']));
              $repo['descricao'] = $analiseReadme['descricao'] !== '' ? $analiseReadme['descricao'] : $repo['descricao'];

              $issues  = gh_cached($pdo, "issues:$ck",  600, fn() => github_issues($token, $ghUser, $repo['nome']));
              $commits = gh_cached($pdo, "commits:$ck", 600, fn() => github_commits_recentes($token, $ghUser, $repo['nome'], 8));
              $repo['issues_data'] = $issues;
              $repo['commits']     = $commits;

              // Progresso: issues fechadas / total. Sem issues → cai pro checklist do README.
              $totIss = $issues['total_abertas'] + $issues['total_fechadas'];
              if ($totIss > 0) {
                  $repo['progresso'] = [
                      'feitas' => $issues['total_fechadas'],
                      'total'  => $totIss,
                      'pct'    => (int)round($issues['total_fechadas'] / $totIss * 100),
                      'fonte'  => 'issues',
                  ];
              } else {
                  $repo['progresso'] = $analiseReadme['progresso'] + ['fonte' => 'readme'];
              }

              $repo['previsao'] = gh_cached($pdo, "milestone:$ck", 600, fn() => github_obter_previsao($token, $ghUser, $repo['nome']));

              $reposPorSecao[$status][] = $repo;
          }
      }
  }

  $secoesInfo = [
      'futuro'      => ['label' => 'Futuros',     'icon' => 'bi-lightbulb-fill',    'cor' => '#7c3aed'],
      'em_execucao' => ['label' => 'Em Execução', 'icon' => 'bi-hourglass-split',   'cor' => '#1a237e'],
      'concluido'   => ['label' => 'Concluídos',  'icon' => 'bi-check-circle-fill', 'cor' => '#1e8e3e'],
  ];
  ?>

  <?php foreach ($errosContas as $err): ?>
    <div class="gh-erro-conta">
      <i class="bi bi-exclamation-triangle-fill me-2"></i>
      <strong><?= esc($err['apelido']) ?>:</strong> não foi possível carregar — <?= esc($err['msg']) ?>
    </div>
  <?php endforeach; ?>

  <?php if (!$minhasContas): ?>
    <div class="text-muted small mt-4">Cadastre uma conta GitHub acima para ver seus projetos aqui.</div>
  <?php else: ?>
    <div class="d-flex justify-content-end mb-2">
      <a href="projetos.php?gh_refresh=1" class="btn btn-sm btn-outline-secondary" style="font-size:.72rem">
        <i class="bi bi-arrow-clockwise me-1"></i>Atualizar do GitHub
      </a>
    </div>
    <?php foreach ($secoesInfo as $chaveSecao => $info):
      $abertoPorPadrao = ($chaveSecao === 'em_execucao');
    ?>
      <div class="gh-grupo-section">
        <div class="gh-grupo-header" style="background:<?= $info['cor'] ?>;cursor:pointer" onclick="toggleGrupo('<?= $chaveSecao ?>')">
          <span><i class="bi <?= $info['icon'] ?> me-2"></i><?= $info['label'] ?></span>
          <span>
            <span class="gh-grupo-count"><?= count($reposPorSecao[$chaveSecao]) ?> projeto(s)</span>
            <i class="bi bi-chevron-down ms-2" id="chvGrupo-<?= $chaveSecao ?>" style="font-size:.75rem;transition:transform .2s<?= $abertoPorPadrao ? ';transform:rotate(-180deg)' : '' ?>"></i>
          </span>
        </div>
        <div class="gh-grupo-body" id="grupoBody-<?= $chaveSecao ?>" style="<?= $abertoPorPadrao ? '' : 'display:none' ?>">
          <?php if ($reposPorSecao[$chaveSecao]): ?>
            <div class="gh-proj-grid">
              <?php foreach ($reposPorSecao[$chaveSecao] as $repo): ?>
                <div class="gh-proj-card" data-conta="<?= esc($repo['conta_apelido']) ?>">
                  <div class="gh-proj-topo">
                    <a href="#" onclick="abrirDocumentacao(event,<?= (int)$repo['conta_id'] ?>,'<?= esc($repo['nome']) ?>')" class="gh-proj-nome">
                      <i class="bi bi-journal-text me-1"></i><?= esc($repo['nome']) ?>
                    </a>
                    <div class="gh-proj-acoes">
                      <button type="button" class="gh-proj-icon-btn" title="Histórico de commits" onclick="abrirCommits(<?= (int)$repo['conta_id'] ?>,'<?= esc($repo['nome']) ?>')"><i class="bi bi-clock-history"></i></button>
                      <a href="<?= esc($repo['url']) ?>" target="_blank" rel="noopener" class="gh-proj-icon-btn" title="Abrir no GitHub"><i class="bi bi-github"></i></a>
                      <div class="dropdown">
                        <button type="button" class="gh-proj-menu" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                          <?php foreach ($secoesInfo as $optKey => $optInfo): ?>
                            <li><a class="dropdown-item" href="#" onclick="mudarStatus(event,<?= (int)$repo['conta_id'] ?>,'<?= esc($repo['nome']) ?>','<?= $optKey ?>')"><i class="bi <?= $optInfo['icon'] ?> me-2"></i><?= $optInfo['label'] ?></a></li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                    </div>
                  </div>
                  <?php if ($repo['descricao']): ?>
                    <div class="gh-proj-desc"><?= esc($repo['descricao']) ?></div>
                  <?php endif; ?>
                  <?php if (($repo['progresso']['total'] ?? 0) > 0): ?>
                    <div class="gh-proj-progress">
                      <div class="gh-proj-progress-bar"><div class="gh-proj-progress-fill" style="width:<?= (int)$repo['progresso']['pct'] ?>%"></div></div>
                      <span class="gh-proj-progress-label">
                        <?= ($repo['progresso']['fonte'] ?? '') === 'issues' ? 'Issues' : 'Checklist README' ?>:
                        <?= (int)$repo['progresso']['feitas'] ?>/<?= (int)$repo['progresso']['total'] ?>
                        <?= ($repo['progresso']['fonte'] ?? '') === 'issues' ? 'fechadas' : 'concluídas' ?>
                      </span>
                    </div>
                  <?php endif; ?>
                  <?php if ($repo['previsao']): ?>
                    <div class="gh-proj-previsao"><i class="bi bi-calendar-event me-1"></i>Previsão: <?= esc(date('d/m/Y', strtotime($repo['previsao']))) ?></div>
                  <?php endif; ?>

                  <?php $iss = $repo['issues_data'] ?? null; ?>
                  <?php if ($iss && $iss['total_abertas'] > 0): ?>
                    <details class="gh-proj-det">
                      <summary><i class="bi bi-list-check text-danger"></i> Pendências (<?= (int)$iss['total_abertas'] ?>)</summary>
                      <ul class="gh-proj-det-list">
                        <?php foreach (array_slice($iss['abertas'], 0, 12) as $it): ?>
                          <li>
                            <a href="<?= esc($it['url']) ?>" target="_blank" rel="noopener">#<?= (int)$it['numero'] ?></a>
                            <?= esc($it['titulo']) ?>
                            <?php foreach ($it['labels'] as $lb): if ($lb === '') continue; ?><span class="gh-lbl"><?= esc($lb) ?></span><?php endforeach; ?>
                          </li>
                        <?php endforeach; ?>
                        <?php if ($iss['total_abertas'] > 12): ?><li class="gh-det-mais">… e mais <?= $iss['total_abertas'] - 12 ?></li><?php endif; ?>
                      </ul>
                    </details>
                  <?php endif; ?>

                  <?php if (!empty($repo['commits'])): ?>
                    <details class="gh-proj-det">
                      <summary><i class="bi bi-clock-history text-success"></i> Feito recentemente</summary>
                      <ul class="gh-proj-det-list">
                        <?php foreach ($repo['commits'] as $cm): ?>
                          <li>
                            <span class="gh-commit-data"><?= esc(dataRelativa($cm['data'])) ?></span>
                            <?php if (!empty($cm['branch']) && $cm['branch'] !== 'main' && $cm['branch'] !== 'master'): ?><span class="gh-lbl"><?= esc($cm['branch']) ?></span><?php endif; ?>
                            <?= esc($cm['msg']) ?>
                          </li>
                        <?php endforeach; ?>
                        <?php foreach (array_slice($iss['fechadas_recentes'] ?? [], 0, 4) as $it): ?>
                          <li><span class="gh-commit-data">✓ #<?= (int)$it['numero'] ?></span> <?= esc($it['titulo']) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    </details>
                  <?php endif; ?>
                  <div class="gh-proj-meta">
                    <?php if ($repo['linguagem']): ?><span class="meta-pill"><i class="bi bi-circle-fill" style="font-size:.5rem"></i><?= esc($repo['linguagem']) ?></span><?php endif; ?>
                    <span class="meta-pill"><i class="bi bi-exclamation-circle"></i><?= (int)$repo['issues_abertas'] ?> issues</span>
                    <span class="meta-pill"><i class="bi bi-clock-history"></i><?= esc(dataRelativa($repo['ultimo_push'])) ?></span>
                    <span class="meta-pill"><i class="bi bi-person"></i><?= esc($repo['conta_apelido']) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="text-muted small mb-0">Nenhum projeto aqui ainda.</p>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php else: ?>
  <!-- ═══════════════ DETALHE DO PROJETO ═══════════════ -->

  <!-- Header -->
  <div class="card-box" data-export-section="header">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
      <div>
        <h2 style="font-size:1.1rem;font-weight:700;margin:0"><?= esc($projeto['titulo']) ?></h2>
        <?php if ($projeto['objetivo']): ?>
          <p style="color:#6b7280;font-size:.82rem;margin:.3rem 0 0"><?= esc($projeto['objetivo']) ?></p>
        <?php endif; ?>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($projeto['equipe']): ?>
          <span class="stat-pill"><i class="bi bi-people text-primary"></i><?= esc($projeto['equipe']) ?></span>
        <?php endif; ?>
        <?php if ($projeto['prazo']): ?>
          <span class="stat-pill"><i class="bi bi-calendar-check text-danger"></i><?= esc($projeto['prazo']) ?></span>
        <?php endif; ?>
        <?php if ($projeto['repo']): ?>
          <a href="<?= esc($projeto['repo']) ?>" target="_blank" class="stat-pill text-decoration-none">
            <i class="bi bi-github"></i>GitHub
          </a>
        <?php endif; ?>
        <?php
        $sIcons  = ['concluido'=>'check-circle-fill','adiantado'=>'arrow-up-circle-fill',
                    'no_prazo'=>'check-circle','atencao'=>'exclamation-triangle-fill','atrasado'=>'x-circle-fill'];
        $sLabels = ['concluido'=>'Concluído','adiantado'=>'Adiantado',
                    'no_prazo'=>'No prazo','atencao'=>'Atenção','atrasado'=>'Em atraso'];
        if (isset($sLabels[$statusProj])): ?>
          <span class="status-badge status-<?= $statusProj ?>">
            <i class="bi bi-<?= $sIcons[$statusProj] ?>"></i><?= $sLabels[$statusProj] ?>
          </span>
        <?php endif; ?>
        <button class="stat-pill border-0" onclick="abrirExportModal()"
                style="cursor:pointer;background:#fff;transition:.15s"
                onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='#fff'"
                title="Exportar projeto">
          <i class="bi bi-file-earmark-arrow-down text-success"></i> Exportar
        </button>
      </div>
    </div>
    <div class="d-flex align-items-center gap-3 mb-1">
      <div class="prog-bar flex-grow-1">
        <div class="prog-fill" style="width:<?= $projeto['pct'] ?>%;background:<?= corPct($projeto['pct']) ?>"></div>
      </div>
      <span style="font-weight:700;font-size:.9rem;color:<?= corPct($projeto['pct']) ?>;min-width:40px">
        <?= $projeto['pct'] ?>%
      </span>
    </div>
    <div style="font-size:.75rem;color:#9ca3af">
      <?= $projeto['done'] ?> / <?= $projeto['total'] ?> tarefas · <?= count(array_filter($projeto['modulos'], fn($m) => $m['tot'] > 0)) ?> módulos
    </div>
  </div>

  <!-- ── Previsão de Término ──────────────────────────────────────────── -->
  <?php if ($projInicio && $projFim2):
        $svgW = 560; $svgH = 185;
        $padL = 44; $padR = 18; $padT = 22; $padB = 42;
        $cW = $svgW - $padL - $padR;
        $cH = $svgH - $padT - $padB;
        $xMax   = max($projFim2, $dataForecast ?? $projFim2);
        $xRange = max(1, $xMax - $projInicio) * 1.08;
        $px = fn($ts) => round($padL + ($ts - $projInicio) / $xRange * $cW, 1);
        $py = fn($pct) => round($padT + $cH * (1 - $pct / 100), 1);
        $xFim   = $px($projFim2);
        $xToday = $px($hoje);
        $xFcPx  = $dataForecast ? min($px($dataForecast), $padL + $cW) : null;
        $yActual = $py($projeto['pct']);
        $yBot    = $py(0);
        $dotColor = match($statusProj) {
            'concluido','adiantado','no_prazo' => '#1e8e3e',
            'atencao' => '#f57c00',
            default   => '#ef4444',
        };
        $modsPrazo = array_filter($projeto['modulos'], fn($m) => !empty($m['prazo']));
  ?>
  <div class="card-box" data-export-section="header">
    <h6 style="font-weight:700;margin-bottom:.75rem">
      <i class="bi bi-graph-up-arrow me-2 text-primary"></i>Cronograma de Previsão de Término
    </h6>
    <svg viewBox="0 0 <?=$svgW?> <?=$svgH?>" style="width:100%;height:auto;display:block">
      <!-- Y grid -->
      <?php foreach ([0,25,50,75,100] as $g):
            $gy = $py($g); ?>
        <line x1="<?=$padL?>" y1="<?=$gy?>" x2="<?=$padL+$cW?>" y2="<?=$gy?>"
              stroke="<?=$g==0?'#d1d5db':'#f3f4f6'?>" stroke-width="<?=$g==0?'1.5':'1'?>"/>
        <text x="<?=$padL-5?>" y="<?=$gy+3.5?>" text-anchor="end" font-size="9" fill="#9ca3af"><?=$g?>%</text>
      <?php endforeach; ?>

      <!-- Planned line (0% at start → 100% at deadline) -->
      <line x1="<?=$padL?>" y1="<?=$yBot?>" x2="<?=$xFim?>" y2="<?=$py(100)?>"
            stroke="#93c5fd" stroke-width="2" stroke-dasharray="6,3"/>

      <!-- Module deadlines -->
      <?php foreach ($modsPrazo as $mod):
            $mts = parseDataBR($mod['prazo']);
            if (!$mts) continue;
            $mx  = $px($mts);
            $mc  = ($mts < $hoje && $mod['pct'] < 100) ? '#ef4444' : '#d1d5db';
      ?>
        <line x1="<?=$mx?>" y1="<?=$py(100)?>" x2="<?=$mx?>" y2="<?=$yBot?>"
              stroke="<?=$mc?>" stroke-width="1" stroke-dasharray="2,3" opacity="0.55"/>
        <circle cx="<?=$mx?>" cy="<?=$py($mod['pct'])?>" r="3.5" fill="<?=$mc?>" opacity="0.85"/>
      <?php endforeach; ?>

      <!-- Forecast line -->
      <?php if ($xFcPx): ?>
        <line x1="<?=$xToday?>" y1="<?=$yActual?>" x2="<?=$xFcPx?>" y2="<?=$py(100)?>"
              stroke="#9ca3af" stroke-width="2" stroke-dasharray="8,4" opacity="0.8"/>
        <?php if ($dataForecast && abs($dataForecast - $projFim2) > 86400): ?>
          <text x="<?=$xFcPx?>" y="<?=$svgH-4?>" text-anchor="middle"
                font-size="9" fill="#6b7280">Prev.&nbsp;<?=date('d/m',$dataForecast)?></text>
        <?php endif; ?>
      <?php endif; ?>

      <!-- Deadline vertical -->
      <line x1="<?=$xFim?>" y1="<?=$padT?>" x2="<?=$xFim?>" y2="<?=$yBot?>"
            stroke="#3b82f6" stroke-width="1.5" stroke-dasharray="5,3" opacity="0.6"/>
      <text x="<?=$xFim?>" y="<?=$svgH-4?>" text-anchor="middle"
            font-size="9" fill="#3b82f6" font-weight="600">Prazo&nbsp;<?=date('d/m',$projFim2)?></text>

      <!-- Today line -->
      <line x1="<?=$xToday?>" y1="<?=$padT?>" x2="<?=$xToday?>" y2="<?=$yBot?>"
            stroke="#ef4444" stroke-width="2" stroke-dasharray="4,2" opacity="0.7"/>
      <text x="<?=$xToday?>" y="<?=$svgH-4?>"
            text-anchor="<?=$xToday > $padL+$cW*0.88 ? 'end' : 'middle'?>"
            font-size="9" fill="#ef4444" font-weight="600">Hoje</text>

      <!-- Progress dot -->
      <circle cx="<?=$xToday?>" cy="<?=$yActual?>" r="7" fill="<?=$dotColor?>" stroke="#fff" stroke-width="2"/>
      <text x="<?=$xToday+11?>" y="<?=$yActual+4?>" font-size="10" fill="<?=$dotColor?>" font-weight="700"><?=$projeto['pct']?>%</text>

      <!-- Legend -->
      <line x1="<?=$padL+5?>" y1="13" x2="<?=$padL+22?>" y2="13" stroke="#93c5fd" stroke-width="2" stroke-dasharray="6,3"/>
      <text x="<?=$padL+26?>" y="17" font-size="9" fill="#6b7280">Planejado</text>
      <line x1="<?=$padL+82?>" y1="13" x2="<?=$padL+99?>" y2="13" stroke="#9ca3af" stroke-width="2" stroke-dasharray="8,4"/>
      <text x="<?=$padL+103?>" y="17" font-size="9" fill="#6b7280">Previsão atual</text>
      <circle cx="<?=$padL+175?>" cy="13" r="4" fill="<?=$dotColor?>"/>
      <text x="<?=$padL+182?>" y="17" font-size="9" fill="#6b7280">Progresso real</text>
    </svg>

    <div class="d-flex flex-wrap gap-3 mt-2 pt-2" style="font-size:.75rem;color:#6b7280;border-top:1px solid #f3f4f6">
      <?php if ($projInicio): ?>
        <span><i class="bi bi-play-circle me-1"></i><strong>Início:</strong> <?=date('d/m/Y',$projInicio)?></span>
      <?php endif; ?>
      <span><i class="bi bi-flag me-1"></i><strong>Prazo:</strong> <?=date('d/m/Y',$projFim2)?></span>
      <?php if ($diasDecorridos > 0): ?>
        <span><i class="bi bi-bar-chart me-1"></i><strong>Esperado hoje:</strong> <?=$pctEsperado?>%
          · <strong>Real:</strong> <?=$projeto['pct']?>%
          <span style="color:<?=$projeto['pct']>=$pctEsperado?'#1e8e3e':'#ef4444'?>">
            (<?=$projeto['pct']>=$pctEsperado?'+':''?><?=$projeto['pct']-$pctEsperado?>pp)
          </span>
        </span>
      <?php endif; ?>
      <?php if ($dataForecast): ?>
        <span><i class="bi bi-calendar-check me-1"></i><strong>Conclusão prevista:</strong>
          <?=date('d/m/Y',$dataForecast)?>
          <?php if ($dataForecast <= $projFim2): ?>
            <span style="color:#1e8e3e">✓ dentro do prazo</span>
          <?php else: ?>
            <span style="color:#ef4444">✗ <?=round(($dataForecast-$projFim2)/86400)?> dias de atraso</span>
          <?php endif; ?>
        </span>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Gantt -->
  <?php if ($ganttBars): ?>
  <div class="card-box" data-export-section="gantt">
    <h6 style="font-weight:700;margin-bottom:.75rem">
      <i class="bi bi-bar-chart-steps me-2 text-primary"></i>Cronograma — Linha do Tempo
    </h6>
    <div class="gantt-wrap"><div class="gantt">
      <div class="gantt-header">
        <div class="gantt-col-label">Etapa</div>
        <div class="gantt-timeline">
          <?php foreach ($ganttBars as $bar): ?>
            <div class="gantt-week"><?= esc($bar['semana']) ?><br><?= esc($bar['periodo']) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php
      $cores = ['#1a73e8','#1e8e3e','#f57c00','#7b1fa2','#c62828','#0097a7'];
      $hojePct = ($dataInicio && $totalDias) ? barPct($hoje,$dataInicio,$totalDias) : -1;
      foreach ($ganttBars as $i => $bar):
          $left  = barPct($bar['ini'],$dataInicio,$totalDias);
          $width = barPct($bar['fim'],$dataInicio,$totalDias) - $left;
          $cor   = $cores[$i % count($cores)];
          $isPast= $bar['fim'] < $hoje;
      ?>
      <div class="gantt-row">
        <div class="gantt-label" title="<?= esc($bar['descricao']) ?>">
          <?= esc($bar['semana']) ?> — <?= esc(mb_substr($bar['descricao'],0,28)) ?>…
        </div>
        <div class="gantt-track">
          <?php if ($hojePct >= 0 && $hojePct <= 100): ?>
            <div class="gantt-today" style="left:<?= $hojePct ?>%">
              <?php if ($i===0): ?><span class="gantt-today-label">Hoje</span><?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="gantt-bar"
               style="left:<?= $left ?>%;width:<?= max($width,2) ?>%;background:<?= $cor ?>;opacity:<?= $isPast?.5:1 ?>"
               title="<?= esc($bar['descricao']) ?>">
            <?= esc($bar['periodo']) ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div></div>
    <div style="font-size:.72rem;color:#9ca3af;margin-top:.5rem">
      <span style="display:inline-block;width:10px;height:10px;background:#ef4444;border-radius:50%;margin-right:4px"></span>Hoje
      &nbsp;·&nbsp; Barras opacas = semanas passadas
    </div>
  </div>
  <?php endif; ?>

  <!-- Módulos -->
  <div class="row g-2">
  <?php foreach ($projeto['modulos'] as $idx => $mod):
      if ($mod['tot'] === 0) continue; ?>
    <div class="col-md-6" data-export-section="modulo_<?= $idx ?>">
      <div class="card-box" style="padding:1rem">
        <div class="mod-header" onclick="toggleMod(<?= $idx ?>)">
          <i class="bi bi-chevron-right" id="chv-<?= $idx ?>" style="font-size:.75rem;color:#9ca3af;transition:transform .2s"></i>
          <span style="font-weight:700;font-size:.85rem;flex:1"><?= esc($mod['nome']) ?></span>
          <span style="font-size:.72rem;font-weight:700;color:<?= corPct($mod['pct']) ?>"><?= $mod['done'] ?>/<?= $mod['tot'] ?></span>
        </div>
        <div class="prog-bar" style="height:5px;margin:.3rem 0">
          <div class="prog-fill" style="width:<?= $mod['pct'] ?>%;background:<?= corPct($mod['pct']) ?>"></div>
        </div>
        <?php if (!empty($mod['prazo'])):
              $mts = parseDataBR($mod['prazo']);
              $mAtrasado = $mts && $mts < $hoje && $mod['pct'] < 100; ?>
          <div class="mod-prazo" style="color:<?= $mAtrasado ? '#ef4444' : '#9ca3af' ?>">
            <i class="bi bi-calendar<?= $mAtrasado ? '-x' : '' ?> me-1"></i><?php
            if ($mAtrasado) echo '<strong>Em atraso</strong> · '; ?>Prazo: <?= esc($mod['prazo']) ?>
          </div>
        <?php endif; ?>
        <div id="mod-body-<?= $idx ?>" class="mod-body-content" style="display:none">
          <?php $subAtual = null;
          foreach ($mod['tarefas'] as $t):
              if ($t['sub'] !== $subAtual):
                  $subAtual = $t['sub'];
                  if ($subAtual): ?><div class="sub-label"><?= esc($subAtual) ?></div><?php endif;
              endif; ?>
            <div class="task-item <?= $t['done']?'done':'' ?>">
              <span style="flex-shrink:0;margin-top:1px">
                <?= $t['done'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-circle" style="color:#d1d5db"></i>' ?>
              </span>
              <span><?= esc($t['texto']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <div style="text-align:center;margin-top:1.5rem;font-size:.75rem;color:#9ca3af">
    <i class="bi bi-journal-bookmark me-1"></i>
    <code title="<?= esc($projeto['filepath'] ?? '') ?>"><?= esc($projeto['arquivo'] ?? '') ?></code>
    · Edite no Obsidian e recarregue · <?= date('d/m/Y H:i') ?>
    <?php
    $lastSyncFile = __DIR__ . '/Docs/wiki/projects/.last_sync';
    if (file_exists($lastSyncFile)):
        $lastSync = @file_get_contents($lastSyncFile);
        if ($lastSync):
    ?>
    · <i class="bi bi-arrow-repeat me-1"></i>Último sync: <?= esc(substr($lastSync, 0, 16)) ?>
    <?php endif; endif; ?>
  </div>

<?php endif; ?>
</div>

<?php if ($modoDetalhe): ?>
<!-- ── Modal Exportar ── -->
<div class="modal fade" id="exportModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content" style="border-radius:14px;border:none;box-shadow:0 8px 32px rgba(0,0,0,.15)">
      <div class="modal-header" style="border-bottom:1px solid #f3f4f6">
        <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-arrow-down me-2 text-success"></i>Exportar Projeto</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3">Selecione as seções para exportar:</p>

        <div class="mb-2">
          <label class="d-flex align-items-center gap-2 py-1">
            <input type="checkbox" class="form-check-input form-check-input-sm" checked data-section="header">
            <span style="font-size:.82rem"><strong>Cabeçalho</strong> <span class="text-muted">(objetivo, equipe, prazos)</span></span>
          </label>
        </div>

        <?php if ($ganttBars): ?>
        <div class="mb-2">
          <label class="d-flex align-items-center gap-2 py-1">
            <input type="checkbox" class="form-check-input form-check-input-sm" checked data-section="gantt">
            <span style="font-size:.82rem"><strong>Cronograma / Gantt</strong></span>
          </label>
        </div>
        <?php endif; ?>

        <div class="mt-3 mb-1" style="font-size:.82rem;font-weight:700;color:#374151"><i class="bi bi-diagram-2 me-1"></i>Módulos</div>
        <?php foreach ($exportModulos as $em): ?>
        <div class="mb-1">
          <label class="d-flex align-items-center gap-2 py-1">
            <input type="checkbox" class="form-check-input form-check-input-sm" checked data-section="modulo_<?= $em['idx'] ?>">
            <span style="font-size:.82rem"><?= esc($em['nome']) ?> <span class="text-muted">(<?= $em['done'] ?>/<?= $em['tot'] ?>)</span></span>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer d-flex gap-2" style="border-top:1px solid #f3f4f6">
        <button class="btn btn-outline-secondary btn-sm" onclick="exportarMD()" style="border-radius:8px">
          <i class="bi bi-download me-1"></i> Download .md
        </button>
        <button class="btn btn-success btn-sm" onclick="exportarPrint()" style="border-radius:8px">
          <i class="bi bi-printer me-1"></i> Imprimir / PDF
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleMod(idx) {
  const b = document.getElementById('mod-body-' + idx);
  const c = document.getElementById('chv-' + idx);
  const open = b.style.display !== 'none';
  b.style.display     = open ? 'none' : '';
  c.style.transform   = open ? '' : 'rotate(90deg)';
}

<?php if ($modoDetalhe): ?>
// ── Export ───────────────────────────────────────────────────────────
const exportProj = '<?= urlencode($selArq) ?>';

function abrirExportModal() {
  const el = document.getElementById('exportModal');
  const modal = bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el);
  modal.show();
}

function getSelectedSections() {
  const checks = document.querySelectorAll('#exportModal input[data-section]:checked');
  return Array.from(checks).map(c => c.dataset.section).filter(Boolean);
}

function exportarMD() {
  const sec = getSelectedSections();
  if (!sec.length) { alert('Selecione ao menos uma seção.'); return; }
  const url = 'projetos.php?proj=' + exportProj + '&action=download&sections=' + encodeURIComponent(sec.join(','));
  window.location.href = url;
  const modal = bootstrap.Modal.getInstance(document.getElementById('exportModal'));
  if (modal) modal.hide();
}

function exportarPrint() {
  const sec = getSelectedSections();
  if (!sec.length) { alert('Selecione ao menos uma seção.'); return; }

  // Fecha modal
  const modal = bootstrap.Modal.getInstance(document.getElementById('exportModal'));
  if (modal) modal.hide();

  // Oculta seções não selecionadas
  const todos = document.querySelectorAll('[data-export-section]');
  todos.forEach(el => {
    el.dataset.exportOrigDisplay = el.style.display || '';
    const nome = el.dataset.exportSection;
    el.style.display = sec.includes(nome) ? '' : 'none';
  });

  // Restaura após impressão (ou timeout de segurança)
  function restaurar() {
    document.querySelectorAll('[data-export-section]').forEach(el => {
      el.style.display = el.dataset.exportOrigDisplay || '';
      delete el.dataset.exportOrigDisplay;
    });
    window.onafterprint = null;
  }

  window.onafterprint = restaurar;

  <?php if ($ganttBars): ?>
  // Expande Gantt se estiver visível
  <?php endif; ?>

  setTimeout(() => {
    window.print();
    // Fallback: se afterprint não disparar em 30s, restaura mesmo assim
    setTimeout(() => { if (window.onafterprint) restaurar(); }, 30000);
  }, 400);
}
<?php endif; ?>
</script>

<?php if (!$modoDetalhe): ?>
<script>
// ── Filtro de projetos por técnico ────────────────────────────────
function aplicarFiltroProjetos() {
  const conta = document.getElementById('filtroConta')?.value || '';
  document.querySelectorAll('.gh-proj-card').forEach(card => {
    card.style.display = (!conta || card.dataset.conta === conta) ? '' : 'none';
  });
}
document.getElementById('filtroConta')?.addEventListener('change', aplicarFiltroProjetos);
</script>
<?php endif; ?>

<!-- Modal: adicionar/editar conta GitHub -->
<div class="modal fade" id="modalConta" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1a237e,#1565c0);color:white">
        <h5 class="modal-title fw-bold" id="modalContaTitulo"><i class="bi bi-github me-2"></i>Nova Conta GitHub</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="conta-id"/>
        <div class="mb-3">
          <label class="form-label fw-semibold">Apelido</label>
          <input type="text" class="form-control" id="conta-apelido" placeholder="Ex: Pessoal"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Usuário GitHub</label>
          <input type="text" class="form-control" id="conta-usuario" placeholder="ex: joaosilva"/>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Personal Access Token</label>
          <input type="password" class="form-control font-monospace" id="conta-token" placeholder="ghp_..." autocomplete="new-password"/>
          <div class="form-text" id="conta-token-hint">Somente leitura, escopo <code>repo</code>. <a href="https://github.com/settings/tokens?type=beta" target="_blank" rel="noopener">Gerar token</a></div>
        </div>
        <div class="mb-2" id="conta-repos-wrap" style="display:none">
          <label class="form-label fw-semibold">Repositórios visíveis</label>
          <div id="conta-repos-lista" style="max-height:220px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:.5rem .75rem">
            <div class="text-muted small">Carregando...</div>
          </div>
        </div>
        <div id="conta-erro" class="text-danger small" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto" id="btn-excluir-conta" style="display:none" onclick="excluirConta()"><i class="bi bi-trash me-1"></i>Excluir</button>
        <button type="button" class="btn btn-outline-secondary" id="btn-testar-conta" style="display:none" onclick="testarConta()"><i class="bi bi-plug me-1"></i>Testar</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" onclick="salvarConta()" style="background:#1a237e;border-color:#1a237e"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: documentação do projeto (README renderizado do GitHub) -->
<div class="modal fade" id="modalDoc" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1a237e,#1565c0);color:white">
        <h5 class="modal-title fw-bold" id="modalDocTitulo"><i class="bi bi-journal-text me-2"></i>Documentação</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body gh-readme-conteudo" id="modalDocCorpo">
        <div class="text-muted small">Carregando...</div>
      </div>
      <div class="modal-footer">
        <a href="#" target="_blank" rel="noopener" class="btn btn-outline-secondary me-auto" id="modalDocGithub"><i class="bi bi-github me-1"></i>Ver no GitHub</a>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: histórico de commits -->
<div class="modal fade" id="modalCommits" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1a237e,#1565c0);color:white">
        <h5 class="modal-title fw-bold" id="modalCommitsTitulo"><i class="bi bi-clock-history me-2"></i>Histórico de commits</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="p-3 d-flex align-items-center gap-2 border-bottom" style="position:sticky;top:0;background:#fff;z-index:2">
          <input type="text" id="commitsBusca" class="form-control form-control-sm" placeholder="Filtrar por mensagem, autor ou branch…" style="max-width:340px" oninput="filtrarCommits()"/>
          <span class="text-muted small ms-auto" id="commitsContagem"></span>
        </div>
        <div id="modalCommitsCorpo"><div class="text-muted small p-3">Carregando…</div></div>
      </div>
      <div class="modal-footer">
        <a href="#" target="_blank" rel="noopener" class="btn btn-outline-secondary me-auto" id="modalCommitsGithub"><i class="bi bi-github me-1"></i>Ver no GitHub</a>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<script>
let modalConta;
let modalDoc;
let modalCommits;
let _commitsCache = [];
document.addEventListener('DOMContentLoaded', () => {
  const elConta = document.getElementById('modalConta');
  if (elConta) modalConta = new bootstrap.Modal(elConta);
  const elDoc = document.getElementById('modalDoc');
  if (elDoc) modalDoc = new bootstrap.Modal(elDoc);
  const elCommits = document.getElementById('modalCommits');
  if (elCommits) modalCommits = new bootstrap.Modal(elCommits);
});

function _fmtDataHora(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d)) return iso;
  const p = n => String(n).padStart(2, '0');
  return `${p(d.getDate())}/${p(d.getMonth()+1)}/${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

function _escCommit(s) {
  const el = document.createElement('div');
  el.textContent = s == null ? '' : String(s);
  return el.innerHTML;
}

function renderCommits(lista) {
  const corpo = document.getElementById('modalCommitsCorpo');
  if (!lista.length) { corpo.innerHTML = '<div class="text-muted small p-3">Nenhum commit.</div>'; return; }
  let ultimoDia = '';
  let html = '<ul class="commit-hist">';
  lista.forEach(c => {
    const dia = (c.data || '').slice(0, 10);
    if (dia && dia !== ultimoDia) {
      ultimoDia = dia;
      const dd = _fmtDataHora(c.data).slice(0, 10);
      html += `<li class="commit-hist-dia">${dd}</li>`;
    }
    const br = c.branch && c.branch !== 'main' && c.branch !== 'master'
      ? `<span class="gh-lbl">${_escCommit(c.branch)}</span>` : '';
    html += `<li class="commit-hist-item">
      <span class="commit-hist-hora">${_fmtDataHora(c.data).slice(11)}</span>
      <a href="${_escCommit(c.url)}" target="_blank" rel="noopener" class="commit-hist-sha">${_escCommit(c.sha)}</a>
      ${br}
      <span class="commit-hist-msg">${_escCommit(c.msg)}</span>
      <span class="commit-hist-autor">${_escCommit(c.autor)}</span>
    </li>`;
  });
  html += '</ul>';
  corpo.innerHTML = html;
}

function filtrarCommits() {
  const q = document.getElementById('commitsBusca').value.trim().toLowerCase();
  const f = !q ? _commitsCache : _commitsCache.filter(c =>
    (c.msg || '').toLowerCase().includes(q) ||
    (c.autor || '').toLowerCase().includes(q) ||
    (c.branch || '').toLowerCase().includes(q));
  document.getElementById('commitsContagem').textContent = f.length + ' de ' + _commitsCache.length;
  renderCommits(f);
}

async function abrirCommits(contaId, repoNome) {
  document.getElementById('modalCommitsTitulo').innerHTML = '<i class="bi bi-clock-history me-2"></i>' + repoNome;
  document.getElementById('modalCommitsCorpo').innerHTML = '<div class="text-muted small p-3">Carregando histórico…</div>';
  document.getElementById('commitsBusca').value = '';
  document.getElementById('commitsContagem').textContent = '';
  document.getElementById('modalCommitsGithub').href = '#';
  _commitsCache = [];
  modalCommits.show();

  try {
    const r = await fetch('projetos.php?gh_action=commits_full', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ conta_id: contaId, repo_nome: repoNome }),
    });
    const d = await r.json();
    if (!d.ok) {
      document.getElementById('modalCommitsCorpo').innerHTML =
        `<p class="text-danger small p-3">${_escCommit(d.msg || 'Erro ao carregar')}</p>`;
      return;
    }
    _commitsCache = d.commits || [];
    document.getElementById('modalCommitsGithub').href = d.repoUrl;
    document.getElementById('commitsContagem').textContent = _commitsCache.length + ' commits';
    renderCommits(_commitsCache);
  } catch (e) {
    document.getElementById('modalCommitsCorpo').innerHTML =
      `<p class="text-danger small p-3">Falha de rede: ${_escCommit(e.message)}</p>`;
  }
}

async function abrirDocumentacao(ev, contaId, repoNome) {
  ev.preventDefault();
  document.getElementById('modalDocTitulo').innerHTML = '<i class="bi bi-journal-text me-2"></i>' + repoNome;
  document.getElementById('modalDocCorpo').innerHTML = '<div class="text-muted small">Carregando...</div>';
  document.getElementById('modalDocGithub').href = '#';
  modalDoc.show();

  const r = await fetch('projetos.php?gh_action=readme_html', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({conta_id: contaId, repo_nome: repoNome}),
  });
  const d = await r.json();
  const corpo = document.getElementById('modalDocCorpo');
  if (!d.ok) {
    corpo.innerHTML = `<p class="text-danger small">${d.msg || 'Erro ao carregar documentação'}</p>`;
    return;
  }
  document.getElementById('modalDocGithub').href = d.repoUrl;
  corpo.innerHTML = d.html || '<p class="text-muted small">Este repositório não tem um README.</p>';
}

function abrirModalConta() {
  document.getElementById('conta-id').value = '';
  document.getElementById('conta-apelido').value = '';
  document.getElementById('conta-usuario').value = '';
  document.getElementById('conta-token').value = '';
  document.getElementById('conta-token').placeholder = 'ghp_...';
  document.getElementById('conta-erro').className = 'text-danger small';
  document.getElementById('conta-erro').style.display = 'none';
  document.getElementById('modalContaTitulo').innerHTML = '<i class="bi bi-github me-2"></i>Nova Conta GitHub';
  document.getElementById('btn-excluir-conta').style.display = 'none';
  document.getElementById('btn-testar-conta').style.display = 'none';
  document.getElementById('conta-repos-wrap').style.display = 'none';
  document.getElementById('conta-repos-lista').innerHTML = '';
  modalConta.show();
}

function editarConta(c) {
  document.getElementById('conta-id').value = c.id;
  document.getElementById('conta-apelido').value = c.apelido;
  document.getElementById('conta-usuario').value = c.usuario_github;
  document.getElementById('conta-token').value = '';
  document.getElementById('conta-token').placeholder = 'Deixe em branco para manter o token atual';
  document.getElementById('conta-erro').className = 'text-danger small';
  document.getElementById('conta-erro').style.display = 'none';
  document.getElementById('modalContaTitulo').textContent = c.apelido;
  document.getElementById('btn-excluir-conta').style.display = 'inline-block';
  document.getElementById('btn-testar-conta').style.display = 'inline-block';
  modalConta.show();
  carregarReposConta(c.id);
}

async function carregarReposConta(id) {
  const wrap  = document.getElementById('conta-repos-wrap');
  const lista = document.getElementById('conta-repos-lista');
  wrap.style.display = '';
  lista.innerHTML = '<div class="text-muted small">Carregando...</div>';

  const r = await fetch('projetos.php?gh_action=conta_repos', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id}),
  });
  const d = await r.json();
  if (!d.ok) { lista.innerHTML = `<div class="text-danger small">${d.msg || 'Erro ao carregar repositórios'}</div>`; return; }
  if (!d.repos.length) { lista.innerHTML = '<div class="text-muted small">Nenhum repositório encontrado.</div>'; return; }

  lista.innerHTML = d.repos.map(rp => `
    <div class="form-check">
      <input class="form-check-input conta-repo-check" type="checkbox" data-nome="${rp.nome}" id="crepo-${rp.nome}" ${rp.visivel ? 'checked' : ''}>
      <label class="form-check-label small" for="crepo-${rp.nome}">${rp.nome}</label>
    </div>
  `).join('');
}

async function testarConta() {
  const id     = document.getElementById('conta-id').value;
  const erroEl = document.getElementById('conta-erro');
  erroEl.style.display = 'none';
  if (!id) return;

  const btn = document.getElementById('btn-testar-conta');
  const htmlOriginal = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Testando...';

  try {
    const r = await fetch('projetos.php?gh_action=conta_testar', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({id}),
    });
    const d = await r.json();
    if (d.ok) {
      erroEl.className = 'text-success small';
      erroEl.textContent = 'Token válido — conexão ok.';
      erroEl.style.display = '';
    } else {
      erroEl.className = 'text-danger small';
      erroEl.textContent = d.msg || 'Falha ao testar token.';
      erroEl.style.display = '';
    }
  } catch (e) {
    erroEl.className = 'text-danger small';
    erroEl.textContent = 'Erro ao testar conta.';
    erroEl.style.display = '';
  } finally {
    btn.disabled = false;
    btn.innerHTML = htmlOriginal;
  }
}

async function salvarConta() {
  const id             = document.getElementById('conta-id').value;
  const apelido        = document.getElementById('conta-apelido').value.trim();
  const usuario_github = document.getElementById('conta-usuario').value.trim();
  const token          = document.getElementById('conta-token').value.trim();
  const erroEl         = document.getElementById('conta-erro');
  erroEl.style.display = 'none';

  if (!apelido || !usuario_github) {
    erroEl.textContent = 'Preencha apelido e usuário.';
    erroEl.style.display = '';
    return;
  }

  const action = id ? 'conta_save' : 'conta_add';
  const r = await fetch(`projetos.php?gh_action=${action}`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id, apelido, usuario_github, token}),
  });
  const d = await r.json();
  if (!d.ok) { erroEl.textContent = d.msg || 'Erro ao salvar'; erroEl.style.display = ''; return; }

  const contaIdFinal = id || d.id;
  const checks = document.querySelectorAll('#conta-repos-lista .conta-repo-check');
  if (checks.length) {
    const repos = Array.from(checks).map(chk => ({nome: chk.dataset.nome, visivel: chk.checked}));
    await fetch('projetos.php?gh_action=visibilidade_set_lote', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({conta_id: contaIdFinal, repos}),
    });
  }
  modalConta.hide();
  location.reload();
}

function toggleContas() {
  const grid = document.getElementById('gridContas');
  const chv  = document.getElementById('chvContas');
  const aberto = grid.style.display !== 'none';
  grid.style.display = aberto ? 'none' : '';
  chv.style.transform = aberto ? '' : 'rotate(-180deg)';
}

function toggleGrupo(chave) {
  const body = document.getElementById('grupoBody-' + chave);
  const chv  = document.getElementById('chvGrupo-' + chave);
  if (!body) return;
  const aberto = body.style.display !== 'none';
  body.style.display = aberto ? 'none' : '';
  chv.style.transform = aberto ? '' : 'rotate(-180deg)';
}

async function mudarStatus(ev, contaId, repoNome, status) {
  ev.preventDefault();
  const r = await fetch('projetos.php?gh_action=status_set', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({conta_id: contaId, repo_nome: repoNome, status}),
  });
  const d = await r.json();
  if (d.ok) location.reload();
  else alert(d.msg || 'Erro ao mudar status');
}

async function excluirConta() {
  const id = document.getElementById('conta-id').value;
  if (!id || !confirm('Excluir esta conta GitHub? Os projetos dela deixarão de aparecer.')) return;
  const r = await fetch('projetos.php?gh_action=conta_delete', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id}),
  });
  const d = await r.json();
  if (d.ok) { modalConta.hide(); location.reload(); }
  else alert(d.msg || 'Erro ao excluir');
}
</script>

</body>
</html>
