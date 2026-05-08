<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST required', 405);

$body = read_json_body();
$code = trim($body['code'] ?? '');
$text = trim($body['text'] ?? '');
$token = get_cookie('player_token');

if (!$code || !$token) json_error('Missing code or token');
if ($text === '') json_error('Suggestion is empty');
if (mb_strlen($text) > 80) $text = mb_substr($text, 0, 80);

$result = with_game_lock($code, function ($game) use ($token, $text) {
    if (!$game) return ['__no_write' => true, 'result' => ['error' => 'Game not found', 'code' => 404]];
    if (!isset($game['players'][$token])) return ['__no_write' => true, 'result' => ['error' => 'Not in game', 'code' => 403]];

    if (!isset($game['suggestions']) || !is_array($game['suggestions'])) {
        $game['suggestions'] = [];
    }

    // Dedupe by lowercase text
    $needle = mb_strtolower($text);
    foreach ($game['suggestions'] as $s) {
        if (mb_strtolower($s['text']) === $needle) {
            return ['__no_write' => true, 'result' => ['error' => 'Already suggested', 'code' => 409]];
        }
    }

    if (count($game['suggestions']) >= 50) {
        return ['__no_write' => true, 'result' => ['error' => 'Too many suggestions, slow down', 'code' => 429]];
    }

    $author = $game['players'][$token]['name'] ?? 'Anon';
    $id = bin2hex(random_bytes(6));

    $game['suggestions'][] = [
        'id'           => $id,
        'text'         => $text,
        'author_token' => $token,
        'author_name'  => $author,
        'votes'        => [$token],         // auto-upvote your own
        'created_at'   => time(),
    ];

    return ['game' => $game, 'result' => ['ok' => true, 'id' => $id]];
});

if (!$result) json_error('Game not found', 404);
if (isset($result['error'])) json_error($result['error'], $result['code'] ?? 400);

json_response($result);
