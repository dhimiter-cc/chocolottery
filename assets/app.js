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
  const snackForm       = $('snack-form');
  const snackInput      = $('snack-input');
  const snacksList      = $('snacks-list');
  const snacksCount     = $('snacks-count');
  const snackError      = $('snack-error');
  const snackQuickWrap  = $('snack-quick-wrap');
  const snackQuickPicks = $('snack-quick-picks');

  const cupboardForm    = $('cupboard-form');
  const cupboardName    = $('cupboard-name');
  const cupboardStock   = $('cupboard-stock');
  const cupboardList    = $('cupboard-list');
  const cupboardCount   = $('cupboard-count');
  const cupboardHint    = $('cupboard-hint');
  const cupboardError   = $('cupboard-error');

  const giveCard        = $('give-card');
  const giveStatus      = $('give-status');
  const giveHostCtrls   = $('give-host-controls');
  const giveSelect      = $('give-select');
  const giveBtn         = $('give-btn');
  const ungiveBtn       = $('ungive-btn');
  const giveError       = $('give-error');

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

  const chatPanel  = $('chat-panel');
  const chatLog    = $('chat-log');
  const chatForm   = $('chat-form');
  const chatInput  = $('chat-input');
  const chatCount  = $('chat-count');
  const chatError  = $('chat-error');

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
  let lastChatId = null;
  let chatPinnedToBottom = true;

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

  // === Sound (Web Audio — synthesized, no assets needed) ===
  let audioCtx = null;
  function getAudio() {
    if (audioCtx) return audioCtx;
    try {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    } catch { audioCtx = null; }
    return audioCtx;
  }
  function tone(freq, duration, type = 'sine', volume = 0.15, when = 0) {
    const ctx = getAudio();
    if (!ctx) return;
    if (ctx.state === 'suspended') ctx.resume();
    const t0 = ctx.currentTime + when;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(freq, t0);
    gain.gain.setValueAtTime(0.0001, t0);
    gain.gain.exponentialRampToValueAtTime(volume, t0 + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + duration);
    osc.connect(gain).connect(ctx.destination);
    osc.start(t0);
    osc.stop(t0 + duration + 0.05);
  }
  function noiseBurst(duration, volume = 0.12, when = 0) {
    const ctx = getAudio();
    if (!ctx) return;
    if (ctx.state === 'suspended') ctx.resume();
    const t0 = ctx.currentTime + when;
    const len = Math.floor(ctx.sampleRate * duration);
    const buf = ctx.createBuffer(1, len, ctx.sampleRate);
    const data = buf.getChannelData(0);
    for (let i = 0; i < len; i++) data[i] = (Math.random() * 2 - 1) * (1 - i / len);
    const src = ctx.createBufferSource();
    src.buffer = buf;
    const gain = ctx.createGain();
    gain.gain.value = volume;
    const filter = ctx.createBiquadFilter();
    filter.type = 'lowpass';
    filter.frequency.value = 220;
    src.connect(filter).connect(gain).connect(ctx.destination);
    src.start(t0);
  }
  function playDrumroll(durationMs) {
    const ticks = Math.floor(durationMs / 55);
    for (let i = 0; i < ticks; i++) {
      noiseBurst(0.06, 0.14, i * 0.055);
    }
  }
  function playTick() { tone(520, 0.08, 'triangle', 0.10); }
  function playWinFanfare() {
    [523.25, 659.25, 783.99, 1046.50].forEach((f, i) => tone(f, 0.35, 'triangle', 0.18, i * 0.12));
    tone(1318.51, 0.6, 'triangle', 0.16, 0.55);
  }
  function playLoseTrombone() {
    const ctx = getAudio();
    if (!ctx) return;
    if (ctx.state === 'suspended') ctx.resume();
    const t0 = ctx.currentTime;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sawtooth';
    osc.frequency.setValueAtTime(330, t0);
    osc.frequency.exponentialRampToValueAtTime(110, t0 + 1.1);
    gain.gain.setValueAtTime(0.0001, t0);
    gain.gain.exponentialRampToValueAtTime(0.18, t0 + 0.05);
    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + 1.2);
    osc.connect(gain).connect(ctx.destination);
    osc.start(t0);
    osc.stop(t0 + 1.3);
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

  // === Cupboard ===
  cupboardForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = cupboardName.value.trim();
    const stock = parseInt(cupboardStock.value, 10);
    cupboardError.textContent = '';
    if (!name) return;
    try {
      const res = await fetch('api/cupboard.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'add', code, name, stock: isNaN(stock) ? 1 : stock })
      });
      const data = await res.json();
      if (data.error) { cupboardError.textContent = data.error; return; }
      cupboardName.value = '';
      cupboardStock.value = '1';
      poll();
    } catch { cupboardError.textContent = 'Could not add'; }
  });

  async function cupboardUpdateStock(id, stock) {
    cupboardError.textContent = '';
    try {
      const res = await fetch('api/cupboard.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'update', code, id, stock })
      });
      const data = await res.json();
      if (data.error) cupboardError.textContent = data.error;
      poll();
    } catch {}
  }
  async function cupboardRemove(id) {
    cupboardError.textContent = '';
    try {
      const res = await fetch('api/cupboard.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'remove', code, id })
      });
      const data = await res.json();
      if (data.error) cupboardError.textContent = data.error;
      poll();
    } catch {}
  }

  function renderCupboard(state) {
    const items = state.cupboard || [];
    const isHost = !!state.is_host;
    const editable = isHost && state.state === 'lobby';

    cupboardCount.textContent = items.length;
    cupboardForm.hidden = !editable;
    cupboardHint.textContent = editable
      ? "What's actually on the shelf. Stock locks when the game starts."
      : (isHost
          ? "Locked while the game is in progress."
          : "What's on the shelf right now.");

    cupboardList.innerHTML = '';
    if (items.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'cupboard-empty';
      empty.textContent = editable
        ? 'Cupboard is bare. Add something.'
        : 'Cupboard is empty.';
      cupboardList.appendChild(empty);
      return;
    }

    items.forEach(it => {
      const row = document.createElement('div');
      row.className = 'cupboard-item' + (it.stock <= 0 ? ' empty' : '');

      const name = document.createElement('div');
      name.className = 'ci-name';
      name.textContent = it.name;
      row.appendChild(name);

      const stock = document.createElement('div');
      stock.className = 'ci-stock';
      stock.textContent = '×' + it.stock;
      row.appendChild(stock);

      if (editable) {
        const ctrls = document.createElement('div');
        ctrls.className = 'ci-controls';
        const minus = document.createElement('button');
        minus.type = 'button';
        minus.className = 'ci-btn';
        minus.textContent = '−';
        minus.title = 'Decrease stock';
        minus.disabled = it.stock <= 0;
        minus.addEventListener('click', () => cupboardUpdateStock(it.id, Math.max(0, it.stock - 1)));
        const plus = document.createElement('button');
        plus.type = 'button';
        plus.className = 'ci-btn';
        plus.textContent = '+';
        plus.title = 'Increase stock';
        plus.addEventListener('click', () => cupboardUpdateStock(it.id, it.stock + 1));
        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'ci-btn del';
        del.textContent = '×';
        del.title = 'Remove from cupboard';
        del.addEventListener('click', () => {
          if (confirm(`Remove "${it.name}" from the cupboard?`)) cupboardRemove(it.id);
        });
        ctrls.appendChild(minus);
        ctrls.appendChild(plus);
        ctrls.appendChild(del);
        row.appendChild(ctrls);
      }

      cupboardList.appendChild(row);
    });
  }

  function renderGiveCard(state) {
    const isReveal = state.state === 'reveal' || state.state === 'done';
    if (!isReveal) { giveCard.hidden = true; return; }
    giveCard.hidden = false;
    giveError.textContent = '';

    const items = state.cupboard || [];
    const givenId = state.prize_given_id;
    const givenName = state.prize_given_name;

    if (givenId) {
      giveStatus.classList.add('given');
      giveStatus.textContent = '✓ ' + (givenName || 'Given') + ' was handed to the winner.';
    } else {
      giveStatus.classList.remove('given');
      giveStatus.textContent = 'Host: which cupboard snack did the winner get?';
    }

    if (!state.is_host) {
      giveHostCtrls.hidden = true;
      return;
    }
    giveHostCtrls.hidden = false;

    // Build the select from in-stock items (preserve selection if possible)
    const prev = giveSelect.value;
    giveSelect.innerHTML = '';
    const stocked = items.filter(it => it.stock > 0);
    if (stocked.length === 0 && !givenId) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = '— nothing in stock —';
      opt.disabled = true; opt.selected = true;
      giveSelect.appendChild(opt);
      giveBtn.disabled = true;
    } else {
      stocked.forEach(it => {
        const opt = document.createElement('option');
        opt.value = it.id;
        opt.textContent = `${it.name} (×${it.stock})`;
        giveSelect.appendChild(opt);
      });
      if (prev && stocked.some(it => it.id === prev)) giveSelect.value = prev;
      giveBtn.disabled = false;
    }

    giveBtn.hidden = !!givenId;
    ungiveBtn.hidden = !givenId;
  }

  giveBtn.addEventListener('click', async () => {
    giveError.textContent = '';
    const id = giveSelect.value;
    if (!id) return;
    try {
      const res = await fetch('api/cupboard.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'give', code, id })
      });
      const data = await res.json();
      if (data.error) { giveError.textContent = data.error; return; }
      poll();
    } catch { giveError.textContent = 'Could not save'; }
  });

  ungiveBtn.addEventListener('click', async () => {
    giveError.textContent = '';
    try {
      const res = await fetch('api/cupboard.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'ungive', code })
      });
      const data = await res.json();
      if (data.error) { giveError.textContent = data.error; return; }
      poll();
    } catch { giveError.textContent = 'Could not undo'; }
  });

  // === Chat ===
  chatLog.addEventListener('scroll', () => {
    const nearBottom = chatLog.scrollHeight - chatLog.scrollTop - chatLog.clientHeight < 40;
    chatPinnedToBottom = nearBottom;
  });

  chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = chatInput.value.trim();
    chatError.textContent = '';
    if (!text) return;
    chatInput.value = '';
    try {
      const res = await fetch('api/chat.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ code, text })
      });
      const data = await res.json();
      if (data.error) { chatError.textContent = data.error; return; }
      chatPinnedToBottom = true;
      poll();
    } catch { chatError.textContent = 'Could not send'; }
  });

  function renderChat(state) {
    const inGame = !!state.in_game;
    chatPanel.hidden = !inGame;
    if (!inGame) return;

    const messages = state.chat || [];
    chatCount.textContent = messages.length;

    const latestId = messages.length ? messages[messages.length - 1].id : null;
    if (latestId === lastChatId) return; // no new messages
    lastChatId = latestId;

    chatLog.innerHTML = '';
    if (messages.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'chat-empty';
      empty.textContent = 'No messages yet. Break the ice.';
      chatLog.appendChild(empty);
      return;
    }

    messages.forEach(m => {
      const row = document.createElement('div');
      row.className = 'chat-msg' + (m.mine ? ' mine' : '');
      const who = document.createElement('div');
      who.className = 'who';
      who.textContent = m.mine ? 'you' : m.name;
      const txt = document.createElement('div');
      txt.className = 'text';
      txt.textContent = m.text;
      row.appendChild(who);
      row.appendChild(txt);
      chatLog.appendChild(row);
    });

    if (chatPinnedToBottom) {
      chatLog.scrollTop = chatLog.scrollHeight;
    }
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
  // Reuse existing DOM nodes per player so the float animation isn't restarted
  // every poll (which caused the "jump" the user saw).
  function renderLobbyPlayers(players) {
    const tokens = new Set(players.map(p => p.token));

    const existing = {};
    [...lobbyPlayers.children].forEach(el => {
      const t = el.dataset.token;
      if (t) existing[t] = el;
    });

    // Remove players who have left
    Object.keys(existing).forEach(t => {
      if (!tokens.has(t)) existing[t].remove();
    });

    lobbyEmpty.hidden = players.length !== 0;

    players.forEach(p => {
      let wrap = existing[p.token];
      if (!wrap) {
        wrap = document.createElement('div');
        wrap.className = 'lobby-player';
        wrap.dataset.token = p.token;
        if (prevLobbyTokens.size > 0) wrap.classList.add('new');

        const av = document.createElement('div');
        av.className = 'avatar';
        wrap.appendChild(av);

        const nm = document.createElement('div');
        nm.className = 'name';
        wrap.appendChild(nm);

        lobbyPlayers.appendChild(wrap);
      }

      wrap.classList.toggle('offline', !p.online);
      wrap.classList.toggle('me', !!p.is_me);
      wrap.title = p.name + (p.online ? '' : ' (offline)');

      const av = wrap.querySelector('.avatar');
      av.style.background = avatarColor(p.name);
      av.textContent = avatarInitial(p.name);

      const nm = wrap.querySelector('.name');
      nm.textContent = p.is_me ? p.name + ' (you)' : p.name;
    });

    prevLobbyTokens = tokens;
  }

  // === Render: quick-pick chips from cupboard stock ===
  function renderQuickPicks(cupboard, suggestions, interactive) {
    const inStock = (cupboard || []).filter(it => it.stock > 0);
    if (inStock.length === 0) { snackQuickWrap.hidden = true; return; }
    snackQuickWrap.hidden = false;
    snackQuickPicks.innerHTML = '';

    inStock.forEach(it => {
      const existing = (suggestions || []).find(s => s.text.toLowerCase() === it.name.toLowerCase());
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'quick-pick-chip' + (existing ? (existing.voted ? ' voted' : ' listed') : '');
      btn.textContent = it.name;
      btn.title = existing
        ? (existing.voted ? `Your vote is in (${existing.votes})` : `Already listed — click to vote (${existing.votes})`)
        : 'Add to suggestions & vote';

      if (interactive) {
        btn.addEventListener('click', async () => {
          if (existing) {
            await vote(existing.id);
          } else {
            snackError.textContent = '';
            try {
              const res = await fetch('api/suggest.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ code, text: it.name })
              });
              const data = await res.json();
              if (data.error) { snackError.textContent = data.error; return; }
              poll();
            } catch { snackError.textContent = 'Could not add'; }
          }
        });
      } else {
        btn.disabled = true;
      }

      snackQuickPicks.appendChild(btn);
    });
  }

  // === Render: snacks ===
  function renderSnacks(suggestions, cupboard, interactive) {
    renderQuickPicks(cupboard, suggestions, interactive);
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
    const previous = currentPhase;
    currentPhase = state;

    cupStage.dataset.phase = state;

    // Lobby → Picking: run a 3,2,1,GO countdown before letting players pick
    if (previous === 'lobby' && state === 'picking') {
      runStartCountdown();
    }

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

    // Overlays — winner overlay is shown by the reveal sequence, not here
    lobbyOverlay.hidden = state !== 'lobby';
    if (state === 'lobby' || state === 'picking') {
      winnerOverlay.hidden = true;
    }
  }

  function render(state) {
    lastState = state;
    setPhase(state.state);

    // === Snacks (always visible, voting always live) ===
    renderSnacks(state.suggestions, state.cupboard, true);
    snacksCount.textContent = state.suggestions ? state.suggestions.length : 0;

    // === Cupboard + give-card ===
    renderCupboard(state);
    renderGiveCard(state);

    // === Chat (participants only) ===
    renderChat(state);

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

      const iAmHost = !!state.is_host;
      const host = state.players.find(p => p.token === state.creator_token);
      const hostName = host ? host.name : 'the host';

      if (iAmHost) {
        actionBtn.style.display = '';
        actionBtn.textContent = onlineCount < 2 ? 'Need 2+ players' : `Start the lottery (${onlineCount})`;
        actionBtn.disabled = onlineCount < 2;
        phaseDetail.textContent = onlineCount < 2
          ? `${onlineCount} here. Send the link to your colleagues.`
          : `${onlineCount} ready. Press Start when everyone's in.`;
      } else {
        actionBtn.style.display = 'none';
        phaseDetail.textContent = onlineCount < 2
          ? `${onlineCount} here. Waiting for more.`
          : `${onlineCount} ready. Waiting for ${hostName} to start.`;
      }

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
      const winner = state.players.find(p => p.token === state.winner_token);

      if (!revealAnimated) {
        runRevealSequence(state, winner);
      } else {
        // Post-animation re-renders (polls after reveal): just keep the final state visible
        renderRevealStraws(state);
        showWinnerOverlay(state, winner);
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

      if (revealAnimated) {
        actionBtn.style.display = '';
        actionBtn.disabled = false;
        actionBtn.textContent = 'New game';
        phaseDetail.textContent = winner ? `🍫 ${winner.name} wins.` : 'Round over.';
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

  // === Start-of-game countdown: "3 / 2 / 1 / GO! / Pick your straw" ===
  function runStartCountdown() {
    // Remove any stale overlay
    const stale = cupStage.querySelector('.countdown-overlay');
    if (stale) stale.remove();

    const overlay = document.createElement('div');
    overlay.className = 'countdown-overlay';
    cupStage.appendChild(overlay);
    cupStage.classList.add('countdown');

    const steps = [
      { text: '3',               freq: 440, dur: 700 },
      { text: '2',               freq: 494, dur: 700 },
      { text: '1',               freq: 554, dur: 700 },
      { text: 'GO!',             freq: 880, dur: 700, big: true },
      { text: 'Pick your straw', freq: 0,   dur: 900, small: true },
    ];

    let i = 0;
    const tick = () => {
      if (i >= steps.length) {
        overlay.remove();
        cupStage.classList.remove('countdown');
        return;
      }
      const step = steps[i++];
      overlay.textContent = step.text;
      overlay.classList.toggle('big', !!step.big);
      overlay.classList.toggle('small', !!step.small);
      overlay.classList.remove('pop');
      void overlay.offsetWidth;
      overlay.classList.add('pop');
      if (step.freq) tone(step.freq, step.big ? 0.35 : 0.12, step.big ? 'triangle' : 'square', step.big ? 0.20 : 0.14);
      setTimeout(tick, step.dur);
    };
    tick();
  }

  // Ensure the straw DOM exists for the given count (used by reveal-after-join)
  function ensureStrawEls(n) {
    if (strawsEl.children.length !== n) {
      strawsEl.innerHTML = '';
      for (let i = 0; i < n; i++) {
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
  }

  // Final, post-animation render (for late-joiners or re-polls after reveal)
  function renderRevealStraws(state) {
    if (!state.straws) return;
    ensureStrawEls(state.straws.length);
    const claimedBy = {};
    state.players.forEach(p => { if (p.straw_index != null) claimedBy[p.straw_index] = p; });
    const max = Math.max(...state.straws);
    const minPx = 110, maxPx = 280;
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
      el.style.height = Math.round(minPx + (len / max) * (maxPx - minPx)) + 'px';
      if (!isWinner) {
        el.classList.add('loser');
        if (i % 2 === 0) el.classList.add('lean-right');
      }
    });
  }

  function showWinnerOverlay(state, winner) {
    if (!winner) return;
    winnerName.textContent = winner.name;
    if (winner.is_me) {
      revealMessage.textContent = "Take it. Walk slowly. Don't apologise.";
    } else {
      const me = state.players.find(p => p.is_me);
      revealMessage.textContent = me
        ? "Pretend you're happy for them. That's professionalism."
        : `${winner.name} drew the longest straw.`;
    }
    winnerOverlay.hidden = false;
  }

  // === The suspenseful reveal sequence ===
  // Timeline: ~3s of vibration + sparkles + rising rumble → flash → all straws
  // reveal at once + winner glow + confetti + fanfare, all together.
  function runRevealSequence(state, winner) {
    if (revealAnimated) return;
    revealAnimated = true;

    if (!state.straws) return;
    ensureStrawEls(state.straws.length);

    winnerOverlay.hidden = true;
    actionBtn.style.display = 'none';

    const claimedBy = {};
    state.players.forEach(p => { if (p.straw_index != null) claimedBy[p.straw_index] = p; });

    const max = Math.max(...state.straws);
    const minPx = 110, maxPx = 280;
    const winnerIdx = state.straws.findIndex(v => v === 100);

    const strawEls = [...strawsEl.children];
    strawEls.forEach((el, i) => {
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
    });

    // --- Phase 1: 3-second tension build ---
    const buildMs = 3000;
    strawsEl.classList.add('drumroll');
    cupStage.classList.add('charging');
    phaseDetail.textContent = '🥁 Drawing lots…';

    playDrumroll(buildMs);
    fireStageSparkles(buildMs);
    playRisingRumble(buildMs);

    // --- Phase 2: BIG reveal, all at once ---
    setTimeout(() => {
      strawsEl.classList.remove('drumroll');
      cupStage.classList.remove('charging');

      // White flash on the stage
      cupStage.classList.add('flash');
      setTimeout(() => cupStage.classList.remove('flash'), 600);

      // All straws snap to their final heights simultaneously
      strawEls.forEach((el, i) => {
        const len = state.straws[i];
        const targetPx = Math.round(minPx + (len / max) * (maxPx - minPx));
        el.style.height = targetPx + 'px';
        if (i === winnerIdx) el.classList.add('winner');
      });

      // Losers wilt while everyone takes in the reveal
      setTimeout(() => {
        strawEls.forEach((el, i) => {
          if (i !== winnerIdx) {
            el.classList.add('loser');
            if (i % 2 === 0) el.classList.add('lean-right');
          }
        });
      }, 600);

      phaseDetail.textContent = '…and the winner is…';

      // Winner name + confetti + fanfare all together, after a beat that lets
      // people register the winning straw first
      const meWon = !!(winner && winner.is_me);
      const iAmHere = state.players.some(p => p.is_me);
      const climaxDelay = 1100;

      setTimeout(() => {
        showWinnerOverlay(state, winner);
        if (winner) {
          playWinFanfare();
          if (!confettiFired) {
            confettiFired = true;
            fireConfetti(meWon);
            if (iAmHere && !meWon) {
              setTimeout(() => playLoseTrombone(), 500);
              setTimeout(() => fireTearRain(), 700);
            }
          }
        }
        actionBtn.style.display = '';
        actionBtn.disabled = false;
        actionBtn.textContent = 'New game';
        phaseDetail.textContent = winner ? `🍫 ${winner.name} wins.` : 'Round over.';
      }, climaxDelay);
    }, buildMs);
  }

  // Sparkles rising from inside the stage during the tension build
  function fireStageSparkles(durationMs) {
    ensureFxCanvas();
    const colors = ['#FFE89C', '#FFD24A', '#FFFFFF', '#E89817'];
    const start = performance.now();
    const spawn = () => {
      if (performance.now() - start > durationMs) return;
      const rect = cupStage.getBoundingClientRect();
      for (let i = 0; i < 4; i++) {
        fxParticles.push({
          x: rect.left + Math.random() * rect.width,
          y: rect.bottom - 40 - Math.random() * 120,
          vx: (Math.random() - 0.5) * 2.5,
          vy: -3 - Math.random() * 4,
          g: 0.04,
          drag: 0.99,
          size: 3 + Math.random() * 4,
          rot: Math.random() * Math.PI * 2,
          vr: (Math.random() - 0.5) * 0.3,
          color: colors[(Math.random() * colors.length) | 0],
          life: 0,
          maxLife: 90,
          kind: 'circle',
        });
      }
      startFxLoop();
      setTimeout(spawn, 60);
    };
    spawn();
  }

  // Rising sawtooth rumble over the full build-up
  function playRisingRumble(durationMs) {
    const ctx = getAudio();
    if (!ctx) return;
    if (ctx.state === 'suspended') ctx.resume();
    const t0 = ctx.currentTime;
    const dur = durationMs / 1000;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sawtooth';
    osc.frequency.setValueAtTime(110, t0);
    osc.frequency.exponentialRampToValueAtTime(660, t0 + dur);
    gain.gain.setValueAtTime(0.0001, t0);
    gain.gain.exponentialRampToValueAtTime(0.06, t0 + 0.3);
    gain.gain.exponentialRampToValueAtTime(0.12, t0 + dur - 0.1);
    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
    osc.connect(gain).connect(ctx.destination);
    osc.start(t0);
    osc.stop(t0 + dur + 0.05);
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
