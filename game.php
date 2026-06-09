<?php
require_once __DIR__ . '/config.php';
$code = strtoupper(trim($_GET['code'] ?? ''));
if (!$code) { header('Location: index.php'); exit; }
$game = load_game($code);
if (!$game) { header('Location: index.php?err=notfound'); exit; }
$myToken = get_cookie('player_token');
$alreadyIn = $myToken && isset($game['players'][$myToken]);
$presetName = get_cookie('player_name') ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($code) ?> — Chocolate Lottery</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="page-game" data-code="<?= htmlspecialchars($code) ?>" data-already-joined="<?= $alreadyIn ? '1' : '0' ?>">

<div id="name-modal" class="modal" <?= $alreadyIn ? 'hidden' : '' ?>>
  <div class="modal-card">
    <h2>Join the round</h2>
    <p class="muted">Game <span class="stamp"><?= htmlspecialchars($code) ?></span></p>
    <form id="name-form" style="margin-top:18px;">
      <label>Display name
        <input type="text" id="modal-name" maxlength="30" required value="<?= htmlspecialchars($presetName) ?>" placeholder="your name">
      </label>
      <button type="submit" class="btn btn-primary">Join</button>
      <p id="modal-error" class="error"></p>
    </form>
  </div>
</div>

<div class="parchment game-room">
  <header class="game-head">
    <div>
      <h1 class="title small">chocolate.lottery</h1>
      <p class="muted">Game <span id="share-code" class="stamp" title="Click to copy share link"><?= htmlspecialchars($code) ?></span></p>
    </div>
    <div class="head-actions">
      <button type="button" id="fairness-btn" class="btn btn-ghost">⚖️ Fairness</button>
      <a href="leaderboard.php" class="btn btn-ghost">Leaderboard</a>
      <a href="index.php" class="btn btn-ghost">Home</a>
    </div>
  </header>

  <div class="game-grid">

    <!-- Snacks: left column on desktop -->
    <section class="panel snacks-panel">
      <div class="section-head">
        <h2>Snack votes</h2>
        <div class="section-head-right">
          <button type="button" id="cupboard-trigger" class="cupboard-trigger-btn">🍫 Cupboard</button>
          <span class="count" id="snacks-count">0</span>
        </div>
      </div>
      <p class="muted">Pitch ideas. Upvote favourites. Highest-voted wins. Tie or no votes? Random. (Dwight, no beets.)</p>
      <div id="snack-quick-wrap" hidden>
        <div class="snack-quick-label">In stock — click to add &amp; vote</div>
        <div id="snack-quick-picks" class="snack-quick-picks"></div>
      </div>
      <div class="snack-form-divider">or type your own</div>
      <form id="snack-form" class="snack-form">
        <input type="text" id="snack-input" maxlength="80" placeholder="Tim Tams, Speculoos, that weird Schrute beet thing…" autocomplete="off">
        <button type="submit" class="btn">Add</button>
      </form>
      <div id="snacks-list" class="snacks-list"></div>
      <div id="snack-non-voters" class="snack-non-voters" hidden></div>
      <p id="snack-error" class="error"></p>
    </section>

    <!-- Chat: right column on desktop, bottom drawer on mobile -->
    <section class="panel chat-panel" id="chat-panel" hidden>
      <div class="section-head">
        <h2>💬 Chat</h2>
        <div class="section-head-right">
          <span class="count" id="chat-count">0</span>
          <button type="button" id="chat-drawer-close" class="chat-drawer-close">✕</button>
        </div>
      </div>
      <div id="chat-log" class="chat-log"></div>
      <form id="chat-form" class="chat-form">
        <input type="text" id="chat-input" maxlength="240" placeholder="say something…" autocomplete="off">
        <button type="submit" class="btn">Send</button>
      </form>
      <p id="chat-error" class="error"></p>
    </section>

    <!-- Stage: right column on desktop, top on mobile -->
    <section class="panel stage-panel">
      <div class="phase-bar">
        <div class="phase-info">
          <span id="phase-pill" class="phase-pill">Lobby</span>
          <span id="phase-detail" class="phase-detail">Waiting for the brave to gather…</span>
        </div>
        <button id="action-btn" class="btn btn-primary big">Start the lottery</button>
      </div>

      <div id="cup-stage" class="cup-stage" data-phase="lobby">
        <div id="straws" class="straws"></div>
        <div class="cup"></div>

        <!-- Lobby overlay: shows people gathered in the stage -->
        <div id="lobby-overlay" class="stage-overlay lobby">
          <div class="overlay-headline">In the room</div>
          <div id="lobby-players" class="lobby-players"></div>
          <div id="lobby-empty" class="overlay-sub" hidden>Waiting for someone — anyone — to show up.</div>
        </div>

        <!-- Winner overlay -->
        <div id="winner-overlay" class="stage-overlay" hidden>
          <div class="overlay-headline">🍫 Longest straw belongs to</div>
          <div id="winner-name" class="winner-name"></div>
          <div id="reveal-message" class="overlay-sub"></div>
        </div>
      </div>

      <div id="prize-card" class="prize-card" hidden>
        <div class="prize-label">🍫 Prize snack</div>
        <div class="prize-text" id="prize-text"></div>
        <div class="prize-meta" id="prize-meta"></div>
      </div>

      <div id="give-card" class="give-card" hidden>
        <div class="give-label">From the cupboard</div>
        <div id="give-status" class="give-status"></div>
        <div id="give-host-controls" class="give-controls" hidden>
          <select id="give-select"></select>
          <button id="give-btn" type="button" class="btn btn-primary">Mark given (−1)</button>
          <button id="ungive-btn" type="button" class="btn btn-ghost" hidden>Undo</button>
        </div>
        <p id="give-error" class="error" style="margin: 8px 0 0;"></p>
      </div>

      <p id="action-error" class="error" style="margin: 10px 0 0;"></p>
      <button id="restart-btn" class="restart-btn" hidden>↺ restart game</button>
    </section>

  </div>
