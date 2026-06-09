<?php
// Hidden extraction test harness — not linked from anywhere.
// Access directly: /chocolottery/test-extraction.php

require_once __DIR__ . '/config.php';

define('TEST_RUNS_DIR', DATA_DIR . '/test-runs');
if (!is_dir(TEST_RUNS_DIR)) @mkdir(TEST_RUNS_DIR, 0777, true);

// ── Collect names from all game files + leaderboard ──────────────────────────
function collect_names(): array {
    $names = [];
    $lb = load_leaderboard();
    foreach ($lb['wins'] as $w) {
        foreach ($w['player_names'] ?? [] as $n) $names[$n] = true;
        if (!empty($w['name'])) $names[$w['name']] = true;
    }
    foreach (glob(GAMES_DIR . '/*.json') as $f) {
        $g = json_decode(@file_get_contents($f), true);
        if (!is_array($g)) continue;
        foreach ($g['players'] ?? [] as $p) {
            if (!empty($p['name'])) $names[$p['name']] = true;
        }
    }
    $names = array_values(array_keys($names));
    if (count($names) < 4) {
        $names = array_unique(array_merge($names, ['Alice','Bob','Carol','Dave','Eve','Frank']));
    }
    return $names;
}

// ── Simulate a single full game ───────────────────────────────────────────────
function simulate_game(int $playerCount, array $namePool): array {
    shuffle($namePool);
    $playerNames = array_slice($namePool, 0, $playerCount);
    $straws      = generate_straws($playerCount);

    $correctWinnerPos = null;
    foreach ($straws as $i => $v) {
        if ($v === 100) { $correctWinnerPos = $i; break; }
    }

    $pickOrder        = range(0, $playerCount - 1);
    shuffle($pickOrder);
    $assignments      = array_fill(0, $playerCount, null);
    $takenStraws      = [];
    $availableStraws  = range(0, $playerCount - 1);

    foreach ($pickOrder as $playerIdx) {
        $available   = array_values(array_diff($availableStraws, $takenStraws));
        $chosenStraw = $available[random_int(0, count($available) - 1)];
        $assignments[$playerIdx] = $chosenStraw;
        $takenStraws[] = $chosenStraw;
    }

    $detectedWinnerIdx = null;
    foreach ($assignments as $playerIdx => $strawIdx) {
        if ($strawIdx !== null && $straws[$strawIdx] === 100) {
            $detectedWinnerIdx = $playerIdx;
            break;
        }
    }

    $correct = $detectedWinnerIdx !== null && $assignments[$detectedWinnerIdx] === $correctWinnerPos;

    return [
        'player_count'       => $playerCount,
        'player_names'       => $playerNames,
        'straws'             => $straws,
        'correct_winner_pos' => $correctWinnerPos,
        'assignments'        => $assignments,
        'pick_order'         => $pickOrder,
        'detected_winner'    => $detectedWinnerIdx,
        'winner_name'        => $detectedWinnerIdx !== null ? $playerNames[$detectedWinnerIdx] : null,
        'is_correct'         => $correct,
        'all_picked'         => !in_array(null, $assignments, true),
        'unique_picks'       => count(array_unique(array_filter($assignments, fn($v) => $v !== null))) === $playerCount,
    ];
}

// ── Build aggregate stats from a list of game results ────────────────────────
function aggregate(array $results): array {
    $total         = count($results);
    $correct       = 0;
    $errors        = [];
    $winByPosition = [];
    $winByCount    = [];

    // expected_wins[slot] = Σ(1/n) over every game where that slot existed
    $expectedByPosition = [];

    foreach ($results as $i => $r) {
        if ($r['is_correct'])  $correct++;
        else                   $errors[] = "Game $i: winner detection mismatch";
        if (!$r['all_picked'])  $errors[] = "Game $i: not all players picked";
        if (!$r['unique_picks']) $errors[] = "Game $i: duplicate straw assignment";

        $n   = $r['player_count'];
        $pos = $r['detected_winner'];
        $winByCount[$n] = ($winByCount[$n] ?? 0) + ($pos !== null ? 1 : 0);
        if ($pos !== null) $winByPosition[$pos] = ($winByPosition[$pos] ?? 0) + 1;

        // Each slot 0..(n-1) had a 1/n chance of winning this game
        for ($s = 0; $s < $n; $s++) {
            $expectedByPosition[$s] = ($expectedByPosition[$s] ?? 0.0) + (1.0 / $n);
        }
    }

    // Normalised χ²: Σ((observed - expected)² / expected) per slot
    // This correctly accounts for slots appearing in fewer games.
    $chiSq  = 0.0;
    $maxPos = empty($winByPosition) ? 0 : max(array_keys($winByPosition));
    for ($p = 0; $p <= $maxPos; $p++) {
        $obs = $winByPosition[$p] ?? 0;
        $exp = $expectedByPosition[$p] ?? 0;
        if ($exp > 0) $chiSq += ($obs - $exp) ** 2 / $exp;
    }

    // Also expose the expected counts so the UI can show them
    ksort($expectedByPosition);
    ksort($winByCount);

    return [
        'total'               => $total,
        'correct'             => $correct,
        'errors'              => $errors,
        'win_by_position'     => $winByPosition,
        'expected_by_position'=> array_map(fn($v) => round($v, 2), $expectedByPosition),
        'win_by_count'        => $winByCount,
        'chi_square'          => round($chiSq, 3),
    ];
}

