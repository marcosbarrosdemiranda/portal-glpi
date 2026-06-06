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
            // Salva TZID se presente
            foreach ($keyParts as $kp) {
                if (str_starts_with($kp, 'TZID=')) {
                    $current[$key . '_TZID'] = substr($kp, 5);
                    break;
                }
            }
            // ATTENDEE: suporta múltiplos, extrai CN (nome) e email
            if ($key === 'ATTENDEE') {
                if (!isset($current['ATTENDEES'])) $current['ATTENDEES'] = [];
                $cn = '';
                foreach ($keyParts as $kp) {
                    if (str_starts_with($kp, 'CN=')) {
                        $cn = substr($kp, 3);
                        // Remove aspas ao redor do nome
                        $cn = trim($cn, '"');
                        break;
                    }
                }
                $email = str_replace('mailto:', '', $val);
                $current['ATTENDEES'][] = ['cn' => $cn, 'email' => $email];
            } else {
                $current[$key] = $val;
            }
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
    $local     = decode_ical_text($ev['LOCATION'] ?? '');
    $descricao = decode_ical_text($ev['DESCRIPTION'] ?? '');
    $attendees = $ev['ATTENDEES'] ?? [];
    $meet_url  = $ev['X-GOOGLE-CONFERENCE'] ?? '';

    // Se LOCATION é uma URL, usa como meet_url (fallback)
    if (!$meet_url && $local && preg_match('/^https?:\/\//', $local)) {
        $meet_url = $local;
        $local = '';
    }
    // Busca link de reunião na descrição se não achou antes
    if (!$meet_url && preg_match('/https?:\/\/[^\s<>"]+/', $descricao, $m)) {
        // Só usa se for um link de reunião conhecido
        $known = ['meet.google.com','zoom.us','teams.microsoft.com','webex.com','gotomeeting.com','whereby.com','jitsi.org'];
        foreach ($known as $d) {
            if (str_contains($m[0], $d)) { $meet_url = $m[0]; break; }
        }
    }

    if (!$start) continue;

    $start_fmt = ical_to_datetime($start);
    $end_fmt   = ical_to_datetime($end);

    // Converte datas com TZID para string ISO com offset
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
        'backgroundColor' => '#7b2d8e', // roxo — diferenciar dos eventos da agenda
        'borderColor'     => '#7b2d8e',
        'textColor'       => '#ffffff',
        'extendedProps'   => [
            'tipo'      => 'google',
            'descricao' => $descricao,
            'google'    => true,
            'local'     => $local,
            'participantes' => $attendees,
            'meet_url'  => $meet_url,
        ]
    ];
}

echo json_encode($resultado);
