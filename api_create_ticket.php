<?php
// api_create_ticket.php - registers a patient and returns the new queue number.
//   POST {"service_id":1,"patient_name":"Maria Santos","priority":true}
declare(strict_types=1);
require __DIR__ . '/db_connect.php';

$u = require_login();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Use POST'], 405);

$in   = read_json();
$sid  = (int)($in['service_id'] ?? 0);
$name = trim((string)($in['patient_name'] ?? ''));
$name = $name === '' ? 'Walk-in' : mb_substr($name, 0, 120);
$prio = !empty($in['priority']);
$pdo  = db();

try {
    $pdo->beginTransaction();

    // Locking the service and clinic rows makes two tellers get different numbers.
    $st = $pdo->prepare(
        'SELECT s.id, s.name, s.prefix, s.is_active, s.establishment_id, e.ticket_limit
           FROM services s JOIN establishments e ON e.id = s.establishment_id
          WHERE s.id = ? FOR UPDATE'
    );
    $st->execute([$sid]);
    $sv = $st->fetch();

    if (!$sv) { $pdo->rollBack(); json_out(['error' => 'Service not found'], 404); }
    if ($u['role'] !== 'Super Admin' && (int)$u['establishment_id'] !== (int)$sv['establishment_id']) {
        $pdo->rollBack(); json_out(['error' => 'You are not allowed to do that'], 403);
    }
    if (!$sv['is_active']) { $pdo->rollBack(); json_out(['error' => 'This service is closed today'], 409); }

    // Count today's tickets for the limit
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM tickets t JOIN services s ON s.id = t.service_id
          WHERE s.establishment_id = ? AND DATE(t.created_at) = CURDATE()'
    );
    $st->execute([$sv['establishment_id']]);
    if ((int)$st->fetchColumn() >= (int)$sv['ticket_limit']) {
        $pdo->rollBack();
        json_out(['error' => 'Daily ticket limit reached (' . $sv['ticket_limit'] . ')'], 409);
    }

    // Find the max number FOR TODAY ONLY
    $st = $pdo->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(queue_number, '-', -1) AS UNSIGNED)), 0) + 1
           FROM tickets WHERE service_id = ? AND DATE(created_at) = CURDATE()"
    );
    $st->execute([$sid]);
    $number = $sv['prefix'] . '-' . str_pad((string)$st->fetchColumn(), 3, '0', STR_PAD_LEFT);

    // People ahead: priority patients only count other priority patients.
    $sql = "SELECT COUNT(*) FROM tickets WHERE service_id = ? AND DATE(created_at) = CURDATE() AND status = 'Waiting'"
         . ($prio ? " AND priority_type = 'Priority'" : '');
    $st = $pdo->prepare($sql);
    $st->execute([$sid]);
    $ahead = (int)$st->fetchColumn();

    // Insert the ticket. We just use NOW() for created_at
    $st = $pdo->prepare(
        'INSERT INTO tickets (queue_number, service_id, patient_name, priority_type, created_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    $st->execute([$number, $sid, $name, $prio ? 'Priority' : 'Regular']);
    $id = (int)$pdo->lastInsertId();
    $pdo->commit();

    json_out(['ticket' => [
        'id' => $id, 'queue_number' => $number, 'service_id' => $sid, 'service_name' => $sv['name'],
        'patient_name' => $name, 'priority_type' => $prio ? 'Priority' : 'Regular',
        'created_ms' => (int)round(microtime(true) * 1000), 'ahead' => $ahead,
    ]], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('create_ticket: ' . $e->getMessage());
    json_out(['error' => 'Could not create the ticket'], 500);
}
