<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';

// Temporary: lists what the web server passes to PHP, to see which SSO attributes are available.
require_admin();

page_start('Server variables');
echo '<table>';
foreach ($_SERVER as $k => $v) {
    $v = is_string($v) ? $v : json_encode($v);
    if (preg_match('/COOKIE|AUTH|SECRET|KEY|TOKEN|PASS/i', (string) $k)) {
        $v = '(hidden)';
    }
    echo '<tr><th>' . h((string) $k) . '</th><td><code>' . h($v) . '</code></td></tr>';
}
echo '</table>';
page_end();
