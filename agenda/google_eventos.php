<?php
/**
 * Busca eventos do Google Calendar via iCal e retorna JSON
 */
session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit; }

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { echo json_encode([]); exit; }

// Busca URL iCal do usuário
$stmt = $pdo->prepare("SELECT ical_url FROM glpi_agenda_gcal WHERE user_id=?");
$stmt->execute([$user_id]);
$row = $stmt->fetch();
$url = $row['ical_url'] ?? '';

if (!$url) { echo json_encode([]); exit; }

// Busca o arquivo iCal
$ctx = stream_context_create(['http' => ['timeout' => 10]]);
$ical = @file_get_contents($url, false, $ctx);
if (!$ical) { echo json_encode([]); exit; }

// Parser iCal simples
function parse_ical(string $ical): array {
    $events  = [];
    $current = null;
    $lines   = preg_split('/\r?\n/', $ical);

    // Junta linhas dobradas (iCal fold)
    $unfolded = [];
    foreach ($lines as $line) {
        if (preg_match('/^[ \t]/', $line) && !empty($unfolded)) {
            $unfolded[count($unfolded)-1] .= ltrim($line);
        } else {
            $unfolded[] = $line;
        }
    }

    foreach ($unfolded as $line) {
        $line = rtrim($line);
        if ($line === 'BEGIN:VEVENT') {
            $current = [];
        } elseif ($line === 'END:VEVENT' && $current !== null) {
            $events[] = $current;
            $current  = null;
        } elseif ($current !== null && str_contains($line, ':')) {
            [$key, $val] = explode(':', $line, 2);
            // Extrai parâmetros (ex: DTSTART;TZID=America/Sao_Paulo)
            $keyParts = explode(';', $key);
            $key = $keyParts[0];
            // Salva TZID se presente (ex: DTSTART;TZID=America/Sao_Paulo → TZID=America/Sao_Paulo)
            foreach ($keyParts as $kp) {
                if (str_starts_with($kp, 'TZID=')) {
                    $current[$key . '_TZID'] = substr($kp, 5);
                    break;
                }
            }
            $current[$key] = $val;
        }
    }
    return $events;
}

function ical_to_datetime(string $dt): string {
    // Formato: 20260601T090000Z (UTC) ou 20260601T090000 (floating) ou 20260601 (dia inteiro)
    $is_utc = str_ends_with($dt, 'Z');
    $dt     = rtrim($dt, 'Z');
    if (strlen($dt) === 8) {
        // All-day: 20260601
        return substr($dt,0,4).'-'.substr($dt,4,2).'-'.substr($dt,6,2);
    }
    // Com hora: 20260601T090000
    $date = substr($dt,0,8);
    $time = substr($dt,9,6);
    $fmt  = substr($date,0,4).'-'.substr($date,4,2).'-'.substr($date,6,2).'T'
          .substr($time,0,2).':'.substr($time,2,2).':'.substr($time,4,2);
    // Se é UTC, mantém Z no final para o JS/FullCalendar converter para o timezone local do browser
    if ($is_utc) $fmt .= 'Z';
    return $fmt;
}

function decode_ical_text(string $text): string {
    return str_replace(['\\n','\\,','\\;','\\\\'], ["\n",',',';','\\'], $text);
}

$raw_events = parse_ical($ical);
$resultado  = [];
$hoje       = new DateTime();
$limite     = (clone $hoje)->modify('+60 days');

foreach ($raw_events as $ev) {
    $summary = decode_ical_text($ev['SUMMARY'] ?? 'Evento Google');
    $start     = $ev['DTSTART'] ?? '';
    $end       = $ev['DTEND']   ?? $start;
    $start_tz  = $ev['DTSTART_TZID'] ?? null;
    $end_tz    = $ev['DTEND_TZID']   ?? null;

    if (!$start) continue;

    $start_fmt = ical_to_datetime($start);
    $end_fmt   = ical_to_datetime($end);

    // Converte datas com TZID para o timezone local (FullCalendar interpreta sem Z como local)
    // Se veio com TZID mas sem Z, o horário está no fuso do TZID — converte para string ISO com offset
    if ($start_tz && !str_ends_with($start_fmt, 'Z')) {
        $start_fmt = (new DateTime($start_fmt, new DateTimeZone($start_tz)))->format('Y-m-d\TH:i:sP');
    }
    if ($end_tz && !str_ends_with($end_fmt, 'Z')) {
        $end_fmt = (new DateTime($end_fmt, new DateTimeZone($end_tz)))->format('Y-m-d\TH:i:sP');
    }

    // Só mostra eventos futuros e dos próximos 60 dias
    try {
        $dt_start = new DateTime($start_fmt);
        if ($dt_start < $hoje || $dt_start > $limite) continue;
    } catch (Exception $e) { continue; }

    $resultado[] = [
        'id'           => 'gcal_' . md5($ev['UID'] ?? $summary.$start),
        'title'        => $summary,
        'start'        => $start_fmt,
        'end'          => $end_fmt,
        'backgroundColor' => '#0b8043', // verde Google Calendar
        'borderColor'     => '#0b8043',
        'textColor'       => '#ffffff',
        'extendedProps'   => [
            'tipo'      => 'google',
            'descricao' => decode_ical_text($ev['DESCRIPTION'] ?? ''),
            'local'     => decode_ical_text($ev['LOCATION'] ?? ''),
            'google'    => true,
        ]
    ];
}

echo json_encode($resultado);
