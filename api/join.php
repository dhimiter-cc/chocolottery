<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST required', 405);

$body = read_json_body();
$code = trim($body['code'] ?? '');
$name = trim($body['name'] ?? '');

if (!$code) json_error('Missing code');
if (!$name) json_error('Missing name');
if (mb_strlen($name) > 30) $name = mb_substr($name, 0, 30);

$existingToken = get_cookie('player_token');

$result = with_game_lock($code, function ($game) use ($name, $existingToken) {
    if (!$game) return ['__no_write' => true, 'result' => ['error' => 'Game not found', 'code' => 404]];
    if ($game['state'] !== 'lobby') {
        // Allow rejoin if already in this game (for reload during play)
        if ($existingToken && isset($game['players'][$existingToken])) {
            $game['players'][$existingToken]['last_seen'] = time();
            return ['game' => $game, 'result' => ['token' => $existingToken, 'name' => $game['players'][$existingToken]['name'], 'code' => $game['code']]];
        }
        return ['__no_write' => true, 'result' => ['error' => 'Game already started', 'code' => 409]];
    }

    // If known token already in game, just update
    if ($existingToken && isset($game['players'][$existingToken])) {
        $game['players'][$existingToken]['last_seen'] = time();
        $game['players'][$existingToken]['name'] = $name;
        return ['game' => $game, 'result' => ['token' => $existingToken, 'name' => $name, 'code' => $game['code']]];
    }

    $token = generate_token();
    if (!is_array($game['players'])) $game['players'] = [];
    $game['players'][$token] = [
        'name'        => $name,
        'last_seen'   => time(),
        'straw_index' => null,
    ];
    if (empty($game['creator_token'])) {
        $game['creator_token'] = $token;
    }
    return ['game' => $game, 'result' => ['token' => $token, 'name' => $name, 'code' => $game['code']]];
});

if (!$result) json_error('Game not found', 404);
if (isset($result['error'])) json_error($result['error'], $result['code'] ?? 400);

set_player_cookie('player_token', $result['token']);
set_player_cookie('player_name', $result['name']);

json_response($result);
