<?php
// api_announcements.php - read (public, for the TV) and post (Admin / Manager) announcements.
//   GET  api_announcements.php?establishment_id=1
//   POST {"message":"Doctor is on a 15-minute break","establishment_id":1}
declare(strict_types=1);
require __DIR__ . '/db_connect.php';

$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $u      = require_login(['Super Admin', 'Clinic Manager']);
    $in     = read_json();
    $clinic = $u['role'] === 'Super Admin' ? (int)($in['establishment_id'] ?? 0) : (int)$u['establishment_id'];
    $msg    = trim((string)($in['message'] ?? ''));
    if ($clinic === 0) json_out(['error' => 'Choose a clinic first'], 422);
    if ($msg === '' || mb_strlen($msg) > 255) json_out(['error' => 'Write a message of up to 255 characters'], 422);

    $pdo->prepare('INSERT INTO announcements (establishment_id, message) VALUES (?, ?)')->execute([$clinic, $msg]);
    json_out(['ok' => true], 201);
}

$clinic = (int)($_GET['establishment_id'] ?? 0);
if ($clinic === 0) $clinic = (int)(current_user()['establishment_id'] ?? 0);
if ($clinic === 0) $clinic = (int)$pdo->query('SELECT MIN(id) FROM establishments')->fetchColumn();

$st = $pdo->prepare(
    'SELECT id, message, UNIX_TIMESTAMP(created_at) * 1000 AS created_ms
       FROM announcements WHERE establishment_id = ? ORDER BY id DESC LIMIT 5'
);
$st->execute([$clinic]);
json_out(['announcements' => array_map(fn($r) => [
    'id' => (int)$r['id'], 'message' => $r['message'], 'created_ms' => (int)$r['created_ms'],
], $st->fetchAll())]);