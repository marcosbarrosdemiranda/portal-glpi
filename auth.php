<?php
/**
 * Autenticação centralizada — valida credenciais contra a API do GLPI
 */
session_start();

require_once __DIR__ . '/agenda/config.php';

function glpi_login(string $usuario, string $senha): array {
    $auth = base64_encode($usuario . ':' . $senha);
    $ch   = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $auth,
            'App-Token: ' . GLPI_APP_TOKEN,
        ],
    ]);
    $res  = json_decode(curl_exec($ch), true);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && !empty($res['session_token'])) {
        $token_temp = $res['session_token'];
        $h_temp = ['Session-Token: '.$token_temp, 'App-Token: '.GLPI_APP_TOKEN];

        // Busca perfis do usuário
        $ch2 = curl_init(GLPI_URL . '/apirest.php/getMyProfiles');
        curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h_temp]);
        $perfis_res = json_decode(curl_exec($ch2), true);
        curl_close($ch2);

        // Detecta se é Self-Service
        $perfil = 'tecnico'; // padrão
        $perfis_lista = $perfis_res['myprofiles'] ?? [];
        foreach ($perfis_lista as $p) {
            $nome_perfil = strtolower($p['name'] ?? '');
            if (str_contains($nome_perfil, 'self') || str_contains($nome_perfil, 'service')) {
                $perfil = 'self-service';
                break;
            }
        }

        // Encerra sessão do usuário
        $ch_kill = curl_init(GLPI_URL . '/apirest.php/killSession');
        curl_setopt_array($ch_kill, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Session-Token: '.$res['session_token'], 'App-Token: '.GLPI_APP_TOKEN],
        ]);
        curl_exec($ch_kill);
        curl_close($ch_kill);

        // Abre sessão ADMIN para buscar dados do usuário (self-service não tem permissão)
        $auth_admin = base64_encode(GLPI_USER . ':' . GLPI_PASS);
        $ch_admin = curl_init(GLPI_URL . '/apirest.php/initSession');
        curl_setopt_array($ch_admin, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Basic '.$auth_admin, 'App-Token: '.GLPI_APP_TOKEN],
        ]);
        $r_admin = json_decode(curl_exec($ch_admin), true);
        curl_close($ch_admin);
        $token_admin = $r_admin['session_token'] ?? '';

        $nome    = $usuario;
        $user_id = null;

        if ($token_admin) {
            $h_admin = ['Session-Token: '.$token_admin, 'App-Token: '.GLPI_APP_TOKEN];

            // Busca o usuário pelo nome usando sessão admin
            $ch3 = curl_init(GLPI_URL . '/apirest.php/User?searchText[name]=' . urlencode($usuario) . '&range=0-1');
            curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h_admin]);
            $usuarios = json_decode(curl_exec($ch3), true);
            curl_close($ch3);

            if (is_array($usuarios) && !empty($usuarios[0]) && isset($usuarios[0]['id'])) {
                $u       = $usuarios[0];
                $user_id = (int)$u['id'];
                $nome    = trim(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? '')) ?: $usuario;
            }

            // Encerra sessão admin
            $ch_kill2 = curl_init(GLPI_URL . '/apirest.php/killSession');
            curl_setopt_array($ch_kill2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h_admin]);
            curl_exec($ch_kill2);
            curl_close($ch_kill2);
        }

        return ['ok' => true, 'nome' => $nome, 'login' => $usuario, 'user_id' => $user_id, 'perfil' => $perfil];
    }

    return ['ok' => false, 'msg' => 'Usuário ou senha inválidos.'];
}

// Processa POST de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $senha   = trim($_POST['senha']   ?? '');
    $erro    = '';

    if ($usuario && $senha) {
        $result = glpi_login($usuario, $senha);
        if ($result['ok']) {
            $_SESSION['usuario']      = $result['login'];
            $_SESSION['nome']         = $result['nome'];
            $_SESSION['user_id']      = $result['user_id'];
            $_SESSION['perfil']       = $result['perfil'];
            $_SESSION['autenticado']  = true;
            header('Location: dashboard.php');
            exit;
        } else {
            $erro = $result['msg'];
        }
    } else {
        $erro = 'Preencha usuário e senha.';
    }
}

