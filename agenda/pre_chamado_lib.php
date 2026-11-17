<?php
/**
 * Helpers de cálculo de ocorrências futuras de glpi_ticketrecurrents (GLPI nativo),
 * usados por sync_rotinas_ajax.php para gerar os placeholders "pre_chamado".
 *
 * Formatos reais de periodicity observados em produção (32 definições, 2026-10-19):
 *   - numérico puro = segundos  (86400, 604800, 1296000, 2592000)
 *   - "NMONTH"       = N meses  ("1MONTH", "2MONTH")
 * Sem suporte a calendário/feriado na v1 — as 32 definições usam o mesmo calendars_id,
 * aberto todos os dias da semana, sem feriado cadastrado (limitação conhecida, não bloqueante).
 */

/**
 * Converte periodicity em um DateInterval (ou false se formato desconhecido).
 */
function preChamadoIntervaloPeriodicidade(string $periodicity)
{
    $periodicity = trim($periodicity);

    if (ctype_digit($periodicity)) {
        $segundos = (int)$periodicity;
        if ($segundos <= 0) return false;
        return new DateInterval('PT' . $segundos . 'S');
    }

    if (preg_match('/^(\d+)MONTH$/i', $periodicity, $m)) {
        return new DateInterval('P' . (int)$m[1] . 'M');
    }

    return false;
}

/**
 * Gera as ocorrências de uma definição (begin_date + periodicity) dentro de [$inicio, $fim].
 * Retorna array de DateTime (hora/minuto/segundo preservados de begin_date).
 */
function preChamadoProximasOcorrencias(string $beginDate, string $periodicity, DateTime $inicio, DateTime $fim): array
{
    $intervalo = preChamadoIntervaloPeriodicidade($periodicity);
    if (!$intervalo) return [];

    $cursor = new DateTime($beginDate);
    $ocorrencias = [];

    // Avança rápido até chegar perto de $inicio (evita loop longo p/ definições antigas)
    $guard = 0;
    while ($cursor < $inicio && $guard < 100000) {
        $cursor->add($intervalo);
        $guard++;
    }

    $guard = 0;
    while ($cursor <= $fim && $guard < 1000) {
        if ($cursor >= $inicio) {
            $ocorrencias[] = clone $cursor;
        }
        $cursor->add($intervalo);
        $guard++;
    }

    return $ocorrencias;
}
