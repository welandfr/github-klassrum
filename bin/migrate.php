<?php
declare(strict_types=1);

// Applies pending database migrations from the command line and prints what it applied. Web requests
// also apply them (see db()); this is for running them right after a deploy, where the CLI PHP has pdo_sqlite.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__) . '/lib/bootstrap.php';
restore_exception_handler();

try {
    // Not db(), which would already have applied them without saying so.
    foreach (migrate(db_connect(db_path())) as $name) {
        echo "Applied migration $name\n";
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