// Verifica se já está logado
if (!empty($_SESSION['autenticado'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Central TI — Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary: #1a237e; --accent: #1a73e8; }

    body {
      min-height: 100vh;
      background: linear-gradient(135deg, #1a237e 0%, #1565c0 50%, #0d47a1 100%);
      display: flex; align-items: center; justify-content: center;
      font-family: 'Segoe UI', sans-serif;
    }

    .login-card {
      background: white;
      border-radius: 20px;
      box-shadow: 0 24px 60px rgba(0,0,0,.35);
      width: 100%;
      max-width: 420px;
      overflow: hidden;
    }

    .login-header {
      background: linear-gradient(135deg, var(--primary), var(--accent));
      padding: 2.5rem 2rem 2rem;
      text-align: center;
      color: white;
    }

    .login-header .logo-icon {
      width: 72px; height: 72px;
      background: rgba(255,255,255,.15);
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 1rem;
      border: 3px solid rgba(255,255,255,.3);
    }

    .login-header h1 { font-size: 1.5rem; font-weight: 700; margin: 0; }
    .login-header p  { opacity: .8; font-size: .9rem; margin-top: .3rem; }

    .login-body { padding: 2rem; }

    .form-floating label { color: #888; }
    .form-control:focus  { border-color: var(--accent); box-shadow: 0 0 0 .2rem rgba(26,115,232,.2); }

    .btn-login {
      background: linear-gradient(135deg, var(--primary), var(--accent));
      border: none; color: white; width: 100%;
      padding: .8rem; border-radius: 10px;
      font-size: 1rem; font-weight: 600;
      transition: opacity .2s;
    }
    .btn-login:hover { opacity: .9; color: white; }
    .btn-login:disabled { opacity: .7; }

    .alert-erro {
      background: #fdecea; border: 1px solid #f5c6cb;
      border-radius: 10px; color: #721c24;
      padding: .75rem 1rem; font-size: .9rem;
      display: flex; align-items: center; gap: .5rem;
    }

    .input-icon { position: relative; }
    .input-icon .bi {
      position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
      color: #aaa; font-size: 1.1rem; z-index: 5;
    }
    .input-icon input { padding-left: 2.5rem !important; }

    .toggle-senha {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      cursor: pointer; color: #aaa; font-size: 1.1rem; z-index: 5;
    }

    .login-footer {
      text-align: center; padding: 0 2rem 1.5rem;
      color: #aaa; font-size: .78rem;
    }
  </style>
</head>
<body>

<div class="login-card">

  <!-- Header -->
  <div class="login-header">
    <div class="logo-icon">
      <i class="bi bi-headset" style="font-size:2rem;"></i>
    </div>
    <h1>Central de TI</h1>
    <p>Faça login com suas credenciais GLPI</p>
  </div>

  <!-- Body -->
  <div class="login-body">

    <?php if (!empty($erro)): ?>
    <div class="alert-erro mb-3">
      <i class="bi bi-exclamation-circle-fill"></i>
      <?= htmlspecialchars($erro) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" id="formLogin">
      <div class="mb-3">
        <label class="form-label fw-semibold text-secondary small">Usuário</label>
        <div class="input-icon">
          <i class="bi bi-person-fill"></i>
          <input type="text" name="usuario" class="form-control form-control-lg"
                 placeholder="Seu usuário GLPI"
                 value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>"
                 autocomplete="username" required/>
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label fw-semibold text-secondary small">Senha</label>
        <div class="input-icon" style="position:relative">
          <i class="bi bi-lock-fill"></i>
          <input type="password" name="senha" id="inputSenha" class="form-control form-control-lg"
                 placeholder="Sua senha" autocomplete="current-password" required/>
          <i class="bi bi-eye toggle-senha" id="toggleSenha" onclick="toggleVer()"></i>
        </div>
      </div>

      <button type="submit" class="btn-login" id="btnLogin">
        <i class="bi bi-box-arrow-in-right me-2"></i>Entrar
      </button>
    </form>
  </div>

  <div class="login-footer">
    <i class="bi bi-shield-lock me-1"></i>Autenticado via API GLPI
  </div>
</div>

<script>
function toggleVer() {
  const input = document.getElementById('inputSenha');
  const icon  = document.getElementById('toggleSenha');
  input.type  = input.type === 'password' ? 'text' : 'password';
  icon.className = 'bi toggle-senha ' + (input.type === 'password' ? 'bi-eye' : 'bi-eye-slash');
}

document.getElementById('formLogin').addEventListener('submit', function() {
  const btn = document.getElementById('btnLogin');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verificando...';
});
</script>
</body>
</html>