// ── Save a run to disk ────────────────────────────────────────────────────────
function save_run(array $payload): string {
    $ts   = time();
    $rand = bin2hex(random_bytes(3));
    $file = TEST_RUNS_DIR . '/' . $ts . '-' . $rand . '.json';
    file_put_contents($file, json_encode($payload));
    return basename($file, '.json'); // run ID
}

// ── Load all saved runs (summary only, no per-game detail) ───────────────────
function load_run_index(): array {
    $runs = [];
    foreach (glob(TEST_RUNS_DIR . '/*.json') as $f) {
        $d = json_decode(@file_get_contents($f), true);
        if (!is_array($d)) continue;
        $runs[] = [
            'id'         => basename($f, '.json'),
            'ts'         => $d['ts']    ?? 0,
            'min'        => $d['min']   ?? '?',
            'max'        => $d['max']   ?? '?',
            'total'      => $d['agg']['total']      ?? 0,
            'correct'    => $d['agg']['correct']    ?? 0,
            'chi_square' => $d['agg']['chi_square'] ?? null,
            'errors'     => count($d['agg']['errors'] ?? []),
        ];
    }
    usort($runs, fn($a, $b) => $b['ts'] - $a['ts']);
    return $runs;
}

// ── Load all saved runs merged into one aggregate ─────────────────────────────
function load_overall_report(): array {
    $allGames      = [];
    $runSummaries  = [];

    foreach (glob(TEST_RUNS_DIR . '/*.json') as $f) {
        $d = json_decode(@file_get_contents($f), true);
        if (!is_array($d) || empty($d['games'])) continue;
        $allGames     = array_merge($allGames, $d['games']);
        $runSummaries[] = [
            'id'    => basename($f, '.json'),
            'ts'    => $d['ts']  ?? 0,
            'min'   => $d['min'] ?? '?',
            'max'   => $d['max'] ?? '?',
            'agg'   => $d['agg'] ?? [],
        ];
    }

    usort($runSummaries, fn($a, $b) => $a['ts'] - $b['ts']);

    return [
        'run_count'  => count($runSummaries),
        'runs'       => $runSummaries,
        'overall'    => aggregate($allGames),
    ];
}

// ════════════════════════════════════════════════════════════════════════════
// API endpoints
// ════════════════════════════════════════════════════════════════════════════

// ── POST ?run=1 ──────────────────────────────────────────────────────────────
if (isset($_GET['run'])) {
    header('Content-Type: application/json');

    $min   = max(2, (int)($_GET['min']   ?? 2));
    $max   = max($min, min(20, (int)($_GET['max']   ?? 8)));
    $count = max(1, min(200, (int)($_GET['count'] ?? 20)));

    $names   = collect_names();
    $results = [];

    for ($i = 0; $i < $count; $i++) {
        $n         = random_int($min, $max);
        $results[] = simulate_game($n, $names);
    }

    $agg = aggregate($results);

    $payload = [
        'ts'     => time(),
        'min'    => $min,
        'max'    => $max,
        'agg'    => $agg,
        'games'  => $results,
    ];

    $runId = save_run($payload);

    echo json_encode(array_merge($agg, [
        'games'      => $results,
        'names_pool' => $names,
        'run_id'     => $runId,
    ]));
    exit;
}

