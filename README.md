# Chocolate Lottery 🍫

A skeuomorphic, multiplayer "pick the longest straw" office game. No login, no database, no build step — pure PHP + vanilla JS/CSS.

## Setup

1. Drop the entire `chocolottery/` folder into your web root (e.g. `C:\xampp\htdocs\Projects\fun\chocolottery\`).
2. Make sure the web server can write to the `data/` directory:
   - **Linux/macOS:** `chmod -R 755 data/` (or `777` if your server runs as a different user)
   - **Windows (XAMPP):** usually works out of the box. If not, give your user "Modify" permission on `data/`.
3. Open it in a browser: `http://localhost/Projects/fun/chocolottery/`

That's it.

## Requirements

- PHP 7.4+
- Apache (for the `.htaccess` data-directory protection — works on any host that supports `mod_rewrite`)

## How it works

- **No auth.** A cookie (`player_token`) identifies you within a game session.
- **No database.** Each game is a JSON file in `data/games/`. Wins go to `data/leaderboard.json`.
- **Real-time-ish.** Clients short-poll `api/state.php` once per second.
- **Self-cleaning.** Games older than 24h are deleted opportunistically on state requests.

## Game flow

1. Someone creates a game → gets a code like `CHOC-4829`.
2. Others join with the code + their name.
3. Anyone hits "Start the Lottery" — only currently-online players are locked in.
4. Each player picks a straw (lengths hidden).
5. When the last player picks, lengths are revealed. The 100-length straw wins.
6. Winner is recorded to the global leaderboard.

## Files

- `index.php` — landing
- `game.php` — game room
- `leaderboard.php` — all-time + monthly leaderboard
- `config.php` — shared helpers
- `api/*.php` — JSON endpoints
- `assets/style.css`, `assets/app.js` — frontend
- `data/` — game state (do not edit by hand)

Have fun. May the longest straw win. 🍫
