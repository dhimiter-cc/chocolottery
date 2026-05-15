<?php
// Shared helpers & constants for Chocolate Lottery

define('DATA_DIR', __DIR__ . '/data');
define('GAMES_DIR', DATA_DIR . '/games');
define('LEADERBOARD_FILE', DATA_DIR . '/leaderboard.json');
define('ONLINE_THRESHOLD', 15);          // seconds
define('GAME_TTL', 86400);               // 24h
define('COOKIE_TTL', 86400);             // 24h

// Ensure data directories exist
if (!is_dir(GAMES_DIR)) {
    @mkdir(GAMES_DIR, 0777, true);
}
if (!file_exists(LEADERBOARD_FILE)) {
    @file_put_contents(LEADERBOARD_FILE, json_encode(['wins' => []]));
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function json_error($msg, $code = 400) {
    json_response(['error' => $msg], $code);
}

function read_json_body() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function game_path($code) {
    $code = strtoupper(preg_replace('/[^A-Z0-9\-]/i', '', $code));
    return GAMES_DIR . '/' . $code . '.json';
}

function load_game($code) {
    $path = game_path($code);
    if (!file_exists($path)) return null;
    $raw = file_get_contents($path);
    return json_decode($raw, true);
}

// Read-modify-write a game JSON under an exclusive lock.
function with_game_lock($code, callable $fn) {
    $path = game_path($code);
    if (!file_exists($path)) return null;

    $fp = fopen($path, 'c+');
    if (!$fp) return null;

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return null;
    }

    $raw = stream_get_contents($fp);
    $game = json_decode($raw, true);

    $result = $fn($game);

    // $fn can return ['game' => ..., 'result' => ...] or modify and return the game directly,
    // or return ['__no_write' => true, 'result' => ...] to skip writing.
    $writeGame = null;
    $ret = null;

    if (is_array($result) && array_key_exists('__no_write', $result)) {
        $ret = $result['result'] ?? null;
    } else if (is_array($result) && isset($result['game'])) {
        $writeGame = $result['game'];
        $ret = $result['result'] ?? null;
    } else if (is_array($result)) {
        $writeGame = $result;
        $ret = $result;
    }

    if ($writeGame !== null) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($writeGame));
        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return $ret;
}

function append_leaderboard($win) {
    $fp = fopen(LEADERBOARD_FILE, 'c+');
    if (!$fp) return;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return; }
    $raw = stream_get_contents($fp);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['wins'])) $data = ['wins' => []];
    $data['wins'][] = $win;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function load_leaderboard() {
    if (!file_exists(LEADERBOARD_FILE)) return ['wins' => []];
    $raw = file_get_contents(LEADERBOARD_FILE);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['wins' => []];
}

function generate_game_code() {
    $prefixes = ['CHOC', 'COCO', 'BEAN', 'WRAP'];
    for ($i = 0; $i < 50; $i++) {
        $prefix = $prefixes[array_rand($prefixes)];
        $num = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $code = $prefix . '-' . $num;
        if (!file_exists(game_path($code))) {
            return $code;
        }
    }
    return null;
}

function generate_token() {
    return bin2hex(random_bytes(16));
}

function set_player_cookie($name, $value, $ttl = COOKIE_TTL) {
    setcookie($name, $value, [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => false,
    ]);
}

function get_cookie($name) {
    return $_COOKIE[$name] ?? null;
}

function cleanup_old_games() {
    $now = time();
    foreach (glob(GAMES_DIR . '/*.json') as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) continue;
        $g = json_decode($raw, true);
        $created = is_array($g) && isset($g['created_at']) ? (int)$g['created_at'] : @filemtime($file);
        if ($now - $created > GAME_TTL) {
            @unlink($file);
        }
    }
}

function is_online($player, $now = null) {
    $now = $now ?? time();
    return isset($player['last_seen']) && ($now - $player['last_seen']) <= ONLINE_THRESHOLD;
}

