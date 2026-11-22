<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
require_admin();
$token = $_POST['csrf_token'] ?? null;
verify_csrf(is_string($token) ? $token : null);
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => $params['samesite'] ?? 'Strict']);
}
session_destroy();
header('Location: /login', true, 303);
exit;
