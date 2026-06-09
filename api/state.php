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

// Piggyback a last_seen refresh on the 1s poll — acts as a fallback heartbeat for
// mobile browsers that throttle background timers and miss the dedicated heartbeat.
// Only write when the value is stale (>8s), keeping lock contention low.
if ($myToken
    && isset($game['players'][$myToken])
    && in_array($game['state'], ['lobby', 'picking'], true)
    && (time() - ($game['players'][$myToken]['last_seen'] ?? 0)) > 8
) {
    $updated = with_game_lock($code, function ($g) use ($myToken) {
        if (!isset($g['players'][$myToken])) return ['__no_write' => true, 'result' => null];
        $g['players'][$myToken]['last_seen'] = time();
        return ['game' => $g, 'result' => $g];
    });
    if (is_array($updated) && isset($updated['players'])) {
        $game = $updated;
    }
}

json_response(sanitise_state_for_player($game, $myToken));