</div>

  <!-- Mobile chat FAB + backdrop -->
  <div id="chat-backdrop" class="chat-backdrop" hidden></div>
  <button id="chat-fab" class="chat-fab" hidden aria-label="Open chat">
    💬
    <span id="chat-fab-dot" class="chat-fab-dot" hidden></span>
  </button>

<!-- Cupboard modal -->
<div id="cupboard-modal" class="modal" hidden>
  <div class="modal-card cupboard-modal-card" id="cupboard-modal-card">
    <div class="section-head" style="margin-bottom:12px;">
      <h2>🍫 The cupboard</h2>
      <span class="count" id="cupboard-count">0</span>
    </div>
    <p class="muted" id="cupboard-hint">What's actually on the shelf. Host: set stock before starting.</p>
    <form id="cupboard-form" class="cupboard-form" hidden>
      <input type="text" id="cupboard-name" maxlength="60" placeholder="e.g. KitKat" autocomplete="off">
      <input type="text" id="cupboard-stock" inputmode="numeric" maxlength="3" placeholder="qty" value="1">
      <button type="submit" class="btn">Add</button>
    </form>
    <div id="cupboard-list" class="cupboard-list"></div>
    <p id="cupboard-error" class="error"></p>
  </div>
</div>

<!-- Fairness Check modal -->
<div id="fairness-modal" class="modal" hidden>
  <div class="modal-card fairness-modal-card">
    <div class="section-head" style="margin-bottom:16px;">
      <h2>⚖️ Fairness Check</h2>
      <button type="button" id="fairness-close" class="btn btn-ghost" style="padding:4px 10px;">✕</button>
    </div>
    <p class="muted">Actual wins vs. statistically expected wins — based on how many players were in each game.</p>
    <div id="fairness-body" style="margin-top:16px;"><p class="muted center">Loading…</p></div>
    <p id="fairness-note" class="muted" style="font-size:0.8rem;margin-top:14px;"></p>
  </div>
</div>

