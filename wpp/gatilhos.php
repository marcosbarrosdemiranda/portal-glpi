<?php
// Gatilhos de notificação do worker WhatsApp (Fase 2).
//
// Cada função roda uma "passada" de um gatilho: detecta o que é novo desde a
// última execução (via watermark / portal_wpp_notificados) e envia. O toggle
// on_* de portal_wpp_config é CHECADO DENTRO de cada gatilho.
//
// Nesta task (5) são apenas stubs — a implementação vem nas Tasks 6-9:
//   gat_novo      -> Task 6  (chamado novo aberto)
//   gat_atribuido -> Task 7  (chamado atribuído a um técnico -> DM)
//   gat_alertas   -> Task 8  (digest de alertas do parque)
//   gat_sla       -> Task 9  (chamado parado / prevenção de estouro de SLA)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/evo_api.php';
require_once __DIR__ . '/../alertas_lib.php';

function gat_novo(PDO $pdo): void {}       // Task 6

function gat_atribuido(PDO $pdo): void {}  // Task 7

function gat_alertas(PDO $pdo): void {}    // Task 8

function gat_sla(PDO $pdo): void {}        // Task 9
