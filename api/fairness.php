<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('GET required', 405);

$data = load_leaderboard();
$wins = $data['wins'] ?? [];

// name => [ actual_wins, expected_wins, tracked ]
$players = [];
$totalGames  = count($wins);
$trackedGames = 0;

foreach ($wins as $w) {
    $winnerName  = $w['name'] ?? null;
    $playerNames = $w['player_names'] ?? null;

    // Always record the actual win
    if ($winnerName) {
        if (!isset($players[$winnerName])) {
            $players[$winnerName] = ['actual_wins' => 0, 'expected_wins' => 0.0, 'tracked' => false];
        }
        $players[$winnerName]['actual_wins']++;
    }

    // Distribute expected wins only when we have the full participant list
    if (is_array($playerNames) && count($playerNames) > 0) {
        $trackedGames++;
        $n    = count($playerNames);
        $prob = 1.0 / $n;
        foreach ($playerNames as $pName) {
            if (!$pName) continue;
            if (!isset($players[$pName])) {
                $players[$pName] = ['actual_wins' => 0, 'expected_wins' => 0.0, 'tracked' => false];
            }
            $players[$pName]['expected_wins'] += $prob;
            $players[$pName]['tracked'] = true;
        }
    }
}

// Build output array
$out = [];
foreach ($players as $name => $p) {
    $actual   = $p['actual_wins'];
    $expected = $p['expected_wins'];
    $tracked  = $p['tracked'];

    $luckScore = null;
    $verdict   = null;
    if ($tracked && $expected > 0) {
        $luckScore = round($actual / $expected, 2);
        if ($luckScore >= 2.0) {
            $verdict = ['emoji' => '🎰', 'label' => 'Suspiciously Lucky', 'class' => 'lucky'];
        } elseif ($luckScore >= 0.7) {
            $verdict = ['emoji' => '⚖️', 'label' => 'Fair and Square', 'class' => 'fair'];
        } else {
            $verdict = ['emoji' => '😢', 'label' => 'Chronically Unlucky', 'class' => 'unlucky'];
        }
    }

    $out[] = [
        'name'          => $name,
        'actual_wins'   => $actual,
        'expected_wins' => $tracked ? round($expected, 2) : null,
        'luck_score'    => $luckScore,
        'verdict'       => $verdict,
    ];
}

// Sort: luck score desc (nulls last), then actual wins desc
usort($out, function ($a, $b) {
    if ($a['luck_score'] === null && $b['luck_score'] === null) {
        return $b['actual_wins'] - $a['actual_wins'];
    }
    if ($a['luck_score'] === null) return 1;
    if ($b['luck_score'] === null) return -1;
    if ($b['luck_score'] !== $a['luck_score']) {
        return $b['luck_score'] <=> $a['luck_score'];
    }
    return $b['actual_wins'] - $a['actual_wins'];
});

json_response([
    'players'       => $out,
    'total_games'   => $totalGames,
    'tracked_games' => $trackedGames,
]);
