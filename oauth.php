<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// Links the SSO user to a verified GitHub account via the GitHub App's user authorization.
$sso = sso_user();
require_configured();

if (!isset($_GET['code'])) {
    $return = $_GET['return'] ?? 'index.php';
    if (!preg_match('/^(index|join)\.php(\?code=[a-f0-9]{16})?$/', $return)) {
        $return = 'index.php';
    }
    $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
    $_SESSION['oauth_return'] = $return;
    header('Location: https://github.com/login/oauth/authorize?' . http_build_query([
        'client_id' => setting('gh_client_id'),
        'redirect_uri' => url('oauth.php'),
        'state' => $_SESSION['oauth_state'],
    ]));
    exit;
}

$state = $_SESSION['oauth_state'] ?? '';
$return = $_SESSION['oauth_return'] ?? 'index.php';
unset($_SESSION['oauth_state'], $_SESSION['oauth_return']);
if ($state === '' || !hash_equals($state, (string) ($_GET['state'] ?? ''))) {
    flash('err', 'GitHub login expired or was tampered with. Please try again.');
    redirect($return);
}

[$s, $res] = http_json('POST', 'https://github.com/login/oauth/access_token', [
    'client_id' => setting('gh_client_id'),
    'client_secret' => setting('gh_client_secret'),
    'code' => (string) $_GET['code'],
    'redirect_uri' => url('oauth.php'),
], ['Accept: application/json']);
if ($s !== 200 || empty($res['access_token'])) {
    throw new RuntimeException('OAuth code exchange failed: ' . ($res['error_description'] ?? "HTTP $s"));
}
[$s, $gh] = gh_api('GET', '/user', null, $res['access_token']);
if ($s !== 200) {
    throw gh_error('Could not read GitHub user', $s, $gh);
}

$other = q('SELECT sso_user FROM classroom_users WHERE github_id = ? AND sso_user <> ?', [$gh['id'], $sso])->fetch();
if ($other) {
    flash('err', "GitHub account {$gh['login']} is already linked to another university user.");
    redirect($return);
}
q('INSERT INTO classroom_users (sso_user, github_id, github_login) VALUES (?, ?, ?)
   ON DUPLICATE KEY UPDATE github_id = VALUES(github_id), github_login = VALUES(github_login)',
    [$sso, $gh['id'], $gh['login']]);
flash('ok', "Linked GitHub account {$gh['login']}.");
redirect($return);