// ── GET ?index=1 — run list ──────────────────────────────────────────────────
if (isset($_GET['index'])) {
    header('Content-Type: application/json');
    echo json_encode(load_run_index());
    exit;
}

// ── GET ?report=1 — full compiled report ─────────────────────────────────────
if (isset($_GET['report'])) {
    header('Content-Type: application/json');
    echo json_encode(load_overall_report());
    exit;
}

// ── GET ?delete=<id> — delete a single run ───────────────────────────────────
if (isset($_GET['delete'])) {
    header('Content-Type: application/json');
    $id   = preg_replace('/[^a-z0-9\-]/i', '', $_GET['delete']);
    $file = TEST_RUNS_DIR . '/' . $id . '.json';
    if (file_exists($file)) {
        unlink($file);
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Extraction Test Harness</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', sans-serif; background: #0f0f13; color: #e2e2e8; min-height: 100vh; padding: 2rem; }

h1 { font-size: 1.4rem; font-weight: 700; margin-bottom: 1.5rem; color: #c084fc; letter-spacing: .03em; }
h2 { font-size: 1rem; font-weight: 600; color: #a3a3c2; margin-bottom: .75rem; }
h3 { font-size: .9rem; font-weight: 600; color: #8080b0; margin-bottom: .5rem; }
p  { font-size: .85rem; color: #7070a0; line-height: 1.5; }

/* Tabs */
.tabs { display: flex; gap: 4px; margin-bottom: 2rem; border-bottom: 1px solid #2a2a4a; padding-bottom: 0; }
.tab  { padding: .5rem 1.1rem; border-radius: 8px 8px 0 0; border: 1px solid transparent;
        font-size: .85rem; font-weight: 600; cursor: pointer; color: #7070a0; background: none;
        border-bottom: none; position: relative; top: 1px; }
.tab:hover  { color: #c084fc; }
.tab.active { background: #1a1a2e; border-color: #2a2a4a; border-bottom-color: #1a1a2e; color: #c084fc; }
.tab-panel  { display: none; }
.tab-panel.active { display: block; }

/* Controls */
.controls { display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; margin-bottom: 2rem; }
.field { display: flex; flex-direction: column; gap: .3rem; }
label { font-size: .75rem; color: #9090b0; text-transform: uppercase; letter-spacing: .07em; }
input[type=number] { width: 90px; padding: .45rem .6rem; border-radius: 6px;
  border: 1px solid #3a3a5c; background: #1a1a2e; color: #e2e2e8; font-size: .95rem; }
.btn { padding: .5rem 1.4rem; border-radius: 8px; border: none; cursor: pointer;
  font-weight: 700; font-size: .9rem; transition: opacity .15s; color: #fff; }
.btn:disabled { opacity: .45; cursor: default; }
.btn:not(:disabled):hover { opacity: .85; }
.btn-primary { background: linear-gradient(135deg,#7c3aed,#a855f7); }
.btn-ghost   { background: #1e1e32; border: 1px solid #3a3a5c; color: #a0a0c0; font-size: .82rem; padding: .35rem .9rem; }
.btn-danger  { background: #5a1a1a; border: 1px solid #8b2020; color: #e63946; font-size: .82rem; padding: .35rem .7rem; }

/* Summary cards */
.summary { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
.card { background: #1a1a2e; border: 1px solid #2a2a4a; border-radius: 10px; padding: 1rem; }
.card-val   { font-size: 1.9rem; font-weight: 800; color: #c084fc; }
.card-label { font-size: .72rem; color: #7070a0; margin-top: .2rem; }
.card.ok   { border-color: #2d6a4f; } .card.ok   .card-val { color: #52b788; }
.card.warn { border-color: #6b4c00; } .card.warn .card-val { color: #f4a261; }
.card.err  { border-color: #6b1a1a; } .card.err  .card-val { color: #e63946; }

/* Bar chart */
.dist { margin-bottom: 2rem; }
.bar-row   { display: flex; align-items: center; gap: .6rem; margin-bottom: .35rem; font-size: .85rem; }
.bar-label { width: 80px; color: #9090b0; text-align: right; flex-shrink: 0; }
.bar-track { flex: 1; background: #1e1e32; border-radius: 4px; height: 18px; overflow: hidden; position: relative; }
.bar-fill  { height: 100%; border-radius: 4px; background: linear-gradient(90deg,#7c3aed,#a855f7); transition: width .4s; }
.bar-count { width: 30px; font-size: .8rem; color: #7070a0; }

/* Errors */
.errors { margin-bottom: 2rem; }
.error-item { background: #2a0a0a; border: 1px solid #6b1a1a; border-radius: 6px;
  padding: .5rem .8rem; font-size: .85rem; color: #e63946; margin-bottom: .4rem; }

/* Table */
.table-wrap { overflow-x: auto; margin-bottom: 2rem; }
table { width: 100%; border-collapse: collapse; font-size: .82rem; }
th { background: #1a1a2e; color: #7070a0; font-weight: 600; padding: .5rem .7rem;
  text-align: left; border-bottom: 1px solid #2a2a4a; }
td { padding: .45rem .7rem; border-bottom: 1px solid #1e1e32; vertical-align: middle; }
tr.ok  td:first-child { border-left: 3px solid #52b788; }
tr.err td:first-child { border-left: 3px solid #e63946; }
tr:hover td { background: #1e1e32; }
.tag { display: inline-block; border-radius: 4px; padding: 1px 6px; font-size: .75rem; font-weight: 600; }
.tag.win  { background: #2d6a4f; color: #52b788; }
.tag.err  { background: #6b1a1a; color: #e63946; }
.tag.info { background: #1a2a4a; color: #7fb0e8; }
.straw-list { display: flex; flex-wrap: wrap; gap: 3px; }
.straw-pill { border-radius: 4px; padding: 1px 6px; font-size: .75rem; font-weight: 700; background: #2a2a4a; }
.straw-pill.winner { background: #7c3aed; color: #fff; }

/* Run history */
.run-row { display: flex; align-items: center; gap: .8rem; background: #1a1a2e;
  border: 1px solid #2a2a4a; border-radius: 8px; padding: .7rem 1rem; margin-bottom: .5rem; }
.run-row:hover { border-color: #3a3a6a; }
.run-ts   { font-size: .8rem; color: #7070a0; white-space: nowrap; }
.run-meta { font-size: .82rem; color: #a0a0c0; flex: 1; }
.run-stat { font-size: .8rem; padding: 2px 7px; border-radius: 4px; }
.run-stat.ok  { background: #1a3a2a; color: #52b788; }
.run-stat.err { background: #3a1a1a; color: #e63946; }

/* Report box */
.report-box { background: #0d0d18; border: 1px solid #2a2a4a; border-radius: 10px;
  padding: 1.2rem 1.4rem; font-family: monospace; font-size: .8rem; line-height: 1.7;
  color: #c0c0e0; white-space: pre-wrap; word-break: break-word;
  max-height: 60vh; overflow-y: auto; margin-bottom: 1rem; }
.report-actions { display: flex; gap: .7rem; margin-bottom: 2rem; flex-wrap: wrap; }

#loading { color: #9090b0; font-style: italic; margin-bottom: 1rem; }
.sep { border: none; border-top: 1px solid #2a2a4a; margin: 1.5rem 0; }
.muted { color: #7070a0; font-size: .82rem; }
</style>
</head>
<body>
<h1>🍫 Extraction Test Harness</h1>

<div class="tabs">
  <button class="tab active" onclick="switchTab('run')">Run tests</button>
  <button class="tab" onclick="switchTab('history')">Run history</button>
  <button class="tab" onclick="switchTab('report')">Overall report</button>
</div>

<!-- ══════════════════════════════════════════════════════ RUN TAB -->
<div id="tab-run" class="tab-panel active">
  <div class="controls">
    <div class="field"><label>Min players</label><input type="number" id="min" value="2" min="2" max="18"></div>
    <div class="field"><label>Max players</label><input type="number" id="max" value="8" min="2" max="20"></div>
    <div class="field"><label>Games to run</label><input type="number" id="count" value="20" min="1" max="200"></div>
    <button class="btn btn-primary" id="runBtn" onclick="runTests()">Run tests</button>
  </div>

  <div id="loading" style="display:none">Running simulations…</div>

  <div id="run-output" style="display:none">
    <div class="summary" id="summary"></div>
    <div id="errorsSection" class="errors" style="display:none">
      <h2>Errors detected</h2>
      <div id="errorsList"></div>
    </div>
    <div class="dist">
      <h2>Win distribution by join-slot</h2>
      <p style="margin-bottom:.8rem">Each bar = wins for that player position. Should be roughly equal across runs.</p>
      <div id="distBars"></div>
    </div>
    <h2>Individual game results</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Players</th><th>Winner</th><th>Straw draws</th><th>Status</th></tr></thead>
        <tbody id="tableBody"></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════ HISTORY TAB -->
<div id="tab-history" class="tab-panel">
  <div style="display:flex;gap:.7rem;align-items:center;margin-bottom:1.2rem">
    <h2 style="margin:0">Saved runs</h2>
    <button class="btn btn-ghost" onclick="loadHistory()">Refresh</button>
  </div>
  <div id="historyList"><span class="muted">Loading…</span></div>
</div>

<!-- ══════════════════════════════════════════════════════ REPORT TAB -->
<div id="tab-report" class="tab-panel">
  <div style="display:flex;gap:.7rem;align-items:center;margin-bottom:1.2rem">
    <h2 style="margin:0">Compiled report</h2>
    <button class="btn btn-ghost" onclick="loadReport()">Refresh</button>
  </div>
  <p style="margin-bottom:1.2rem">Aggregates every saved run into one report. Copy and paste it for review.</p>
  <div class="report-actions">
    <button class="btn btn-primary" onclick="copyReport()">Copy to clipboard</button>
  </div>
  <div id="reportBox" class="report-box">Loading…</div>
</div>

<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(name) {
  document.querySelectorAll('.tab').forEach((t, i) => {
    const panels = ['run','history','report'];
    t.classList.toggle('active', panels[i] === name);
  });
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  if (name === 'history') loadHistory();
  if (name === 'report')  loadReport();
}

// ── Run tests ─────────────────────────────────────────────────────────────────
async function runTests() {
  const min   = parseInt(document.getElementById('min').value)   || 2;
  const max   = parseInt(document.getElementById('max').value)   || 8;
  const count = parseInt(document.getElementById('count').value) || 20;
  if (min > max) { alert('Min must be ≤ max'); return; }

  const btn = document.getElementById('runBtn');
  btn.disabled = true;
  document.getElementById('loading').style.display = 'block';
  document.getElementById('run-output').style.display = 'none';

  try {
    const res  = await fetch(`?run=1&min=${min}&max=${max}&count=${count}&_=${Date.now()}`);
    const data = await res.json();
    renderResults(data);
  } catch(e) {
    alert('Request failed: ' + e.message);
  } finally {
    btn.disabled = false;
    document.getElementById('loading').style.display = 'none';
  }
}

function renderResults(data) {
  const { games, total, correct, errors, win_by_position, chi_square, run_id } = data;
  const failures = total - correct;
  const pctOk    = ((correct / total) * 100).toFixed(1);
  const chiClass  = chi_square < 5 ? 'ok' : chi_square < 15 ? 'warn' : 'err';

  document.getElementById('summary').innerHTML = `
    <div class="card ${failures === 0 ? 'ok' : 'err'}">
      <div class="card-val">${pctOk}%</div><div class="card-label">Correct detections</div></div>
    <div class="card ${failures === 0 ? 'ok' : 'err'}">
      <div class="card-val">${failures}</div><div class="card-label">Detection failures</div></div>
    <div class="card">
      <div class="card-val">${total}</div><div class="card-label">Games simulated</div></div>
    <div class="card ${chiClass}">
      <div class="card-val">${chi_square}</div><div class="card-label">χ² fairness</div></div>
    <div class="card ${errors.length === 0 ? 'ok' : 'err'}">
      <div class="card-val">${errors.length}</div><div class="card-label">Integrity errors</div></div>
    <div class="card info" style="border-color:#2a3a5a">
      <div class="card-val" style="font-size:1rem;color:#7fb0e8;word-break:break-all">${run_id ?? '—'}</div>
      <div class="card-label">Saved run ID</div></div>`;

  const errSec = document.getElementById('errorsSection');
  if (errors.length > 0) {
    errSec.style.display = 'block';
    document.getElementById('errorsList').innerHTML =
      errors.map(e => `<div class="error-item">${e}</div>`).join('');
  } else { errSec.style.display = 'none'; }

  const positions = Object.keys(win_by_position).map(Number).sort((a, b) => a - b);
  const exp       = data.expected_by_position || {};
  const maxWins   = Math.max(...positions.map(p => Math.max(win_by_position[p]||0, exp[p]||0)), 1);
  document.getElementById('distBars').innerHTML = positions.map(pos => {
    const wins  = win_by_position[pos] || 0;
    const expV  = exp[pos] != null ? exp[pos] : null;
    const ratio = expV ? (wins / expV).toFixed(2) : '—';
    const cls   = expV == null ? '' : wins > expV * 1.15 ? 'style="background:linear-gradient(90deg,#7c3aed,#e63946)"'
                                   : wins < expV * 0.85  ? 'style="background:linear-gradient(90deg,#7c3aed,#f4a261)"' : '';
    return `<div class="bar-row">
      <span class="bar-label">Slot ${pos + 1}</span>
      <div class="bar-track" title="expected: ${expV ?? '?'}">
        ${expV != null ? `<div style="position:absolute;height:18px;width:${(expV/maxWins*100).toFixed(1)}%;border-right:2px dashed #555;pointer-events:none"></div>` : ''}
        <div class="bar-fill" ${cls} style="width:${(wins/maxWins*100).toFixed(1)}%;position:relative"></div>
      </div>
      <span class="bar-count">${wins}</span>
      <span style="font-size:.72rem;color:${wins > (expV||0)*1.15 ? '#e63946' : wins < (expV||0)*0.85 ? '#f4a261' : '#52b788'};width:42px">×${ratio}</span>
    </div>`;
  }).join('') || '<span class="muted">No data</span>';
  document.getElementById('distBars').insertAdjacentHTML('beforebegin',
    `<p style="font-size:.75rem;color:#7070a0;margin-bottom:.5rem">Dashed line = expected wins (Σ 1/n). ×ratio: green = fair, orange = under, red = over.</p>`);

  document.getElementById('tableBody').innerHTML = games.map((g, i) => {
    const pills = g.player_names.map((name, idx) => {
      const val = g.assignments[idx] !== null ? g.straws[g.assignments[idx]] : '?';
      return `<span class="straw-pill ${idx === g.detected_winner ? 'winner' : ''}">${name}: ${val}</span>`;
    }).join('');
    const extras = [
      !g.all_picked   ? '<span class="tag err">not all picked</span>' : '',
      !g.unique_picks ? '<span class="tag err">dup straw</span>'      : '',
    ].filter(Boolean).join(' ');
    return `<tr class="${g.is_correct ? 'ok' : 'err'}">
      <td>${i + 1}</td><td>${g.player_count}p</td>
      <td>${g.winner_name ?? '<em>none</em>'}</td>
      <td><div class="straw-list">${pills}</div></td>
      <td>${g.is_correct ? '<span class="tag win">✓ correct</span>' : '<span class="tag err">✗ wrong</span>'} ${extras}</td></tr>`;
  }).join('');

  document.getElementById('run-output').style.display = 'block';
}

// ── History ───────────────────────────────────────────────────────────────────
async function loadHistory() {
  document.getElementById('historyList').innerHTML = '<span class="muted">Loading…</span>';
  const data = await fetch('?index=1').then(r => r.json());
  if (!data.length) {
    document.getElementById('historyList').innerHTML = '<span class="muted">No saved runs yet. Run some tests first.</span>';
    return;
  }
  document.getElementById('historyList').innerHTML = data.map(r => {
    const d    = new Date(r.ts * 1000);
    const ts   = d.toLocaleDateString() + ' ' + d.toLocaleTimeString();
    const pct  = r.total ? ((r.correct / r.total) * 100).toFixed(1) : '—';
    const ok   = r.errors === 0;
    return `<div class="run-row">
      <span class="run-ts">${ts}</span>
      <span class="run-meta">${r.total} games &nbsp;·&nbsp; ${r.min}–${r.max} players</span>
      <span class="run-stat ${ok ? 'ok' : 'err'}">${pct}% correct</span>
      ${r.chi_square !== null ? `<span class="run-stat ${r.chi_square < 5 ? 'ok' : r.chi_square < 15 ? '' : 'err'}">χ²&nbsp;${r.chi_square}</span>` : ''}
      ${r.errors > 0 ? `<span class="run-stat err">${r.errors} errors</span>` : ''}
      <button class="btn btn-danger" onclick="deleteRun('${r.id}')">✕</button>
    </div>`;
  }).join('');
}

async function deleteRun(id) {
  if (!confirm('Delete this run?')) return;
  await fetch('?delete=' + id);
  loadHistory();
}

// ── Report ────────────────────────────────────────────────────────────────────
let lastReportText = '';

async function loadReport() {
  document.getElementById('reportBox').textContent = 'Compiling…';
  const data = await fetch('?report=1').then(r => r.json());
  lastReportText = buildReportText(data);
  document.getElementById('reportBox').textContent = lastReportText;
}

function buildReportText(data) {
  const { run_count, runs, overall } = data;
  if (!run_count) return 'No saved runs yet.';

  const o   = overall;
  const pct = o.total ? ((o.correct / o.total) * 100).toFixed(2) : '—';

  const lines = [];
  lines.push('═══════════════════════════════════════════════════════');
  lines.push('  CHOCOLOTTERY — Extraction Algorithm Test Report');
  lines.push('  Generated: ' + new Date().toISOString());
  lines.push('═══════════════════════════════════════════════════════');
  lines.push('');
  lines.push('OVERVIEW');
  lines.push('────────');
  lines.push(`Total test runs:       ${run_count}`);
  lines.push(`Total games simulated: ${o.total}`);
  lines.push(`Correct detections:    ${o.correct} / ${o.total}  (${pct}%)`);
  lines.push(`Detection failures:    ${o.total - o.correct}`);
  lines.push(`Integrity errors:      ${o.errors.length}`);
  lines.push(`χ² uniformity score:   ${o.chi_square}  (normalised per-slot; < 5 = good, < 20 = ok, ≥ 20 = investigate)`);
  lines.push('');

  if (o.errors.length > 0) {
    lines.push('ERRORS FOUND');
    lines.push('────────────');
    o.errors.forEach(e => lines.push('  ⚠ ' + e));
    lines.push('');
  }

  lines.push('WIN DISTRIBUTION BY PLAYER JOIN-SLOT (combined)');
  lines.push('  actual vs expected (Σ 1/n per slot) — ratio shows fairness');
  lines.push('────────────────────────────────────────────────');
  const positions = Object.keys(o.win_by_position).map(Number).sort((a, b) => a - b);
  const exp       = o.expected_by_position || {};
  const maxWins   = positions.length ? Math.max(...positions.map(p => Math.max(o.win_by_position[p]||0, exp[p]||0))) : 1;
  positions.forEach(pos => {
    const wins  = o.win_by_position[pos] || 0;
    const expV  = exp[pos] != null ? exp[pos] : null;
    const bar   = '█'.repeat(Math.round(wins / maxWins * 20)).padEnd(20);
    const ratio = expV ? (wins / expV).toFixed(2) : '—';
    const flag  = expV && wins > expV * 1.15 ? ' ⚠ OVER'
                : expV && wins < expV * 0.85 ? ' ⚠ UNDER' : '';
    lines.push(`  Slot ${String(pos + 1).padStart(2)}  ${bar}  actual:${String(wins).padStart(4)}  expected:${String(expV ?? '?').padStart(6)}  ×${ratio}${flag}`);
  });
  lines.push('');

  if (o.win_by_count && Object.keys(o.win_by_count).length) {
    lines.push('GAMES BY PLAYER COUNT');
    lines.push('──────────────────────');
    Object.entries(o.win_by_count).sort(([a],[b]) => a - b).forEach(([n, c]) => {
      lines.push(`  ${n} players: ${c} games`);
    });
    lines.push('');
  }

  lines.push('PER-RUN SUMMARY');
  lines.push('───────────────');
  runs.forEach((r, i) => {
    const d   = new Date(r.ts * 1000);
    const ts  = d.toISOString().replace('T',' ').slice(0,19);
    const agg = r.agg;
    const p   = agg.total ? ((agg.correct / agg.total) * 100).toFixed(1) : '—';
    lines.push(`  Run ${String(i+1).padStart(2)}.  ${ts}  |  ${agg.total} games  |  ${r.min}–${r.max} players  |  ${p}% correct  |  χ²=${agg.chi_square}  |  ${agg.errors?.length ?? 0} errors`);
  });
  lines.push('');
  lines.push('═══════════════════════════════════════════════════════');

  return lines.join('\n');
}

async function copyReport() {
  if (!lastReportText) await loadReport();
  try {
    await navigator.clipboard.writeText(lastReportText);
    const btn = event.target;
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy to clipboard', 2000);
  } catch(e) {
    alert('Copy failed — select and copy manually.');
  }
}

// Load report on first visit to that tab (via loadReport call in switchTab)
</script>
</body>
</html>
