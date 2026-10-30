<?php
// db_connect.php - shared by every api_*.php file.
// Put it in C:\xampp\htdocs\queuesync\ next to the other API files.
declare(strict_types=1);

// ---- Settings (XAMPP defaults) ----
const DB_HOST = '127.0.0.1';
const DB_NAME = 'queuesync';
const DB_USER = 'root';
const DB_PASS = '';              // XAMPP's root password is empty by default. Change it for real deployments.
const CLINIC_TZ = 'Asia/Manila'; // set to your clinic's timezone

// Websites allowed to call this API from the browser (React dev servers).
// Add your final address when you deploy, e.g. 'http://192.168.1.10'.
const ALLOWED_ORIGINS = [
    'http://localhost:5173',     // Vite
    'http://localhost:3000',     // Create React App
    'http://localhost',          // React build served by Apache
];

date_default_timezone_set(CLINIC_TZ);

// ---- JSON + CORS setup (runs for every request) ----
header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true'); // needed for the login session cookie
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { // browser pre-flight check
    http_response_code(204);
    exit;
}

// ---- Helpers ----
function json_out($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Reads the JSON body that fetch() sends with POST.
function read_json(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '[]', true);
    return is_array($data) ? $data : [];
}

// One shared PDO connection. Prepared statements protect against SQL injection.
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    try {
        $offset = (new DateTime('now', new DateTimeZone(CLINIC_TZ)))->format('P'); // e.g. +08:00
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '$offset'",
            ]
        );
    } catch (PDOException $e) {
        error_log('QueueSync DB error: ' . $e->getMessage()); // details go to the log, not the browser
        json_out(['error' => 'Database connection failed'], 500);
    }
    return $pdo;
}

// ---- Login session helpers (used by api_login.php and the protected APIs) ----
function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('queuesync');
        session_set_cookie_params([
            'lifetime' => 0,          // ends when the browser closes
            'path'     => '/',
            'httponly' => true,       // JavaScript cannot read the cookie
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function current_user(): ?array
{
    start_session();
    return $_SESSION['user'] ?? null;
}

// Stops the request unless someone is logged in (and, optionally, has one of the given roles).
function require_login(array $roles = []): array
{
    $user = current_user();
    if (!$user) {
        json_out(['error' => 'Please log in first'], 401);
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        json_out(['error' => 'You are not allowed to do that'], 403);
    }
    return $user;
}
