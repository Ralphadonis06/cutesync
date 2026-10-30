<?php
// api_get_queue.php - everything the Teller desk, TV display and patient page need in one call.
//   GET api_get_queue.php?establishment_id=1
declare(strict_types=1);
require __DIR__ . '/db_connect.php';

$pdo    = db();
$user   = current_user();
$clinic = (int)($_GET['establishment_id'] ?? 0);
if ($clinic === 0) $clinic = (int)($user['establishment_id'] ?? 0);
if ($clinic === 0) $clinic = (int)$pdo->query('SELECT MIN(id) FROM establishments')->fetchColumn();

$st = $pdo->prepare('SELECT id, name, ticket_limit FROM establishments WHERE id = ?');
$st->execute([$clinic]);
$est = $st->fetch();
if (!$est) json_out(['error' => 'Clinic not found'], 404);

$st = $pdo->prepare('SELECT id, name, prefix, color, is_active FROM services WHERE establishment_id = ? ORDER BY id');
$st->execute([$clinic]);
$services = array_map(fn($r) => [
    'id' => (int)$r['id'], 'name' => $r['name'], 'prefix' => $r['prefix'],
    'color' => $r['color'], 'is_active' => (bool)$r['is_active'],
], $st->fetchAll());

// Patient names are only sent to logged-in staff of this clinic (the TV and patient page get numbers only).
$staffView = $user && ($user['role'] === 'Super Admin' || (int)$user['establishment_id'] === $clinic);

// Only fetch TODAY's tickets
$st = $pdo->prepare(
    'SELECT t.id, t.queue_number, t.service_id, t.patient_name, t.status, t.priority_type,
            UNIX_TIMESTAMP(t.created_at) * 1000 AS created_ms,
            UNIX_TIMESTAMP(t.served_at)  * 1000 AS served_ms
       FROM tickets t JOIN services s ON s.id = t.service_id
      WHERE s.establishment_id = ? AND DATE(t.created_at) = CURDATE()
      ORDER BY t.created_at, t.id'
);
$st->execute([$clinic]);
$tickets = array_map(function ($r) use ($staffView) {
    $t = [
        'id' => (int)$r['id'], 'queue_number' => $r['queue_number'], 'service_id' => (int)$r['service_id'],
        'status' => $r['status'], 'priority_type' => $r['priority_type'],
        'created_ms' => (int)$r['created_ms'], 'served_ms' => $r['served_ms'] !== null ? (int)$r['served_ms'] : null,
    ];
    if ($staffView) $t['patient_name'] = $r['patient_name'];
    return $t;
}, $st->fetchAll());

$st = $pdo->prepare('SELECT message FROM announcements WHERE establishment_id = ? ORDER BY id DESC LIMIT 1');
$st->execute([$clinic]);

json_out([
    'establishment' => ['id' => (int)$est['id'], 'name' => $est['name'], 'ticket_limit' => (int)$est['ticket_limit']],
    'services'      => $services,
    'tickets'       => $tickets,
    'announcement'  => (string)($st->fetchColumn() ?: ''),
]);