<script src="assets/app.js"></script>
<script>
(function () {
  const modal = document.getElementById('fairness-modal');
  const body  = document.getElementById('fairness-body');
  const note  = document.getElementById('fairness-note');
  let loaded  = false;

  async function openFairness() {
    modal.removeAttribute('hidden');
    if (loaded) return;
    body.innerHTML = '<p class="muted center">Loading…</p>';
    try {
      const res  = await fetch('api/fairness.php');
      const data = await res.json();
      loaded = true;
      renderFairness(data);
    } catch {
      body.innerHTML = '<p class="error center">Could not load fairness data.</p>';
    }
  }

  function renderFairness(data) {
    if (!data.players || data.players.length === 0) {
      body.innerHTML = '<p class="muted center">No games played yet — nothing to check!</p>';
      return;
    }

    function fmtMonth(m) {
      if (!m) return '—';
      return new Date(m + '-02').toLocaleDateString('en', { month: 'short', year: 'numeric' });
    }

    const rows = data.players.map((p, i) => {
      const expected = p.expected_wins !== null ? p.expected_wins.toFixed(1) : '—';
      const score    = p.luck_score    !== null ? p.luck_score.toFixed(2)    : '—';
      const verdict  = p.verdict
        ? `<span class="fairness-verdict fairness-${p.verdict.class}">${p.verdict.emoji} ${p.verdict.label}</span>`
        : '<span class="fairness-verdict fairness-none">—</span>';

      const gameRows = (p.games || []).map(g => `
        <div class="fg-row">
          <span class="fg-month">${fmtMonth(g.month)}</span>
          <span class="fg-players">👥 ${g.participants} players</span>
          <span class="fg-chance">1 in ${g.participants} &nbsp;·&nbsp; ${g.chance_pct}% chance</span>
          <span class="fg-result ${g.won ? 'fg-won' : 'fg-lost'}">${g.won ? '🍫 Won' : '✗ Lost'}</span>
        </div>`).join('');

      const detail = p.games && p.games.length
        ? `<div class="fg-list">${gameRows}</div>`
        : '<p class="muted" style="margin:0;font-size:0.85rem;">No tracked games yet.</p>';

      return `
        <tr class="fairness-row" data-idx="${i}">
          <td><span class="fg-chevron">▸</span> ${p.name}</td>
          <td class="tc">${p.actual_wins}</td>
          <td class="tc">${expected}</td>
          <td class="tc">${score}</td>
          <td>${verdict}</td>
        </tr>
        <tr class="fairness-detail" id="fg-detail-${i}" hidden>
          <td colspan="5"><div class="fg-detail-inner">${detail}</div></td>
        </tr>`;
    }).join('');

    body.innerHTML = `
      <table class="leaderboard fairness-table">
        <thead><tr>
          <th>Name<span class="th-sub">click a row to see game history</span></th>
          <th>Wins<span class="th-sub">times you've won</span></th>
          <th>Expected<span class="th-sub">wins chance predicts, based on how many players were in each game you played</span></th>
          <th>Luck score<span class="th-sub">your wins ÷ expected — 1.0 means perfectly average</span></th>
          <th>Verdict<span class="th-sub">our completely unbiased assessment</span></th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>`;

    body.querySelectorAll('.fairness-row').forEach(row => {
      row.addEventListener('click', () => {
        const detail  = document.getElementById('fg-detail-' + row.dataset.idx);
        const chevron = row.querySelector('.fg-chevron');
        const opening = detail.hidden;
        detail.hidden = !opening;
        chevron.textContent = opening ? '▾' : '▸';
        row.classList.toggle('fairness-row-open', opening);
      });
    });

    const diff = data.total_games - data.tracked_games;
    note.textContent = diff > 0
      ? `${diff} game${diff > 1 ? 's' : ''} predate participant tracking and are excluded from expected win calculations.`
      : '';
  }

  document.getElementById('fairness-btn').addEventListener('click', openFairness);
  document.getElementById('fairness-close').addEventListener('click', () => modal.setAttribute('hidden', ''));
  modal.addEventListener('click', e => { if (e.target === modal) modal.setAttribute('hidden', ''); });
})();
</script>
</body>
</html>
