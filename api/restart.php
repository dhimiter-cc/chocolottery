<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST required', 405);

$body  = read_json_body();
$code  = trim($body['code'] ?? '');
$token = get_cookie('player_token');

if (!$code || !$token) json_error('Missing code or token');

$result = with_game_lock($code, function ($game) use ($token) {
    if (!$game) return ['__no_write' => true, 'result' => ['error' => 'Game not found', 'code' => 404]];
    if (($game['creator_token'] ?? null) !== $token) return ['__no_write' => true, 'result' => ['error' => 'Host only', 'code' => 403]];
    if ($game['state'] === 'lobby') return ['__no_write' => true, 'result' => ['error' => 'Already in lobby', 'code' => 400]];

    $game['state']           = 'lobby';
    $game['straws']          = null;
    $game['winner_token']    = null;
    $game['prize_snack']     = null;
    $game['prize_given_id']  = null;
    $game['prize_given_name'] = null;

    foreach ($game['players'] as $t => &$p) {
        $p['straw_index'] = null;
    }
    unset($p);

    return ['game' => $game, 'result' => ['ok' => true]];
});

if (!$result) json_error('Game not found', 404);
if (isset($result['error'])) json_error($result['error'], $result['code'] ?? 400);

json_response($result);
