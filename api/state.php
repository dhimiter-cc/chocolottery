<?php
require_once __DIR__ . '/../config.php';

// Opportunistic cleanup (cheap; one in ~10 requests)
if (random_int(1, 10) === 1) {
    cleanup_old_games();
}

$code = trim($_GET['code'] ?? '');
if (!$code) json_error('Missing code');

$game = load_game($code);
if (!$game) json_error('Game not found', 404);

$myToken = get_cookie('player_token');

// If picking and our last_seen drifted, refresh it cheaply (no lock needed for stale read)
json_response(sanitise_state_for_player($game, $myToken));
