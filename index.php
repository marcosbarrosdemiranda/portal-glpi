<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Central de Chamados</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root {
      --primary: #1a73e8;
      --primary-dark: #1558b0;
      --bg: #f0f4f9;
    }

    body {
      background: var(--bg);
      font-family: 'Segoe UI', sans-serif;
      min-height: 100vh;
    }

    .navbar-brand img {
      height: 36px;
    }

    .hero {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
      color: white;
      padding: 3rem 1rem 6rem;
      text-align: center;
    }

    .hero h1 { font-size: 2rem; font-weight: 700; }
    .hero p  { opacity: .85; }

    .card-form {
      margin-top: -3rem;
      border: none;
      border-radius: 16px;
      box-shadow: 0 8px 32px rgba(0,0,0,.12);
    }

    .step-badge {
      width: 28px; height: 28px;
      background: var(--primary);
      color: white;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: .75rem;
      font-weight: 700;
      margin-right: .5rem;
    }

    .tipo-btn {
      cursor: pointer;
      border: 2px solid #dee2e6;
      border-radius: 12px;
      padding: 1rem;
      text-align: center;
      transition: all .2s;
    }
    .tipo-btn:hover, .tipo-btn.active {
      border-color: var(--primary);
      background: #e8f0fe;
    }
    .tipo-btn i { font-size: 2rem; color: var(--primary); }

    .urgencia-badge {
      cursor: pointer;
      border-radius: 20px;
      padding: .35rem 1rem;
      border: 2px solid #dee2e6;
      font-size: .85rem;
      transition: all .2s;
      display: inline-block;
    }
    .urgencia-badge:hover { border-color: var(--primary); }
    .urgencia-badge.active-1 { background:#d4edda; border-color:#28a745; color:#155724; }
    .urgencia-badge.active-2 { background:#fff3cd; border-color:#ffc107; color:#856404; }
    .urgencia-badge.active-3 { background:#f8d7da; border-color:#dc3545; color:#721c24; }

    .btn-submit {
      background: var(--primary);
      border: none;
      border-radius: 10px;
      padding: .75rem 2rem;
      font-size: 1rem;
      font-weight: 600;
      color: white;
      transition: background .2s;
    }
    .btn-submit:hover { background: var(--primary-dark); color: white; }
    .btn-submit:disabled { opacity: .6; }

    #toast-container {
      position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999;
    }

    .char-count { font-size: .78rem; color: #888; }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark" style="background:var(--primary-dark);">
  <div class="container">
    <span class="navbar-brand fw-bold"><i class="bi bi-headset me-2"></i>Central de Chamados</span>
    <a href="#" class="text-white text-decoration-none small"><i class="bi bi-clock-history me-1"></i>Meus chamados</a>
  </div>
</nav>

<!-- Hero -->
<div class="hero">
  <h1><i class="bi bi-ticket-perforated me-2"></i>Abrir Chamado</h1>
  <p>Preencha o formulário e nossa equipe de TI entrará em contato em breve.</p>
</div>

<!-- Formulário -->
<div class="container pb-5" style="max-width:720px;">
  <div class="card card-form p-4 p-md-5">

    <form id="formChamado" novalidate>

      <!-- Seção 1: Identificação -->
      <h6 class="mb-3"><span class="step-badge">1</span>Seus dados</h6>
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <label class="form-label fw-semibold">Nome completo <span class="text-danger">*</span></label>
          <input type="text" name="nome" class="form-control" placeholder="João Silva" required/>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">E-mail <span class="text-danger">*</span></label>
          <input type="email" name="email" class="form-control" placeholder="joao@empresa.com" required/>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Setor / Departamento</label>
          <select name="setor" class="form-select">
            <option value="">Selecione...</option>
            <option>Administrativo</option>
            <option>Comercial</option>
            <option>Financeiro</option>
            <option>RH</option>
            <option>TI</option>
            <option>Operações</option>
            <option>Diretoria</option>
          </select>
        </div>
      </div>

      <hr/>

      <!-- Seção 2: Tipo -->
      <h6 class="mb-3"><span class="step-badge">2</span>Tipo de solicitação</h6>
      <div class="row g-3 mb-4">
        <div class="col-6">
          <div class="tipo-btn active" data-tipo="incident" onclick="selectTipo(this)">
            <i class="bi bi-exclamation-triangle-fill d-block mb-1"></i>
            <strong>Incidente</strong>
            <div class="text-muted small mt-1">Algo parou de funcionar</div>
          </div>
        </div>
        <div class="col-6">
          <div class="tipo-btn" data-tipo="request" onclick="selectTipo(this)">
            <i class="bi bi-box-arrow-in-down d-block mb-1"></i>
            <strong>Requisição</strong>
            <div class="text-muted small mt-1">Preciso de algo novo</div>
          </div>
        </div>
      </div>
      <input type="hidden" name="tipo" id="tipo" value="incident"/>

      <hr/>

      <!-- Seção 3: Urgência -->
      <h6 class="mb-3"><span class="step-badge">3</span>Urgência</h6>
      <div class="d-flex gap-2 flex-wrap mb-4">
        <span class="urgencia-badge" data-urg="1" onclick="selectUrgencia(this)">
          <i class="bi bi-circle-fill text-success me-1"></i>Baixa
        </span>
        <span class="urgencia-badge active-2" data-urg="2" onclick="selectUrgencia(this)">
          <i class="bi bi-circle-fill text-warning me-1"></i>Média
        </span>
        <span class="urgencia-badge" data-urg="3" onclick="selectUrgencia(this)">
          <i class="bi bi-circle-fill text-danger me-1"></i>Alta
        </span>
      </div>
      <input type="hidden" name="urgencia" id="urgencia" value="2"/>

      <hr/>

      <!-- Seção 4: Detalhes -->
      <h6 class="mb-3"><span class="step-badge">4</span>Detalhes do chamado</h6>
      <div class="mb-3">
        <label class="form-label fw-semibold">Título / Assunto <span class="text-danger">*</span></label>
        <input type="text" name="titulo" class="form-control" placeholder="Resumo do problema" required maxlength="100"/>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold">Descrição detalhada <span class="text-danger">*</span></label>
        <textarea name="descricao" id="descricao" class="form-control" rows="5"
          placeholder="Descreva o problema com o máximo de detalhes possível: quando começou, o que estava fazendo, mensagens de erro..."
          required maxlength="2000" oninput="updateChar()"></textarea>
        <div class="char-count text-end mt-1"><span id="charCount">0</span>/2000 caracteres</div>
      </div>

      <div class="d-grid">
        <button type="submit" class="btn btn-submit" id="btnEnviar">
          <i class="bi bi-send-fill me-2"></i>Enviar Chamado
        </button>
      </div>

    </form>
  </div>

  <p class="text-center text-muted small mt-3">
    <i class="bi bi-shield-lock me-1"></i>Seus dados são usados apenas para atendimento interno.
  </p>
</div>

<!-- Toast -->
<div id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  function selectTipo(el) {
    document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('tipo').value = el.dataset.tipo;
  }

  function selectUrgencia(el) {
    document.querySelectorAll('.urgencia-badge').forEach(b => {
      b.classList.remove('active-1','active-2','active-3');
    });
    el.classList.add('active-' + el.dataset.urg);
    document.getElementById('urgencia').value = el.dataset.urg;
  }

  function updateChar() {
    document.getElementById('charCount').textContent =
      document.getElementById('descricao').value.length;
  }

  function toast(msg, type='success') {
    const id = 'toast-' + Date.now();
    const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill';
    const bg   = type === 'success' ? 'bg-success' : 'bg-danger';
    document.getElementById('toast-container').insertAdjacentHTML('beforeend', `
      <div id="${id}" class="toast align-items-center text-white ${bg} border-0 show mb-2" role="alert">
        <div class="d-flex">
          <div class="toast-body"><i class="bi ${icon} me-2"></i>${msg}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
      </div>`);
    setTimeout(() => document.getElementById(id)?.remove(), 5000);
  }

  document.getElementById('formChamado').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (!this.checkValidity()) { this.classList.add('was-validated'); return; }

    const btn = document.getElementById('btnEnviar');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Enviando...';

    try {
      const res = await fetch('api.php', {
        method: 'POST',
        body: new FormData(this),
      });
      const data = await res.json();

      if (data.success) {
        toast(`Chamado #${data.ticket_id} aberto com sucesso! Nossa equipe entrará em contato.`);
        this.reset();
        this.classList.remove('was-validated');
        document.getElementById('charCount').textContent = '0';
        // Redefine seleções visuais
        selectTipo(document.querySelector('[data-tipo="incident"]'));
        selectUrgencia(document.querySelector('[data-urg="2"]'));
      } else {
        toast(data.error || 'Erro ao enviar. Tente novamente.', 'danger');
      }
    } catch {
      toast('Erro de conexão. Verifique sua rede.', 'danger');
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-send-fill me-2"></i>Enviar Chamado';
    }
  });
</script>
</body>
</html>
