<?php
require_once __DIR__ . '/../config.php';

// GET: list items (public, no auth)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['items' => cupboard_items_public()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST required', 405);

$body = read_json_body();
$action = $body['action'] ?? '';
$code = trim($body['code'] ?? '');
$token = get_cookie('player_token');

if (!$code || !$token) json_error('Missing code or token');

// All write actions require the caller to be the host of the named game.
$game = load_game($code);
if (!$game) json_error('Game not found', 404);
if (($game['creator_token'] ?? null) !== $token) json_error('Only the host can edit the cupboard', 403);

// Edits (add/update/remove) are only allowed in lobby — once the game starts the cupboard is locked
// for that round. Marking a prize as given (`give`) is only allowed once the round is in reveal/done.
$editActions = ['add', 'update', 'remove'];
$giveActions = ['give', 'ungive'];

if (in_array($action, $editActions, true) && $game['state'] !== 'lobby') {
    json_error('Cupboard is locked once the game starts', 409);
}
if (in_array($action, $giveActions, true) && !in_array($game['state'], ['reveal', 'done'], true)) {
    json_error('Cannot mark prize given before the reveal', 409);
}

if ($action === 'add') {
    $name = trim($body['name'] ?? '');
    $stock = max(0, (int)($body['stock'] ?? 0));
    if ($name === '') json_error('Name is empty');
    if (mb_strlen($name) > 60) $name = mb_substr($name, 0, 60);
    if ($stock > 999) $stock = 999;

    $result = with_cupboard_lock(function ($data) use ($name, $stock) {
        $needle = mb_strtolower($name);
        foreach ($data['items'] as &$item) {
            if (mb_strtolower($item['name']) === $needle) {
                // Same-name → bump stock instead of duplicating
                $item['stock'] = min(999, (int)$item['stock'] + $stock);
                return ['data' => $data, 'result' => ['ok' => true, 'id' => $item['id']]];
            }
        }
        unset($item);
        if (count($data['items']) >= 100) {
            return ['__no_write' => true, 'result' => ['error' => 'Cupboard full', 'code' => 429]];
        }
        $id = bin2hex(random_bytes(6));
        $data['items'][] = [
            'id'         => $id,
            'name'       => $name,
            'stock'      => $stock,
            'created_at' => time(),
        ];
        return ['data' => $data, 'result' => ['ok' => true, 'id' => $id]];
    });
    if (isset($result['error'])) json_error($result['error'], $result['code'] ?? 400);
    json_response($result);
}

if ($action === 'update') {
    $id = trim($body['id'] ?? '');
    if ($id === '') json_error('Missing id');
    $hasStock = array_key_exists('stock', $body);
    $hasName  = array_key_exists('name', $body);
    if (!$hasStock && !$hasName) json_error('Nothing to update');

    $stock = $hasStock ? max(0, min(999, (int)$body['stock'])) : null;
    $name  = $hasName ? trim((string)$body['name']) : null;
    if ($hasName && $name === '') json_error('Name is empty');
    if ($hasName && mb_strlen($name) > 60) $name = mb_substr($name, 0, 60);

    $result = with_cupboard_lock(function ($data) use ($id, $stock, $name, $hasStock, $hasName) {
        foreach ($data['items'] as &$item) {
            if (($item['id'] ?? '') === $id) {
                if ($hasStock) $item['stock'] = $stock;
                if ($hasName)  $item['name']  = $name;
                return ['data' => $data, 'result' => ['ok' => true]];
            }
        }
        return ['__no_write' => true, 'result' => ['error' => 'Item not found', 'code' => 404]];
    });
    if (isset($result['error'])) json_error($result['error'], $result['code'] ?? 400);
    json_response($result);
}

if ($action === 'remove') {
    $id = trim($body['id'] ?? '');
    if ($id === '') json_error('Missing id');
    $result = with_cupboard_lock(function ($data) use ($id) {
        $before = count($data['items']);
        $data['items'] = array_values(array_filter($data['items'], function ($it) use ($id) {
            return ($it['id'] ?? '') !== $id;
        }));
        if (count($data['items']) === $before) {
            return ['__no_write' => true, 'result' => ['error' => 'Item not found', 'code' => 404]];
        }
        return ['data' => $data, 'result' => ['ok' => true]];
    });
    if (isset($result['error'])) json_error($result['error'], $result['code'] ?? 400);
    json_response($result);
}

if ($action === 'give') {
    // Host marks which cupboard item was actually handed to the winner → −1 stock.
    $id = trim($body['id'] ?? '');
    if ($id === '') json_error('Missing id');

    // 1. Subtract from cupboard
    $cupResult = with_cupboard_lock(function ($data) use ($id) {
        foreach ($data['items'] as &$item) {
            if (($item['id'] ?? '') === $id) {
                if ((int)$item['stock'] <= 0) {
                    return ['__no_write' => true, 'result' => ['error' => 'Out of stock', 'code' => 409]];
                }
                $item['stock'] = (int)$item['stock'] - 1;
                return ['data' => $data, 'result' => ['ok' => true, 'name' => $item['name']]];
            }
        }
        return ['__no_write' => true, 'result' => ['error' => 'Item not found', 'code' => 404]];
    });
    if (isset($cupResult['error'])) json_error($cupResult['error'], $cupResult['code'] ?? 400);

    // 2. Record on the game so everyone sees what was given
    with_game_lock($code, function ($game) use ($id, $cupResult) {
        if (!$game) return ['__no_write' => true, 'result' => null];
        $game['prize_given_id']   = $id;
        $game['prize_given_name'] = $cupResult['name'] ?? '';
        return ['game' => $game, 'result' => ['ok' => true]];
    });

    json_response(['ok' => true]);
}

if ($action === 'ungive') {
    // Undo: refund 1 to stock and clear the marker on the game.
    $previousId = $game['prize_given_id'] ?? null;
    if (!$previousId) json_error('No prize marked given', 409);

    with_cupboard_lock(function ($data) use ($previousId) {
        foreach ($data['items'] as &$item) {
            if (($item['id'] ?? '') === $previousId) {
                $item['stock'] = min(999, (int)$item['stock'] + 1);
                return ['data' => $data, 'result' => ['ok' => true]];
            }
        }
        return ['__no_write' => true, 'result' => ['ok' => true]];
    });

    with_game_lock($code, function ($game) {
        if (!$game) return ['__no_write' => true, 'result' => null];
        unset($game['prize_given_id'], $game['prize_given_name']);
        return ['game' => $game, 'result' => ['ok' => true]];
    });

    json_response(['ok' => true]);
}

json_error('Unknown action');
