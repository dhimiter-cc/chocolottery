<?php
require_once __DIR__ . '/config.php';

$data = load_leaderboard();
$wins = $data['wins'] ?? [];

$months = [];
foreach ($wins as $w) {
    if (!empty($w['month'])) $months[$w['month']] = true;
}
$months = array_keys($months);
sort($months);

$selectedMonth = $_GET['month'] ?? '';
if ($selectedMonth && !preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) $selectedMonth = '';

$filtered = $wins;
if ($selectedMonth) {
    $filtered = array_filter($wins, fn($w) => ($w['month'] ?? '') === $selectedMonth);
}

// Aggregate by name
$counts = [];
foreach ($filtered as $w) {
    $n = $w['name'] ?? 'Unknown';
    if (!isset($counts[$n])) $counts[$n] = ['name' => $n, 'wins' => 0, 'months' => []];
    $counts[$n]['wins']++;
    $m = $w['month'] ?? '';
    if ($m) $counts[$n]['months'][$m] = ($counts[$n]['months'][$m] ?? 0) + 1;
}
usort($counts, fn($a, $b) => $b['wins'] - $a['wins']);

// Best month for each (only when viewing all-time)
foreach ($counts as &$c) {
    if (!empty($c['months'])) {
        arsort($c['months']);
        $c['best_month'] = array_key_first($c['months']);
        $c['best_month_count'] = $c['months'][$c['best_month']];
    } else {
        $c['best_month'] = null;
    }
}
unset($c);

// Prev/next month nav
$prevMonth = $nextMonth = null;
if ($selectedMonth && $months) {
    $idx = array_search($selectedMonth, $months);
    if ($idx !== false) {
        if ($idx > 0) $prevMonth = $months[$idx - 1];
        if ($idx < count($months) - 1) $nextMonth = $months[$idx + 1];
    }
}

function fmt_month($m) {
    if (!$m) return '';
    $ts = strtotime($m . '-01');
    return date('F Y', $ts);
}

$title = $selectedMonth ? fmt_month($selectedMonth) : 'All Time';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Leaderboard — Chocolate Lottery</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="page-leaderboard">
<div class="parchment">
  <header class="masthead">
    <div>
      <h1 class="title">leaderboard</h1>
      <p class="tagline">The Chocolate Industrial Complex 🏭</p>
    </div>
    <a href="index.php" class="btn btn-ghost">← Back</a>
  </header>

  <div class="month-nav">
    <a class="btn btn-ghost <?= !$selectedMonth ? 'active' : '' ?>" href="leaderboard.php">All time</a>
    <?php if ($prevMonth): ?><a class="btn btn-ghost" href="?month=<?= $prevMonth ?>">← <?= fmt_month($prevMonth) ?></a><?php endif; ?>
    <span class="current-month"><?= htmlspecialchars($title) ?></span>
    <?php if ($nextMonth): ?><a class="btn btn-ghost" href="?month=<?= $nextMonth ?>"><?= fmt_month($nextMonth) ?> →</a><?php endif; ?>
  </div>

  <?php if (count($months) > 1): ?>
  <div class="month-list">
    <?php foreach (array_reverse($months) as $m): ?>
      <a class="chip <?= $m === $selectedMonth ? 'active' : '' ?>" href="?month=<?= $m ?>"><?= fmt_month($m) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <main class="panel leaderboard-panel">
    <?php if (empty($counts)): ?>
      <p class="muted center">No wins yet. The chocolate sits, lonely, waiting for its first hero.</p>
    <?php else: ?>
    <table class="leaderboard">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Wins</th>
          <?php if (!$selectedMonth): ?><th>Best month</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($counts as $i => $c): ?>
          <tr class="<?= $i === 0 ? 'top-rank' : '' ?>">
            <td class="rank"><?= $i + 1 ?><?= $i === 0 ? ' 👑' : '' ?></td>
            <td><?= htmlspecialchars($c['name']) ?></td>
            <td><strong><?= $c['wins'] ?></strong></td>
            <?php if (!$selectedMonth): ?>
              <td class="muted"><?= $c['best_month'] ? fmt_month($c['best_month']) . ' (' . $c['best_month_count'] . ')' : '—' ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </main>

  <footer class="foot">
    <a href="index.php">← Back to the lottery</a>
  </footer>
</div>
</body>
</html>
