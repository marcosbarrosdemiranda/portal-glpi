<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (!in_array($_SESSION['perfil'] ?? '', ['admin','super-admin','tecnico'])) { echo "Sem permissão"; exit; }
require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirmar'] ?? '') === 'SIM') {
    // Pega todos os guac_id antes de limpar
    $st = $pdo->query("SELECT guac_id FROM portal_rdp_maquinas WHERE guac_id IS NOT NULL AND guac_id > 0");
    $guac_ids = $st->fetchAll(PDO::FETCH_COLUMN);

    // Deleta do banco
    $pdo->exec("DELETE FROM portal_rdp_maquinas");

    // Tenta deletar do Guacamole
    $guac_removidos = 0;
    $guac_erros = 0;
    if (!empty($guac_ids)) {
        $guac_url = rtrim(GUACAMOLE_URL, '/');
        $ch = curl_init($guac_url . '/api/tokens');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['username' => GUACAMOLE_USER, 'password' => GUACAMOLE_PASS]),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http === 200 && $resp) {
            $auth = json_decode($resp, true);
            $token = $auth['authToken'] ?? '';
            $ds = $auth['dataSource'] ?? 'mysql';
            if ($token) {
                foreach ($guac_ids as $gid) {
                    $ch2 = curl_init("{$guac_url}/api/session/data/{$ds}/connections/" . (int)$gid);
                    curl_setopt_array($ch2, [
                        CURLOPT_CUSTOMREQUEST => 'DELETE',
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                        CURLOPT_HTTPHEADER => ["Guacamole-Token: {$token}"],
                    ]);
                    curl_exec($ch2);
                    $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                    curl_close($ch2);
                    if ($code >= 200 && $code < 300) {
                        $guac_removidos++;
                    } else {
                        $guac_erros++;
                    }
                }
            }
        }
    }

    $msg = "✅ Todas as máquinas foram excluídas do banco!";
    if ($guac_removidos > 0) $msg .= " Guacamole: {$guac_removidos} removidas.";
    if ($guac_erros > 0) $msg .= " {$guac_erros} falhas (já podem ter sido removidas).";
    echo "<p style='color:green;font-size:1.2rem'>{$msg}</p>";
    echo "<p><a href='rdp_central.php'>← Voltar para Central RDP</a></p>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><title>Limpar Central RDP</title>
<style>
body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f1f5f9; }
.card { background: #fff; padding: 2rem; border-radius: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; max-width: 480px; }
h1 { color: #dc2626; }
p { color: #475569; line-height: 1.5; }
input[type=text] { font-size: 1.2rem; padding: 0.5rem 1rem; text-align: center; border: 2px solid #e2e8f0; border-radius: 0.5rem; margin: 1rem 0; }
.btn { display: inline-block; padding: 0.75rem 2rem; border-radius: 0.5rem; text-decoration: none; font-weight: 600; margin: 0.5rem; }
.btn-danger { background: #dc2626; color: #fff; border: none; cursor: pointer; }
.btn-danger:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-secondary { background: #e2e8f0; color: #475569; }
</style>
</head>
<body>
<div class="card">
  <h1>🗑️ Limpar Central RDP</h1>
  <p>Isso vai <strong>excluir TODAS as máquinas</strong> cadastradas na Central RDP<br>
  e <strong>remover as conexões do Guacamole</strong>.<br>
  Depois você cadastra só as que realmente precisa.</p>
  <form method="post">
    <p>Digite <strong>SIM</strong> para confirmar:</p>
    <input type="text" name="confirmar" id="confirmar" placeholder="SIM" autocomplete="off" oninput="document.getElementById('btn').disabled = (this.value !== 'SIM')">
    <br>
    <button type="submit" id="btn" class="btn btn-danger" disabled>🗑️ Excluir tudo</button>
    <br>
    <a href="rdp_central.php" class="btn btn-secondary">Cancelar</a>
  </form>
</div>
</body>
</html>
