<?php
// api_admin.php - Admin dashboard (Super Admin and Clinic Manager only). Pick the job with ?action=
//   GET  analytics&days=1|7|30   services   staff   clinics
//   POST add_service  toggle_service  add_staff  set_staff_service  reset_password  add_clinic  set_limit
declare(strict_types=1);
require __DIR__ . '/db_connect.php';

$u       = require_login(['Super Admin', 'Clinic Manager']);
$pdo     = db();
$in      = read_json();
$action  = (string)($_GET['action'] ?? '');
$isSuper = $u['role'] === 'Super Admin';

// The clinic being managed: managers are fixed to their own, the Super Admin chooses.
$clinic = $isSuper ? (int)($_GET['establishment_id'] ?? $in['establishment_id'] ?? 0) : (int)$u['establishment_id'];
if ($clinic === 0) $clinic = (int)$pdo->query('SELECT MIN(id) FROM establishments')->fetchColumn();

function need_super(bool $isSuper): void
{
    if (!$isSuper) json_out(['error' => 'Only the Super Admin can do that'], 403);
}
function service_in_clinic(PDO $pdo, int $sid, int $clinic): bool
{
    $st = $pdo->prepare('SELECT 1 FROM services WHERE id = ? AND establishment_id = ?');
    $st->execute([$sid, $clinic]);
    return (bool)$st->fetchColumn();
}
function temp_password(): string
{
    return bin2hex(random_bytes(4)); // e.g. 9f3a1c7e - shown to the admin once
}

