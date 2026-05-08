<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST required', 405);

$body = read_json_body();
$code = trim($body['code'] ?? '');
$id = trim($body['id'] ?? '');
$token = get_cookie('player_token');

if (!$code || !$token) json_error('Missing code or token');
if (!$id) json_error('Missing suggestion id');

$result = with_game_lock($code, function ($game) use ($token, $id) {
    if (!$game) return ['__no_write' => true, 'result' => ['error' => 'Game not found', 'code' => 404]];
    if (!isset($game['players'][$token])) return ['__no_write' => true, 'result' => ['error' => 'Not in game', 'code' => 403]];
    if (!isset($game['suggestions']) || !is_array($game['suggestions'])) {
        return ['__no_write' => true, 'result' => ['error' => 'No suggestions', 'code' => 404]];
    }

    $found = false;
    foreach ($game['suggestions'] as &$s) {
        if (($s['id'] ?? '') === $id) {
            $found = true;
            $votes = isset($s['votes']) && is_array($s['votes']) ? $s['votes'] : [];
            $idx = array_search($token, $votes, true);
            if ($idx === false) {
                $votes[] = $token;
            } else {
                array_splice($votes, $idx, 1);
            }
            $s['votes'] = array_values($votes);
            break;
        }
    }
    unset($s);

    if (!$found) return ['__no_write' => true, 'result' => ['error' => 'Suggestion not found', 'code' => 404]];

    return ['game' => $game, 'result' => ['ok' => true]];
});

if (!$result) json_error('Game not found', 404);
if (isset($result['error'])) json_error($result['error'], $result['code'] ?? 400);

json_response($result);
