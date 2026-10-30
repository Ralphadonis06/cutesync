<?php
// api_login.php - login, logout and "who am I" for Admin / Clinic Manager / Teller.
//
//   POST api_login.php                 {"email":"...","password":"..."}  -> {"user":{...}}
//   GET  api_login.php?action=me                                         -> {"user":{...}} or {"user":null}
//   POST api_login.php?action=logout                                     -> {"ok":true}
declare(strict_types=1);
require __DIR__ . '/db_connect.php';

$action = $_GET['action'] ?? 'login';

// ---- Who is logged in right now? (React calls this when the page loads) ----
if ($action === 'me') {
    json_out(['user' => current_user()]);
}

// ---- Logout ----
if ($action === 'logout') {
    start_session();
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true]);
}

// ---- Login ----
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['error' => 'Use POST to log in'], 405);
}

$in    = read_json();
$email = strtolower(trim((string)($in['email'] ?? '')));
$pass  = (string)($in['password'] ?? '');

if ($email === '' || $pass === '') {
    json_out(['error' => 'Enter your email and password'], 422);
}

$stmt = db()->prepare(
    'SELECT s.id, s.name, s.email, s.password_hash, s.role, s.establishment_id,
            s.assigned_service_id, s.must_change_password,
            sv.name AS service_name, sv.prefix AS service_prefix
       FROM staff s
       LEFT JOIN services sv ON sv.id = s.assigned_service_id
      WHERE s.email = ?
      LIMIT 1'
);
$stmt->execute([$email]);
$row = $stmt->fetch();

// Always run password_verify, even for unknown emails, so response time does not reveal
// which emails exist. (This hash is only a placeholder and never matches a real login.)
$hash = $row['password_hash'] ?? '$2y$12$t4BfeOZPOCeVy.y3/aqJreIcNgvTTPxXBuib9/HWFXSAwW/MeIoYe';
$ok   = password_verify($pass, $hash) && $row;

if (!$ok) {
    usleep(400000); // small delay slows down password guessing
    json_out(['error' => 'Incorrect email or password'], 401);
}

// Upgrade old hashes automatically if PHP's default algorithm gets stronger.
if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
    db()->prepare('UPDATE staff SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
}

$user = [
    'id'                   => (int)$row['id'],
    'name'                 => $row['name'],
    'email'                => $row['email'],
    'role'                 => $row['role'],            // 'Super Admin' | 'Clinic Manager' | 'Teller'
    'establishment_id'     => $row['establishment_id'] !== null ? (int)$row['establishment_id'] : null,
    'assigned_service_id'  => $row['assigned_service_id'] !== null ? (int)$row['assigned_service_id'] : null,
    'service_name'         => $row['service_name'],
    'service_prefix'       => $row['service_prefix'],
    'must_change_password' => (bool)$row['must_change_password'],
];

start_session();
session_regenerate_id(true); // new session id after login blocks session fixation
$_SESSION['user'] = $user;

json_out(['user' => $user]);