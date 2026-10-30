<?php
// api_update_status.php - Call Next, Serving, Completed, Skip and Recall.
//   POST {"action":"call_next","service_id":1}
//   POST {"action":"serving|complete|skip|recall","ticket_id":12}
declare(strict_types=1);
require __DIR__ . '/db_connect.php';

$u = require_login();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Use POST'], 405);

$in     = read_json();
$action = (string)($in['action'] ?? '');
$pdo    = db();

// Tellers may only work on their own department; managers and admins on their clinic.
function allowed(array $u, int $est, int $svc): bool
{
    if ($u['role'] === 'Super Admin') return true;
    if ((int)$u['establishment_id'] !== $est) return false;
    return $u['role'] === 'Clinic Manager' || (int)$u['assigned_service_id'] === $svc;
}

try {
    $pdo->beginTransaction();

    if ($action === 'call_next') {
        $sid = (int)($in['service_id'] ?? 0);
        $st = $pdo->prepare('SELECT id, name, establishment_id, is_active FROM services WHERE id = ? FOR UPDATE');
        $st->execute([$sid]);
        $sv = $st->fetch();
        if (!$sv) { $pdo->rollBack(); json_out(['error' => 'Service not found'], 404); }
        if (!allowed($u, (int)$sv['establishment_id'], $sid)) { $pdo->rollBack(); json_out(['error' => 'You are not allowed to do that'], 403); }
        if (!$sv['is_active']) { $pdo->rollBack(); json_out(['error' => 'This service is closed'], 409); }

        $st = $pdo->prepare("SELECT id FROM tickets WHERE service_id = ? AND ticket_date = CURDATE() AND status IN ('Called','Serving') LIMIT 1");
        $st->execute([$sid]);
        if ($st->fetch()) { $pdo->rollBack(); json_out(['error' => 'Finish or skip the current patient first'], 409); }

        // Priority patients first, then first come first served.
        $st = $pdo->prepare(
            "SELECT id, queue_number FROM tickets
              WHERE service_id = ? AND ticket_date = CURDATE() AND status = 'Waiting'
              ORDER BY (priority_type = 'Priority') DESC, created_at, id LIMIT 1 FOR UPDATE"
        );
        $st->execute([$sid]);
        $next = $st->fetch();
        if (!$next) { $pdo->rollBack(); json_out(['error' => 'No patients are waiting'], 404); }

        $pdo->prepare("UPDATE tickets SET status = 'Called', served_at = NOW() WHERE id = ?")->execute([$next['id']]);
        $pdo->commit();
        json_out(['ticket' => ['id' => (int)$next['id'], 'queue_number' => $next['queue_number'], 'service_name' => $sv['name']]]);
    }

    // action => [allowed current statuses, SQL]
    $moves = [
        'serving'  => [['Called'], "UPDATE tickets SET status = 'Serving' WHERE id = ?"],
        'complete' => [['Called', 'Serving'], "UPDATE tickets SET status = 'Completed', completed_at = NOW() WHERE id = ?"],
        'skip'     => [['Called', 'Serving', 'Waiting'], "UPDATE tickets SET status = 'Skipped', served_at = NULL WHERE id = ?"],
        'recall'   => [['Skipped'], "UPDATE tickets SET status = 'Waiting', served_at = NULL WHERE id = ?"],
    ];
    if (!isset($moves[$action])) { $pdo->rollBack(); json_out(['error' => 'Unknown action'], 422); }

    $st = $pdo->prepare(
        'SELECT t.id, t.status, t.service_id, s.establishment_id
           FROM tickets t JOIN services s ON s.id = t.service_id WHERE t.id = ? FOR UPDATE'
    );
    $st->execute([(int)($in['ticket_id'] ?? 0)]);
    $t = $st->fetch();
    if (!$t) { $pdo->rollBack(); json_out(['error' => 'Ticket not found'], 404); }
    if (!allowed($u, (int)$t['establishment_id'], (int)$t['service_id'])) { $pdo->rollBack(); json_out(['error' => 'You are not allowed to do that'], 403); }
    if (!in_array($t['status'], $moves[$action][0], true)) { $pdo->rollBack(); json_out(['error' => 'That ticket has changed. Please refresh.'], 409); }

    $pdo->prepare($moves[$action][1])->execute([$t['id']]);
    $pdo->commit();
    json_out(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('update_status: ' . $e->getMessage());
    json_out(['error' => 'Could not update the ticket'], 500);
}