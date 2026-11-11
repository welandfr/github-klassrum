<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';

// One-off: real names for users already in the database, from students.tmp in the app folder
// (tab-separated: first name, last name, username). Names saved from the SSO login are kept.
// Remove this page, and students.tmp, once it has been run.
require_admin();

$file = APP_DIR . '/students.tmp';
$rows = [];
foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
    $f = array_map('trim', explode("\t", $line));
    if (count($f) === 3 && $f[2] !== '') {
        $rows[strtolower($f[2])] = trim("$f[0] $f[1]");
    }
}
// Users the app knows: linked GitHub accounts, team members and reserved slots.
$known = array_change_key_case(array_flip(q('SELECT sso_user FROM users UNION SELECT sso_user FROM team_members
                                             UNION SELECT sso_user FROM team_reservations')->fetchAll(PDO::FETCH_COLUMN)));
$named = array_change_key_case(q('SELECT sso_user, display_name FROM people')->fetchAll(PDO::FETCH_KEY_PAIR));
$todo = array_filter($rows, fn($name, $sso) => isset($known[$sso]) && !isset($named[$sso]), ARRAY_FILTER_USE_BOTH);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    tx(function () use ($todo) {
        foreach ($todo as $sso => $name) {
            q('INSERT INTO people (sso_user, display_name) VALUES (?, ?) ON CONFLICT (sso_user) DO NOTHING', [$sso, $name]);
        }
    });
    flash('ok', 'Saved ' . count($todo) . ' name(s).');
    redirect('admin/import-names.php');
}

page_start('Import names');
if (!$rows) {
    echo '<p class="err">No names found in <code>' . h($file) . '</code>.</p>';
    page_end();
    exit;
}
echo '<p>' . count($rows) . ' names in <code>' . h($file) . '</code>: ' . count(array_intersect_key($rows, $known)) . ' of them are in Klassrum, '
    . count(array_intersect_key($rows, $known, $named)) . ' of those already have a name (kept).</p>';
if ($todo) {
    echo '<table><tr><th>User</th><th>Name to save</th></tr>';
    foreach ($todo as $sso => $name) {
        echo '<tr><td>' . h($sso) . '</td><td>' . h($name) . '</td></tr>';
    }
    echo '</table><form method="post">' . csrf_field() . '<button>Save ' . count($todo) . ' name(s)</button></form>';
} else {
    echo '<p class="ok">Nothing to save.</p>';
}
page_end();
