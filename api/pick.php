<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST required', 405);

$body = read_json_body();
$code = trim($body['code'] ?? '');
$strawIndex = isset($body['straw_index']) ? (int)$body['straw_index'] : -1;
$token = get_cookie('player_token');

if (!$code || !$token) json_error('Missing code or token');

$winRecord = null;

$result = with_game_lock($code, function ($game) use ($token, $strawIndex, &$winRecord) {
    if (!$game) return ['__no_write' => true, 'result' => ['error' => 'Game not found', 'code' => 404]];
    if ($game['state'] !== 'picking') return ['__no_write' => true, 'result' => ['error' => 'Game not picking', 'code' => 409]];
    if (!isset($game['players'][$token])) return ['__no_write' => true, 'result' => ['error' => 'Not in game', 'code' => 403]];
    if ($game['players'][$token]['straw_index'] !== null) return ['__no_write' => true, 'result' => ['error' => 'Already picked', 'code' => 409]];
    if (!is_array($game['straws'])) return ['__no_write' => true, 'result' => ['error' => 'No straws', 'code' => 500]];
    if ($strawIndex < 0 || $strawIndex >= count($game['straws'])) return ['__no_write' => true, 'result' => ['error' => 'Bad straw index', 'code' => 400]];

    foreach ($game['players'] as $t => $p) {
        if ($p['straw_index'] === $strawIndex) {
            return ['__no_write' => true, 'result' => ['error' => 'Straw already taken', 'code' => 409]];
        }
    }

    $game['players'][$token]['straw_index'] = $strawIndex;
    $game['players'][$token]['last_seen'] = time();

    // All picked?
    $allPicked = true;
    foreach ($game['players'] as $p) {
        if ($p['straw_index'] === null) { $allPicked = false; break; }
    }

    if ($allPicked) {
        $winnerToken = null;
        foreach ($game['players'] as $t => $p) {
            if ($game['straws'][$p['straw_index']] === 100) {
                $winnerToken = $t;
                break;
            }
        }
        $game['winner_token'] = $winnerToken;
        $game['state'] = 'reveal';
        $game['prize_snack'] = pick_prize_snack($game);

        if ($winnerToken) {
            $playerNames = [];
            foreach ($game['players'] as $p) {
                $playerNames[] = $p['name'];
            }
            $winRecord = [
                'name' => $game['players'][$winnerToken]['name'],
                'game_code' => $game['code'],
                'timestamp' => time(),
                'month' => date('Y-m'),
                'participants' => count($game['players']),
                'player_names' => $playerNames,
                'prize_snack' => $game['prize_snack']['text'] ?? null,
            ];
        }
    }

    return ['game' => $game, 'result' => ['ok' => true]];
});

if ($winRecord) {
    append_leaderboard($winRecord);
}

if (!$result) json_error('Game not found', 404);
if (isset($result['error'])) json_error($result['error'], $result['code'] ?? 400);

json_response($result);
