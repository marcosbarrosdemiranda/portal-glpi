<?php
// wpp/glpi_bot.php — chamadas à API do GLPI usadas pelo chatbot. Sem HTML,
// funções isoladas, retorno em array, nunca lançam. Reusa o padrão
// Basic-auth de agenda/criar_ticket.php (sem token por usuário GLPI).
require_once __DIR__ . '/../agenda/config.php';
require_once __DIR__ . '/../agenda/db.php'; // $pdo — usado pelas consultas de loja/usuário/perfil

// Monta o array "input" do POST /apirest.php/Ticket. Pura, sem rede —
// testável sem precisar de um GLPI de verdade.
function bot_criar_chamado_payload(int $requerente_id, int $entities_id, string $titulo, string $descricao): array {
    return [
        'name'                => $titulo,
        'content'             => $descricao !== '' ? $descricao : $titulo,
        'type'                => 1, // Incidente
        'urgency'             => 3,
        'priority'            => 3,
        'status'              => 1, // Novo — sem atendente ainda
        '_users_id_requester' => $requerente_id,
        'entities_id'         => $entities_id,
    ];
}

// Cria o chamado de verdade via REST. Nunca lança — qualquer falha de rede
// ou da API volta como ['ok'=>false,'erro'=>...].
function bot_criar_chamado(int $requerente_id, int $entities_id, string $titulo, string $descricao): array {
    try {
        $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
        $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth, 'App-Token: ' . GLPI_APP_TOKEN],
        ]);
        $r = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        $token = $r['session_token'] ?? '';
        if (!$token) {
            return ['ok' => false, 'ticket_id' => null, 'erro' => 'falha ao autenticar na API do GLPI'];
        }

        $headers = ['Content-Type: application/json', 'Session-Token: ' . $token, 'App-Token: ' . GLPI_APP_TOKEN];
        $input   = bot_criar_chamado_payload($requerente_id, $entities_id, $titulo, $descricao);

        $ch = curl_init(GLPI_URL . '/apirest.php/Ticket');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => json_encode(['input' => $input]),
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $res = json_decode((string) curl_exec($ch), true);
        curl_close($ch);

        // best-effort: encerra a sessão, sem deixar isso afetar o resultado
        $ch = curl_init(GLPI_URL . '/apirest.php/killSession');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
        curl_exec($ch);
        curl_close($ch);

        if (!empty($res['id'])) {
            return ['ok' => true, 'ticket_id' => (int) $res['id'], 'erro' => null];
        }
        return ['ok' => false, 'ticket_id' => null, 'erro' => 'GLPI nao retornou id: ' . json_encode($res)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'ticket_id' => null, 'erro' => $e->getMessage()];
    }
}

require_once __DIR__ . '/../entidade_alias.php'; // apelido_entidade(), nome_requerente()

// Lojas pra o picker do chatbot: entidades filhas de verdade (level > 1 —
// exclui a raiz/holding), com o apelido curto já aplicado.
function bot_lojas(): array {
    global $pdo;
    try {
        $st = $pdo->query("SELECT id, completename FROM glpi_entities WHERE id > 0 AND level > 1 ORDER BY completename");
        $lojas = [];
        foreach ($st->fetchAll() as $r) {
            $lojas[] = ['id' => (int) $r['id'], 'nome' => apelido_entidade($r['completename'])];
        }
        return $lojas;
    } catch (\Throwable $e) {
        return [];
    }
}

// Usuários ativos de uma loja, pro picker depois de escolher a entidade.
function bot_usuarios_loja(int $entities_id): array {
    global $pdo;
    try {
        $st = $pdo->prepare(
            "SELECT id, realname, firstname, name FROM glpi_users
             WHERE is_active = 1 AND is_deleted = 0 AND entities_id = ?
             ORDER BY realname, firstname"
        );
        $st->execute([$entities_id]);
        $usuarios = [];
        foreach ($st->fetchAll() as $r) {
            $nome = trim(($r['realname'] ?? '') . ' ' . ($r['firstname'] ?? ''));
            if ($nome === '') {
                $nome = (string) ($r['name'] ?? '');
            }
            $usuarios[] = ['id' => (int) $r['id'], 'nome' => nome_requerente($nome)];
        }
        return $usuarios;
    } catch (\Throwable $e) {
        return [];
    }
}

// Nome curto da entidade, pro texto de confirmação do vínculo tipo loja.
// Se não houver apelido cadastrado (apelido_entidade devolve o completename
// intacto), cai pro nome curto original em vez do caminho completo feio.
function bot_entidade_nome(int $entities_id): string {
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT name, completename FROM glpi_entities WHERE id = ?");
        $st->execute([$entities_id]);
        $r = $st->fetch();
        if ($r === false) {
            return 'Entidade #' . $entities_id;
        }
        $apelido = apelido_entidade((string) $r['completename']);
        return $apelido !== $r['completename'] ? $apelido : (string) $r['name'];
    } catch (\Throwable $e) {
        return 'Entidade #' . $entities_id;
    }
}

// true se o usuário GLPI tem o perfil "técnico" (profiles_id=4 — mesmo
// critério já usado na aba Contatos de config_whatsapp.php).
function bot_perfil_tecnico(int $glpi_user_id): bool {
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT 1 FROM glpi_profiles_users WHERE users_id = ? AND profiles_id = 4 LIMIT 1");
        $st->execute([$glpi_user_id]);
        return (bool) $st->fetchColumn();
    } catch (\Throwable $e) {
        // Fail-safe: em erro de banco, assume técnico (lado mais seguro) —
        // isso força o picker completo de loja/usuário em vez do atalho de
        // confirmação, que não deve ser oferecido a quem pode ser técnico.
        return true;
    }
}
