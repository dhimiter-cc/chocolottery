<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST required', 405);

$body = read_json_body();
$code = trim($body['code'] ?? '');
$token = get_cookie('player_token');
if (!$code || !$token) json_error('Missing code or token');

with_game_lock($code, function ($game) use ($token) {
    if (!$game) return ['__no_write' => true];
    if (!isset($game['players'][$token])) return ['__no_write' => true];
    $game['players'][$token]['last_seen'] = time();
    return ['game' => $game, 'result' => true];
});

json_response(['ok' => true]);
