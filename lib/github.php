<?php
declare(strict_types=1);

/** @return array{0:int,1:mixed} [HTTP status, decoded JSON body] */
function http_json(string $method, string $url, ?array $body, array $headers): array
{
    $ch = curl_init($url);
    $headers[] = 'User-Agent: mini-classroom';
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');  // so curl sends Content-Length: 0
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('GitHub request failed: ' . curl_error($ch));
    }
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $raw === '' ? null : json_decode($raw, true)];
}

function gh_api(string $method, string $path, ?array $body, string $token): array
{
    return http_json($method, 'https://api.github.com' . $path, $body, [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'Authorization: Bearer ' . $token,
    ]);
}

function gh_error(string $what, int $status, mixed $body): RuntimeException
{
    return new RuntimeException("$what (HTTP $status: " . ($body['message'] ?? 'no message') . ')');
}

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** Short-lived JWT that authenticates as the GitHub App itself. */
function gh_app_jwt(): string
{
    $now = time();
    $data = b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
        . b64url(json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => setting('gh_client_id')]));
    $key = openssl_pkey_get_private((string) setting('gh_private_key'));
    if ($key === false || !openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('GitHub App private key is missing or invalid.');
    }
    return $data . '.' . b64url($sig);
}

/** Installation access token for an org, cached in settings until shortly before expiry. */
function gh_token(string $org): string
{
    $token = setting("gh_token:$org");
    if ($token !== null && (int) setting("gh_token_expires:$org") > time() + 300) {
        return $token;
    }
    $jwt = gh_app_jwt();
    [$s, $inst] = gh_api('GET', '/orgs/' . rawurlencode($org) . '/installation', null, $jwt);
    if ($s !== 200) {
        throw gh_error("GitHub App is not installed on org '$org'", $s, $inst);
    }
    [$s, $res] = gh_api('POST', "/app/installations/{$inst['id']}/access_tokens", null, $jwt);
    if ($s !== 201) {
        throw gh_error('Could not get an installation token', $s, $res);
    }
    set_setting("gh_token:$org", $res['token']);
    set_setting("gh_token_expires:$org", (string) strtotime($res['expires_at']));
    return $res['token'];
}

/** Drops all cached installation tokens, e.g. after the app credentials change. */
function gh_forget_tokens(): void
{
    q("DELETE FROM classroom_settings WHERE k LIKE 'gh\\_token%'");
    $cache = &settings_cache();
    $cache = array_filter($cache, fn($k) => !str_starts_with((string) $k, 'gh_token'), ARRAY_FILTER_USE_KEY);
}

/** @return string[] logins of the orgs the app is installed on, sorted */
function gh_installed_orgs(): array
{
    $orgs = [];
    $jwt = gh_app_jwt();
    for ($page = 1; ; $page++) {
        [$s, $list] = gh_api('GET', "/app/installations?per_page=100&page=$page", null, $jwt);
        if ($s !== 200) {
            throw gh_error('Could not list the GitHub App installations', $s, $list);
        }
        foreach ($list as $inst) {
            if ($inst['account']['type'] === 'Organization') {
                $orgs[] = $inst['account']['login'];
            }
        }
        if (count($list) < 100) {
            break;
        }
    }
    natcasesort($orgs);
    return array_values($orgs);
}

/**
 * Flashes a warning unless the org's base permission is "No permission". GitHub only reveals
 * it when the app has the Organization → Administration (read) permission.
 */
function check_org_base_permission(string $org): void
{
    [$s, $info] = gh($org, 'GET', '/orgs/' . rawurlencode($org));
    $perm = $s === 200 ? $info['default_repository_permission'] ?? null : null;
    if ($perm === null) {
        flash('muted', "Could not check the base permission of $org. Make sure it is 'No permission' "
            . "(org Settings → Member privileges), or give the app Organization → Administration: Read-only.");
    } elseif ($perm !== 'none') {
        flash('err', "Warning: the base permission of $org is '$perm'. Set it to 'No permission' so students can't see each other's repos.");
    }
}

/** Calls the API as the app installation on the given org. */
function gh(string $org, string $method, string $path, ?array $body = null): array
{
    return gh_api($method, $path, $body, gh_token($org));
}
