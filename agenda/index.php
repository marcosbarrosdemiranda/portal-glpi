<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../auth.php'); exit; }
$nome_usuario  = $_SESSION['nome']    ?? $_SESSION['usuario'] ?? 'Atendente';
$user_id_sessao = (int)($_SESSION['user_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Agenda TI</title>
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <!-- FullCalendar -->
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet"/>
  <style>
    :root {
      --primary: #1a73e8;
      --sidebar-w: 320px;
      --navbar-h: 56px;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', sans-serif; background: #f1f3f4; overflow: hidden; }

    /* ── Navbar ── */
    .navbar {
      height: var(--navbar-h);
      background: #1a237e;
      color: white;
      display: flex;
      align-items: center;
      padding: 0 1rem;
      gap: 1rem;
      position: fixed; top: 0; left: 0; right: 0; z-index: 100;
      box-shadow: 0 2px 8px rgba(0,0,0,.3);
    }
    .navbar .brand { font-weight: 700; font-size: 1.1rem; display: flex; align-items: center; gap: .5rem; }
    .navbar .spacer { flex: 1; }
    .nav-btn {
      background: rgba(255,255,255,.15);
      border: none; color: white;
      border-radius: 8px; padding: .35rem .75rem;
      cursor: pointer; font-size: .85rem;
      transition: background .2s;
    }
    .nav-btn:hover { background: rgba(255,255,255,.25); }
    .nav-btn.active { background: var(--primary); }

    /* Botões de visão com setas embutidas */
    .nav-views { display:flex; gap:4px; }
    .nav-view-btn {
      display: flex; align-items: center; gap: 4px;
      background: rgba(255,255,255,.15); color: white;
      border-radius: 8px; padding: .35rem .6rem;
      cursor: pointer; font-size: .85rem;
      transition: background .2s; user-select: none;
    }
    .nav-view-btn:hover { background: rgba(255,255,255,.25); }
    .nav-view-btn.active { background: var(--primary); }
    .nav-arrows { display: none; align-items: center; gap: 1px; margin-left: 4px; }
    .nav-view-btn.active .nav-arrows { display: flex; }
    .nav-arrows button {
      background: rgba(255,255,255,.2); border: none; color: white;
      border-radius: 4px; width: 22px; height: 22px;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; font-size: .75rem; padding: 0;
      transition: background .15s;
    }
    .nav-arrows button:hover { background: rgba(255,255,255,.35); }

    /* Filtro atendente */
    .atendente-filtro {
      display: flex; align-items: center; color: white;
      background: rgba(255,255,255,.12);
      border-radius: 8px; padding: .3rem .75rem; gap: .3rem;
    }
    .atendente-filtro select {
      background: transparent; border: none; color: white;
      font-size: .85rem; cursor: pointer; outline: none;
      max-width: 180px;
    }
    .atendente-filtro select option { background: #1a237e; color: white; }

    /* Filtro tipo */
    .tipo-filtro {
      display: flex; align-items: center; color: white;
      background: rgba(255,255,255,.12);
      border-radius: 8px; padding: .3rem .75rem; gap: .3rem;
    }
    .tipo-filtro select {
      background: transparent; border: none; color: white;
      font-size: .85rem; cursor: pointer; outline: none;
      max-width: 160px;
    }
    .tipo-filtro select option { background: #1a237e; color: white; }

    /* Menu ⋮ nos eventos */
    .ev-menu-btn {
      position: absolute; top: 1px; right: 1px; z-index: 10;
      background: rgba(0,0,0,.15); border: none; color: rgba(255,255,255,.85);
      font-size: .8rem; line-height: 1; padding: 1px 4px; border-radius: 3px;
      cursor: pointer; display: none;
    }
    .fc-event:hover .ev-menu-btn { display: block; }
    .ev-menu-btn:hover { background: rgba(0,0,0,.35); color: #fff; }
    .ev-dropdown {
      display: none; position: absolute; top: 18px; right: 0; z-index: 5002;
      background: #fff; border: 1px solid #ddd; border-radius: 8px;
      box-shadow: 0 4px 16px rgba(0,0,0,.18); min-width: 160px;
      font-size: .8rem; overflow: hidden;
    }
    .ev-dropdown.show { display: block; }
    .ev-dropdown .ev-dropdown-header {
      padding: .4rem .7rem; font-weight: 700; font-size: .72rem;
      color: #555; text-transform: uppercase; letter-spacing: .5px;
      background: #f5f5f5; border-bottom: 1px solid #e0e0e0;
    }
    .ev-dropdown .ev-dropdown-item {
      display: block; width: 100%; text-align: left;
      padding: .45rem .7rem; border: none; background: none;
      cursor: pointer; font-size: .8rem; color: #333;
    }
    .ev-dropdown .ev-dropdown-item:hover { background: #f0f4ff; color: #1a73e8; }
    .ev-dropdown .ev-dropdown-item:disabled { opacity: .4; cursor: not-allowed; }
    .ev-dropdown .ev-dropdown-divider { height: 1px; background: #e0e0e0; margin: 2px 0; }
    .ev-dropdown .ev-submenu-wrap { position: relative; }
    .ev-dropdown .ev-submenu-wrap .ev-submenu {
      display: none; position: absolute; left: 100%; top: 0;
      background: #fff; border: 1px solid #ddd; border-radius: 8px;
      box-shadow: 0 4px 16px rgba(0,0,0,.18); min-width: 170px;
    }
    .ev-dropdown .ev-submenu-wrap:hover .ev-submenu { display: block; }
    .ev-dropdown .ev-submenu-item {
      display: block; width: 100%; text-align: left;
      padding: .45rem .7rem; border: none; background: none;
      cursor: pointer; font-size: .8rem; color: #333; white-space: nowrap;
    }
    .ev-dropdown .ev-submenu-item:hover { background: #f0f4ff; color: #1a73e8; }
    .ev-dropdown .ev-submenu-item:disabled { opacity: .4; cursor: not-allowed; }

    /* ── Layout principal ── */
    .layout {
      display: flex;
      height: calc(100vh - var(--navbar-h));
      margin-top: var(--navbar-h);
    }

    /* ── Sidebar ── */
    .sidebar {
      width: var(--sidebar-w);
      background: white;
      border-right: 1px solid #e0e0e0;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .sidebar-header {
      padding: .75rem 1rem;
      border-bottom: 1px solid #e0e0e0;
      background: #f8f9fa;
    }
    .sidebar-header h6 { margin: 0; font-weight: 700; color: #333; }

    /* Filtros */
    .filtros { padding: .5rem 1rem; border-bottom: 1px solid #eee; }
    .filtros select, .filtros input {
      font-size: .8rem; padding: .25rem .5rem;
      border: 1px solid #ddd; border-radius: 6px;
      width: 100%; margin-bottom: .35rem;
    }

    /* Lista de chamados */
    .ticket-list { flex: 1; overflow-y: auto; padding: .5rem; }
    .ticket-card {
      background: #fff;
      border: 1px solid #e0e0e0;
      border-left: 4px solid #ccc;
      border-radius: 8px;
      padding: .6rem .75rem;
      margin-bottom: .4rem;
      cursor: grab;
      transition: box-shadow .2s, transform .15s;
      user-select: none;
      font-size: .82rem;
    }
    .ticket-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.12); transform: translateY(-1px); }
    .ticket-card.dragging { opacity: .6; cursor: grabbing; }
    .ticket-card.em-andamento { background:#f0f4ff; border-left-color:#1a73e8; }

    /* Preview popup do chamado no sidebar */
    .ticket-preview-overlay {
      display:none; position:fixed; inset:0; z-index:5000;
      background:rgba(0,0,0,.2);
    }
    .ticket-preview-overlay.show { display:block; }
    .ticket-preview {
      display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
      width:440px; max-width:92vw; max-height:80vh; overflow-y:auto;
      background:#fff; border-radius:14px; box-shadow:0 12px 50px rgba(0,0,0,.3);
      padding:1.5rem; z-index:5001;
    }
    .ticket-preview .tp-close {
      position:absolute; top:10px; right:14px; font-size:1.4rem;
      cursor:pointer; color:#999; line-height:1; border:none; background:none;
    }
    .ticket-preview .tp-close:hover { color:#333; }
    .ticket-preview .tp-id { font-size:.8rem; color:#1a73e8; font-weight:700; margin-bottom:.2rem; }
    .ticket-preview .tp-titulo { font-weight:700; font-size:1rem; color:#111; margin-bottom:.6rem; padding-right:1.5rem; }
    .ticket-preview .tp-descricao {
      background:#f8f9fa; border-radius:8px; padding:.7rem .9rem;
      font-size:.85rem; color:#333; line-height:1.5; white-space:pre-wrap;
      max-height:240px; overflow-y:auto; margin-bottom:.7rem;
    }
    .ticket-preview .tp-descricao:empty { display:none; }
    .ticket-preview .tp-meta { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:.4rem; }
    .ticket-preview .tp-meta .badge { font-size:.72rem; padding:.25rem .55rem; border-radius:6px; }
    .badge-andamento { background:#e8f0fe; color:#1558b0; border:1px solid #c5d5f0; border-radius:4px; padding:.1rem .45rem; font-size:.68rem; font-weight:600; }
    .followup-item { background:#f8f9fa; border:1px solid #e9ecef; border-radius:8px; padding:.6rem .85rem; font-size:.82rem; }
    .followup-item .fw-autor { font-weight:600; color:#1a237e; }
    .followup-item .fw-data  { color:#888; font-size:.75rem; margin-left:.4rem; }
    .followup-item .fw-texto { margin-top:.25rem; color:#333; white-space:pre-wrap; }
    /* Anexos no modal de detalhe */
    .anexo-grid { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.4rem; }
    .anexo-thumb { width:80px; height:60px; object-fit:cover; border-radius:6px;
                   border:1px solid #dee2e6; cursor:zoom-in; transition:opacity .15s; }
    .anexo-thumb:hover { opacity:.8; }
    .anexo-file  { display:flex; align-items:center; gap:.35rem; background:#f1f3f4;
                   border-radius:6px; padding:.3rem .6rem; font-size:.76rem; color:#374151;
                   text-decoration:none; }
    .anexo-file:hover { background:#e2e8f0; }
    /* Lightbox do modal de detalhe */
    #lbModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.85);
               z-index:9999; align-items:center; justify-content:center; cursor:zoom-out; }
    #lbModal.open { display:flex; }
    #lbModal img  { max-width:90vw; max-height:88vh; border-radius:8px; cursor:default;
                    box-shadow:0 8px 40px rgba(0,0,0,.6); }
    #lbModal .lbx { position:absolute; top:16px; right:20px; color:#fff;
                    font-size:2rem; cursor:pointer; line-height:1; }

    /* ── Layout inline: label + campo na mesma linha ── */
    #campos-evento .col-md-6,
    #campos-evento .col-inline {
      display: flex; align-items: center; gap: .5rem;
    }
    #campos-evento .col-md-6 > .form-label,
    #campos-evento .col-md-4 > .form-label,
    #campos-evento .col-inline > .form-label {
      min-width: 70px; width: 70px; text-align: left;
      margin-bottom: 0; font-size: .8rem; color: #555; flex-shrink: 0;
    }
    #campos-evento .col-md-6 > .form-select,
    #campos-evento .col-md-6 > .form-control,
    #campos-evento .col-inline > .form-control,
    #campos-evento .col-inline > .form-select { flex: 1; }
    /* Atendentes: label + chips na mesma linha */
    #campo-atendentes { display:flex !important; flex-direction:row !important;
                        align-items:center; flex-wrap:wrap; gap:.4rem; }
    #campo-atendentes > .form-label { margin-bottom:0 !important; white-space:nowrap; flex-shrink:0; }
    #ev-atendentes-multi { display:flex !important; flex-direction:row !important;
                           align-items:center; flex-wrap:wrap; gap:.3rem; flex:1; }
    .atendentes-multi-wrap { margin-bottom:0 !important; }
    /* Descrição, followups e concluído ficam em coluna */
    #campo-followups,
    .col-descricao,
    .col-concluido { display: block !important; }
    .col-descricao > .form-label,
    #campo-followups > .form-label { margin-bottom: .3rem; }
    .ticket-card .tc-id { font-size: .7rem; color: #888; }
    .ticket-card .tc-title { font-weight: 600; color: #222; margin: .15rem 0; line-height: 1.3; }
    .ticket-card .tc-tags { display: flex; gap: .3rem; flex-wrap: wrap; }
    .badge-urg { font-size: .68rem; border-radius: 10px; padding: .15rem .5rem; font-weight: 600; }
    .urg-1 { background:#e8f5e9; color:#2e7d32; }
    .urg-2 { background:#e3f2fd; color:#1565c0; }
    .urg-3 { background:#fff3e0; color:#e65100; }
    .urg-4 { background:#fce4ec; color:#c62828; }
    .urg-5 { background:#f3e5f5; color:#6a1b9a; }
    .badge-status { font-size: .68rem; border-radius: 10px; padding: .15rem .5rem; background:#f5f5f5; color:#555; }

    /* ── Calendário ── */
    .cal-wrap {
      flex: 1;
      padding: .75rem 1rem;
      overflow: auto;
      background: #f1f3f4;
    }
    #calendar {
      background: white;
      border-radius: 12px;
      padding: 1rem;
      box-shadow: 0 2px 12px rgba(0,0,0,.08);
      height: calc(100vh - var(--navbar-h) - 1.5rem);
    }
    .fc-toolbar-title { font-size: 1rem !important; font-weight: 700 !important; }
    .fc-button { font-size: .8rem !important; }
    .fc-event { border-radius: 6px !important; font-size: .78rem !important; cursor: pointer; }
    /* Modo leitura no modal */
    .modo-leitura .form-control,
    .modo-leitura .form-select,
    .modo-leitura textarea { background:#f8f9fa!important; pointer-events:none; border-color:#dee2e6!important; color:#444!important; }
    .modo-leitura .form-check-input { pointer-events:none; }
    /* Checkbox concluído sempre clicável mesmo em modo leitura */
    #ev-concluido, label[for="ev-concluido"] { pointer-events:all!important; }
    /* Botão Responder sempre clicável, independente do modo */
    #btnResponder { pointer-events:all!important; opacity:1!important; }

    /* Drop zone de arquivos */
    .drop-zone {
      border: 2px dashed #ccc; border-radius: 10px; padding: 1.2rem;
      text-align: center; cursor: pointer; transition: all .2s;
      background: #fafafa;
    }
    .drop-zone:hover, .drop-zone.dragover { border-color: #d93025; background: #fff5f5; }
    /* Miniatura de arquivo */
    .arquivo-chip {
      display: flex; align-items: center; gap: .4rem;
      background: #f1f3f4; border-radius: 20px;
      padding: .3rem .75rem; font-size: .8rem; max-width: 200px;
    }
    .arquivo-chip .rm { cursor:pointer; color:#999; }
    .arquivo-chip .rm:hover { color:#d93025; }
    /* Chip de imagem: preview maior */
    .arquivo-chip.chip-img {
      flex-direction: column; align-items: flex-start;
      border-radius: 10px; padding: .4rem; max-width: 120px;
      gap: .3rem; background: #e8eaed;
    }
    .arquivo-chip.chip-img .chip-thumb {
      width: 110px; height: 80px; object-fit: cover;
      border-radius: 6px; cursor: zoom-in; display: block;
      transition: opacity .15s;
    }
    .arquivo-chip.chip-img .chip-thumb:hover { opacity: .85; }
    .arquivo-chip.chip-img .chip-footer {
      display: flex; align-items: center; gap: .3rem;
      width: 100%; font-size: .72rem; color: #555;
    }
    /* Lightbox */
    #imgLightbox {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,.82); z-index: 9999;
      align-items: center; justify-content: center;
      cursor: zoom-out;
    }
    #imgLightbox.open { display: flex; }
    #imgLightbox img {
      max-width: 90vw; max-height: 88vh;
      border-radius: 8px; box-shadow: 0 8px 40px rgba(0,0,0,.6);
      cursor: default;
    }
    #imgLightbox .lb-close {
      position: absolute; top: 18px; right: 22px;
      color: #fff; font-size: 2rem; cursor: pointer; line-height: 1;
    }
    /* Evento multi-atendente: só borda tracejada, mantém cor original */
    .ev-multi { outline: 3px dashed #f9a825 !important; outline-offset: -2px; }
    .ev-multi .fc-event-main { opacity: .95; }
    /* Evento curto ≤ 20min: sem horário, título compacto em 1 linha */
    .ev-short { overflow: hidden; }
    .ev-short .ev-inner { flex-wrap: nowrap; padding: 0 3px; line-height: 1.2; }
    .ev-short .ev-time  { display: none !important; }
    .ev-short .ev-title { font-size: .7rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 120px; }
    .ev-short .ev-icon  { font-size: .75rem; }
    .ev-short .ev-group-icon { font-size: .65rem; padding: 0 2px; }
    .ev-group-icon  { font-size:.8rem; background:rgba(255,255,255,.25); border-radius:4px; padding:0 3px; flex-shrink:0; }
    .ev-aviso-icon  { font-size:.85rem; flex-shrink:0; opacity:.95; }
    /* Badge de concluído na beirada */
    .ev-check-badge { position:absolute; top:-6px; right:-6px; width:18px; height:18px; background:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 1px 4px rgba(0,0,0,.3); }
    .ev-check-badge i { font-size:.7rem; color:#1e8e3e; font-weight:900; }
    /* REMOVIDO: .fc-event { position: relative !important; }
       Essa regra sobrescrevia o position:absolute do FullCalendar nos eventos
       do timeGrid, fazendo o card colapsar para 25px (tamanho do conteúdo)
       em vez de preencher o harness corretamente (ex: 150px para 3h). */
    .fc-event { overflow: visible !important; }

    /* Multi-select de atendentes no modal */
    .atendentes-multi-wrap { display:flex; flex-wrap:wrap; gap:.3rem; margin-bottom:.1rem; }
    .atendente-multi-chip {
      display:inline-flex; align-items:center; gap:.3rem;
      padding:.18rem .55rem; border-radius:14px;
      border:1.5px solid #dee2e6; cursor:pointer;
      font-size:.76rem; transition:all .12s; user-select:none; white-space:nowrap;
    }
    .atendente-multi-chip:hover { border-color:#1a73e8; background:#f0f4ff; }
    .atendente-multi-chip.selected { border-color:#1a73e8; background:#e8f0fe; font-weight:700; }
    .atendente-multi-chip .dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
    .atendentes-multi-wrap.is-invalid { border:2px solid #dc3545 !important; border-radius:6px; padding:2px; }
    .form-control.is-invalid, .form-select.is-invalid { border-color:#dc3545 !important; box-shadow:0 0 0 .2rem rgba(220,53,69,.15) !important; }

    .atendente-check-item { display:flex; align-items:center; gap:.6rem; padding:.5rem .75rem; border-radius:8px; border:2px solid #e0e0e0; cursor:pointer; transition:all .15s; }
    .atendente-check-item:hover { border-color:#1a73e8; background:#f0f4ff; }
    .atendente-check-item.selecionado { border-color:#1a73e8; background:#e8f0fe; }
    .atendente-dot { width:12px; height:12px; border-radius:50%; flex-shrink:0; }
    .ev-inner  { display: flex; align-items: center; gap: 4px; padding: 1px 3px; overflow: hidden; white-space: nowrap; }
    .ev-icon   { font-size: .85rem; flex-shrink: 0; }
    .ev-time   { font-size: .72rem; opacity: .9; flex-shrink: 0; }
    .ev-title  { overflow: hidden; text-overflow: ellipsis; }

    /* ── Modal ── */
    .modal-header { border-bottom: 3px solid var(--primary); }
    .form-label { font-size: .85rem; font-weight: 600; }
    .form-control, .form-select { font-size: .85rem; }

    /* Loading */
    .loading-overlay {
      position: absolute; inset: 0;
      background: rgba(255,255,255,.7);
      display: flex; align-items: center; justify-content: center;
      z-index: 10; border-radius: 8px;
    }

    /* Badge prioridade */
    .pr-alta    { border-left-color: #dc3545 !important; }
    .pr-media   { border-left-color: #fd7e14 !important; }
    .pr-baixa   { border-left-color: #198754 !important; }
    .pr-critica { border-left-color: #6f42c1 !important; }

    .no-tickets { text-align:center; color:#aaa; padding: 2rem 1rem; font-size:.85rem; }

    /* Drag over calendar */
    .fc-highlight { background: rgba(26,115,232,.15) !important; }

    @media (max-width: 768px) {
      .sidebar { width: 100%; height: 260px; }
      .layout { flex-direction: column; }
    }
    /* ── Google Calendar modal ──────────────── */
    #google-info { margin-top: 12px; padding-top: 12px; border-top: 1px solid #e0e0e0; }
    .gcal-header { font-size: .8rem; color: #7b2d8e; font-weight: 700; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
    .gcal-field { font-size: .9rem; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
    .gcal-field a { color: #1a73e8; }
    .gcal-descricao { max-height: 300px; overflow-y: auto; white-space: pre-wrap; font-size: .85rem; line-height: 1.5; background: #f8f9fa; border-radius: 6px; padding: 10px 12px; margin-top: 4px; border: 1px solid #e9ecef; }
    .gcal-subtitle { font-size: .85rem; font-weight: 600; color: #495057; margin: 8px 0 4px; display: flex; align-items: center; gap: 5px; }
    .gcal-participante { display: flex; align-items: center; gap: 6px; padding: 2px 0; font-size: .85rem; }
    .gcal-participante .avatar { width: 24px; height: 24px; border-radius: 50%; background: #7b2d8e; color: #fff; display: flex; align-items: center; justify-content: center; font-size: .7rem; font-weight: 700; flex-shrink: 0; }
    .gcal-participante .email { color: #6c757d; font-size: .8rem; }

  </style>
</head>
<body>

<!-- ── Navbar ── -->
<div class="navbar">
  <div class="brand"><i class="bi bi-calendar3"></i> Agenda TI</div>
  <div class="spacer"></div>

  <!-- Filtro de atendente -->
  <div class="atendente-filtro">
    <i class="bi bi-person-fill me-1"></i>
    <select id="filtro-atendente" onchange="filtrarPorAtendente()">
      <option value="">👥 Todos os atendentes</option>
    </select>
  </div>

  <!-- Filtro por tipo -->
  <div class="tipo-filtro">
    <i class="bi bi-funnel me-1"></i>
    <select id="filtro-tipo" onchange="filtrarPorTipo()">
      <option value="">Todos os tipos</option>
      <optgroup label="🔷 Chamados">
        <option value="chamado_todos">🔷 Chamados (Todos)</option>
        <option value="chamado_concluido">✅ Chamados Concluídos</option>
        <option value="chamado_pendente">⏳ Chamados Pendentes</option>
      </optgroup>
      <option value="requisicao">📋 Requisição</option>
      <option value="reuniao">👥 Reunião</option>
      <option value="evento">📅 Evento</option>
    </select>
  </div>

  <!-- Botão Hoje separado -->
  <button class="nav-btn" onclick="calendar.today()" title="Voltar para hoje">
    <i class="bi bi-calendar-check me-1"></i>Hoje
  </button>

  <!-- Botões de visão com navegação embutida no ativo -->
  <div class="nav-views">
    <div class="nav-view-btn" id="vbtn-month" onclick="calView('dayGridMonth')">
      <i class="bi bi-calendar-month"></i> Mês
      <span class="nav-arrows" id="arrows-month">
        <button onclick="event.stopPropagation();calendar.prev()"><i class="bi bi-chevron-left"></i></button>
        <button onclick="event.stopPropagation();calendar.next()"><i class="bi bi-chevron-right"></i></button>
      </span>
    </div>
    <div class="nav-view-btn active" id="vbtn-week" onclick="calView('timeGridWeek')">
      <i class="bi bi-calendar-week"></i> Semana
      <span class="nav-arrows" id="arrows-week">
        <button onclick="event.stopPropagation();calendar.prev()"><i class="bi bi-chevron-left"></i></button>
        <button onclick="event.stopPropagation();calendar.next()"><i class="bi bi-chevron-right"></i></button>
      </span>
    </div>
    <div class="nav-view-btn" id="vbtn-day" onclick="calView('timeGridDay')">
      <i class="bi bi-calendar-day"></i> Dia
      <span class="nav-arrows" id="arrows-day">
        <button onclick="event.stopPropagation();calendar.prev()"><i class="bi bi-chevron-left"></i></button>
        <button onclick="event.stopPropagation();calendar.next()"><i class="bi bi-chevron-right"></i></button>
      </span>
    </div>
  </div>
  <button class="nav-btn ms-2" style="background:#1a73e8;" onclick="abrirModalEvento()">
    <i class="bi bi-plus-lg me-1"></i>Novo Evento
  </button>
  <!-- Menu hamburguer -->
  <div style="position:relative;margin-left:.25rem" id="menu-wrap">
    <button class="nav-btn" onclick="toggleMenu()" id="btn-menu" title="Mais opções">
      <i class="bi bi-three-dots-vertical"></i>
    </button>
    <div id="dropdown-menu" style="display:none;position:absolute;top:calc(100% + 6px);right:0;background:white;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.18);min-width:190px;z-index:999;overflow:hidden">
      <div onclick="syncRotinas(true);toggleMenu()" style="display:flex;align-items:center;gap:.6rem;padding:.65rem 1rem;cursor:pointer;font-size:.85rem;color:#222;transition:background .15s" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='white'">
        <i class="bi bi-arrow-repeat" style="color:#0f9d58;font-size:1rem"></i> Sync Rotinas
      </div>
      <div onclick="abrirConfigGcal();toggleMenu()" id="btn-gcal" style="display:flex;align-items:center;gap:.6rem;padding:.65rem 1rem;cursor:pointer;font-size:.85rem;color:#222;transition:background .15s;border-top:1px solid #f3f4f6" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='white'">
        <img src="https://www.google.com/favicon.ico" style="width:16px;height:16px"/> Google Calendar
      </div>
    </div>
  </div>
  <a href="../dashboard.php" class="nav-btn ms-1"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<!-- ── Layout ── -->
<div class="layout">

  <!-- Sidebar: chamados GLPI -->
  <div class="sidebar">
    <div class="sidebar-header">
      <div class="d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="bi bi-ticket-perforated me-2 text-primary"></i>Chamados GLPI</h6>
        <span id="badge-abertos" class="badge rounded-pill" style="background:#1a73e8;font-size:.75rem">
          <i class="bi bi-hourglass-split me-1"></i><span id="qtd-abertos">...</span> abertos
        </span>
      </div>
    </div>

    <!-- Filtros -->
    <div class="filtros">
      <input type="text" id="filtro-texto" placeholder="🔍 Buscar chamado..." oninput="filtrarTickets()"/>
      <select id="filtro-urgencia" onchange="filtrarTickets()">
        <option value="">Todas as urgências</option>
        <option value="muito baixa">Muito Baixa</option>
        <option value="baixa">Baixa</option>
        <option value="média">Média</option>
        <option value="alta">Alta</option>
        <option value="muito alta">Muito Alta</option>
      </select>
      <select id="filtro-status" onchange="filtrarTickets()">
        <option value="">Todos os status</option>
        <option value="Novo">Novo</option>
        <option value="Em atendimento">Em atendimento</option>
        <option value="Em espera">Em espera</option>
      </select>
      <label style="display:flex;align-items:center;gap:.35rem;font-size:.78rem;color:#555;cursor:pointer;margin-top:.35rem;padding:.2rem .5rem;background:#f0f4ff;border-radius:6px;border:1px solid #d0e0ff">
        <input type="checkbox" id="ordenar-abertura" onchange="filtrarTickets()" style="cursor:pointer;flex-shrink:0;width:14px;height:14px"/>
        <i class="bi bi-sort-down" style="color:#1a73e8;flex-shrink:0"></i>
        <span>Ordenar por abertura</span>
      </label>
    </div>

    <div class="ticket-list" id="ticketList">
      <div class="no-tickets"><div class="spinner-border spinner-border-sm text-primary mb-2"></div><br/>Carregando chamados...</div>
    </div>
  </div>

  <!-- Preview popup do chamado -->
  <div class="ticket-preview-overlay" id="tpOverlay" onclick="fecharPreview()"></div>
  <div class="ticket-preview" id="tpCard">
    <button class="tp-close" onclick="fecharPreview()">&times;</button>
    <div class="tp-id" id="tpId"></div>
    <div class="tp-titulo" id="tpTitulo"></div>
    <div class="tp-meta" id="tpMeta"></div>
    <div class="tp-descricao" id="tpDescricao">Sem descrição</div>
  </div>

  <!-- Calendário -->
  <div class="cal-wrap">
    <div id="calendar"></div>
  </div>
</div>

<!-- ── Modal: Criar/Editar Evento ── -->
<div class="modal fade" id="modalEvento" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitulo"><i class="bi bi-calendar-plus me-2"></i>Novo Evento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="ev-id"/>
        <input type="hidden" id="ev-ticket-id"/>
        <input type="hidden" id="ev-orig-start"/>

        <div class="row g-2" id="campos-evento">
          <div class="col-md-6">
            <label class="form-label">Tipo</label>
            <select class="form-select" id="ev-tipo" onchange="ajustarDuracaoPorTipo(); ajustarCamposPorTipo()">
              <option value="chamado" selected>🎫 Chamado GLPI</option>
              <option value="requisicao">📋 Requisição GLPI</option>
              <option value="reuniao">👥 Reunião</option>
              <option value="evento">📅 Evento</option>
            </select>
          </div>
          <!-- Banner modo leitura — mesma linha que Tipo -->
          <div class="col-md-6 d-flex align-items-center" id="banner-readonly" style="display:none!important">
            <div class="d-flex align-items-center justify-content-between w-100 px-3 py-2 rounded" style="background:#fff8e1;border:1px solid #ffc107;font-size:.82rem;">
              <span><i class="bi bi-lock-fill text-warning me-1"></i>Modo leitura</span>
              <button class="btn btn-sm btn-warning fw-bold py-0 px-2" onclick="habilitarEdicao()">
                <i class="bi bi-pencil-fill me-1"></i>Editar
              </button>
            </div>
          </div>
          <div class="col-12 col-inline">
            <label class="form-label">Título <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="ev-titulo" placeholder="Ex: Atendimento servidor"/>
          </div>
          <div class="col-md-4">
            <label class="form-label">Início <span class="text-danger">*</span></label>
            <input type="datetime-local" class="form-control" id="ev-start" oninput="aoMudarInicio()"/>
          </div>
          <div class="col-md-3">
            <label class="form-label">Duração</label>
            <select class="form-select" id="ev-duracao" onchange="aoMudarDuracao()">
              <option value="900000">15 min</option>
              <option value="1800000" selected>30 min</option>
              <option value="3600000">1 hora</option>
              <option value="5400000">1h 30min</option>
              <option value="7200000">2 horas</option>
              <option value="10800000">3 horas</option>
              <option value="14400000">4 horas</option>
              <option value="28800000">8 horas</option>
              <option value="0">Personalizado</option>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label" id="label-fim">Fim <span class="text-danger">*</span></label>
            <input type="datetime-local" class="form-control" id="ev-end" oninput="aoMudarFim()"/>
          </div>
          <div class="col-md-6" id="campo-entidade">
            <label class="form-label">Entidade <span class="text-danger">*</span></label>
            <select class="form-select" id="ev-entidade">
              <option value="">---</option>
            </select>
          </div>
          <div class="col-md-6" id="campo-atendentes">
            <label class="form-label mb-0" id="label-atendente">Atendentes <span class="text-danger">*</span></label>
            <select class="form-select" id="ev-atendente" style="display:none">
              <option value="">Selecione...</option>
            </select>
            <div id="ev-atendentes-multi">
              <div id="lista-atendentes-multi" class="atendentes-multi-wrap"></div>
            </div>
          </div>
          <div class="col-md-6" id="campo-requerente">
            <label class="form-label">Requerente <span class="text-danger">*</span></label>
            <select class="form-select" id="ev-requerente">
              <option value="">Selecione o requerente...</option>
            </select>
          </div>
          <div class="col-md-6" id="campo-categoria">
            <label class="form-label">Categoria</label>
            <select class="form-select" id="ev-categoria">
              <option value="">Carregando...</option>
            </select>
          </div>
          <div class="col-md-6" id="campo-origem">
            <label class="form-label">Origem</label>
            <select class="form-select" id="ev-origem">
              <option value="1">Helpdesk</option>
              <option value="2">E-mail</option>
              <option value="3">Telefone</option>
              <option value="4">Presencial</option>
            </select>
          </div>
          <div class="col-md-6" id="campo-prioridade">
            <label class="form-label">Prioridade</label>
            <select class="form-select" id="ev-prioridade">
              <option value="baixa">🟢 Baixa</option>
              <option value="media" selected>🟡 Média</option>
              <option value="alta">🔴 Alta</option>
              <option value="critica">🟣 Crítica</option>
            </select>
          </div>
          <input type="hidden" id="ev-setor"/>
          <div class="col-12 col-descricao">
            <label class="form-label" id="label-descricao">Descrição <span class="text-danger" id="star-descricao">*</span></label>
            <textarea class="form-control" id="ev-descricao" rows="3" placeholder="Detalhes do evento..."></textarea>
          </div>
          <div class="col-12" id="campo-followups" style="display:none">
            <label class="form-label d-flex align-items-center gap-2">
              <i class="bi bi-chat-left-text text-primary"></i> Acompanhamentos
            </label>
            <div id="ev-followups" style="max-height:220px;overflow-y:auto;display:flex;flex-direction:column;gap:.5rem;"></div>
          </div>
          <div class="col-12" id="campo-anexos" style="display:none">
            <label class="form-label d-flex align-items-center gap-2">
              <i class="bi bi-paperclip text-secondary"></i> Anexos
            </label>
            <div id="ev-anexos" class="anexo-grid"></div>
          </div>
          <!-- Anexos na criação/edição -->
          <div class="col-12" id="campo-anexos-criar">
            <label class="form-label fw-semibold">Anexar arquivos (imagens, docs, prints)</label>
            <div class="drop-zone" id="dropZoneCriar" onclick="document.getElementById('ev-arquivos').click()">
              <i class="bi bi-cloud-upload fs-3 text-muted"></i>
              <p class="mb-0 text-muted small">Clique ou arraste arquivos aqui</p>
              <p class="mb-0 text-muted" style="font-size:.75rem"><kbd>Ctrl+V</kbd> para colar imagem da área de transferência</p>
              <input type="file" id="ev-arquivos" multiple accept="image/*,.pdf,.doc,.docx,.txt,.zip,.xls,.xlsx" class="d-none" onchange="listarArquivosCriar()"/>
            </div>
            <div id="lista-arquivos-criar" class="mt-2 d-flex flex-wrap gap-2"></div>
          </div>
          <div class="col-12 col-concluido">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="ev-concluido" onchange="atualizarPreviewCor(); mostrarSalvarSeConcluido(); toggleFecharGlpi()"/>
              <label class="form-check-label fw-semibold" for="ev-concluido">
                ✅ Marcar como concluído (ficará verde na agenda)
              </label>
            </div>
            <div id="campo-fechar-glpi" style="display:none" class="mt-2 ms-4">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="ev-fechar-glpi"/>
                <label class="form-check-label text-danger fw-semibold" for="ev-fechar-glpi">
                  🔒 Fechar chamado no GLPI (período final)
                </label>
              </div>
            </div>
          </div>
        </div>

        <!-- Painel de informações do Google Calendar (eventos somente-leitura) -->
        <div id="google-info" class="row g-2" style="display:none">
          <div class="col-12">
            <div class="gcal-header">
              <img src="https://www.google.com/favicon.ico" style="width:14px;height:14px"/>
              <span>Google Calendar</span>
            </div>
          </div>
          <div class="col-12">
            <div id="gcal-local" class="gcal-field"><i class="bi bi-geo-alt"></i> <span id="gcal-local-text"></span></div>
          </div>
          <div class="col-12">
            <div id="gcal-meet" class="gcal-field" style="display:none">
              <i class="bi bi-camera-video"></i>
              <a id="gcal-meet-link" href="#" target="_blank" rel="noopener">Abrir reunião</a>
            </div>
          </div>
          <div class="col-12">
            <div id="gcal-participantes" style="display:none">
              <div class="gcal-subtitle"><i class="bi bi-people"></i> Participantes</div>
              <div id="gcal-participantes-lista"></div>
            </div>
          </div>
          <div class="col-12">
            <div class="gcal-subtitle"><i class="bi bi-card-text"></i> Descrição</div>
            <div id="gcal-descricao" class="gcal-descricao"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger me-auto" id="btnDeletar" onclick="deletarEvento()" style="display:none">
          <i class="bi bi-trash me-1"></i>Excluir
        </button>
        <button class="btn btn-outline-danger" id="btnResponder" onclick="abrirModalResposta()" style="display:none">
          <i class="bi bi-reply-fill me-1"></i>Responder Chamado
        </button>
        <button class="btn btn-outline-primary" id="btnNovoPeriodo" onclick="novoPeriodo()" style="display:none">
          <i class="bi bi-plus-circle me-1"></i>Novo período
        </button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="salvarEvento()">
          <i class="bi bi-check-lg me-1"></i>Salvar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Responder Chamado ── -->
<div class="modal fade" id="modalResposta" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="border-bottom:3px solid #d93025;">
        <h5 class="modal-title fw-bold" id="modalRespostaTitulo">
          <i class="bi bi-reply-fill me-2 text-danger"></i>Responder Chamado
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="resp-ticket-id"/>

        <!-- Info do chamado -->
        <div class="alert alert-light border d-flex align-items-center gap-2 py-2 mb-3" id="resp-info">
          <i class="bi bi-ticket-perforated text-danger"></i>
          <span id="resp-chamado-label" class="fw-semibold"></span>
        </div>

        <!-- Resposta -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Resposta / Acompanhamento <span class="text-danger">*</span></label>
          <textarea id="resp-texto" class="form-control" rows="6"
            placeholder="Descreva o que foi feito, orientações ao usuário, próximos passos..."></textarea>
        </div>

        <!-- Anexos -->
        <div class="mb-2">
          <label class="form-label fw-semibold">Anexar arquivos (imagens, docs, prints)</label>
          <div class="drop-zone" id="dropZone" onclick="document.getElementById('resp-arquivos').click()">
            <i class="bi bi-cloud-upload fs-3 text-muted"></i>
            <p class="mb-0 text-muted small">Clique ou arraste arquivos aqui</p>
            <p class="mb-0 text-muted" style="font-size:.75rem"><kbd>Ctrl+V</kbd> para colar imagem da área de transferência</p>
            <input type="file" id="resp-arquivos" multiple accept="image/*,.pdf,.doc,.docx,.txt,.zip,.xls,.xlsx" class="d-none" onchange="listarArquivos()"/>
          </div>
          <div id="lista-arquivos" class="mt-2 d-flex flex-wrap gap-2"></div>
        </div>
      </div>
      <div class="modal-footer">
        <div class="form-check form-switch me-auto">
          <input class="form-check-input" type="checkbox" id="resp-concluido"/>
          <label class="form-check-label fw-semibold" for="resp-concluido">
            ✅ Marcar como concluído (ficará verde na agenda)
          </label>
        </div>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger" id="btnEnviarResposta" onclick="enviarResposta()">
          <i class="bi bi-send-fill me-2"></i>Enviar Resposta
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Selecionar Atendentes (drag em visão "Todos") ── -->
<div class="modal fade" id="modalAtendentes" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header" style="border-bottom:3px solid #1a73e8;">
        <h6 class="modal-title fw-bold"><i class="bi bi-people-fill me-2 text-primary"></i>Atribuir a quem?</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="cancelarDrop()"></button>
      </div>
      <div class="modal-body pb-2">
        <p class="text-muted small mb-2">Selecione um ou mais atendentes para este chamado:</p>
        <div id="lista-atendentes-check" class="d-flex flex-column gap-2"></div>
      </div>
      <div class="modal-footer pt-2">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal" onclick="cancelarDrop()">Cancelar</button>
        <button class="btn btn-primary btn-sm" onclick="confirmarAtribuicao()">
          <i class="bi bi-check-lg me-1"></i>Confirmar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Toast container -->
<div id="toast-container" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;"></div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/locales/pt-br.global.min.js"></script>

<script>
// Dados do usuário logado (via PHP session)
const USUARIO_LOGADO_NOME   = <?= json_encode($nome_usuario) ?>;
const USUARIO_LOGADO_ID     = <?= json_encode($user_id_sessao) ?>;

// ── Gabarito visual de entidades ─────────────────────────────────────────────
// Muda APENAS o texto exibido no select. O value (nome real) e o data-id
// continuam intactos — o GLPI recebe o nome original sem nenhuma alteração.
const ALIAS_ENTIDADES = {
  'Entidade raiz > Grupo Gmais':                                     'Grupo Gmais',
  'Entidade raiz > Grupo Gmais > Gmais ADM':                         'Lj 101',
  'Entidade raiz > Grupo Gmais > Rincão Atacadista - BTO':           'Lj 030',
  'Entidade raiz > Grupo Gmais > Supermercado Express - BTO':         'Lj 010',
  'Entidade raiz > Grupo Gmais > Supermercado Santos - BTO':         'Lj 001',
  'Entidade raiz > Grupo Gmais > Supermercado Santos - JDM':         'Lj 003',
};

/** Retorna o alias visual da entidade, ou o nome original se não há mapeamento. */
function apelidoEntidade(nome) {
  return ALIAS_ENTIDADES[nome] ?? nome;
}

/** Reduz nome completo do atendente para "Primeiro InícialSobrenome."
 *  Ex: "Barros de Miranda Marcos" → "Marcos B."
 *      "Lima Cavalheiro Celso"    → "Celso L."
 *      "Agnelo Felix"             → "Felix A."  */
function apelidoAtendente(nome) {
  const p = nome.trim().split(/\s+/);
  return p[p.length - 1] || nome; // última palavra = primeiro nome no GLPI
}

function toast(msg, type = 'success') {
  const id  = 'toast-' + Date.now();
  const bg  = type === 'success' ? 'bg-success' : 'bg-danger';
  const icon= type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill';
  document.getElementById('toast-container').insertAdjacentHTML('beforeend', `
    <div id="${id}" class="toast align-items-center text-white ${bg} border-0 show mb-2" role="alert">
      <div class="d-flex">
        <div class="toast-body"><i class="bi ${icon} me-2"></i>${msg}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="document.getElementById('${id}').remove()"></button>
      </div>
    </div>`);
  setTimeout(() => document.getElementById(id)?.remove(), 5000);
}

let calendar;
let todosTickets    = [];
let todosEventos    = [];
let modalEvento;
let modalAtendentes;
let atendentes      = [];
let filtroAtendente = '';
let filtroTipo = '';
let _dropPendente   = null; // dados do drop aguardando seleção de atendente
let _inEventReceive = false; // bloqueia eventChange durante mutações do eventReceive
let _dropCache      = {};   // id → dados do drop; fallback de extendedProps no eventChange

// ──────────────────────────────────────────
// Cores por prioridade
// ──────────────────────────────────────────
// âš ï¸ CORES FIXAS — N?ƒO ALTERAR âš ï¸
const COR_TIPO = {
  evento:     { bg: '#1a73e8', border: '#1558b0' }, // Azul
  requisicao: { bg: '#e67c00', border: '#b35f00' }, // Laranja
  reuniao:    { bg: '#7b1fa2', border: '#4a148c' }, // Roxo
  chamado:    { bg: '#d93025', border: '#a52218' }, // Vermelho
  concluido:  { bg: '#1e8e3e', border: '#155a2e' }, // Verde
  atrasado:   { bg: '#f9a825', border: '#c6790a' }, // Amarelo (atrasado/não concluído)
};

function corDoEvento(tipo, concluido, atrasado) {
  if (concluido) return COR_TIPO.concluido;
  if (atrasado)  return COR_TIPO.atrasado;
  return COR_TIPO[tipo] || COR_TIPO.evento;
}

function estaAtrasado(end, concluido) {
  if (concluido) return false;
  return end && new Date(end) < new Date();
}

// Mantido para compatibilidade com urgência na sidebar
const COR_PRIORIDADE = COR_TIPO;
const COR_URG = { 'muito baixa':'urg-1','baixa':'urg-2','média':'urg-3','alta':'urg-4','muito alta':'urg-5' };

// ──────────────────────────────────────────
// Init FullCalendar
// ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  modalEvento     = new bootstrap.Modal(document.getElementById('modalEvento'));
  modalAtendentes = new bootstrap.Modal(document.getElementById('modalAtendentes'));
  modalResposta   = new bootstrap.Modal(document.getElementById('modalResposta'));

  // Drag & drop na drop zone de arquivos
  const dz = document.getElementById('dropZone');
  dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('dragover'); });
  dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
  dz.addEventListener('drop', e => {
    e.preventDefault(); dz.classList.remove('dragover');
    adicionarArquivos(e.dataTransfer.files);
  });

  // Colar imagem da área de transferência (Ctrl+V no modal de resposta)
  document.getElementById('modalResposta').addEventListener('paste', e => {
    colarImagemClipboard(e);
  });
  document.getElementById('resp-texto').addEventListener('paste', e => {
    if (colarImagemClipboard(e)) { e.preventDefault(); e.stopPropagation(); }
  });

  // Drag & drop na drop zone de anexos da criação
  const dzCriar = document.getElementById('dropZoneCriar');
  dzCriar.addEventListener('dragover',  e => { e.preventDefault(); dzCriar.classList.add('dragover'); });
  dzCriar.addEventListener('dragleave', () => dzCriar.classList.remove('dragover'));
  dzCriar.addEventListener('drop', e => {
    e.preventDefault(); dzCriar.classList.remove('dragover');
    adicionarArquivosCriar(e.dataTransfer.files);
  });

  // Colar imagem da área de transferência (Ctrl+V no modal do evento)
  document.getElementById('modalEvento').addEventListener('paste', e => {
    if (e.target.closest('#dropZoneCriar') || e.target.closest('#lista-arquivos-criar') || e.target.closest('#ev-descricao')) {
      colarImagemClipboardCriar(e);
    }
  });

  calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
    locale: 'pt-br',
    initialView: 'timeGridWeek',
    headerToolbar: false,
    allDayText: 'Dia Inteiro',
    dayHeaderContent: function(arg) {
      const d    = arg.date;
      const dias = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
      const dia  = String(d.getDate()).padStart(2,'0');
      const mes  = String(d.getMonth()+1).padStart(2,'0');
      return dias[d.getDay()] + ' ' + dia + '/' + mes;
    },
    height: '100%',
    nowIndicator: true,
    slotMinTime: '06:00:00',
    slotMaxTime: '23:00:00',
    slotDuration: '00:15:00',
    slotLabelInterval: '00:30:00',
    slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
    snapDuration: '00:15:00',
    allDaySlot: true,
    editable: true,
    droppable: true,

    // âš ï¸ REGRA PROTEGIDA — N?ƒO ALTERAR SEM PERMISS?ƒO DO RESPONSÁVEL âš ï¸
    // Criação de eventos só é permitida em datas de hoje em diante.
    dateClick(info) {
      if (dataNoPassado(info.date)) {
        toast('âš ï¸ Não é possível agendar em datas passadas.', 'danger');
        return;
      }
      abrirModalEvento(info.dateStr);
    },

    // Clique em evento existente → editar ou abrir menu ⋮
    eventClick(info) {
      const jsEv = info.jsEvent;
      if (jsEv) {
        const elAbaixo = document.elementFromPoint(jsEv.clientX, jsEv.clientY);
        if (elAbaixo?.closest('.ev-menu-btn')) {
          const ev = info.event;
          const props = ev.extendedProps;
          const c = _dropCache[ev.id] || {};
          // Passa coordenadas do clique em vez de btn (FC pode remover o elemento)
          abrirMenuAcoes(jsEv.clientX, jsEv.clientY, ev.id, props.ticket_id || c.ticket_id || '', props.concluido);
          return;
        }
      }
      editarEvento(info.event);
    },

    // Drag externo: salva direto ao soltar, sem abrir modal
    eventReceive(info) {
      const ev    = info.event;
      const props = ev.extendedProps;
      const urg   = props.urgencia || 'média';
      const setor = props.setor    || '';
      document.querySelectorAll('.ticket-card.dragging').forEach(c => c.classList.remove('dragging'));

      const start = new Date(ev.start);

      // âš ï¸ REGRA PROTEGIDA — N?ƒO ALTERAR SEM PERMISS?ƒO DO RESPONSÁVEL âš ï¸
      // Chamados arrastados do sidebar não podem ser soltos em datas passadas.
      if (dataNoPassado(start)) {
        ev.remove();
        document.querySelectorAll('.ticket-card.dragging').forEach(c => c.classList.remove('dragging'));
        toast('âš ï¸ Não é possível agendar em datas passadas.', 'danger');
        return;
      }

      const end        = new Date(start.getTime() + duracaoPadrao('chamado'));
      const prioridade = urgToProioridade(urg);
      const cor        = corDoEvento('chamado', false);

      // Suprime eventChange enquanto aplicamos as mutações visuais ao evento
      _inEventReceive = true;
      ev.setEnd(end);
      ev.setProp('backgroundColor', cor.bg);
      ev.setProp('borderColor', cor.border);
      ev.setExtendedProp('tipo', 'chamado'); // garante tipo correto antes do refetch

      const dados = {
        id:           ev.id || uniqEvId(),
        titulo:       ev.title,
        start:        toDatetimeLocal(start),
        end:          toDatetimeLocal(end),
        prioridade,
        tipo:         'chamado',
        concluido:    0,
        ticket_id:    props.ticket_id || null,
        setor,
        atendente:    '',
        atendente_id: null,
        atendente_cor:cor.bg,
        descricao:    '',
      };

      ev.setProp('id', dados.id);
      _inEventReceive = false;

      // Visão de atendente específico → atribui direto
      const atendenteAtivo = atendentes.find(a => a.nome === filtroAtendente);
      if (atendenteAtivo) {
        dados.atendente     = atendenteAtivo.nome;
        dados.atendente_id  = atendenteAtivo.id;
        dados.atendente_cor = atendenteAtivo.cor;
        // Guarda cache: extendedProps do drop ficam vazios no FC até o próximo refetch;
        // o cache é o fallback no eventChange para resizes antes do refetch
        _dropCache[dados.id] = { ...dados };
        salvarEventoObj(dados, () => carregarTickets());
        return;
      }

      // Visão "Todos" → remove fantasma e pergunta para quem atribuir
      ev.remove();
      _dropPendente = dados;
      abrirModalAtendentes();
    },

    // âš ï¸ REGRA PROTEGIDA — N?ƒO ALTERAR SEM PERMISS?ƒO DO RESPONSÁVEL âš ï¸
    // Nenhum evento pode ser movido/solto em datas anteriores ao dia atual.
    // Eventos concluídos já são bloqueados antes via editable:false (não chegam aqui).
    eventAllow(dropInfo) {
      return !dataNoPassado(dropInfo.start);
    },

    // Evento movido/redimensionado → salva automaticamente
    eventChange(info) {
      if (_inEventReceive) return; // ignora mutações programáticas do eventReceive
      const ev = info.event;
      // Fallback: extendedProps de evento recém-arrastado ficam vazios no FC
      // até o próximo refetch; o cache do drop preserva os dados corretos
      const c = _dropCache[ev.id] || {};
      salvarEventoObj({
        id:            ev.id,
        titulo:        ev.title,
        start:         ev.startStr,
        end:           ev.endStr || ev.startStr,
        orig_start:    info.oldEvent.startStr,
        atendente:     ev.extendedProps.atendente     || c.atendente     || '',
        atendente_id:  ev.extendedProps.atendente_id  ?? c.atendente_id  ?? null,
        atendente_cor: ev.extendedProps.atendente_cor || c.atendente_cor || '#1a73e8',
        prioridade:    ev.extendedProps.prioridade    || c.prioridade    || 'media',
        setor:         ev.extendedProps.setor         || c.setor         || '',
        descricao:     ev.extendedProps.descricao     || c.descricao     || '',
        ticket_id:     ev.extendedProps.ticket_id     || c.ticket_id     || null,
        tipo:          ev.extendedProps.tipo          || c.tipo          || 'chamado',
        concluido:     ev.extendedProps.concluido     ? 1 : (c.concluido ?? 0),
        _only_reposition: true, // não cria followup no GLPI ao reposicionar (ticket pode estar fechado)
      }, () => carregarTickets());
    },

    events: carregarEventos,

    eventClassNames(arg) {
      const classes  = [];
      const props    = arg.event.extendedProps;
      // Tracejado amarelo só quando há 2+ atendentes
      if (props.multi && (props.atendentes || []).length >= 2) classes.push('ev-multi');
      // Evento curto: ≤ 20 minutos
      const dur = arg.event.end ? (arg.event.end - arg.event.start) : 0;
      if (dur > 0 && dur <= 20 * 60 * 1000) classes.push('ev-short');
      return classes;
    },

    // Renderiza ícone + título + menu ⋮ no evento
    eventContent(arg) {
      const props     = arg.event.extendedProps;
      const c         = _dropCache[arg.event.id] || {};
      const tipo      = props.tipo || c.tipo || 'chamado';
      const concluido = props.concluido;
      const multi     = props.multi;
      const ticketId  = props.ticket_id || c.ticket_id || '';
      const icones = {
        evento:     'bi-calendar-event',
        requisicao: 'bi-clipboard-check',
        reuniao:    'bi-people-fill',
        chamado:    'bi-headset',
      };
      // Usa sempre o ícone do tipo; o check aparece só no badge da beirada
      const icone = icones[tipo] || 'bi-calendar-event';
      const timeText = ''; // Horário oculto no evento — visível na barra lateral
      const nAtendentes = (props.atendentes || []).length;
      const grupoTag = (multi && nAtendentes >= 2)
        ? `<span class="ev-group-icon" title="Atribuído a: ${props.atendentes.join(', ')}"><i class="bi bi-people-fill"></i> ${nAtendentes}</span>`
        : '';
      const checkBadge = concluido
        ? `<span class="ev-check-badge"><i class="bi bi-check-lg"></i></span>`
        : '';
      const avisoTag = props.atrasado
        ? `<span class="ev-aviso-icon" title="Atrasado — não concluído no prazo"><i class="bi bi-exclamation-triangle-fill"></i></span>`
        : '';
      // Menu ⋮ apenas para chamado/requisição (que têm ticket_id no GLPI)
      const temMenu = ticketId && (tipo === 'chamado' || tipo === 'requisicao');
      const menuBtn = temMenu
        ? `<button class="ev-menu-btn" title="Ações">⋮</button>`
        : '';
      return {
        html: `<div class="ev-inner">
                 ${timeText}<i class="bi ${icone} ev-icon"></i>
                 <span class="ev-title">${arg.event.title.replace(/^#\d+\s*[â€“-]\s*/, '')}</span>
                 ${grupoTag}${avisoTag}
               </div>
               ${checkBadge}
               ${menuBtn}`
      };
    },

  });

  calendar.render();

  // ── Destaque visual: horários de plantão (fundo cinza claro na grade) ──
  // As células dos horários 06-07h, 11-13h e 17-22h recebem fundo cinza
  // para indicar períodos cobertos por plantonistas.
  // Gera via JS para acompanhar a granularidade dos slots (15 min).
  (function() {
    const RANGES = [[6,7],[11,13],[17,22]];
    const pad = n => String(n).padStart(2,'0');
    const style = document.createElement('style');
    style.id = 'plantonista-slots';
    let css = '/* Plantonista hours */\n';
    for (const [s,e] of RANGES) {
      for (let h = s; h < e; h++) {
        css += `.fc-timegrid-slot[data-time="${pad(h)}:00:00"],.fc-timegrid-slot[data-time="${pad(h)}:15:00"],.fc-timegrid-slot[data-time="${pad(h)}:30:00"],.fc-timegrid-slot[data-time="${pad(h)}:45:00"],`;
      }
    }
    css = css.replace(/,$/,'') + '{background:#f5f3f1!important}';
    style.textContent = css;
    document.head.appendChild(style);
  })();

  // ── Almoço: células compactas (11h-13h) ──
  aplicarCompactacaoAlmoco();

  carregarAtendentes();
  // verificarAtrasados → syncRotinas → refetchEvents + carregarTickets (sequencial)
  // evita race condition onde refetchEvents do verificar removeria rotinas recém-inseridas
  verificarAtrasados();
});

// ── Almoço: células compactas (11h-13h) ───────────────────────
// Reduz altura dos slots 11h-13h pela metade via JS inline !important.
// style.setProperty('height', '...', 'important') → inline important
// que nada sobrescreve — resistente a re-renders do FullCalendar.
function aplicarCompactacaoAlmoco() {
  const SEL_TBODY = '.fc-timegrid-slots table tbody';
  function comprimir() {
    const tbody = document.querySelector(SEL_TBODY);
    if (!tbody) return;
    for (const el of tbody.querySelectorAll('.fc-timegrid-slot')) {
      const t = el.getAttribute('data-time') || '';
      if (t.startsWith('11:') || t.startsWith('12:')) {
        el.style.setProperty('height', '0.75em', 'important');
      } else if (t) {
        el.style.setProperty('height', '1.5em', 'important');
      }
    }
  }
  setTimeout(comprimir, 50);
  // Reaplica em re-renders (troca de view, navegação, redim)
  const obs = new MutationObserver(comprimir);
  const alvo = document.querySelector('.fc-timegrid-slots');
  if (alvo) obs.observe(alvo, { childList: true, subtree: true });
  document.addEventListener('click', function(e) {
    if (e.target.closest('.fc-prev-button, .fc-next-button, .fc-today-button')) {
      setTimeout(comprimir, 200);
    }
  });
}

// ──────────────────────────────────────────
// Carregar eventos da agenda (PHP)
// ──────────────────────────────────────────
function carregarEventos(info, success) {
  fetch('eventos.php?action=list')
    .then(r => r.json())
    .then(data => {
      todosEventos = data.map(e => {
        const concluido = !!e.concluido;
        const atrasado  = estaAtrasado(e.end, concluido);
        const cor = corDoEvento(e.tipo, concluido, atrasado);
        // FullCalendar 6 exige formato ISO 8601 com 'T' para interpretar como
        // horário LOCAL. Sem o 'T', o parser trata como UTC → end fica errado
        // e o evento colapsa para a altura mínima do slot (parecendo 30 min).
        const fcStart = (e.start || '').replace(' ', 'T');
        const fcEnd   = (e.end   || e.start || '').replace(' ', 'T');
        return {
          id: e.id,
          title: e.titulo,
          start: fcStart,
          end:   fcEnd,
          backgroundColor: cor.bg,
          borderColor: cor.border,
          // âš ï¸ REGRA PROTEGIDA — N?ƒO ALTERAR SEM PERMISS?ƒO DO RESPONSÁVEL âš ï¸
          // Eventos concluídos (verde) são somente-leitura: não podem ser arrastados nem redimensionados.
          editable: !concluido,
          extendedProps: {
            descricao:    e.descricao,
            atendente:    e.atendente,
            atendente_id: e.atendente_id,
            atendente_cor:e.atendente_cor,
            prioridade:   e.prioridade,
            setor:        e.setor,
            ticket_id:    e.ticket_id,
            tipo:         e.tipo,
            concluido,
            atrasado,
          }
        };
      });
      // Log para depuração multi-atendente
      if (todosEventos.some(e => e.extendedProps.ticket_id)) {
        const porAtendente = {};
        todosEventos.forEach(e => {
          const a = e.extendedProps.atendente || '(sem atendente)';
          porAtendente[a] = (porAtendente[a] || 0) + 1;
        });
        console.log('📊 carregarEventos: total=', todosEventos.length, 'porAtendente=', porAtendente);
      }

      // Aplica filtro de atendente se houver
      success(eventosFiltrados());
    });
}

function eventosFiltrados() {
  // âš ï¸ REGRA PROTEGIDA — N?ƒO ALTERAR SEM PERMISS?ƒO DO RESPONSÁVEL âš ï¸
  // Visibilidade por atendente:
  //   - Eventos (ativos ou concluídos) só aparecem na agenda do atendente que os possui.
  //   - Concluídos sem atendente (histórico) aparecem apenas para o usuário logado.
  //   - NUNCA mostrar eventos de um atendente na agenda de outro.
  //   - Eventos tipo "evento" são pessoais — só aparecem na agenda de quem criou

  // ── Mapa multi-atendente (aplicado a TODOS os eventos antes do filtro) ──
  // Mesmo quando filtrado por um técnico, o indicador "multi" deve aparecer
  // se o chamado tiver 2+ técnicos no total (não apenas nos eventos filtrados).
  const multiMap = {}; // "ticket_id|start" → [atendente1, atendente2, ...]
  todosEventos.forEach(ev => {
    const tid = ev.extendedProps.ticket_id;
    if (!tid) return;
    const key = tid + '|' + (ev.start || '');
    if (!multiMap[key]) multiMap[key] = [];
    if (ev.extendedProps.atendente) {
      multiMap[key].push(ev.extendedProps.atendente);
    }
  });

  // Aplica info multi a cada evento em todosEventos
  todosEventos.forEach(ev => {
    const tid = ev.extendedProps.ticket_id;
    if (!tid) return;
    const key = tid + '|' + (ev.start || '');
    const lista = multiMap[key];
    if (lista && lista.length >= 2) {
      ev.extendedProps.multi      = true;
      ev.extendedProps.atendentes = lista;
    } else {
      ev.extendedProps.multi = false;
    }
  });

  // ── Filtro por atendente ──
  if (filtroAtendente) {
    const filtrados = todosEventos.filter(e => {
      if (e.extendedProps.atendente === filtroAtendente) return true;
      if (e.extendedProps.concluido && !e.extendedProps.atendente) {
        return filtroAtendente === USUARIO_LOGADO_NOME;
      }
      return false;
    });
    console.log(`📊 eventosFiltrados: filtro="${filtroAtendente}", total=${todosEventos.length}, filtrados=${filtrados.length}`);
    // Se filtrou menos que o total, loga os excluídos
    if (filtrados.length < todosEventos.length) {
      todosEventos.forEach(e => {
        if (!filtrados.includes(e)) {
          console.log(`   âŒ excluído: atendente="${e.extendedProps.atendente}", ticket=${e.extendedProps.ticket_id}, tipo=${e.extendedProps.tipo}`);
        }
      });
    }
    // Aplica filtro por tipo
    if (filtroTipo) {
      return filtrados.filter(e => {
        if (filtroTipo === 'chamado_todos') return e.extendedProps.tipo === 'chamado';
        if (filtroTipo === 'chamado_concluido') return e.extendedProps.tipo === 'chamado' && e.extendedProps.concluido;
        if (filtroTipo === 'chamado_pendente') return e.extendedProps.tipo === 'chamado' && !e.extendedProps.concluido;
        return e.extendedProps.tipo === filtroTipo;
      });
    }
    return filtrados;
  }

  // Visão "Todos"
  // Regras:
  //   1. Eventos tipo "evento" são pessoais → não aparecem em "Todos"
  //   2. Chamados com MESMO ticket + MESMO horário → agrupa (multi-atendente)
  //   3. Chamados com MESMO ticket + HORÁRIOS diferentes → exibe separadamente (multi-período)

  // Filtra eventos pessoais (tipo "evento") — só aparecem na agenda do dono
  const eventosVisiveis = todosEventos.filter(e => e.extendedProps.tipo !== 'evento');

  // Ignora eventos sem atendente se já existe versão com atendente do mesmo ticket
  const ticketsComAtendente = new Set(
    eventosVisiveis
      .filter(e => e.extendedProps.ticket_id && e.extendedProps.atendente)
      .map(e => e.extendedProps.ticket_id)
  );

  const vistos    = {}; // "ticket_id|start" → índice no resultado
  const resultado = [];

  for (const ev of eventosVisiveis) {
    const tid = ev.extendedProps.ticket_id;

    // Ignora eventos sem atendente se já existe versão com atendente do mesmo ticket
    if (tid && !ev.extendedProps.atendente && ticketsComAtendente.has(tid)) continue;

    if (!tid) {
      // Evento sem chamado vinculado → exibe normalmente
      resultado.push(ev);
      continue;
    }

    // Agrupa por ticket_id + horário de início
    // Isso separa chamados com múltiplos períodos (cada período aparece no seu horário)
    // e agrupa apenas eventos do mesmo chamado no mesmo horário (multi-atendente)
    const key = tid + '|' + (ev.start || '');

    if (vistos[key] !== undefined) {
      // Mesmo período — funde no primeiro (que já está em resultado)
      // O multiMap já foi aplicado acima, então o primeiro evento já tem multi info
    } else {
      // Primeiro evento deste ticket NESTE horário — clona para não mutar o original
      const clone = JSON.parse(JSON.stringify(ev));
      clone.extendedProps.multi      = ev.extendedProps.multi;
      clone.extendedProps.atendentes = ev.extendedProps.atendentes;
      vistos[key] = resultado.length;
      resultado.push(clone);
    }
  }

  // Aplica filtro por tipo (após os filtros de atendente/Todos)
  if (filtroTipo) {
    return resultado.filter(e => {
      if (filtroTipo === 'chamado_todos') return e.extendedProps.tipo === 'chamado';
      if (filtroTipo === 'chamado_concluido') return e.extendedProps.tipo === 'chamado' && e.extendedProps.concluido;
      if (filtroTipo === 'chamado_pendente') return e.extendedProps.tipo === 'chamado' && !e.extendedProps.concluido;
      return e.extendedProps.tipo === filtroTipo;
    });
  }
  return resultado;
}

function filtrarPorAtendente() {
  filtroAtendente = document.getElementById('filtro-atendente').value;
  calendar.refetchEvents();
}

function filtrarPorTipo() {
  filtroTipo = document.getElementById('filtro-tipo').value;
  calendar.refetchEvents();
}

// ── Menu ⋮ de ações no evento ────────────
function abrirMenuAcoes(clientX, clientY, evId, ticketId, concluido) {
  // Fecha qualquer outro menu aberto
  document.querySelectorAll('.ev-dropdown-dinamico').forEach(el => el.remove());

  // Cria o dropdown
  const dropdown = document.createElement('div');
  dropdown.className = 'ev-dropdown ev-dropdown-dinamico show';
  dropdown.innerHTML = `<div class="ev-dropdown-header">Chamado</div>
    <button class="ev-dropdown-item" onclick="excluirChamado('${ticketId}', this)" data-ticket="${ticketId}">
      🗑️ Excluir chamado
      ${concluido ? '<br><small style="color:#999;font-size:.7rem">(apenas chamados em aberto)</small>' : '<br><small style="color:#999;font-size:.7rem">Excluir permanentemente do GLPI</small>'}
    </button>
    <div class="ev-dropdown-divider"></div>
    <button class="ev-dropdown-item" onclick="reabrirChamado('${ticketId}', this)" data-ticket="${ticketId}">
      🔄 Reabrir chamado
      ${concluido ? '<br><small style="color:#999;font-size:.7rem">Reabrir chamado fechado</small>' : '<br><small style="color:#999;font-size:.7rem">(chamado já está em aberto)</small>'}
    </button>`;

  // Habilita/desabilita itens conforme estado
  const excluirBtn = dropdown.querySelector('button:nth-child(1)');
  const reabrirBtn = dropdown.querySelector('button:nth-child(3)');
  if (concluido) {
    excluirBtn.disabled = true;
  } else {
    reabrirBtn.disabled = true;
  }

  // Posiciona nas coordenadas do clique
  dropdown.style.position = 'fixed';
  dropdown.style.left = Math.max(8, clientX - 170) + 'px';
  dropdown.style.top = (clientY + 5) + 'px';
  dropdown.style.zIndex = '99999';

  document.body.appendChild(dropdown);

  // Fecha ao clicar fora
  setTimeout(() => {
    const fechar = (e) => {
      if (!dropdown.contains(e.target)) {
        dropdown.remove();
        document.removeEventListener('pointerdown', fechar, true);
      }
    };
    document.addEventListener('pointerdown', fechar, true);
  }, 50);
}

// ── Confirmação com código aleatório ─────────
function confirmWithCode(mensagem) {
  const codigo = Math.floor(1000 + Math.random() * 9000);
  const resposta = prompt(
    '🔐 CONFIRMAÇÃO SEGURA\n\nDigite o código abaixo para confirmar esta ação:\n\n📌 Código: ' + codigo + '\n\n' + mensagem,
    ''
  );
  return resposta === String(codigo);
}

function excluirChamado(ticketId, btn) {
  if (btn.disabled) return;
  if (!confirmWithCode('🗑️ EXCLUIR chamado #' + ticketId + ' permanentemente para a lixeira do GLPI?')) return;

  fetch('excluir_ticket_glpi.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ticket_id: parseInt(ticketId) }),
  })
  .then(r => r.json())
  .then(res => {
    document.querySelectorAll('.ev-dropdown-dinamico').forEach(el => el.remove());
    if (res.ok) {
      fetch('eventos.php?action=deleteByTicket&ticket_id=' + encodeURIComponent(ticketId))
        .then(r => r.json())
        .catch(() => ({}));
      toast('🗑️ Chamado #' + ticketId + ' enviado para a lixeira do GLPI.');
      calendar.refetchEvents();
      carregarTickets();
    } else {
      alert('Erro ao excluir: ' + (res.msg || 'Falha desconhecida'));
    }
  })
  .catch(() => {
    document.querySelectorAll('.ev-dropdown-dinamico').forEach(function(el) { el.remove(); });
    alert('Erro de conexão ao tentar excluir chamado.');
  });
}

function reabrirChamado(ticketId, btn) {
  if (btn.disabled) return;
  if (!confirmWithCode('🔄 REABRIR chamado #' + ticketId + '?\n\nO chamado voltará para o status "Atribuído".')) return;

  fetch('reabrir_ticket.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ticket_id: parseInt(ticketId) }),
  })
  .then(r => r.json())
  .then(res => {
    document.querySelectorAll('.ev-dropdown-dinamico').forEach(el => el.remove());
    if (res.ok) {
      const eventos = calendar.getEvents().filter(e => String(e.extendedProps.ticket_id) === String(ticketId));
      eventos.forEach(ev => {
        fetch('eventos.php?action=save', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: ev.id, ticket_id: parseInt(ticketId), concluido: 0 }),
        }).catch(() => {});
        ev.setExtendedProp('concluido', false);
        ev.setDates(ev.start, ev.end, { allDay: ev.allDay });
      });
      toast('🔄 Chamado #' + ticketId + ' reaberto como "Atribuído"!');
    } else {
      alert('Erro ao reabrir: ' + (res.msg || 'Falha desconhecida'));
    }
  })
  .catch(() => {
    document.querySelectorAll('.ev-dropdown-dinamico').forEach(function(el) { el.remove(); });
    alert('Erro de conexao ao tentar reabrir chamado.');
  });
}

// ──────────────────────────────────────────
// Carregar atendentes do GLPI
// ──────────────────────────────────────────
function carregarAtendentes() {
  fetch('users.php')
    .then(r => r.json())
    .then(data => {
      atendentes = data;

      // Popula select do modal (edição de evento)
      document.getElementById('ev-atendente').innerHTML =
        '<option value="">Selecione...</option>' +
        data.map(a => `<option value="${escHtml(a.nome)}" data-cor="${a.cor}" data-id="${a.id}">${escHtml(apelidoAtendente(a.nome))}</option>`).join('');
      renderAtendentesMulti([]);

      // Popula filtro do navbar — usa a.nome cru (sem escHtml no value) para
      // comparação exata com e.extendedProps.atendente em eventosFiltrados.
      // Escapamos apenas & para não quebrar a sintaxe HTML quando o nome contém &.
      const filtro = document.getElementById('filtro-atendente');
      filtro.innerHTML =
        '<option value="">👥 Todos os atendentes</option>' +
        data.map(a => `<option value="${String(a.nome).replace(/&/g, '&amp;').replace(/"/g, '&quot;')}">${escHtml(apelidoAtendente(a.nome))}</option>`).join('');

      // Pré-seleciona o atendente logado (por ID ou por nome)
      const atendenteLogado = data.find(a => a.id === USUARIO_LOGADO_ID || a.nome === USUARIO_LOGADO_NOME);
      if (atendenteLogado) {
        filtro.value     = atendenteLogado.nome;
        filtroAtendente  = atendenteLogado.nome;
        calendar.refetchEvents();
      }
    })
    .catch(() => {
      document.getElementById('ev-atendente').innerHTML = '<option value="">Erro ao carregar</option>';
    });

  // Carrega entidades
  fetch('entidades.php')
    .then(r => r.json())
    .then(data => {
      document.getElementById('ev-entidade').innerHTML =
        '<option value="">Selecione a entidade...</option>' +
        data.map(e => `<option value="${escHtml(e.nome)}" data-id="${e.id}">${escHtml(apelidoEntidade(e.nome))}</option>`).join('');
    });

  // Carrega requerentes (todos os usuários ativos)
  fetch('users.php?todos=1')
    .then(r => r.json())
    .then(data => {
      document.getElementById('ev-requerente').innerHTML =
        '<option value="">Selecione o requerente...</option>' +
        data.map(u => `<option value="${escHtml(u.nome)}" data-id="${u.id}">${escHtml(u.nome)}</option>`).join('');
    });

  // Carrega categorias
  fetch('categorias.php')
    .then(r => r.json())
    .then(data => {
      document.getElementById('ev-categoria').innerHTML =
        '<option value="">-- Sem categoria --</option>' +
        data.map(c => `<option value="${c.id}">${escHtml(c.nome)}</option>`).join('');
    });
}

// ──────────────────────────────────────────
// Carregar chamados GLPI
// ──────────────────────────────────────────
function carregarTickets() {
  fetch('tickets.php')
    .then(r => r.json())
    .then(data => {
      todosTickets = data;
      renderTickets(data);
      iniciarDrag();
      // Atualiza contador de abertos
      const qtd = Array.isArray(data) ? data.length : 0;
      document.getElementById('qtd-abertos').textContent = qtd;
    })
    .catch(() => {
      document.getElementById('ticketList').innerHTML =
        '<div class="no-tickets text-danger"><i class="bi bi-exclamation-circle"></i><br/>Erro ao carregar chamados.</div>';
      document.getElementById('qtd-abertos').textContent = '!';
    });
}

function renderTickets(tickets) {
  const list = document.getElementById('ticketList');
  if (!tickets.length) {
    list.innerHTML = '<div class="no-tickets"><i class="bi bi-inbox"></i><br/>Nenhum chamado encontrado.</div>';
    return;
  }
  const urgLabel = {
    'muito baixa':'Muito Baixa','baixa':'Baixa','média':'Média','alta':'Alta','muito alta':'Muito Alta'
  };
  list.innerHTML = tickets.map(t => `
    <div class="ticket-card pr-${urgToProioridade(t.urgencia)}${t.agendado ? ' em-andamento' : ''}"
         draggable="true"
         data-id="${t.id}"
         data-titulo="${escHtml(t.titulo)}"
         data-urgencia="${t.urgencia}"
         data-setor="${escHtml(t.setor)}"
         onclick="mostrarPreview(event, this)"
         data-descricao="${escHtml(t.descricao || '')}">
      <div class="tc-id">#${t.id} · ${t.data}</div>
      <div class="tc-title">${escHtml(t.titulo)}</div>
      <div class="tc-tags">
        <span class="badge-urg ${COR_URG[t.urgencia] || 'urg-3'}">${urgLabel[t.urgencia] || t.urgencia}</span>
        <span class="badge-status">${t.status}</span>
        ${t.setor ? `<span class="badge-status"><i class="bi bi-building me-1"></i>${escHtml(t.setor)}</span>` : ''}
        ${t.agendado ? `<span class="badge-andamento"><i class="bi bi-clock-history me-1"></i>Em andamento</span>` : ''}
      </div>
    </div>
  `).join('');

  iniciarDrag();
}

/* -- Preview do chamado no sidebar -- */
const PREVIEW_CORES = {1:'primary',2:'info',3:'warning',4:'danger',5:'purple'};
const PREVIEW_URG   = {'muito baixa':1,'baixa':2,'média':3,'alta':4,'muito alta':5};
const PREVIEW_URG_LABEL = {1:'Muito Baixa',2:'Baixa',3:'Média',4:'Alta',5:'Muito Alta'};

function mostrarPreview(event, el) {
  // Se estiver arrastando, não abre preview
  if (el.classList.contains('dragging')) return;
  event.stopPropagation();

  const id        = el.dataset.id || '';
  const titulo    = el.dataset.titulo || '';
  const descricao = el.dataset.descricao || '';
  const setor     = el.dataset.setor || '';
  const urgencia  = el.dataset.urgencia || 'média';

  const urgNum = PREVIEW_URG[urgencia] || 3;
  const urgCor = PREVIEW_CORES[urgNum] || 'warning';
  const urgNome = PREVIEW_URG_LABEL[urgNum] || urgencia;

  // Preenche
  document.getElementById('tpId').textContent     = `#${id}`;
  document.getElementById('tpTitulo').textContent  = titulo;
  document.getElementById('tpDescricao').textContent = descricao || 'Sem descrição';
  document.getElementById('tpMeta').innerHTML = `
    <span class="badge bg-${urgCor}"><i class="bi bi-exclamation-triangle me-1"></i>${urgNome}</span>
    ${setor ? `<span class="badge bg-secondary"><i class="bi bi-building me-1"></i>${setor}</span>` : ''}
    <span onclick="event.stopPropagation();window.open('chamado.php?id=${id}','_blank')" class="badge bg-dark" style="cursor:pointer">
      <i class="bi bi-box-arrow-up-right me-1"></i>Abrir chamado
    </span>`;

  // Mostra
  document.getElementById('tpOverlay').classList.add('show');
  document.getElementById('tpCard').style.display = 'block';
}

function fecharPreview() {
  document.getElementById('tpOverlay').classList.remove('show');
  document.getElementById('tpCard').style.display = 'none';
}

function filtrarTickets() {
  const txt        = document.getElementById('filtro-texto').value.toLowerCase();
  const urg        = document.getElementById('filtro-urgencia').value;
  const sta        = document.getElementById('filtro-status').value;
  const porAbertura= document.getElementById('ordenar-abertura').checked;

  let filtrados = todosTickets.filter(t => {
    const ok_txt = !txt || t.titulo.toLowerCase().includes(txt) || String(t.id).includes(txt);
    const ok_urg = !urg || t.urgencia === urg;
    const ok_sta = !sta || t.status === sta;
    return ok_txt && ok_urg && ok_sta;
  });

  // Ordena: por data de abertura ou por última atualização (padrão)
  if (porAbertura) {
    filtrados = [...filtrados].sort((a, b) => (b.data || '').localeCompare(a.data || ''));
  } else {
    filtrados = [...filtrados].sort((a, b) => (b.date_mod || '').localeCompare(a.date_mod || ''));
  }

  renderTickets(filtrados);
}

// ──────────────────────────────────────────
// Drag & Drop (sidebar → calendário)
// Registrado uma única vez na inicialização
// ──────────────────────────────────────────
let _draggableIniciado = false;
function iniciarDrag() {
  if (!_draggableIniciado && FullCalendar.Draggable) {
    new FullCalendar.Draggable(document.getElementById('ticketList'), {
      itemSelector: '.ticket-card',
      eventData(el) {
        el.classList.add('dragging');
        return {
          id:       uniqEvId(),
          title:    `#${el.dataset.id} â€“ ${el.dataset.titulo}`,
          duration: '00:30', // ghost visual (chamados = 30min)
          extendedProps: {
            ticket_id: el.dataset.id,
            urgencia:  el.dataset.urgencia,
            setor:     el.dataset.setor || '',
          },
        };
      }
    });
    _draggableIniciado = true;
  }
}

function verificarAtrasados() {
  fetch('verificar_atrasados.php')
    .then(r => r.json())
    .then(res => {
      if (res.removidos > 0) {
        const extra = res.periodos_antigos > 0 ? ` (${res.periodos_antigos} período(s) antigo(s) removido(s), ticket continua ativo)` : '';
        toast(`â†©ï¸ ${res.removidos} chamado(s) com +24h de atraso retornaram para a fila${extra}.`);
      }
      // Após verificar atrasados, sincroniza rotinas e só depois carrega tudo
      // (evita race condition onde refetchEvents do verificar apaga rotinas recém-inseridas)
      syncRotinas();
    })
    .catch(() => {
      // Se verificar falhar, ainda tenta sincronizar rotinas
      syncRotinas();
    });
}

function uniqEvId() {
  return 'ev_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
}

// Retorna true se a data for de um DIA anterior ao de hoje (permite horas passadas do dia atual)
function dataNoPassado(date) {
  const agora = new Date();
  const hoje  = new Date(agora.getFullYear(), agora.getMonth(), agora.getDate());
  const d     = new Date(date);
  const dDia  = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  return dDia < hoje;
}

// ──────────────────────────────────────────
// Modal de seleção de atendentes (visão Todos)
// ──────────────────────────────────────────
function abrirModalAtendentes() {
  const lista = document.getElementById('lista-atendentes-check');
  lista.innerHTML = atendentes.map(a => `
    <div class="atendente-check-item" data-nome="${escHtml(a.nome)}" data-id="${a.id}" data-cor="${a.cor}" onclick="toggleAtendente(this)">
      <span class="atendente-dot" style="background:${a.cor}"></span>
      <span class="fw-semibold">${escHtml(apelidoAtendente(a.nome))}</span>
      <i class="bi bi-check-circle-fill ms-auto text-primary d-none check-icon"></i>
    </div>
  `).join('');
  modalAtendentes.show();
}

function toggleAtendente(el) {
  el.classList.toggle('selecionado');
  el.querySelector('.check-icon').classList.toggle('d-none');
}

function cancelarDrop() {
  _dropPendente = null;
  // Devolve o chamado para a sidebar
  carregarTickets();
}

async function confirmarAtribuicao() {
  const selecionados = [...document.querySelectorAll('.atendente-check-item.selecionado')];
  if (!selecionados.length) {
    alert('Selecione pelo menos um atendente.');
    return;
  }

  modalAtendentes.hide();
  const base = _dropPendente;
  _dropPendente = null;

  // Cria um evento por atendente selecionado
  for (const el of selecionados) {
    const dados = Object.assign({}, base, {
      id:           uniqEvId(),
      atendente:    el.dataset.nome,
      atendente_id: parseInt(el.dataset.id),
      atendente_cor:el.dataset.cor,
    });
    await salvarEventoObjAsync(dados);
  }

  calendar.refetchEvents();
  carregarTickets();
  toast(`✅ Chamado atribuído a ${selecionados.length} atendente(s).`);
}

async function uploadAnexosCriar(ticketId) {
  if (!arquivosAnexosCriar.length) return { ok: true, anexos: 0 };
  const form = new FormData();
  form.append('ticket_id', ticketId);
  arquivosAnexosCriar.forEach(f => form.append('arquivos[]', f));
  const res = await fetch('anexar_ticket.php', { method: 'POST', body: form });
  const data = await res.json();
  if (data.ok && data.anexos > 0) {
    toast(`📎 ${data.anexos} anexo(s) enviado(s) ao chamado #${ticketId}.`);
  }
  return data;
}

function salvarEventoObjAsync(dados) {
  return fetch('eventos.php?action=save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(dados),
  })
  .then(r => r.json())
  .then(res => {
    if (!res.ok) {
      console.error('âŒ eventosAsync save falhou:', res.error || res, 'dados:', JSON.stringify(dados));
      return;
    }
    if (dados._skipGlpi) return;
    if (dados.ticket_id && dados.atendente_id) {
      return fetch('atribuir_ticket.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          ticket_id:      dados.ticket_id,
          atendente_id:   dados.atendente_id,
          atendentes_ids: dados.atendentes_ids || null,
        }),
      });
    }
  });
}

// Duração padrão em ms por tipo
function duracaoPadrao(tipo) {
  if (tipo === 'reuniao')                              return 60 * 60 * 1000;  // 1 hora
  if (tipo === 'chamado' || tipo === 'requisicao')     return 30 * 60 * 1000;  // 30 min
  return 30 * 60 * 1000;                                                        // evento: 30 min
}

// ──────────────────────────────────────────
// Modal de Evento
// ──────────────────────────────────────────
let _dadosModal = null;

function abrirModalEvento(dataStr) {
  _dadosModal = null;
  limparValidacao();
  document.getElementById('ev-id').value         = '';
  document.getElementById('ev-ticket-id').value  = '';
  document.getElementById('ev-orig-start').value = '';
  document.getElementById('ev-titulo').value = '';
  document.getElementById('ev-descricao').value = '';
  document.getElementById('ev-atendente').value = '';
  document.getElementById('ev-prioridade').value = 'media';
  document.getElementById('ev-setor').value = '';
  document.getElementById('ev-tipo').value = 'chamado';
  document.getElementById('ev-entidade').value   = '';
  document.getElementById('ev-requerente').value = '';
  document.getElementById('ev-categoria').value  = '';
  document.getElementById('ev-origem').value     = '';
  document.getElementById('ev-concluido').checked  = false;
  document.getElementById('ev-fechar-glpi').checked = false;
  document.getElementById('campo-fechar-glpi').style.display = 'none';
  renderAtendentesMulti([]); // limpa chips de atendente da sessão anterior
  document.getElementById('ev-followups').innerHTML = '';
  document.getElementById('campo-followups').style.display = 'none';
  document.getElementById('ev-anexos').innerHTML = '';
  document.getElementById('campo-anexos').style.display = 'none';
  document.getElementById('lista-arquivos-criar').innerHTML = '';
  arquivosAnexosCriar = [];
  document.getElementById('btnDeletar').style.display     = 'none';
  document.getElementById('btnResponder').style.display   = 'none';
  document.getElementById('btnNovoPeriodo').style.display = 'none';
  document.getElementById('banner-readonly').style.display = 'none';
  document.getElementById('modalTitulo').innerHTML = '<i class="bi bi-calendar-plus me-2"></i>Novo Evento';
  ajustarCamposPorTipo();
  setModoLeitura(false); // novo evento sempre em modo edição

  const now = dataStr ? new Date(dataStr) : new Date();
  document.getElementById('ev-start').value  = toDatetimeLocal(now);
  document.getElementById('ev-duracao').value = String(duracaoPadrao('chamado')); // 30 min padrão
  aoMudarInicio(); // calcula ev-end = start + duração

  modalEvento.show();
}

function preencherModal(dados) {
  _dadosModal = dados;
  document.getElementById('lista-arquivos-criar').innerHTML = '';
  arquivosAnexosCriar = [];
  document.getElementById('ev-id').value        = dados.id || '';
  document.getElementById('ev-ticket-id').value = dados.ticket_id || '';
  document.getElementById('ev-titulo').value    = dados.titulo || '';
  document.getElementById('ev-orig-start').value = dados.start || '';
  document.getElementById('ev-start').value = toDatetimeLocal(new Date(dados.start));
  document.getElementById('ev-end').value   = toDatetimeLocal(new Date(dados.end || dados.start));
  // Sincroniza o select de duração com a diferença real do evento
  (function() {
    const diffMs = new Date(dados.end || dados.start).getTime() - new Date(dados.start).getTime();
    const sel    = document.getElementById('ev-duracao');
    const match  = diffMs > 0 && Array.from(sel.options).find(o => +o.value === diffMs);
    sel.value = match ? match.value : (diffMs > 0 ? '0' : String(duracaoPadrao(dados.tipo || 'chamado')));
  })();
  document.getElementById('ev-prioridade').value  = dados.prioridade || 'media';
  document.getElementById('ev-tipo').value         = dados.tipo || 'chamado';
  document.getElementById('ev-setor').value        = dados.setor || '';
  ajustarCamposPorTipo();
  document.getElementById('ev-descricao').value    = dados.descricao || '';
  document.getElementById('ev-atendente').value    = dados.atendente || '';
  document.getElementById('ev-concluido').checked  = !!dados.concluido;
  toggleFecharGlpi();

  // Busca dados completos do ticket no GLPI (descrição, entidade, categoria, requerente)
  if (dados.ticket_id) {
    fetch('ticket_descricao.php?id=' + dados.ticket_id)
      .then(r => r.json())
      .then(d => {
        if (d.descricao) document.getElementById('ev-descricao').value = d.descricao;

        // Entidade: options têm data-id → busca por ID numérico (evita mismatch de HTML entities)
        // Se a entidade não estiver na lista (ex: Entidade raiz excluída), insere dinamicamente
        // âš ï¸ d.entidade_id pode ser 0 (entidade raiz) — N?ƒO usar if (d.entidade_id) pois 0 é falsy
        const selEnt = document.getElementById('ev-entidade');
        if (selEnt && d.entidade_id !== undefined && d.entidade_id !== null) {
          const opt = selEnt.querySelector(`option[data-id="${d.entidade_id}"]`);
          if (opt) {
            selEnt.value = opt.value;
          } else if (d.entidade) {
            const novaOpt = new Option(apelidoEntidade(d.entidade), d.entidade);
            novaOpt.dataset.id = d.entidade_id;
            selEnt.insertBefore(novaOpt, selEnt.options[1]);
            selEnt.value = d.entidade;
          }
        }

        // Categoria: options têm value=id → usa d.categoria_id (correto)
        if (d.categoria_id) {
          const selCat = document.getElementById('ev-categoria');
          if (selCat) selCat.value = d.categoria_id;
        }

        // Requerente: options têm value=nome mas há data-id → busca pelo data-id para evitar
        // problemas de formatação de nome (GLPI pode retornar ordem diferente de users.php)
        if (d.requerente_id) {
          const selReq = document.getElementById('ev-requerente');
          if (selReq) {
            const opt = selReq.querySelector(`option[data-id="${d.requerente_id}"]`);
            if (opt) {
              selReq.value = opt.value;
            } else if (d.requerente) {
              // Requerente não está na lista → insere dinamicamente
              const novaOpt = new Option(d.requerente, d.requerente);
              novaOpt.dataset.id = d.requerente_id;
              selReq.insertBefore(novaOpt, selReq.options[1]);
              selReq.value = d.requerente;
            }
          }
        }

        // Atualiza setor interno com nome da entidade
        if (d.entidade) document.getElementById('ev-setor').value = d.entidade;

        // Renderiza followups
        const fw = d.followups || [];
        const fwWrap = document.getElementById('ev-followups');
        const fwCampo = document.getElementById('campo-followups');
        if (fw.length) {
          fwWrap.innerHTML = fw.map(f => `
            <div class="followup-item">
              <span class="fw-autor">${escHtml(apelidoAtendente(f.autor))}</span>
              <span class="fw-data">${escHtml(f.data)}</span>
              <div class="fw-texto">${escHtml(f.texto)}</div>
            </div>`).join('');
          fwCampo.style.display = '';
        } else {
          fwWrap.innerHTML = '';
          fwCampo.style.display = 'none';
        }

        // Renderiza anexos
        const docs = d.docs || [];
        const anexosWrap = document.getElementById('ev-anexos');
        const anexosCampo = document.getElementById('campo-anexos');
        if (docs.length) {
          anexosWrap.innerHTML = '';
          docs.forEach(doc => {
            if (doc.isImg) {
              const img = document.createElement('img');
              img.className = 'anexo-thumb';
              img.alt = doc.nome;
              img.title = doc.nome;
              img.src = '../glpi_doc_proxy.php?docid=' + doc.id;
              img.onerror = () => img.remove();
              img.onclick = () => abrirLbModal(img.src);
              anexosWrap.appendChild(img);
            } else {
              const a = document.createElement('a');
              a.className = 'anexo-file';
              a.href = '#';
              a.title = doc.nome;
              a.innerHTML = `<i class="bi bi-file-earmark"></i>${escHtml(doc.nome.length > 22 ? doc.nome.slice(0,20)+'â€¦' : doc.nome)}`;
              a.onclick = e => { e.preventDefault(); window.open('../glpi_doc_proxy.php?docid=' + doc.id); };
              anexosWrap.appendChild(a);
            }
          });
          anexosCampo.style.display = '';
        } else {
          anexosWrap.innerHTML = '';
          anexosCampo.style.display = 'none';
        }
      })
      .catch(() => {});
  }

  // Atualiza modo single/multi e chips (N?ƒO chama ajustarDuracaoPorTipo aqui —
  // a duração e o ev-end já foram sincronizados pela IIFE acima com os dados reais do evento)
  document.getElementById('ev-atendente').style.display        = 'none';
  document.getElementById('ev-atendentes-multi').style.display = '';
  document.getElementById('label-atendente').innerHTML = 'Atendentes <span class="text-danger">*</span>';
  const selecionados = dados.atendentes_lista || (dados.atendente ? [dados.atendente] : []);
  renderAtendentesMulti(selecionados);
}

function ajustarCamposPorTipo() {
  const tipo      = document.getElementById('ev-tipo').value;
  const reuniao   = tipo === 'reuniao';
  const evento    = tipo === 'evento';
  const isChamado = tipo === 'chamado' || tipo === 'requisicao';

  // Prioridade: oculta em reunião e evento
  document.getElementById('campo-prioridade').style.display  = (reuniao || evento) ? 'none' : '';
  // Atendentes: oculta apenas em evento; ajusta largura por tipo
  const atCampo = document.getElementById('campo-atendentes');
  atCampo.style.display = evento ? 'none' : '';
  // Chamado/Requisição → col-md-6 (ao lado da Entidade); Reunião → col-12 (linha inteira)
  atCampo.className = atCampo.className.replace(/\bcol-\S+/g, '').trim()
    + (isChamado ? ' col-md-6' : ' col-12');
  // Chips sempre visíveis, dropdown sempre oculto
  document.getElementById('ev-atendente').style.display        = 'none';
  document.getElementById('ev-atendentes-multi').style.display = '';
  // Entidade, Requerente, Categoria e Origem: apenas em chamado e requisição
  document.getElementById('campo-entidade').style.display   = isChamado ? '' : 'none';
  document.getElementById('campo-requerente').style.display = isChamado ? '' : 'none';
  document.getElementById('campo-categoria').style.display  = isChamado ? '' : 'none';
  document.getElementById('campo-origem').style.display     = isChamado ? '' : 'none';
  // Asterisco de Descrição: obrigatório apenas em chamado/requisição
  const starDesc = document.getElementById('star-descricao');
  if (starDesc) starDesc.style.display = isChamado ? '' : 'none';

  // Atualiza título do modal
  const titulo = document.getElementById('modalTitulo');
  const icone  = titulo.innerHTML.includes('bi-eye') ? 'bi-eye'
               : titulo.innerHTML.includes('bi-pencil') ? 'bi-pencil'
               : 'bi-calendar-plus';
  const acoes  = { 'bi-pencil': 'Editar', 'bi-eye': 'Ver', 'bi-calendar-plus': 'Novo' };
  const acao   = acoes[icone] || 'Novo';

  if (reuniao) {
    titulo.innerHTML = `<i class="bi ${icone} me-2"></i>${acao} Reunião`;
  } else if (evento) {
    titulo.innerHTML = `<i class="bi ${icone} me-2"></i>${acao} Evento`;
  }
}

function ajustarDuracaoPorTipo() {
  const tipo = document.getElementById('ev-tipo').value;
  // Reseta o select de duração para o padrão do tipo e recalcula o Fim
  document.getElementById('ev-duracao').value = String(duracaoPadrao(tipo));
  aoMudarInicio();
  // Sempre usa multi-select para todos os tipos
  document.getElementById('ev-atendente').style.display         = 'none';
  document.getElementById('ev-atendentes-multi').style.display  = '';
  document.getElementById('label-atendente').innerHTML = 'Atendentes <span class="text-danger">*</span>';
}

function renderAtendentesMulti(selecionados) {
  const wrap = document.getElementById('lista-atendentes-multi');
  wrap.innerHTML = atendentes.map(a => {
    const sel = selecionados.includes(a.nome);
    return `<div class="atendente-multi-chip ${sel ? 'selected' : ''}"
                 data-nome="${escHtml(a.nome)}" data-id="${a.id}" data-cor="${a.cor}"
                 title="${escHtml(a.nome)}"
                 onclick="toggleChip(this)">
              <span class="dot" style="background:${a.cor}"></span>
              ${escHtml(apelidoAtendente(a.nome))}
            </div>`;
  }).join('');
}

function toggleChip(el) {
  el.classList.toggle('selected');
}

function getAtendentesMultiSelecionados() {
  return [...document.querySelectorAll('.atendente-multi-chip.selected')].map(el => ({
    nome: el.dataset.nome,
    id:   parseInt(el.dataset.id),
    cor:  el.dataset.cor,
  })).filter(a => a.id && !isNaN(a.id)); // descarta chips sem ID válido
}

// ──────────────────────────────────────────
// Modal de Resposta ao Chamado
// ──────────────────────────────────────────
let modalResposta;
let arquivosAnexos = [];
let arquivosAnexosCriar = [];
// Snapshot do evento capturado no momento de abrir o modal de resposta.
// Evita race condition com verificarAtrasados() que pode remover o evento
// do store do FullCalendar enquanto o usuário está preenchendo a resposta.
let _eventoParaResponder = null;

// modalResposta inicializado no DOMContentLoaded principal (abaixo)

function colarImagemClipboard(e) {
  const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
  if (!items) return false;
  const imagens = [];
  for (const item of items) {
    if (!item.type.startsWith('image/')) continue;
    const blob = item.getAsFile();
    if (!blob) continue;
    const ext  = item.type.split('/')[1] || 'png';
    const nome = `print_${Date.now()}.${ext}`;
    imagens.push(new File([blob], nome, { type: item.type }));
  }
  if (!imagens.length) return false;
  adicionarArquivos(imagens);
  // Flash visual na drop zone para feedback
  const dz = document.getElementById('dropZone');
  dz.classList.add('dragover');
  setTimeout(() => dz.classList.remove('dragover'), 600);
  return true;
}

function colarImagemClipboardCriar(e) {
  const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
  if (!items) return false;
  const imagens = [];
  for (const item of items) {
    if (!item.type.startsWith('image/')) continue;
    const blob = item.getAsFile();
    if (!blob) continue;
    const ext  = item.type.split('/')[1] || 'png';
    const nome = `print_${Date.now()}.${ext}`;
    imagens.push(new File([blob], nome, { type: item.type }));
  }
  if (!imagens.length) return false;
  adicionarArquivosCriar(imagens);
  const dz = document.getElementById('dropZoneCriar');
  dz.classList.add('dragover');
  setTimeout(() => dz.classList.remove('dragover'), 600);
  return true;
}

function abrirModalResposta() {
  const ticketId = document.getElementById('ev-ticket-id').value;
  const titulo   = document.getElementById('ev-titulo').value;
  if (!ticketId) return;

  // ── Captura snapshot do evento ANTES de fechar o modal.
  //    Fontes em cascata: FC store → _dropCache (drag recente) → _dadosModal (form).
  //    Nunca fica null — garante que enviarResposta() sempre tenha dados para o
  //    INSERT de fallback caso verificarAtrasados() tenha deletado o evento do DB.
  const evId  = document.getElementById('ev-id').value;
  const evCal = evId ? calendar.getEventById(evId) : null;
  const cache = _dropCache[evId] || {};
  const modal = _dadosModal    || {};
  _eventoParaResponder = {
    id:        evCal?.id        || evId                           || modal.id        || '',
    titulo:    evCal?.title     || cache.titulo                   || modal.titulo    || '',
    start:     evCal?.startStr  || cache.start                   || modal.start     || '',
    end:       evCal?.endStr    || evCal?.startStr || cache.end  || modal.end       || modal.start || '',
    tipo:      evCal?.extendedProps.tipo       || cache.tipo       || modal.tipo       || 'chamado',
    prioridade:evCal?.extendedProps.prioridade || cache.prioridade || modal.prioridade || 'media',
    setor:     evCal?.extendedProps.setor      || cache.setor      || modal.setor      || '',
    descricao: evCal?.extendedProps.descricao  || cache.descricao  || modal.descricao  || '',
    atendente: evCal?.extendedProps.atendente  || cache.atendente  || modal.atendente  || '',
    atendente_id:  evCal?.extendedProps.atendente_id  ?? cache.atendente_id  ?? null,
    atendente_cor: evCal?.extendedProps.atendente_cor || cache.atendente_cor || '#1a73e8',
    ticket_id: evCal?.extendedProps.ticket_id  || cache.ticket_id  || modal.ticket_id  || null,
  };

  document.getElementById('resp-ticket-id').value   = ticketId;
  document.getElementById('resp-chamado-label').textContent = `#${ticketId} — ${titulo}`;
  document.getElementById('resp-texto').value         = '';
  document.getElementById('resp-arquivos').value      = '';
  document.getElementById('lista-arquivos').innerHTML = '';
  document.getElementById('resp-concluido').checked   = false;
  arquivosAnexos = [];

  modalEvento.hide();
  setTimeout(() => modalResposta.show(), 300);
}

function listarArquivos() {
  adicionarArquivos(document.getElementById('resp-arquivos').files);
}

function adicionarArquivos(files) {
  for (const f of files) {
    if (arquivosAnexos.find(a => a.name === f.name)) continue;
    arquivosAnexos.push(f);
  }
  renderizarArquivos();
}

function renderizarArquivos() {
  const lista = document.getElementById('lista-arquivos');
  lista.innerHTML = '';
  arquivosAnexos.forEach((f, i) => {
    const isImg = f.type.startsWith('image/');
    const chip  = document.createElement('div');
    const nome  = f.name.length > 18 ? f.name.slice(0,16) + 'â€¦' : f.name;

    if (isImg) {
      chip.className = 'arquivo-chip chip-img';
      const thumb = document.createElement('img');
      thumb.className = 'chip-thumb';
      thumb.title = f.name;
      const reader = new FileReader();
      reader.onload = ev => {
        thumb.src = ev.target.result;
        thumb.onclick = () => abrirLightbox(ev.target.result);
      };
      reader.readAsDataURL(f);

      // footer via DOM — não usar innerHTML+= (destrói o nó thumb já inserido)
      const footer = document.createElement('div');
      footer.className = 'chip-footer';
      const span = document.createElement('span');
      span.title = f.name;
      span.style.cssText = 'flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap';
      span.textContent = nome;
      const rm = document.createElement('i');
      rm.className = 'bi bi-x rm';
      rm.onclick = () => removerArquivo(i);
      footer.appendChild(span);
      footer.appendChild(rm);

      chip.appendChild(thumb);
      chip.appendChild(footer);
    } else {
      chip.className = 'arquivo-chip';
      chip.innerHTML = `
        <i class="bi bi-file-earmark text-secondary"></i>
        <span title="${escHtml(f.name)}">${escHtml(nome)}</span>
        <i class="bi bi-x rm" onclick="removerArquivo(${i})"></i>`;
    }
    lista.appendChild(chip);
  });
}

function abrirLightbox(src) {
  document.getElementById('imgLightboxSrc').src = src;
  document.getElementById('imgLightbox').classList.add('open');
}
function fecharLightbox() {
  document.getElementById('imgLightbox').classList.remove('open');
  document.getElementById('imgLightboxSrc').src = '';
}

function removerArquivo(i) {
  arquivosAnexos.splice(i, 1);
  renderizarArquivos();
}

// ── Upload de arquivos na criação do chamado ──

function listarArquivosCriar() {
  adicionarArquivosCriar(document.getElementById('ev-arquivos').files);
}

function adicionarArquivosCriar(files) {
  for (const f of files) {
    if (arquivosAnexosCriar.find(a => a.name === f.name)) continue;
    arquivosAnexosCriar.push(f);
  }
  renderizarArquivosCriar();
}

function renderizarArquivosCriar() {
  const lista = document.getElementById('lista-arquivos-criar');
  lista.innerHTML = '';
  arquivosAnexosCriar.forEach((f, i) => {
    const isImg = f.type.startsWith('image/');
    const chip  = document.createElement('div');
    const nome  = f.name.length > 18 ? f.name.slice(0,16) + 'â€¦' : f.name;

    if (isImg) {
      chip.className = 'arquivo-chip chip-img';
      const thumb = document.createElement('img');
      thumb.className = 'chip-thumb';
      thumb.title = f.name;
      const reader = new FileReader();
      reader.onload = ev => {
        thumb.src = ev.target.result;
        thumb.onclick = () => abrirLightbox(ev.target.result);
      };
      reader.readAsDataURL(f);

      const footer = document.createElement('div');
      footer.className = 'chip-footer';
      const span = document.createElement('span');
      span.title = f.name;
      span.style.cssText = 'flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap';
      span.textContent = nome;
      const rm = document.createElement('i');
      rm.className = 'bi bi-x rm';
      rm.onclick = () => removerArquivoCriar(i);
      footer.appendChild(span);
      footer.appendChild(rm);

      chip.appendChild(thumb);
      chip.appendChild(footer);
    } else {
      chip.className = 'arquivo-chip';
      chip.innerHTML = `
        <i class="bi bi-file-earmark text-secondary"></i>
        <span title="${escHtml(f.name)}">${escHtml(nome)}</span>
        <i class="bi bi-x rm" onclick="removerArquivoCriar(${i})"></i>`;
    }
    lista.appendChild(chip);
  });
}

function removerArquivoCriar(i) {
  arquivosAnexosCriar.splice(i, 1);
  renderizarArquivosCriar();
}

async function enviarResposta() {
  const ticketId = document.getElementById('resp-ticket-id').value;
  const texto    = document.getElementById('resp-texto').value.trim();
  if (!texto) { alert('Digite uma resposta antes de enviar.'); return; }

  const btn = document.getElementById('btnEnviarResposta');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Enviando...';

  const form = new FormData();
  form.append('ticket_id', ticketId);
  form.append('resposta',  texto);
  arquivosAnexos.forEach(f => form.append('arquivos[]', f));

  const concluido = document.getElementById('resp-concluido').checked;
  // Snapshot capturado fora do bloco if/else para garantir escopo acessível em todo o try/catch
  const snap = _eventoParaResponder;

  try {
    const res  = await fetch('responder_ticket.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.ok) {
      const extra = data.anexos > 0 ? ` com ${data.anexos} anexo(s)` : '';

      if (concluido) {
        // Fecha o chamado no GLPI
        const resFechar = await fetch('fechar_ticket.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ticket_id: parseInt(ticketId) }),
        });
        const dataFechar = await resFechar.json();

        // ── Marca o evento como concluído ────────────────────────────────
        // Estratégia em 2 etapas para resistir a race conditions com verificarAtrasados():
        //   1) UPDATE cirúrgico (inclui atendente do logado para rotinas sem dono)
        //   2) Fallback: re-INSERT completo se o evento foi removido do DB
        const concluirRes = await fetch('eventos.php?action=concluir_ticket', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            ticket_id:    parseInt(ticketId),
            atendente:    snap?.atendente     || '',
            atendente_id: snap?.atendente_id  ?? null,
            atendente_cor:snap?.atendente_cor || '#1a73e8',
          }),
        }).then(r => r.json()).catch(() => ({ updated: 0 }));

        // Se updated=0, o evento foi deletado do DB (verificarAtrasados) → recria com concluido=1
        // Usa parseInt(ticketId) em vez de snap.ticket_id por consistência com o request anterior
        if (!concluirRes.updated && snap && snap.id) {
          await salvarEventoObjAsync({
            id:           snap.id,
            titulo:       snap.titulo,
            start:        snap.start,
            end:          snap.end || snap.start,
            tipo:         snap.tipo,
            prioridade:   snap.prioridade,
            setor:        snap.setor,
            descricao:    snap.descricao,
            atendente:    snap.atendente,
            atendente_id: snap.atendente_id,
            atendente_cor:snap.atendente_cor,
            ticket_id:    parseInt(ticketId),
            concluido:    1,
          });
        }

        toast(dataFechar.ok
          ? `🔒 Resposta enviada e chamado #${ticketId} fechado${extra}!`
          : `✅ Resposta enviada${extra} (erro ao fechar chamado)`);
      } else {
        toast(`✅ Resposta enviada ao chamado #${ticketId}${extra}!`);
      }

      modalResposta.hide();
      calendar.refetchEvents();
      carregarTickets();
    } else {
      // Exibe o detalhe retornado pelo GLPI para facilitar diagnóstico
      const detalhe = data.detail ? '\n\nDetalhe GLPI: ' + JSON.stringify(data.detail) : '';
      alert('Erro ao enviar resposta: ' + (data.msg || 'Falha ao enviar') + detalhe);
      console.error('enviarResposta GLPI error:', data);
    }
  } catch(e) {
    alert('Erro ao enviar resposta: ' + e.message);
    console.error('enviarResposta error:', e);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send-fill me-2"></i>Enviar Resposta';
  }
}

function mostrarSalvarSeConcluido() {
  // Exibe o botão Salvar ao interagir com o checkbox mesmo em modo leitura
  const btnSalvar = document.querySelector('#modalEvento .btn-primary');
  btnSalvar.style.display = '';
}

function toggleFecharGlpi() {
  const concluido = document.getElementById('ev-concluido').checked;
  const temTicket = !!document.getElementById('ev-ticket-id').value;
  const campoFechar = document.getElementById('campo-fechar-glpi');
  const fecharCheck = document.getElementById('ev-fechar-glpi');
  if (concluido && temTicket) {
    campoFechar.style.display = '';
    // Auto-marca "Fechar GLPI" — concluir = fechar no GLPI por padrão
    fecharCheck.checked = true;
  } else {
    campoFechar.style.display = 'none';
    if (!concluido) fecharCheck.checked = false;
  }
}

function atualizarPreviewCor() {
  const check = document.getElementById('ev-concluido');
  const label = check.nextElementSibling;
  label.style.color = check.checked ? '#212121' : '';
}

// ── Novo período para o mesmo chamado ─────────────────────────
function novoPeriodo() {
  const tid   = document.getElementById('ev-ticket-id').value;
  const titulo = document.getElementById('ev-titulo').value;
  const setor  = document.getElementById('ev-setor').value;
  const tipo   = document.getElementById('ev-tipo').value;
  const prio   = document.getElementById('ev-prioridade').value;
  const desc   = document.getElementById('ev-descricao').value;
  const aten   = document.getElementById('ev-atendente').value;

  modalEvento.hide();

  document.getElementById('modalEvento').addEventListener('hidden.bs.modal', function handler() {
    this.removeEventListener('hidden.bs.modal', handler);

    document.getElementById('ev-id').value          = '';
    document.getElementById('ev-ticket-id').value   = tid;
    document.getElementById('ev-titulo').value      = titulo;
    document.getElementById('ev-descricao').value   = desc;
    document.getElementById('ev-atendente').value   = aten;
    document.getElementById('ev-prioridade').value  = prio;
    document.getElementById('ev-tipo').value        = tipo;
    document.getElementById('ev-setor').value       = setor;
    document.getElementById('ev-start').value       = '';
    document.getElementById('ev-end').value         = '';
    document.getElementById('ev-orig-start').value  = '';
    document.getElementById('ev-concluido').checked  = false;
    document.getElementById('ev-fechar-glpi').checked = false;
    document.getElementById('campo-fechar-glpi').style.display = 'none';
    document.getElementById('lista-arquivos-criar').innerHTML = '';
    arquivosAnexosCriar = [];
    ajustarCamposPorTipo();
    ajustarDuracaoPorTipo();

    document.getElementById('btnDeletar').style.display     = 'none';
    document.getElementById('btnResponder').style.display   = 'none';
    document.getElementById('btnNovoPeriodo').style.display = 'none';
    document.getElementById('banner-readonly').style.display = 'none';
    document.getElementById('modalTitulo').innerHTML =
      `<i class="bi bi-clock-history me-2"></i>Novo período — #${tid}`;
    setModoLeitura(false);

    toast(`📅 Novo período para o chamado #${tid} — informe a data e horário.`);
    modalEvento.show();
  });
}

function abrirModalSemLimpar() {
  document.getElementById('btnDeletar').style.display     = 'none';
  document.getElementById('btnResponder').style.display   = 'none';
  document.getElementById('btnNovoPeriodo').style.display = 'none';
  document.getElementById('modalTitulo').innerHTML = '<i class="bi bi-calendar-plus me-2"></i>Agendar Chamado';
  document.getElementById('lista-arquivos-criar').innerHTML = '';
  arquivosAnexosCriar = [];
  setModoLeitura(false);
  modalEvento.show();
}

function editarEvento(ev) {
  limparValidacao();
  const c = _dropCache[ev.id] || {}; // fallback para eventos recém-arrastados (antes do refetch)
  preencherModal({
    id:        ev.id,
    titulo:    ev.title,
    start:     ev.startStr,
    end:       ev.endStr || ev.startStr,
    prioridade:ev.extendedProps.prioridade || c.prioridade,
    tipo:      ev.extendedProps.tipo       || c.tipo || 'chamado',
    setor:     ev.extendedProps.setor      || c.setor,
    descricao: ev.extendedProps.descricao  || c.descricao,
    atendente: ev.extendedProps.atendente  || c.atendente,
    ticket_id: ev.extendedProps.ticket_id  || c.ticket_id,
    concluido: ev.extendedProps.concluido,
  });
  const concluido = ev.extendedProps.concluido;
  const isGoogle = ev.extendedProps.google === true;

  // Abre em modo LEITURA; se concluído esconde o botão Editar
  setModoLeitura(true);
  document.getElementById('banner-readonly').querySelector('button').style.display =
    concluido ? 'none' : '';

  // ── Google Calendar: modo somente-leitura especial ──
  const googleInfo = document.getElementById('google-info');
  if (isGoogle) {
    // Popula descrição
    const desc = ev.extendedProps.descricao || '';
    document.getElementById('gcal-descricao').textContent = desc || '(sem descrição)';

    // Local
    const local = ev.extendedProps.local || '';
    document.getElementById('gcal-local-text').textContent = local;
    document.getElementById('gcal-local').style.display = local ? '' : 'none';

    // Link da reunião
    const meetUrl = ev.extendedProps.meet_url || '';
    const gcalMeet = document.getElementById('gcal-meet');
    if (meetUrl) {
      document.getElementById('gcal-meet-link').href = meetUrl;
      gcalMeet.style.display = '';
    } else {
      gcalMeet.style.display = 'none';
    }

    // Participantes
    const participantes = ev.extendedProps.participantes || [];
    const lista = document.getElementById('gcal-participantes-lista');
    const gcalPart = document.getElementById('gcal-participantes');
    if (participantes.length) {
      lista.innerHTML = participantes.map(p => {
        const initial = (p.cn || p.email || '?')[0].toUpperCase();
        return `<div class="gcal-participante">
          <span class="avatar">${initial}</span>
          <span><strong>${escHtml(p.cn || '')}</strong>${p.email ? ' <span class="email">' + escHtml(p.email) + '</span>' : ''}</span>
        </div>`;
      }).join('');
      gcalPart.style.display = '';
    } else {
      gcalPart.style.display = 'none';
    }

    // Exibe painel Google, esconde formulário principal e banner
    googleInfo.style.display = '';
    document.getElementById('campos-evento').style.display = 'none';
    document.getElementById('banner-readonly').style.display = 'none';

    // Esconde todos os botões de ação do footer
    document.getElementById('btnDeletar').style.display = 'none';
    document.getElementById('btnResponder').style.display = 'none';
    document.getElementById('btnNovoPeriodo').style.display = 'none';
    document.querySelector('#modalEvento .btn-secondary')?.style.setProperty('display', 'none');
    document.querySelector('#modalEvento .btn-primary')?.style.setProperty('display', 'none');

    document.getElementById('modalTitulo').innerHTML = '<i class="bi bi-google me-2" style="color:#7b2d8e;"></i>Google Calendar';
  } else {
    // Restaura visibilidade para eventos normais
    googleInfo.style.display = 'none';
    document.getElementById('campos-evento').style.display = '';
    document.querySelector('#modalEvento .btn-secondary')?.style.removeProperty('display');

    // Botões normais (concluído controla visibilidade)
    document.getElementById('btnDeletar').style.display     = concluido ? 'none' : 'inline-block';
    document.getElementById('btnResponder').style.display   = (ev.extendedProps.ticket_id && !concluido) ? 'inline-block' : 'none';
    document.getElementById('btnNovoPeriodo').style.display = (ev.extendedProps.ticket_id && !concluido) ? 'inline-block' : 'none';
    document.getElementById('modalTitulo').innerHTML = '<i class="bi bi-eye me-2"></i>Detalhes do Evento';
  }

  modalEvento.show();
}

function setModoLeitura(ativo) {
  const campos = document.getElementById('campos-evento');
  const banner = document.getElementById('banner-readonly');
  const btnSalvar = document.querySelector('#modalEvento .btn-primary');

  if (ativo) {
    campos.classList.add('modo-leitura');
    banner.style.removeProperty('display'); // mostra banner
    btnSalvar.style.display = 'none';
    document.getElementById('campo-anexos-criar').style.display = 'none';
  } else {
    campos.classList.remove('modo-leitura');
    banner.style.display = 'none';
    btnSalvar.style.display = '';
    document.getElementById('campo-anexos-criar').style.display = '';
    const tipoAtual = document.getElementById('ev-tipo').value;
    document.getElementById('modalTitulo').innerHTML = tipoAtual === 'reuniao'
      ? '<i class="bi bi-pencil me-2"></i>Editar Reunião'
      : '<i class="bi bi-pencil me-2"></i>Editar Evento';
    ajustarCamposPorTipo();
  }
}

function habilitarEdicao() {
  setModoLeitura(false);
}

// ── Controle de Duração ───────────────────────────────────────────────────────
// aoMudarInicio: recalcula Fim mantendo a duração selecionada
function aoMudarInicio() {
  const dur = parseInt(document.getElementById('ev-duracao').value);
  if (dur > 0) {
    const start = document.getElementById('ev-start').value;
    if (start) {
      document.getElementById('ev-end').value =
        toDatetimeLocal(new Date(new Date(start).getTime() + dur));
    }
  }
}

// aoMudarDuracao: ao trocar o select de duração, recalcula Fim
function aoMudarDuracao() {
  const dur = parseInt(document.getElementById('ev-duracao').value);
  // "Personalizado" (0): o usuário digita o Fim manualmente — deixa como está
  if (dur > 0) aoMudarInicio();
}

// aoMudarFim: quando o usuário edita o Fim manualmente, marca como Personalizado
// e atualiza o select para refletir o novo valor (se coincidir com opção padrão)
function aoMudarFim() {
  const start = document.getElementById('ev-start').value;
  const end   = document.getElementById('ev-end').value;
  if (!start || !end) return;
  const diffMs = new Date(end).getTime() - new Date(start).getTime();
  if (diffMs <= 0) return;
  const sel    = document.getElementById('ev-duracao');
  const match  = Array.from(sel.options).find(o => +o.value === diffMs);
  sel.value = match ? match.value : '0'; // 0 = Personalizado
}

// ── Helpers de validação do modal ────────────────────────────────────────────
function limparValidacao() {
  document.querySelectorAll('#campos-evento .is-invalid').forEach(el => el.classList.remove('is-invalid'));
  const banner = document.getElementById('modal-erros');
  if (banner) banner.remove();
}

function marcarInvalido(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add('is-invalid');
}

function mostrarErroModal(erros) {
  const body = document.querySelector('#modalEvento .modal-body');
  let banner = document.getElementById('modal-erros');
  if (!banner) {
    banner = document.createElement('div');
    banner.id = 'modal-erros';
    body.prepend(banner);
  }
  banner.className = 'alert alert-danger py-2 px-3 mb-0 mt-1';
  banner.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>'
    + '<strong>Campos obrigatórios:</strong> ' + erros.join(' · ');
}

function salvarEvento() {
  const titulo   = document.getElementById('ev-titulo').value.trim();
  const start    = document.getElementById('ev-start').value;
  const tipo     = document.getElementById('ev-tipo').value;
  const multiSel = getAtendentesMultiSelecionados();

  // Garante que o ev-end esteja atualizado com a duração selecionada antes de qualquer leitura
  aoMudarInicio();
  const end = document.getElementById('ev-end').value;

  // ── Validação de campos obrigatórios ──────────────────────────────────
  limparValidacao();
  const errosVal = [];
  const isChamadoOuReq = (tipo === 'chamado' || tipo === 'requisicao');

  if (!titulo) { errosVal.push('Título');  marcarInvalido('ev-titulo'); }
  if (!start)    errosVal.push('Início');
  if (!end)      errosVal.push('Fim');

  if (isChamadoOuReq) {
    const _desc     = document.getElementById('ev-descricao').value.trim();
    const _entId    = document.getElementById('ev-entidade').selectedOptions[0]?.dataset?.id || '';
    const _reqId    = document.getElementById('ev-requerente').selectedOptions[0]?.dataset?.id || '';
    // Todos os tipos usam chips — verifica multiSel
    const _temAtend = multiSel.length > 0;
    if (!_desc)     { errosVal.push('Descrição');  marcarInvalido('ev-descricao'); }
    if (!_entId)    { errosVal.push('Entidade');   marcarInvalido('ev-entidade'); }
    if (!_temAtend) { errosVal.push('Atendente');  marcarInvalido('lista-atendentes-multi'); }
    if (!_reqId)    { errosVal.push('Requerente'); marcarInvalido('ev-requerente'); }
  }

  if (errosVal.length > 0) { mostrarErroModal(errosVal); return; }
  // ── fim validação ─────────────────────────────────────────────────────

  const atendenteEl  = document.getElementById('ev-atendente');
  const atendenteOpt = atendenteEl.selectedOptions[0];
  const cor          = atendenteOpt?.dataset?.cor || '#1a73e8';
  const atendId      = atendenteOpt?.dataset?.id  || null;

  // Garante que o ID seja string não-vazia (edição) ou null (novo)
  const evId = document.getElementById('ev-id').value.trim() || null;

  const concluido = document.getElementById('ev-concluido').checked ? 1 : 0;

  // Chamado/requisição com 2+ técnicos: fluxo multi-atendente (cria ticket + um evento por técnico).
  // Reunião/evento com 1+ técnico: fluxo multi-atendente (um evento por técnico, sem ticket GLPI).
  // Chamado/requisição com 1 técnico: caminho único clássico.
  const qtdTecs = multiSel.length;
  const isMulti = qtdTecs > 1 || (!isChamadoOuReq && qtdTecs > 0);

  // Para chamado/requisição com 1 técnico: compatibilidade retroativa
  const primeiroChip = isChamadoOuReq && qtdTecs > 0 && !isMulti ? multiSel[0] : null;

  // Categoria: options têm value=id (direto)
  const categoriaId = parseInt(document.getElementById('ev-categoria').value) || null;
  // Entidade: options têm data-id no elemento selecionado
  const entidadeEl  = document.getElementById('ev-entidade');
  const entidadeId  = entidadeEl.selectedOptions[0]?.dataset?.id
                      ? parseInt(entidadeEl.selectedOptions[0].dataset.id) : null;
  // Requerente: options têm data-id
  const requerenteEl = document.getElementById('ev-requerente');
  const requerenteId = requerenteEl.selectedOptions[0]?.dataset?.id
                       ? parseInt(requerenteEl.selectedOptions[0].dataset.id) : null;
  // Origem
  const origemId = parseInt(document.getElementById('ev-origem').value) || null;

  const dadosBase = {
    id:            evId,
    titulo,
    start,
    end,
    atendente:     primeiroChip ? primeiroChip.nome : atendenteEl.value,
    atendente_id:  primeiroChip ? primeiroChip.id   : atendId,
    atendente_cor: primeiroChip ? primeiroChip.cor  : cor,
    prioridade:    document.getElementById('ev-prioridade').value,
    setor:         document.getElementById('ev-setor').value,
    descricao:     document.getElementById('ev-descricao').value,
    ticket_id:     document.getElementById('ev-ticket-id').value.trim() || null,
    orig_start:    document.getElementById('ev-orig-start').value || '',
    tipo,
    concluido,
    fechar_glpi:   document.getElementById('ev-fechar-glpi').checked ? 1 : 0,
    // Campos GLPI extras (categoria, entidade, requerente, origem)
    categoria_id:  categoriaId,
    entidade_id:   entidadeId,
    requerente_id: requerenteId,
    origem_id:     origemId,
  };
  // Para tipo 'evento' ou 'reuniao': se nenhum atendente foi selecionado, atribui automaticamente ao criador
  if ((tipo === 'evento' || tipo === 'reuniao') && !dadosBase.atendente) {
    const me = atendentes.find(a => a.id === USUARIO_LOGADO_ID) || atendentes.find(a => a.nome === USUARIO_LOGADO_NOME);
    if (me) {
      dadosBase.atendente     = me.nome;
      dadosBase.atendente_id  = me.id;
      dadosBase.atendente_cor = me.cor;
    } else {
      dadosBase.atendente    = USUARIO_LOGADO_NOME;
      dadosBase.atendente_id = USUARIO_LOGADO_ID;
    }
  }

  const dados = dadosBase;

  // ── Fluxo multi-atendente: 2+ técnicos (chamado) ou 1+ técnico (evento/reunião) ──
  if (isMulti) {
    const btnSalvar2 = document.querySelector('#modalEvento .btn-primary');
    btnSalvar2.disabled = true;
    btnSalvar2.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Salvando...';
    const reativar = () => { btnSalvar2.disabled = false; btnSalvar2.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvar'; };
    const finalizarMulti = (ticket_id) => {
      // Se edição (tem evId) → deleta o evento antigo e recria (só para evento, chamado edita in-line)
      const mapTech = (a, i) => Object.assign({}, dadosBase, {
        id:       (!isChamadoOuReq && i === 0 && evId) ? evId : uniqEvId(),
        atendente:     a.nome,
        atendente_id:  a.id,
        atendente_cor: a.cor,
        ticket_id:     ticket_id || dadosBase.ticket_id,
        // Chamado criado no GLPI já tem todos os técnicos — pula atribuição extra
        _skipGlpi:     isChamadoOuReq,
      });
      const dadosSalvos = multiSel.map(mapTech);
      console.log('📊 Multi-tech save:', dadosSalvos.map(d => ({atendente: d.atendente, atendente_id: d.atendente_id, ticket_id: d.ticket_id, id: d.id})));
      const promises = dadosSalvos.map(d => salvarEventoObj(d));
      Promise.all(promises).then(() => {
        modalEvento.hide();
        calendar.refetchEvents();
        carregarTickets();
        reativar();
        const verb = isChamadoOuReq ? 'Chamado' : 'Evento';
        toast(`✅ ${verb} salvo para ${multiSel.length} atendente(s).`);
      }).catch(err => {
        console.error('âŒ Multi-tech save falhou:', err);
        // Mesmo com erro, tenta recarregar o que foi salvo
        calendar.refetchEvents();
        carregarTickets();
        reativar();
        alert('Erro ao salvar para um ou mais atendentes. Verifique o console (F12).');
      });
    };

    if (isChamadoOuReq && !dadosBase.ticket_id) {
      // Novo chamado com 2+ técnicos → cria ticket no GLPI com todos
      const catId  = parseInt(document.getElementById('ev-categoria').value) || null;
      const entEl  = document.getElementById('ev-entidade');
      const entId  = entEl.selectedOptions[0]?.dataset?.id ? parseInt(entEl.selectedOptions[0].dataset.id) : null;
      const reqEl  = document.getElementById('ev-requerente');
      const reqId  = reqEl.selectedOptions[0]?.dataset?.id ? parseInt(reqEl.selectedOptions[0].dataset.id) : null;
      const orgId  = parseInt(document.getElementById('ev-origem').value) || null;

      fetch('criar_ticket.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          titulo:         dadosBase.titulo,
          descricao:      dadosBase.descricao,
          tipo:           dadosBase.tipo,
          prioridade:     dadosBase.prioridade,
          atendentes_ids: multiSel.map(a => a.id),
          categoria_id:   catId,
          entidade_id:    entId,
          requerente_id:  reqId,
          origem_id:      orgId,
        }),
      })
      .then(r => r.json())
      .then(res => {
        if (res.ok) {
          toast(`🎫 Chamado #${res.ticket_id} criado no GLPI!`);
          uploadAnexosCriar(res.ticket_id).then(() => {
            finalizarMulti(res.ticket_id);
          });
        } else {
          alert('Erro ao criar chamado no GLPI: ' + (res.msg || 'Falha desconhecida'));
          reativar();
        }
      })
      .catch(() => {
        alert('Erro de conexão ao criar chamado.');
        reativar();
      });
      return;
    }

    // Chamado já existente → deleta eventos antigos antes de recriar
    if (isChamadoOuReq && dadosBase.ticket_id) {
      fetch('eventos.php?action=deleteByTicket&ticket_id=' + dadosBase.ticket_id)
        .then(r => r.json())
        .then(() => {
          finalizarMulti(null);
        })
        .catch(() => {
          finalizarMulti(null); // Tenta salvar mesmo se delete falhar
        });
      return;
    }

    // Evento/reunião (sem ticket)
    finalizarMulti(null);
    return;
  }

  // Desabilita botão para evitar cliques múltiplos
  const btnSalvar = document.querySelector('#modalEvento .btn-primary');
  btnSalvar.disabled = true;
  btnSalvar.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Salvando...';

  const reativarBtn = () => {
    btnSalvar.disabled = false;
    btnSalvar.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvar';
  };

  const finalizarSalvar = () => {
    modalEvento.hide();
    calendar.refetchEvents();
    carregarTickets();
    reativarBtn();
    // Navega o calendário até a data do evento salvo
    if (start) calendar.gotoDate(start.slice(0, 10));
  };

  // Se é chamado/requisição sem ticket_id → cria no GLPI primeiro
  const precisaCriar = (tipo === 'chamado' || tipo === 'requisicao') && !dados.ticket_id;
  if (precisaCriar) {
    fetch('criar_ticket.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        titulo:        dados.titulo,
        descricao:     dados.descricao,
        tipo:          dados.tipo,
        prioridade:    dados.prioridade,
        atendente_id:  dados.atendente_id  || null,
        categoria_id:  dados.categoria_id  || null,
        entidade_id:   dados.entidade_id   || null,
        requerente_id: dados.requerente_id || null,
        origem_id:     dados.origem_id     || null,
      }),
    })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        dados.ticket_id = res.ticket_id;
        toast(`🎫 Chamado #${res.ticket_id} criado no GLPI!`);
        uploadAnexosCriar(res.ticket_id).then(() => {
          salvarEventoObj(dados, finalizarSalvar);
        });
      } else {
        alert('Erro ao criar chamado no GLPI: ' + (res.msg || 'Falha desconhecida'));
        reativarBtn();
      }
    })
    .catch(() => {
      alert('Erro de conexão ao criar chamado.');
      reativarBtn();
    });
    return;
  }

  salvarEventoObj(dados, finalizarSalvar);
}

function salvarEventoObj(dados, cb) {
  return fetch('eventos.php?action=save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(dados),
  })
  .then(r => r.json())
  .then(res => {
    // Verifica se o PHP retornou erro
    if (!res.ok) {
      console.error('âŒ eventos.php save falhou:', res.error || res, 'dados:', JSON.stringify(dados));
      if (cb) cb();
      return;
    }
    if (!dados.ticket_id) { if (cb) cb(); return; }
    if (dados._skipGlpi)  { if (cb) cb(); return; }

    if (dados.concluido && !dados._only_reposition) {
        // Registra o período como acompanhamento no GLPI (apenas no save do modal, não no drag)
        const fmtDt = s => {
          const d = new Date(String(s).replace(' ', 'T'));
          return {
            dia:  d.toLocaleDateString('pt-BR'),
            hora: d.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'}),
          };
        };
        const ini = fmtDt(dados.start);
        const fim = fmtDt(dados.end || dados.start);
        let msg = `Período de atendimento registrado: ${ini.dia} das ${ini.hora} às ${fim.hora}`;
        if (dados.atendente) msg += ` — ${dados.atendente}`;

        const fd = new FormData();
        fd.append('ticket_id', dados.ticket_id);
        fd.append('resposta', msg);
        fetch('responder_ticket.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(res => {
            if (res.ok) toast(`📝 Período registrado no acompanhamento do #${dados.ticket_id}.`);
          })
          .catch(() => {});
      }

      // âš ï¸ ORDEM DE EXECU?‡?ƒO PROTEGIDA — N?ƒO ALTERAR SEM PERMISS?ƒO DO RESPONSÁVEL âš ï¸
      // REGRA: atualizar_ticket.php deve SEMPRE completar ANTES de fechar_ticket.php.
      // Se rodarem em paralelo, o PUT de campos do atualizar pode chegar ao GLPI DEPOIS
      // do fechamento e reabrir o chamado. O padrão _fecharAposSalvar garante a sequência:
      //   1. atualizar_ticket (PUT campos) → .then() → fechar_ticket (PUT status=6)
      //   2. Se atualizar falhar → .catch() → fechar_ticket de qualquer forma
      if (!dados._only_reposition && (dados.tipo === 'chamado' || dados.tipo === 'requisicao')) {
        const _fecharAposSalvar = () => {
          if (dados.fechar_glpi) {
            fetch('fechar_ticket.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ ticket_id: dados.ticket_id }),
            })
            .then(r => r.json())
            .then(res => {
              if (res.ok) toast(`🔒 Chamado #${dados.ticket_id} fechado no GLPI.`);
            });
          }
        };

        fetch('atualizar_ticket.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            ticket_id:     dados.ticket_id,
            titulo:        dados.titulo?.replace(/#\d+\s*[â€“-]\s*/g, '').trim() || null,
            descricao:     dados.descricao     || null,
            tipo:          dados.tipo          || null,
            prioridade:    dados.prioridade    || null,
            categoria_id:  dados.categoria_id  || null,
            // entities_id N?ƒO é enviado — entidade é definida na criação
            // e NUNCA deve ser alterada por atualização da agenda
            requerente_id: dados.requerente_id || null,
            origem_id:     dados.origem_id     || null,
          }),
        })
        .then(r => r.json())
        .then(res => {
          if (res.ok && res.campos > 0) toast(`🔄 Chamado #${dados.ticket_id} atualizado no GLPI.`);
          _fecharAposSalvar(); // fecha DEPOIS dos campos atualizados
        })
        .catch(() => {
          _fecharAposSalvar(); // garante fechamento mesmo se atualizar falhar
        });

      } else if (dados.fechar_glpi) {
        // Tipo não é chamado/requisição mas tem fechar_glpi (não deveria ocorrer, mas garante)
        fetch('fechar_ticket.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ticket_id: dados.ticket_id }),
        })
        .then(r => r.json())
        .then(res => {
          if (res.ok) toast(`🔒 Chamado #${dados.ticket_id} fechado no GLPI.`);
        });
      }

      // Atribuição de técnico: apenas quando não está fechando e não está concluído
      if (dados.atendente_id && !dados.concluido && !dados.fechar_glpi) {
        fetch('atribuir_ticket.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ticket_id: dados.ticket_id, atendente_id: dados.atendente_id }),
        })
        .then(r => r.json())
        .then(res => {
          if (res.ok) toast(`✅ Chamado #${dados.ticket_id} atribuído a ${dados.atendente} no GLPI.`);
        });
      }
    if (cb) cb();
  })
  .catch(err => {
    console.error('âŒ salvarEventoObj erro:', err, 'dados:', JSON.stringify(dados));
    if (cb) cb();
  });
}

function deletarEvento() {
  const id       = document.getElementById('ev-id').value.trim();
  const ticketId = document.getElementById('ev-ticket-id').value.trim();
  if (!id) { alert('Evento sem ID, não é possível excluir.'); return; }

  // Verifica se é evento multi-atendente (mesmo ticket_id em vários eventos)
  const isMulti = ticketId && todosEventos.filter(e => String(e.extendedProps.ticket_id) === ticketId).length > 1;

  const msg = isMulti
    ? `Este chamado #${ticketId} está agendado para múltiplos atendentes.\nDeseja remover da agenda de TODOS?\n\nO chamado voltará para "Novo" e sem técnico atribuído.`
    : `Excluir este evento da agenda?` + (ticketId ? `\n\nO chamado #${ticketId} voltará para "Novo" e sem técnico atribuído.` : '');

  if (!confirm(msg)) return;

  // Se multi → deleta todos pelo ticket_id; senão → deleta só este evento
  const url = isMulti
    ? `eventos.php?action=deleteByTicket&ticket_id=${encodeURIComponent(ticketId)}`
    : `eventos.php?action=delete&id=${encodeURIComponent(id)}`;

  fetch(url)
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        // Remove imediatamente do calendário (eventos de drag ficam em fonte separada
        // e o refetchEvents() sozinho não os remove — precisa do remove() explícito)
        if (isMulti) {
          todosEventos
            .filter(e => String(e.extendedProps.ticket_id) === ticketId)
            .forEach(e => calendar.getEventById(e.id)?.remove());
        } else {
          calendar.getEventById(id)?.remove();
        }
        delete _dropCache[id];

        modalEvento.hide();
        calendar.refetchEvents();

        if (ticketId) {
          // Aguarda o reset no GLPI antes de recarregar a sidebar:
          // resetar_ticket.php muda o status para Novo (1); se chamarmos
          // carregarTickets() antes, o GLPI ainda retorna status=6 (Fechado)
          // e o chamado fica de fora da lista por estar em $status_excluidos.
          fetch('resetar_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: parseInt(ticketId) }),
          })
          .then(r => r.json())
          .then(r => {
            if (r.ok) toast(`â†©ï¸ Chamado #${ticketId} voltou para Novo e sem técnico.`);
            carregarTickets(); // sidebar atualizada AP?“S o GLPI ter status=1
          })
          .catch(() => carregarTickets()); // garante reload mesmo se resetar falhar
        } else {
          carregarTickets(); // evento puro (sem ticket) → recarrega imediatamente
        }
      } else {
        alert('Erro ao excluir: ' + (res.error || 'desconhecido'));
      }
    });
}

// ──────────────────────────────────────────
// Utilitários
// ──────────────────────────────────────────
function calView(view) {
  calendar.changeView(view);
  document.querySelectorAll('.nav-view-btn').forEach(b => b.classList.remove('active'));
  const map = { dayGridMonth: 'vbtn-month', timeGridWeek: 'vbtn-week', timeGridDay: 'vbtn-day' };
  document.getElementById(map[view])?.classList.add('active');
}

function urgToProioridade(urg) {
  const m = {'muito baixa':'baixa','baixa':'baixa','média':'media','alta':'alta','muito alta':'critica'};
  return m[urg] || 'media';
}

function toDatetimeLocal(d) {
  const pad = n => String(n).padStart(2,'0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function escHtml(str) {
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Sync Rotinas ──────────────────────────────────────────────
// Chamado pelo botão manual E automaticamente no carregamento da agenda.
// O script PHP já ignora chamados já agendados hoje (idempotente).
function syncRotinas(manual = false) {
  const btn = document.querySelector('[onclick*="syncRotinas"]');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sincronizando...';
  }

  fetch('sync_rotinas_ajax.php')
    .then(r => r.json())
    .then(d => {
      if (d.adicionados > 0) {
        toast(`📋 ${d.adicionados} rotina(s) adicionada(s) à agenda automaticamente.`);
      } else if (manual) {
        toast(`✅ Rotinas já sincronizadas (${d.ignorados} já estavam na agenda).`);
      }
    })
    .catch(() => { if (manual) toast('âš ï¸ Erro ao sincronizar rotinas.'); })
    .finally(() => {
      // Sempre carrega eventos e tickets ao final do sync (automático ou manual)
      // Garante que rotinas e eventuais remoções do verificarAtrasados sejam refletidos juntos
      calendar.refetchEvents();
      carregarTickets();
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Sync Rotinas';
      }
    });
}
</script>
<!-- Modal Google Calendar -->
<div class="modal fade" id="modalGcal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1a73e8,#0b8043);color:white">
        <h5 class="modal-title">
          <img src="https://www.google.com/favicon.ico" style="width:18px;margin-right:8px;vertical-align:middle"/>
          Google Calendar
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          Cole o link <strong>iCal secreto</strong> do seu Google Calendar para sincronizar seus eventos na agenda.
        </p>

        <div class="alert alert-info small p-2 mb-3">
          <strong>Como obter o link:</strong><br>
          1. Abra <a href="https://calendar.google.com" target="_blank">calendar.google.com</a><br>
          2. Clique nos <strong>3 pontinhos</strong> do seu calendário → <strong>Configurações</strong><br>
          3. Role até <strong>"URL secreta no formato iCal"</strong><br>
          4. Copie e cole aqui abaixo
        </div>

        <label class="form-label fw-semibold">URL iCal do Google Calendar</label>
        <input type="url" id="gcal-url" class="form-control" placeholder="https://calendar.google.com/calendar/ical/..."/>
        <div class="form-text">Seus eventos aparecerão em <span style="color:#0b8043;font-weight:700">verde</span> na agenda.</div>

        <div id="gcal-status" class="mt-2"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-danger btn-sm" onclick="removerGcal()">
          <i class="bi bi-trash me-1"></i>Remover
        </button>
        <button class="btn btn-primary btn-sm" onclick="salvarGcal()">
          <i class="bi bi-check-lg me-1"></i>Salvar e Sincronizar
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// ── Menu hamburguer ───────────────────────────────────────────
function toggleMenu() {
  const menu = document.getElementById('dropdown-menu');
  menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', e => {
  if (!document.getElementById('menu-wrap').contains(e.target)) {
    document.getElementById('dropdown-menu').style.display = 'none';
  }
});

// ── Google Calendar ───────────────────────────────────────────
let modalGcal;
let gcalEventIds = new Set();

document.addEventListener('DOMContentLoaded', () => {
  modalGcal = new bootstrap.Modal(document.getElementById('modalGcal'));
  carregarEventosGcal();
});

function abrirConfigGcal() {
  fetch('google_calendar.php?action=get')
    .then(r => r.json())
    .then(d => {
      document.getElementById('gcal-url').value = d.url || '';
      document.getElementById('gcal-status').innerHTML = d.url
        ? '<span class="badge bg-success">âœ“ Google Calendar conectado</span>'
        : '<span class="text-muted small">Não configurado</span>';
    });
  modalGcal.show();
}

function salvarGcal() {
  const url = document.getElementById('gcal-url').value.trim();
  fetch('google_calendar.php?action=save', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({url})
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      document.getElementById('gcal-status').innerHTML = '<span class="badge bg-success">âœ“ ' + d.msg + '</span>';
      modalGcal.hide();
      carregarEventosGcal();
    } else {
      document.getElementById('gcal-status').innerHTML = '<span class="badge bg-danger">' + d.msg + '</span>';
    }
  });
}

function removerGcal() {
  fetch('google_calendar.php?action=save', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({url: ''})
  })
  .then(r => r.json())
  .then(() => {
    // Remove eventos do Google do calendário
    gcalEventIds.forEach(id => {
      const ev = calendar.getEventById(id);
      if (ev) ev.remove();
    });
    gcalEventIds.clear();
    document.getElementById('gcal-url').value = '';
    document.getElementById('gcal-status').innerHTML = '<span class="text-muted small">Removido</span>';
    modalGcal.hide();
    toast('success', 'Google Calendar desconectado');
  });
}

