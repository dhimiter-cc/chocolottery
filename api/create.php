<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST required', 405);

$code = generate_game_code();
if (!$code) json_error('Could not generate game code', 500);

$game = [
    'code'         => $code,
    'state'        => 'lobby',
    'created_at'   => time(),
    'players'      => (object)[],
    'straws'       => null,
    'winner_token' => null,
    'suggestions'  => [],
];

$path = game_path($code);
file_put_contents($path, json_encode($game), LOCK_EX);

json_response(['code' => $code]);
