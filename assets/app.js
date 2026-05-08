// Chocolate Lottery — single-page live client

(function () {
  const body = document.body;
  if (!body.classList.contains('page-game')) return;

  const code = body.dataset.code;
  let alreadyJoined = body.dataset.alreadyJoined === '1';

  const $ = (id) => document.getElementById(id);

  // Always-visible elements
  const lobbyPlayers  = $('lobby-players');
  const lobbyEmpty    = $('lobby-empty');
  const snackForm     = $('snack-form');
  const snackInput    = $('snack-input');
  const snacksList    = $('snacks-list');
  const snacksCount   = $('snacks-count');
  const snackError    = $('snack-error');

  const cupStage      = $('cup-stage');
  const strawsEl      = $('straws');
  const lobbyOverlay  = $('lobby-overlay');
  const winnerOverlay = $('winner-overlay');
  const winnerName    = $('winner-name');
  const revealMessage = $('reveal-message');
  const prizeCard     = $('prize-card');
  const prizeText     = $('prize-text');
  const prizeMeta     = $('prize-meta');

  const phasePill   = $('phase-pill');
  const phaseDetail = $('phase-detail');
  const actionBtn   = $('action-btn');
  const actionError = $('action-error');

  const shareCode  = $('share-code');
  const nameModal  = $('name-modal');
  const nameForm   = $('name-form');
  const modalName  = $('modal-name');
  const modalError = $('modal-error');

  let lastState = null;
  let pickInFlight = false;
  let revealAnimated = false;
  let confettiFired = false;
  let prevPickedSet = new Set();
  let currentPhase = null;
  let prevLobbyTokens = new Set();

  // === Avatar helpers ===
  function avatarColor(name) {
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) | 0;
    const hue = ((h % 360) + 360) % 360;
    return `linear-gradient(160deg, hsl(${hue} 70% 60%) 0%, hsl(${(hue + 30) % 360} 60% 38%) 100%)`;
  }
  function avatarInitial(name) {
    const trimmed = (name || '?').trim();
    return trimmed ? trimmed.charAt(0).toUpperCase() : '?';
  }

  // === Toast ===
  function showToast(msg) {
    const t = document.createElement('div');
    t.className = 'toast';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2000);
  }

  // === Share code ===
  shareCode.addEventListener('click', () => {
    const url = window.location.origin + window.location.pathname + '?code=' + encodeURIComponent(code);
    navigator.clipboard?.writeText(url).then(() => showToast('Link copied. Share. Conquer.'));
  });

  // === Name modal ===
  if (nameForm) {
    nameForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const name = modalName.value.trim();
      if (!name) return;
      modalError.textContent = '';
      try {
        const res = await fetch('api/join.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ code, name })
        });
        const data = await res.json();
        if (data.error) { modalError.textContent = data.error; return; }
        alreadyJoined = true;
        nameModal.hidden = true;
        startLoops();
      } catch { modalError.textContent = 'Join failed'; }
    });
  }

  // === Action button (Start / New game) ===
  actionBtn.addEventListener('click', async () => {
    actionError.textContent = '';
    if (!lastState) return;

    if (lastState.state === 'lobby') {
      try {
        const res = await fetch('api/start.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ code })
        });
        const data = await res.json();
        if (data.error) { actionError.textContent = data.error; return; }
      } catch { actionError.textContent = 'Could not start'; }
    } else if (lastState.state === 'reveal' || lastState.state === 'done') {
      window.location = 'index.php';
    }
  });

  // === Snacks (always live) ===
  snackForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = snackInput.value.trim();
    snackError.textContent = '';
    if (!text) return;
    try {
      const res = await fetch('api/suggest.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ code, text })
      });
      const data = await res.json();
      if (data.error) { snackError.textContent = data.error; return; }
      snackInput.value = '';
      poll();
    } catch { snackError.textContent = 'Could not add'; }
  });

  async function vote(id) {
    try {
      const res = await fetch('api/vote.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ code, id })
      });
      const data = await res.json();
      if (data.error) showToast(data.error);
      poll();
    } catch {}
  }

  // === Polling ===
  async function poll() {
    try {
      const res = await fetch('api/state.php?code=' + encodeURIComponent(code), { cache: 'no-store' });
      if (!res.ok) return;
      render(await res.json());
    } catch {}
  }
  async function heartbeat() {
    try {
      await fetch('api/heartbeat.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ code })
      });
    } catch {}
  }

  // === Render: lobby avatars (inside the stage during lobby) ===
  function renderLobbyPlayers(players) {
    const tokens = new Set(players.map(p => p.token));
    lobbyPlayers.innerHTML = '';

    if (players.length === 0) {
      lobbyEmpty.hidden = false;
    } else {
      lobbyEmpty.hidden = true;
    }

    players.forEach(p => {
      const wrap = document.createElement('div');
      wrap.className = 'lobby-player';
      if (!p.online) wrap.classList.add('offline');
      if (p.is_me) wrap.classList.add('me');
      if (!prevLobbyTokens.has(p.token) && prevLobbyTokens.size > 0) wrap.classList.add('new');
      wrap.title = p.name + (p.online ? '' : ' (offline)');

      const av = document.createElement('div');
      av.className = 'avatar';
      av.style.background = avatarColor(p.name);
      av.textContent = avatarInitial(p.name);

      const nm = document.createElement('div');
      nm.className = 'name';
      nm.textContent = p.is_me ? p.name + ' (you)' : p.name;

      wrap.appendChild(av);
      wrap.appendChild(nm);
      lobbyPlayers.appendChild(wrap);
    });

    prevLobbyTokens = tokens;
  }

  // === Render: snacks ===
  function renderSnacks(suggestions, interactive) {
    snacksList.innerHTML = '';
    if (!suggestions || suggestions.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'snacks-empty';
      empty.textContent = interactive
        ? 'No suggestions yet. Be brave. Be specific. Be Kevin.'
        : 'No suggestions submitted.';
      snacksList.appendChild(empty);
      return;
    }
    suggestions.forEach(s => {
      const row = document.createElement('div');
      row.className = 'snack' + (s.voted ? ' voted' : '');

      const v = document.createElement('div');
      v.className = 'snack-vote';
      v.innerHTML = `<span class="arrow">▲</span><span class="num">${s.votes}</span>`;
      if (interactive) v.addEventListener('click', () => vote(s.id));
      else v.style.cursor = 'default';

      const text = document.createElement('div');
      text.className = 'snack-text';
      text.textContent = s.text;

      const author = document.createElement('div');
      author.className = 'snack-author';
      author.textContent = '— ' + s.author_name;

      row.appendChild(v);
      row.appendChild(text);
      row.appendChild(author);
      snacksList.appendChild(row);
    });
  }

  // === Render: master ===
  const PICKING_QUIPS = [
    "Pick a straw. Try to look casual.",
    "Trust your gut. Or don't. It's already decided.",
    "Choose wisely. Or wildly. We're not your boss.",
    "It's just a straw. With life-altering consequences.",
    "Statistically speaking, one of these is the bad one.",
    "Pam would never. Be Pam. Or don't.",
  ];

  function setPhase(state) {
    if (state === currentPhase) return;
    currentPhase = state;

    cupStage.dataset.phase = state;

    phasePill.classList.remove('lobby', 'picking', 'reveal');
    if (state === 'lobby') {
      phasePill.classList.add('lobby');
      phasePill.textContent = 'Lobby';
    } else if (state === 'picking') {
      phasePill.classList.add('picking');
      phasePill.textContent = 'Picking';
    } else {
      phasePill.classList.add('reveal');
      phasePill.textContent = 'Reveal';
    }

    // Overlays
    lobbyOverlay.hidden  = state !== 'lobby';
    winnerOverlay.hidden = !(state === 'reveal' || state === 'done');
  }

  function render(state) {
    lastState = state;
    setPhase(state.state);

    // === Snacks (always visible, voting always live) ===
    renderSnacks(state.suggestions, true);
    snacksCount.textContent = state.suggestions ? state.suggestions.length : 0;

    const onlineCount = state.players.filter(p => p.online).length;

    // === Phase-specific UI ===
    if (state.state === 'lobby') {
      // Reset reveal flags so a fresh game restarts cleanly
      revealAnimated = false;
      confettiFired = false;
      prevPickedSet = new Set();

      strawsEl.innerHTML = '';
      prizeCard.hidden = true;

      // Show players IN the stage
      renderLobbyPlayers(state.players);

      actionBtn.style.display = '';
      actionBtn.textContent = onlineCount < 2 ? 'Need 2+ players' : `Start the lottery (${onlineCount})`;
      actionBtn.disabled = onlineCount < 2;

      phaseDetail.textContent = onlineCount < 2
        ? `${onlineCount} here. Send the link to your colleagues.`
        : `${onlineCount} ready. Press Start when everyone's in.`;

    } else if (state.state === 'picking') {
      renderStraws(state);

      const total = state.players.length;
      const pickedCount = state.players.filter(p => p.picked).length;
      const remaining = total - pickedCount;

      if (remaining === 0) {
        phaseDetail.textContent = '🥁 The drumroll, please…';
        strawsEl.classList.add('drumroll');
      } else if (state.my_straw == null) {
        const seed = (state.my_token || '').charCodeAt(0) || 0;
        phaseDetail.textContent = PICKING_QUIPS[seed % PICKING_QUIPS.length];
      } else {
        phaseDetail.textContent = `Locked in. Waiting for ${remaining} more brave soul${remaining === 1 ? '' : 's'}.`;
      }

      // Hide the action button during picking
      actionBtn.style.display = 'none';
      prizeCard.hidden = true;

    } else if (state.state === 'reveal' || state.state === 'done') {
      renderRevealStraws(state);

      const winner = state.players.find(p => p.token === state.winner_token);
      if (winner) {
        winnerName.textContent = winner.name;
        if (winner.is_me) {
          revealMessage.textContent = "Take it. Walk slowly. Don't apologise.";
        } else {
          const me = state.players.find(p => p.is_me);
          revealMessage.textContent = me
            ? "Pretend you're happy for them. That's professionalism."
            : `${winner.name} drew the longest straw.`;
        }
      }

      // Prize snack
      if (state.prize_snack && state.prize_snack.text) {
        prizeCard.hidden = false;
        prizeText.textContent = state.prize_snack.text;
        prizeMeta.textContent = state.prize_snack.random
          ? 'picked at random — democracy failed'
          : `${state.prize_snack.votes} vote${state.prize_snack.votes === 1 ? '' : 's'} · suggested by ${state.prize_snack.author_name}`;
      } else {
        prizeCard.hidden = true;
      }

      actionBtn.style.display = '';
      actionBtn.disabled = false;
      actionBtn.textContent = 'New game';

      phaseDetail.textContent = winner
        ? `🍫 ${winner.name} wins.`
        : 'Round over.';

      if (!confettiFired && winner) {
        confettiFired = true;
        const meWon = !!winner.is_me;
        const iAmHere = state.players.some(p => p.is_me);
        setTimeout(() => fireConfetti(meWon), 200);
        if (iAmHere && !meWon) {
          setTimeout(() => fireTearRain(), 600);
        }
      }
    }
  }

  // === Straws (picking) ===
  function renderStraws(state) {
    const n = state.straws ? state.straws.length : 0;
    if (strawsEl.children.length !== n) {
      strawsEl.innerHTML = '';
      for (let i = 0; i < n; i++) {
        const s = document.createElement('div');
        s.className = 'straw';
        s.dataset.idx = i;
        s.style.height = '240px';
        const num = document.createElement('div');
        num.className = 'straw-number';
        num.textContent = (i + 1).toString();
        s.appendChild(num);
        s.addEventListener('click', () => onStrawClick(i));
        strawsEl.appendChild(s);
      }
    }

    const claimedBy = {};
    state.players.forEach(p => {
      if (p.picked && p.straw_index != null) claimedBy[p.straw_index] = p;
    });
    const myPicked = state.my_straw != null;

    const nowPicked = new Set();
    [...strawsEl.children].forEach((el, i) => {
      el.classList.toggle('taken', !!claimedBy[i]);
      el.classList.toggle('mine', state.my_straw === i);
      el.classList.toggle('disabled', myPicked || !!claimedBy[i]);

      let tag = el.querySelector('.straw-tag');
      if (claimedBy[i]) {
        nowPicked.add(i);
        if (!tag) {
          tag = document.createElement('div');
          tag.className = 'straw-tag';
          el.appendChild(tag);
        }
        tag.textContent = claimedBy[i].is_me ? 'you' : claimedBy[i].name;

        if (!prevPickedSet.has(i)) {
          el.classList.remove('just-picked');
          void el.offsetWidth;
          el.classList.add('just-picked');
        }
      } else if (tag) {
        tag.remove();
      }
    });
    prevPickedSet = nowPicked;
  }

  // === Straws (reveal) — extends the existing straws in place ===
  function renderRevealStraws(state) {
    if (!state.straws) return;

    // Make sure straws exist (they may have been emptied if user joined late at reveal)
    if (strawsEl.children.length !== state.straws.length) {
      strawsEl.innerHTML = '';
      for (let i = 0; i < state.straws.length; i++) {
        const s = document.createElement('div');
        s.className = 'straw';
        s.style.height = '240px';
        const num = document.createElement('div');
        num.className = 'straw-number';
        num.textContent = (i + 1).toString();
        s.appendChild(num);
        strawsEl.appendChild(s);
      }
    }

    if (revealAnimated) return;

    const claimedBy = {};
    state.players.forEach(p => {
      if (p.straw_index != null) claimedBy[p.straw_index] = p;
    });

    const n = state.straws.length;
    const max = Math.max(...state.straws);
    const minPx = 110, maxPx = 280;

    const strawEls = [];
    [...strawsEl.children].forEach((el, i) => {
      el.classList.remove('drumroll');
      const len = state.straws[i];
      const isWinner = len === 100;
      if (isWinner) el.classList.add('winner');
      if (claimedBy[i]) {
        el.classList.add('taken');
        if (claimedBy[i].is_me) el.classList.add('mine');
        let tag = el.querySelector('.straw-tag');
        if (!tag) {
          tag = document.createElement('div');
          tag.className = 'straw-tag';
          el.appendChild(tag);
        }
        tag.textContent = claimedBy[i].is_me ? 'you' : claimedBy[i].name;
      }
      strawEls.push({ el, isWinner });

      const targetPx = Math.round(minPx + (len / max) * (maxPx - minPx));
      setTimeout(() => { el.style.height = targetPx + 'px'; }, 150 + i * 110);
    });

    // After rise settles, wilt the losers
    setTimeout(() => {
      strawEls.forEach(({ el, isWinner }, i) => {
        if (!isWinner) {
          el.classList.add('loser');
          if (i % 2 === 0) el.classList.add('lean-right');
        }
      });
    }, 150 + n * 110 + 1000);

    revealAnimated = true;
  }

  async function onStrawClick(i) {
    if (pickInFlight) return;
    if (!lastState || lastState.state !== 'picking') return;
    if (lastState.my_straw != null) return;
    if (lastState.players.some(p => p.straw_index === i)) return;
    pickInFlight = true;
    try {
      const res = await fetch('api/pick.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ code, straw_index: i })
      });
      const data = await res.json();
      if (data.error) showToast(data.error);
    } catch {}
    pickInFlight = false;
    poll();
  }

  // === Effects canvas ===
  let fxCanvas = null, fxCtx = null;
  const fxParticles = [];
  let fxRunning = false;

  function ensureFxCanvas() {
    if (fxCanvas) return;
    fxCanvas = document.createElement('canvas');
    fxCanvas.id = 'fx-canvas';
    document.body.appendChild(fxCanvas);
    fxCtx = fxCanvas.getContext('2d');
    sizeFxCanvas();
    window.addEventListener('resize', sizeFxCanvas);
  }
  function sizeFxCanvas() {
    if (!fxCanvas) return;
    const dpr = window.devicePixelRatio || 1;
    fxCanvas.width  = window.innerWidth  * dpr;
    fxCanvas.height = window.innerHeight * dpr;
    fxCanvas.style.width  = window.innerWidth  + 'px';
    fxCanvas.style.height = window.innerHeight + 'px';
    fxCtx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  function startFxLoop() {
    if (fxRunning) return;
    fxRunning = true;
    requestAnimationFrame(fxTick);
  }
  function fxTick() {
    if (!fxCanvas) { fxRunning = false; return; }
    const W = window.innerWidth, H = window.innerHeight;
    fxCtx.clearRect(0, 0, W, H);

    let alive = 0;
    for (const p of fxParticles) {
      if (p.dead) continue;
      p.vx *= p.drag;
      p.vy = p.vy * p.drag + p.g;
      p.x += p.vx;
      p.y += p.vy;
      p.rot += p.vr;
      p.life++;
      if (p.life > p.maxLife || p.y > H + 40) { p.dead = true; continue; }
      alive++;

      const fade = 1 - Math.max(0, (p.life - p.maxLife * 0.7) / (p.maxLife * 0.3));
      fxCtx.globalAlpha = Math.max(0, fade);
      fxCtx.save();
      fxCtx.translate(p.x, p.y);
      fxCtx.rotate(p.rot);

      if (p.kind === 'rect') {
        fxCtx.fillStyle = p.color;
        fxCtx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
      } else if (p.kind === 'circle') {
        fxCtx.fillStyle = p.color;
        fxCtx.beginPath();
        fxCtx.arc(0, 0, p.size / 2, 0, Math.PI * 2);
        fxCtx.fill();
      } else if (p.kind === 'tear') {
        fxCtx.fillStyle = p.color;
        fxCtx.beginPath();
        fxCtx.moveTo(0, -p.size);
        fxCtx.bezierCurveTo(p.size * 0.6, -p.size * 0.3, p.size * 0.55, p.size * 0.55, 0, p.size * 0.55);
        fxCtx.bezierCurveTo(-p.size * 0.55, p.size * 0.55, -p.size * 0.6, -p.size * 0.3, 0, -p.size);
        fxCtx.fill();
        fxCtx.fillStyle = 'rgba(255,255,255,0.55)';
        fxCtx.beginPath();
        fxCtx.ellipse(-p.size * 0.18, -p.size * 0.35, p.size * 0.12, p.size * 0.22, 0, 0, Math.PI * 2);
        fxCtx.fill();
      }
      fxCtx.restore();
    }
    fxCtx.globalAlpha = 1;

    if (alive === 0) {
      fxRunning = false;
      fxParticles.length = 0;
      if (fxCanvas) { fxCanvas.remove(); fxCanvas = null; fxCtx = null; }
      return;
    }
    requestAnimationFrame(fxTick);
  }

  function fireConfetti(big) {
    ensureFxCanvas();
    const colors = ['#E89817', '#FFD24A', '#FF8A4C', '#6366F1', '#0F9D6E', '#DC2A2A', '#FFFFFF', '#FFE89C'];
    const W = window.innerWidth, H = window.innerHeight;
    const burstCount = big ? 220 : 160;

    function spawnCannon(x, dirX, y) {
      for (let i = 0; i < burstCount / 2; i++) {
        const angle = (-Math.PI / 2) + (Math.random() - 0.5) * 0.7 + dirX * 0.35;
        const speed = 9 + Math.random() * 11;
        fxParticles.push({
          x, y,
          vx: Math.cos(angle) * speed,
          vy: Math.sin(angle) * speed,
          g: 0.22 + Math.random() * 0.1,
          drag: 0.993,
          size: 6 + Math.random() * 8,
          rot: Math.random() * Math.PI * 2,
          vr: (Math.random() - 0.5) * 0.4,
          color: colors[(Math.random() * colors.length) | 0],
          life: 0,
          maxLife: 200 + (Math.random() * 80 | 0),
          kind: Math.random() < 0.5 ? 'rect' : 'circle',
        });
      }
    }
    function spawnTopBurst(x) {
      for (let i = 0; i < 60; i++) {
        const angle = Math.random() * Math.PI * 2;
        const speed = 2 + Math.random() * 6;
        fxParticles.push({
          x, y: H * 0.18,
          vx: Math.cos(angle) * speed,
          vy: Math.sin(angle) * speed - 2,
          g: 0.18,
          drag: 0.99,
          size: 5 + Math.random() * 6,
          rot: Math.random() * Math.PI * 2,
          vr: (Math.random() - 0.5) * 0.4,
          color: colors[(Math.random() * colors.length) | 0],
          life: 0,
          maxLife: 160,
          kind: Math.random() < 0.5 ? 'rect' : 'circle',
        });
      }
    }
    spawnCannon(W * 0.12, +1, H - 10);
    spawnCannon(W * 0.88, -1, H - 10);
    spawnTopBurst(W * 0.5);
    setTimeout(() => {
      if (!fxCanvas) ensureFxCanvas();
      spawnCannon(W * 0.5, 0, H - 10);
      startFxLoop();
    }, 450);
    startFxLoop();
  }

  function fireTearRain() {
    ensureFxCanvas();
    const W = window.innerWidth;
    const blueColors = ['#7AB6E8', '#9CC8EE', '#B5D6F2', '#5C9DD8'];
    for (let i = 0; i < 80; i++) {
      fxParticles.push({
        x: Math.random() * W,
        y: -20 - Math.random() * 200,
        vx: (Math.random() - 0.5) * 0.6,
        vy: 2 + Math.random() * 3,
        g: 0.08,
        drag: 0.999,
        size: 6 + Math.random() * 6,
        rot: 0, vr: 0,
        color: blueColors[(Math.random() * blueColors.length) | 0],
        life: 0,
        maxLife: 220,
        kind: 'tear',
      });
    }
    let bursts = 0;
    const trickle = setInterval(() => {
      if (++bursts > 6 || !fxCanvas) { clearInterval(trickle); return; }
      for (let i = 0; i < 18; i++) {
        fxParticles.push({
          x: Math.random() * W,
          y: -20 - Math.random() * 80,
          vx: (Math.random() - 0.5) * 0.6,
          vy: 2 + Math.random() * 3,
          g: 0.08, drag: 0.999,
          size: 5 + Math.random() * 6,
          rot: 0, vr: 0,
          color: blueColors[(Math.random() * blueColors.length) | 0],
          life: 0,
          maxLife: 220,
          kind: 'tear',
        });
      }
    }, 240);
    startFxLoop();
  }

  let pollTimer = null, hbTimer = null;
  function startLoops() {
    poll();
    heartbeat();
    if (!pollTimer) pollTimer = setInterval(poll, 1000);
    if (!hbTimer)   hbTimer   = setInterval(heartbeat, 5000);
  }

  if (alreadyJoined) startLoops();
})();