function generate_straws($n) {
    // Fisher-Yates over a deck of positions, using CSPRNG. Fully uniform winner placement.
    $deck = range(0, $n - 1);
    for ($i = $n - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$deck[$i], $deck[$j]] = [$deck[$j], $deck[$i]];
    }
    $winnerPos = $deck[0];

    $straws = array_fill(0, $n, 0);
    for ($i = 0; $i < $n; $i++) {
        // wider spread (15..70) plus a small jitter so two losers rarely tie exactly
        $straws[$i] = random_int(15, 70);
    }
    $straws[$winnerPos] = 100;
    return $straws;
}

function pick_prize_snack($game) {
    if (empty($game['suggestions']) || !is_array($game['suggestions'])) return null;

    // Highest vote count wins; if tied or all zero, random among the top tier.
    $maxVotes = 0;
    foreach ($game['suggestions'] as $s) {
        $v = is_array($s['votes'] ?? null) ? count($s['votes']) : 0;
        if ($v > $maxVotes) $maxVotes = $v;
    }

    $candidates = [];
    foreach ($game['suggestions'] as $s) {
        $v = is_array($s['votes'] ?? null) ? count($s['votes']) : 0;
        if ($v === $maxVotes) $candidates[] = $s;
    }
    if (empty($candidates)) return null;

    $pick = $candidates[random_int(0, count($candidates) - 1)];
    return [
        'text'        => $pick['text'] ?? '',
        'author_name' => $pick['author_name'] ?? '',
        'votes'       => is_array($pick['votes'] ?? null) ? count($pick['votes']) : 0,
        'random'      => $maxVotes === 0,
    ];
}

function sanitise_state_for_player($game, $myToken) {
    $now = time();
    $players = [];
    foreach ($game['players'] as $token => $p) {
        $players[] = [
            'token'  => $token,
            'name'   => $p['name'],
            'online' => is_online($p, $now),
            'picked' => isset($p['straw_index']) && $p['straw_index'] !== null,
            'straw_index' => isset($p['straw_index']) ? $p['straw_index'] : null,
            'is_me'  => $token === $myToken,
        ];
    }

    $strawsOut = null;
    if (is_array($game['straws'])) {
        if ($game['state'] === 'reveal' || $game['state'] === 'done') {
            $strawsOut = $game['straws'];
        } else {
            $strawsOut = array_fill(0, count($game['straws']), null);
        }
    }

    $myStraw = null;
    if ($myToken && isset($game['players'][$myToken])) {
        $myStraw = $game['players'][$myToken]['straw_index'] ?? null;
    }

    $suggestions = [];
    if (isset($game['suggestions']) && is_array($game['suggestions'])) {
        foreach ($game['suggestions'] as $s) {
            $votes = $s['votes'] ?? [];
            $suggestions[] = [
                'id'          => $s['id'] ?? '',
                'text'        => $s['text'] ?? '',
                'author_name' => $s['author_name'] ?? 'Anon',
                'mine'        => ($s['author_token'] ?? '') === $myToken,
                'votes'       => count($votes),
                'voted'       => $myToken && in_array($myToken, $votes, true),
                'created_at'  => $s['created_at'] ?? 0,
            ];
        }
        // Sort by votes desc, then by created_at asc
        usort($suggestions, function ($a, $b) {
            if ($b['votes'] !== $a['votes']) return $b['votes'] - $a['votes'];
            return $a['created_at'] - $b['created_at'];
        });
    }

    return [
        'code'          => $game['code'],
        'state'         => $game['state'],
        'players'       => $players,
        'straws'        => $strawsOut,
        'winner_token'  => $game['winner_token'] ?? null,
        'creator_token' => $game['creator_token'] ?? null,
        'is_host'       => $myToken && ($game['creator_token'] ?? null) === $myToken,
        'my_token'      => $myToken,
        'my_straw'      => $myStraw,
        'suggestions'   => $suggestions,
        'prize_snack'   => $game['prize_snack'] ?? null,
    ];
}
