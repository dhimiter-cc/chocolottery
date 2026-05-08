<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Chocolate Lottery</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="page-landing">
<div class="parchment">
  <header class="masthead">
    <div>
      <h1 class="title">chocolate.lottery</h1>
      <p class="tagline">One chocolate. Many victims. Pick the longest straw. Don't make it weird.</p>
    </div>
    <a href="leaderboard.php" class="btn btn-ghost">Leaderboard →</a>
  </header>

  <main class="landing-grid">
    <section class="panel">
      <h2>Start a new round</h2>
      <p class="muted">Open a new game. Share the code. Watch productivity plummet.</p>
      <button id="create-btn" class="btn btn-primary big" style="margin-top: 14px;">Create a game</button>
    </section>

    <section class="panel">
      <h2>Join an existing round</h2>
      <p class="muted">Got a code? Drop in. No HR forms. No team-building exercises.</p>
      <form id="join-form" class="join-form" style="margin-top: 14px;">
        <label>Game code
          <input type="text" id="join-code" placeholder="CHOC-1234" autocomplete="off" required>
        </label>
        <label>Your name
          <input type="text" id="join-name" placeholder="your name" maxlength="30" autocomplete="off" required>
        </label>
        <button type="submit" class="btn btn-primary">Join the game</button>
      </form>
      <p id="join-error" class="error"></p>
    </section>
  </main>

  <footer class="foot">
    <a href="leaderboard.php">The Chocolate Industrial Complex 🏭</a>
  </footer>
</div>

<script>
document.getElementById('create-btn').addEventListener('click', async () => {
  try {
    const res = await fetch('api/create.php', { method: 'POST' });
    const data = await res.json();
    if (data.code) window.location = 'game.php?code=' + encodeURIComponent(data.code);
  } catch { alert('Could not create game'); }
});

document.getElementById('join-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const code = document.getElementById('join-code').value.trim().toUpperCase();
  const name = document.getElementById('join-name').value.trim();
  const err = document.getElementById('join-error');
  err.textContent = '';
  try {
    const res = await fetch('api/join.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ code, name })
    });
    const data = await res.json();
    if (data.error) { err.textContent = data.error; return; }
    window.location = 'game.php?code=' + encodeURIComponent(data.code);
  } catch { err.textContent = 'Join failed'; }
});
</script>
</body>
</html>
