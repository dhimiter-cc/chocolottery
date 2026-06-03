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
      <p id="snack-error" class="error"></p>
    </section>

    <!-- Chat: right column on desktop, only for joined participants -->
    <section class="panel chat-panel" id="chat-panel" hidden>
      <div class="section-head">
        <h2>💬 Chat</h2>
        <span class="count" id="chat-count">0</span>
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
    </section>

  </div>
</div>

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

<script src="assets/app.js"></script>
</body>
</html>
