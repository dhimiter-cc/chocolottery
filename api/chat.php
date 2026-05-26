<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST required', 405);

$body = read_json_body();
$code = trim($body['code'] ?? '');
$text = trim($body['text'] ?? '');
$token = get_cookie('player_token');

if (!$code || !$token) json_error('Missing code or token');
if ($text === '') json_error('Empty message');
if (mb_strlen($text) > 240) $text = mb_substr($text, 0, 240);

$result = with_game_lock($code, function ($game) use ($token, $text) {
    if (!$game) return ['__no_write' => true, 'result' => ['error' => 'Game not found', 'code' => 404]];
    if (!isset($game['players'][$token])) return ['__no_write' => true, 'result' => ['error' => 'Not in game', 'code' => 403]];

    if (!isset($game['chat']) || !is_array($game['chat'])) $game['chat'] = [];

    // Cheap rate limit: max 1 message per 800ms per player
    $now = time();
    $nowMs = (int) (microtime(true) * 1000);
    $last = $game['players'][$token]['last_chat_ms'] ?? 0;
    if ($nowMs - $last < 800) {
        return ['__no_write' => true, 'result' => ['error' => 'Slow down', 'code' => 429]];
    }

    $name = $game['players'][$token]['name'] ?? 'Anon';
    $game['chat'][] = [
        'id'    => bin2hex(random_bytes(6)),
        'token' => $token,
        'name'  => $name,
        'text'  => $text,
        'ts'    => $now,
    ];
    // Keep last 100
    if (count($game['chat']) > 100) {
        $game['chat'] = array_slice($game['chat'], -100);
    }
    $game['players'][$token]['last_chat_ms'] = $nowMs;
    $game['players'][$token]['last_seen']    = $now;

    return ['game' => $game, 'result' => ['ok' => true]];
});

if (!$result) json_error('Game not found', 404);
if (isset($result['error'])) json_error($result['error'], $result['code'] ?? 400);

json_response($result);
