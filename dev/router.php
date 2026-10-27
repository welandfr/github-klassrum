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
if (preg_match('~^/(lib|config|dev|migrations)(/|$)~', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

$user = $_GET['as'] ?? $_COOKIE['dev_user'] ?? getenv('DEV_USER') ?: 'dev';
if (isset($_GET['as'])) {
    setcookie('dev_user', $user, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}
$_SERVER['REMOTE_USER'] = $user;

return false;  // let the built-in server run the requested script