try {
    switch ($action) {

        case 'analytics':
            $days = in_array((int)($_GET['days'] ?? 1), [1, 7, 30], true) ? (int)$_GET['days'] : 1;
            $st = $pdo->prepare(
                "SELECT s.id, s.name, s.color, COUNT(t.id) AS total,
                        COALESCE(SUM(t.status = 'Completed'), 0) AS served,
                        COALESCE(SUM(t.status = 'Skipped'), 0)   AS skipped,
                        COUNT(t.served_at) AS timed,
                        COALESCE(AVG(TIMESTAMPDIFF(SECOND, t.created_at, t.served_at)), 0) AS avg_sec
                   FROM services s
                   LEFT JOIN tickets t ON t.service_id = s.id
                        AND t.ticket_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  WHERE s.establishment_id = ?
                  GROUP BY s.id, s.name, s.color ORDER BY s.id"
            );
            $st->execute([$days - 1, $clinic]);
            json_out(['days' => $days, 'services' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'name' => $r['name'], 'color' => $r['color'],
                'total' => (int)$r['total'], 'served' => (int)$r['served'], 'skipped' => (int)$r['skipped'],
                'timed' => (int)$r['timed'], 'avg_wait_min' => (int)round(((float)$r['avg_sec']) / 60),
            ], $st->fetchAll())]);

        case 'services':
            $st = $pdo->prepare('SELECT id, name, prefix, color, is_active FROM services WHERE establishment_id = ? ORDER BY id');
            $st->execute([$clinic]);
            json_out(['services' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'name' => $r['name'], 'prefix' => $r['prefix'],
                'color' => $r['color'], 'is_active' => (bool)$r['is_active'],
            ], $st->fetchAll())]);

        case 'add_service':
            $name   = trim((string)($in['name'] ?? ''));
            $prefix = strtoupper(trim((string)($in['prefix'] ?? '')));
            if ($name === '' || !preg_match('/^[A-Z]{2,5}$/', $prefix)) json_out(['error' => 'Enter a name and a 2-5 letter prefix'], 422);
            $count = $pdo->prepare('SELECT COUNT(*) FROM services WHERE establishment_id = ?');
            $count->execute([$clinic]);
            $colors = ['#1E3A8A', '#0F766E', '#B45309', '#0369A1', '#475569', '#7C2D12'];
            try {
                $pdo->prepare('INSERT INTO services (establishment_id, name, prefix, color) VALUES (?, ?, ?, ?)')
                    ->execute([$clinic, mb_substr($name, 0, 60), $prefix, $colors[(int)$count->fetchColumn() % count($colors)]]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') json_out(['error' => 'That prefix is already used'], 409);
                throw $e;
            }
            json_out(['ok' => true], 201);

        case 'toggle_service':
            $sid = (int)($in['service_id'] ?? 0);
            if (!service_in_clinic($pdo, $sid, $clinic)) json_out(['error' => 'Service not found'], 404);
            $pdo->prepare('UPDATE services SET is_active = 1 - is_active WHERE id = ?')->execute([$sid]);
            json_out(['ok' => true]);

        case 'staff':
            $st = $pdo->prepare(
                "SELECT s.id, s.name, s.email, s.role, s.assigned_service_id, sv.name AS service_name
                   FROM staff s LEFT JOIN services sv ON sv.id = s.assigned_service_id
                  WHERE s.establishment_id = ? ORDER BY s.role = 'Teller', s.name"
            );
            $st->execute([$clinic]);
            json_out(['staff' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'role' => $r['role'],
                'assigned_service_id' => $r['assigned_service_id'] !== null ? (int)$r['assigned_service_id'] : null,
                'service_name' => $r['service_name'],
            ], $st->fetchAll())]);

        case 'add_staff':
            $name  = trim((string)($in['name'] ?? ''));
            $email = strtolower(trim((string)($in['email'] ?? '')));
            $role  = (string)($in['role'] ?? 'Teller');
            $sid   = (int)($in['service_id'] ?? 0);
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Enter a name and a valid email'], 422);
            if (!in_array($role, ['Teller', 'Clinic Manager'], true)) json_out(['error' => 'Invalid role'], 422);
            if ($role === 'Clinic Manager') need_super($isSuper);
            if ($role === 'Teller' && !service_in_clinic($pdo, $sid, $clinic)) json_out(['error' => 'Choose a department for the teller'], 422);
            $pass = temp_password();
            try {
                $pdo->prepare(
                    'INSERT INTO staff (establishment_id, name, email, password_hash, role, assigned_service_id, must_change_password)
                     VALUES (?, ?, ?, ?, ?, ?, 1)'
                )->execute([$clinic, mb_substr($name, 0, 100), $email, password_hash($pass, PASSWORD_DEFAULT), $role, $role === 'Teller' ? $sid : null]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') json_out(['error' => 'That email is already used'], 409);
                throw $e;
            }
            json_out(['ok' => true, 'temp_password' => $pass], 201);

        case 'set_staff_service':
            $sid = (int)($in['service_id'] ?? 0);
            if (!service_in_clinic($pdo, $sid, $clinic)) json_out(['error' => 'Service not found'], 404);
            $pdo->prepare("UPDATE staff SET assigned_service_id = ? WHERE id = ? AND establishment_id = ? AND role = 'Teller'")
                ->execute([$sid, (int)($in['staff_id'] ?? 0), $clinic]);
            json_out(['ok' => true]);

        case 'reset_password':
            $pass = temp_password();
            $st = $pdo->prepare("UPDATE staff SET password_hash = ?, must_change_password = 1 WHERE id = ? AND establishment_id = ? AND role <> 'Super Admin'");
            $st->execute([password_hash($pass, PASSWORD_DEFAULT), (int)($in['staff_id'] ?? 0), $clinic]);
            if ($st->rowCount() === 0) json_out(['error' => 'Staff member not found'], 404);
            json_out(['ok' => true, 'temp_password' => $pass]);

        case 'clinics':
            need_super($isSuper);
            json_out(['clinics' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'name' => $r['name'], 'join_code' => $r['join_code'], 'ticket_limit' => (int)$r['ticket_limit'],
            ], $pdo->query('SELECT id, name, join_code, ticket_limit FROM establishments ORDER BY id')->fetchAll())]);

        case 'add_clinic':
            need_super($isSuper);
            $name  = trim((string)($in['name'] ?? ''));
            $limit = max(1, (int)($in['ticket_limit'] ?? 100));
            if ($name === '') json_out(['error' => 'Enter the clinic name'], 422);
            $letters = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name) . 'CLN', 0, 3));
            $code    = $letters . '-' . strtoupper(bin2hex(random_bytes(3))); // join code, e.g. SUN-A1B2C3
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO establishments (name, join_code, ticket_limit) VALUES (?, ?, ?)')->execute([mb_substr($name, 0, 120), $code, $limit]);
            $eid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO services (establishment_id, name, prefix, color) VALUES (?, ?, ?, ?)');
            foreach ([['Consultation', 'CON', '#1E3A8A'], ['Laboratory', 'LAB', '#0F766E'], ['Pharmacy', 'PHA', '#B45309']] as $s) {
                $ins->execute([$eid, $s[0], $s[1], $s[2]]);
            }
            $pdo->prepare('INSERT INTO announcements (establishment_id, message) VALUES (?, ?)')
                ->execute([$eid, 'Welcome to ' . $name . '. Please wait for your number to be called.']);
            $pdo->commit();
            json_out(['ok' => true, 'id' => $eid, 'join_code' => $code], 201);

        case 'set_limit':
            need_super($isSuper);
            $pdo->prepare('UPDATE establishments SET ticket_limit = ? WHERE id = ?')
                ->execute([max(1, (int)($in['ticket_limit'] ?? 100)), $clinic]);
            json_out(['ok' => true]);

        default:
            json_out(['error' => 'Unknown action'], 422);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('api_admin: ' . $e->getMessage());
    json_out(['error' => 'Something went wrong on the server'], 500);
}