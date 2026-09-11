<?php
// Testes do parser do payload da Evolution. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../webhook_parse.php';

// formato v2: data = objeto da mensagem direto
$payloadV2 = [
    'event' => 'messages.upsert',
    'data'  => [
        'key' => ['remoteJid' => '556799998888@s.whatsapp.net', 'fromMe' => false, 'id' => 'ABC123'],
        'message' => ['conversation' => 'oi'],
        'messageTimestamp' => 1736450000,
    ],
];
$m = wpp_extrair_msg($payloadV2);
t_ok($m !== null, 'payload v2: reconhece');
t_eq($m['id'], 'ABC123', 'payload v2: id');
t_eq($m['remoteJid'], '556799998888@s.whatsapp.net', 'payload v2: remoteJid');
t_eq($m['fromMe'], false, 'payload v2: fromMe');
t_eq($m['timestamp'], 1736450000, 'payload v2: timestamp');
t_eq($m['texto'], 'oi', 'payload v2: texto (conversation)');
t_eq($m['temMidia'], false, 'payload v2: sem midia');

// timestamp como protobuf Long ({low: n})
$payloadLong = $payloadV2;
$payloadLong['data']['messageTimestamp'] = ['low' => 1736450001, 'high' => 0];
$m2 = wpp_extrair_msg($payloadLong);
t_eq($m2['timestamp'], 1736450001, 'timestamp formato Long: extrai o low');

// fallback: data.messages[0] (formato Baileys puro)
$payloadArr = [
    'event' => 'messages.upsert',
    'data'  => ['messages' => [[
        'key' => ['remoteJid' => '120363@g.us', 'fromMe' => false, 'id' => 'XYZ'],
        'message' => ['extendedTextMessage' => ['text' => 'resposta longa']],
        'messageTimestamp' => 1736450002,
    ]]],
];
$m3 = wpp_extrair_msg($payloadArr);
t_ok($m3 !== null, 'payload com messages[]: reconhece');
t_eq($m3['remoteJid'], '120363@g.us', 'payload com messages[]: remoteJid');
t_eq($m3['texto'], 'resposta longa', 'extendedTextMessage: texto');

// imagem
$payloadImg = $payloadV2;
$payloadImg['data']['message'] = ['imageMessage' => ['caption' => 'foto']];
$m4 = wpp_extrair_msg($payloadImg);
t_eq($m4['temMidia'], true, 'imageMessage: temMidia=true');

// payload sem key -> null
t_ok(wpp_extrair_msg(['event' => 'connection.update', 'data' => ['state' => 'open']]) === null, 'payload sem key: null');
// payload vazio -> null
t_ok(wpp_extrair_msg([]) === null, 'payload vazio: null');
// timestamp ausente -> usa "agora" (nunca deixa passar como "antigo")
$payloadSemTs = $payloadV2;
unset($payloadSemTs['data']['messageTimestamp']);
$m5 = wpp_extrair_msg($payloadSemTs);
t_ok($m5['timestamp'] >= time() - 2, 'sem timestamp: usa agora');