function carregarEventosGcal() {
  fetch('google_eventos.php')
    .then(r => r.json())
    .then(eventos => {
      if (!Array.isArray(eventos) || eventos.length === 0) return;

      // Remove eventos Google anteriores
      gcalEventIds.forEach(id => {
        const ev = calendar.getEventById(id);
        if (ev) ev.remove();
      });
      gcalEventIds.clear();

      // Adiciona novos eventos do Google
      eventos.forEach(ev => {
        calendar.addEvent(ev);
        gcalEventIds.add(ev.id);
      });

      // Atualiza botão para indicar que está conectado
      document.getElementById('btn-gcal').style.background = '#0b8043';
    })
    .catch(() => {});
}
</script>

<meta name="base-url" content="../"/>
<script src="../assets/notificacoes.js"></script>
<!-- Lightbox de imagens -->
<div id="imgLightbox" onclick="fecharLightbox()">
  <span class="lb-close" onclick="fecharLightbox()">&times;</span>
  <img id="imgLightboxSrc" src="" alt="" onclick="event.stopPropagation()"/>
</div>

<!-- Lightbox para anexos do modal de detalhe -->
<div id="lbModal" onclick="fecharLbModal()">
  <span class="lbx" onclick="fecharLbModal()">&times;</span>
  <img id="lbModalImg" src="" alt="" onclick="event.stopPropagation()"/>
</div>

<script>
function abrirLbModal(src) {
  document.getElementById('lbModalImg').src = src;
  document.getElementById('lbModal').classList.add('open');
}
function fecharLbModal() {
  document.getElementById('lbModal').classList.remove('open');
  document.getElementById('lbModalImg').src = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharLbModal(); });
</script>
</body>
</html>

