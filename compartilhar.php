<?php
/**
 * Cofre TI — visualização pública de item compartilhado por link temporário.
 * NÃO exige login. Valida token, expiração e nº de visualizações.
 */
require_once __DIR__ . '/agenda/db.php';     // PDO $pdo
require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/vault_crypto.php';  // vault_decrypt

$erro = null;
$item = null;
$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');

if (!$token) {
    $erro = 'Link inválido.';
} else {
    $st = $pdo->prepare("SELECT * FROM portal_vault_share WHERE token = ?");
    $st->execute([$token]);
    $share = $st->fetch(PDO::FETCH_ASSOC);

    if (!$share) {
        $erro = 'Link inválido ou já removido.';
    } elseif (new DateTime() > new DateTime($share['expira_em'])) {
        $erro = 'Este link expirou.';
    } elseif ((int)$share['views'] >= (int)$share['max_views']) {
        $erro = 'Este link já atingiu o limite de visualizações.';
    } else {
        // Consome uma visualização
        $pdo->prepare("UPDATE portal_vault_share SET views = views + 1 WHERE id = ?")
            ->execute([$share['id']]);

        $it = $pdo->prepare("SELECT titulo, categoria, usuario, url, conteudo FROM portal_vault WHERE id = ?");
        $it->execute([$share['item_id']]);
        $row = $it->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $erro = 'O item compartilhado não existe mais.';
        } else {
            $item = [
                'titulo'    => $row['titulo'],
                'usuario'   => $row['usuario'],
                'url'       => $row['url'],
                'conteudo'  => $row['conteudo'] ? vault_decrypt($row['conteudo']) : '',
                'restantes' => (int)$share['max_views'] - (int)$share['views'] - 1,
            ];
            // Auditoria do acesso público
            $pdo->prepare(
                "INSERT INTO portal_vault_audit (user_id,user_nome,acao,item_id,item_titulo,ip)
                 VALUES (NULL,?,?,?,?,?)"
            )->execute(['(link público)', 'share_view', (int)$share['item_id'], $row['titulo'],
                        $_SERVER['REMOTE_ADDR'] ?? null]);
        }
    }
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Cofre TI — Conteúdo compartilhado</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:linear-gradient(135deg,#263238,#37474f); min-height:100vh;
           font-family:'Segoe UI',sans-serif; display:flex; align-items:center; justify-content:center; padding:1rem; }
    .card-share { background:white; border-radius:16px; max-width:460px; width:100%;
                  box-shadow:0 8px 40px rgba(0,0,0,.4); overflow:hidden; }
    .card-share .head { background:linear-gradient(135deg,#263238,#37474f); color:white; padding:1.25rem 1.5rem; }
    .card-share .body { padding:1.5rem; }
    .secret { background:#f8f9fa; border:1px solid #e5e7eb; border-radius:10px; padding:.75rem 1rem;
              font-family:monospace; word-break:break-all; white-space:pre-wrap; position:relative; }
    .field-label { font-size:.72rem; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.04em; }
  </style>
</head>
<body>
  <div class="card-share">
    <div class="head">
      <div class="fw-bold"><i class="bi bi-safe2-fill me-2"></i>Cofre TI</div>
      <div class="small" style="opacity:.8">Conteúdo compartilhado com segurança</div>
    </div>
    <div class="body">
      <?php if ($erro): ?>
        <div class="text-center py-3">
          <i class="bi bi-shield-exclamation text-danger" style="font-size:3rem"></i>
          <h6 class="fw-bold mt-3"><?= e($erro) ?></h6>
          <p class="text-muted small mb-0">Solicite um novo link a quem compartilhou.</p>
        </div>
      <?php else: ?>
        <div class="field-label">Título</div>
        <div class="fw-bold mb-3"><?= e($item['titulo']) ?></div>

        <?php if ($item['usuario']): ?>
          <div class="field-label">Usuário / Login</div>
          <div class="mb-3"><?= e($item['usuario']) ?></div>
        <?php endif; ?>

        <?php if ($item['url']): ?>
          <div class="field-label">URL / Servidor</div>
          <div class="mb-3"><?= e($item['url']) ?></div>
        <?php endif; ?>

        <?php if ($item['conteudo'] !== ''): ?>
          <div class="field-label">Conteúdo</div>
          <div class="secret mb-2" id="secret"><?= e($item['conteudo']) ?></div>
          <button class="btn btn-sm btn-dark w-100" onclick="copiar()">
            <i class="bi bi-clipboard me-1"></i>Copiar conteúdo
          </button>
        <?php endif; ?>

        <div class="alert alert-warning small mt-3 mb-0">
          <i class="bi bi-exclamation-triangle me-1"></i>
          <?php if ($item['restantes'] > 0): ?>
            Este link ainda pode ser aberto <strong><?= (int)$item['restantes'] ?></strong> vez(es).
          <?php else: ?>
            Esta foi a <strong>última visualização</strong> permitida deste link.
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
  function copiar() {
    navigator.clipboard.writeText(document.getElementById('secret').textContent).then(() => {
      const b = document.querySelector('button');
      b.innerHTML = '<i class="bi bi-clipboard-check me-1"></i>Copiado!';
      setTimeout(() => b.innerHTML = '<i class="bi bi-clipboard me-1"></i>Copiar conteúdo', 2000);
    });
  }
  </script>
</body>
</html>
