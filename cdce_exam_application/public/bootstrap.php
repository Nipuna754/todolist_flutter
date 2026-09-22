<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Cdce\ExamApplication\Config;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'cookie_secure' => (($_SERVER['HTTPS'] ?? '') !== ''),
    ]);
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function cdce_config(): Config
{
    static $config = null;

    return $config ??= Config::load();
}

function cdce_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf'];
}

function cdce_csrf_valid(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals((string) $_SESSION['csrf'], $token);
}

/**
 * Identifies the client for rate limiting. Behind the university reverse proxy
 * REMOTE_ADDR is the proxy, so trust X-Forwarded-For only when the immediate
 * peer is a configured proxy.
 */
function cdce_client_key(Config $config): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $trusted = $config->get('trusted_proxies', []);

    if (is_array($trusted) && in_array($remote, $trusted, true)) {
        $forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        if ($forwarded !== '') {
            return $forwarded;
        }
    }

    return $remote;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
