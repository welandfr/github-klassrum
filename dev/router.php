<?php
declare(strict_types=1);

// Router for PHP's built-in server only; stands in for the SSO in local development.
//   php -S 0.0.0.0:8000 -t . dev/router.php
// Switch user with ?as=<sso-user> (remembered in a cookie); default is $DEV_USER or "dev".
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

// Mirror the .htaccess denies, which the built-in server ignores.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/(lib|config|dev|migrations|\.git)(/|$)~', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

$user = $_GET['as'] ?? $_COOKIE['dev_user'] ?? getenv('DEV_USER') ?: 'dev';
if (isset($_GET['as'])) {
    setcookie('dev_user', $user, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}
$_SERVER['REMOTE_USER'] = $user;
// Fake SSO attributes like the server's mod_auth_mellon sets (see sso_attr()).
// "dev" and users named teacher… are teachers, everyone else a student.
$_SERVER['MELLON_displayName'] = ucfirst($user) . ' Dev';
$_SERVER['MELLON_arcadaRole'] = $_SERVER['MELLON_arcadaRole_0'] =
    $user === 'dev' || str_starts_with($user, 'teacher') ? 'teacher' : 'student';

return false;  // let the built-in server run the requested script
