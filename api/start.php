<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST required', 405);

$body = read_json_body();
$code = trim($body['code'] ?? '');
if (!$code) json_error('Missing code');

$token = get_cookie('player_token');

$result = with_game_lock($code, function ($game) use ($token) {
    if (!$game) return ['__no_write' => true, 'result' => ['error' => 'Game not found', 'code' => 404]];
    if ($game['state'] !== 'lobby') return ['__no_write' => true, 'result' => ['error' => 'Game not in lobby', 'code' => 409]];
    if (!empty($game['creator_token']) && $game['creator_token'] !== $token) {
        return ['__no_write' => true, 'result' => ['error' => 'Only the host can start', 'code' => 403]];
    }

    $now = time();
    $online = [];
    foreach ($game['players'] as $token => $p) {
        if (is_online($p, $now)) $online[$token] = $p;
    }

    if (count($online) < 2) {
        return ['__no_write' => true, 'result' => ['error' => 'Need at least 2 online players', 'code' => 400]];
    }

    foreach ($online as $token => &$p) {
        $p['straw_index'] = null;
    }
    unset($p);

    $game['players'] = $online;
    $game['straws']  = generate_straws(count($online));
    $game['state']   = 'picking';
    $game['winner_token'] = null;

    return ['game' => $game, 'result' => ['ok' => true]];
});

if (!$result) json_error('Game not found', 404);
if (isset($result['error'])) json_error($result['error'], $result['code'] ?? 400);

json_response($result);
