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
        . b64url(json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => setting('gh_app_id')]));
    $key = openssl_pkey_get_private((string) setting('gh_private_key'));
    if ($key === false || !openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('GitHub App private key is missing or invalid.');
    }
    return $data . '.' . b64url($sig);
}

/** Installation access token for the org, cached in settings until shortly before expiry. */
function gh_token(): string
{
    $token = setting('gh_token');
    if ($token !== null && (int) setting('gh_token_expires') > time() + 300) {
        return $token;
    }
    $jwt = gh_app_jwt();
    $org = rawurlencode((string) setting('gh_org'));
    [$s, $inst] = gh_api('GET', "/orgs/$org/installation", null, $jwt);
    if ($s !== 200) {
        throw gh_error("GitHub App is not installed on org '" . setting('gh_org') . "'", $s, $inst);
    }
    [$s, $res] = gh_api('POST', "/app/installations/{$inst['id']}/access_tokens", null, $jwt);
    if ($s !== 201) {
        throw gh_error('Could not get an installation token', $s, $res);
    }
    set_setting('gh_token', $res['token']);
    set_setting('gh_token_expires', (string) strtotime($res['expires_at']));
    return $res['token'];
}

/** Calls the API as the app installation on the classroom org. */
function gh(string $method, string $path, ?array $body = null): array
{
    return gh_api($method, $path, $body, gh_token());
}
