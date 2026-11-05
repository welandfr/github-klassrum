<?php
declare(strict_types=1);

// Applies pending database migrations from the command line, e.g. in the deploy cron job
// (see README). Prints only what it applied, so cron mails only when something happened.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__) . '/lib/bootstrap.php';
restore_exception_handler();

try {
    foreach (migrate(db()) as $name) {
        echo "Applied migration $name\n";
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
