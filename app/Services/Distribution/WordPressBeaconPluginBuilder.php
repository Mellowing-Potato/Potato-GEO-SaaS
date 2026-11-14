<?php

namespace App\Services\Distribution;

/**
 * W2 GEO 三期：单文件 WordPress 信标插件生成器。
 *
 * 把客户 WP 站的访问按同一 GEOFlow 信标协议回传 SaaS 端点（端点零改动，
 * 防重放 / 限流全部现成）。key_id 与信标地址烤入文件；明文 secret 绝不进文件，
 * 由客户在 wp-config.php 加一行常量 GEOFLOW_BEACON_SECRET 提供。
 */
class WordPressBeaconPluginBuilder
{
    public const PLUGIN_VERSION = '1.0.0';

    /**
     * Generate the single-file WordPress beacon plugin.
     *
     * @return array{files: array<string, string>, instructions: string}
     */
    public function build(string $channelName, string $keyId): array
    {
        // 信标地址同 X3.2：取当前 geo-saas 域名拼接公开端点（可由 GEOFLOW_BEACON_URL 覆盖）。
        $beaconUrl = rtrim(config('app.url'), '/') . '/api/geoflow-beacon/v1/visits';

        return [
            'files' => [
                'geoflow-beacon.php' => $this->buildPluginFile($channelName, $keyId, $beaconUrl),
            ],
            'instructions' => $this->buildInstructions($channelName, $beaconUrl),
        ];
    }

    private function buildPluginFile(string $channelName, string $keyId, string $beaconUrl): string
    {
        $escapedName = addslashes($channelName);
        $escapedKeyId = addslashes($keyId);
        $escapedUrl = addslashes($beaconUrl);
        $version = self::PLUGIN_VERSION;

        // 头部（双引号 heredoc，烤入 version / key_id / 信标 URL；secret 不在此）
        $header = <<<PHP
<?php

/**
 * Plugin Name: GEOFlow Beacon
 * Description: Reports AI crawler visits on distributed GEO articles back to GEO SaaS via the GEOFlow beacon protocol.
 * Version: {$version}
 * Author: GEO SaaS
 */

if (!defined('ABSPATH')) {
    exit;
}

// ─── Baked at generation time ───
define('GEOFLOW_BEACON_KEY_ID', '{$escapedKeyId}');
define('GEOFLOW_BEACON_URL', '{$escapedUrl}');

// The shared secret is NOT baked into this file.
// Add the following line to your wp-config.php (the secret was shown once
// when you generated this channel's key):
//   define('GEOFLOW_BEACON_SECRET', '<your-secret>');
// If the constant is missing, the plugin silently skips reporting.

// ─── GEOFlow Beacon for "{$escapedName}" ───
PHP;

        // 主体（单引号 heredoc，逐字输出，不做变量插值——协议与 X3 端点逐字节一致）
        $body = <<<'PHP'

add_action('template_redirect', static function () {
    // Only frontend requests.
    if (is_admin()) {
        return;
    }
    if (wp_doing_cron()) {
        return;
    }
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return;
    }

    $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    // Skip login / REST / static assets.
    if (strpos($requestUri, 'wp-login') !== false) {
        return;
    }
    if (strpos($requestUri, '/wp-json') === 0 || strpos($requestUri, 'wp-json') !== false) {
        return;
    }
        // Strip the query string BEFORE checking the extension - otherwise a page like
        // "/?s=v1.5" is mistaken for a static asset and never reported. Also treat
        // .html/.htm as real pages so "/%postname%.html" SEO permalinks (common on
        // Chinese sites) are reported; only skip other extensions.
    $path = strtok($requestUri, '?');
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    if ($ext !== '' && $ext !== 'html' && $ext !== 'htm') {
        return;
    }

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }

    // Defer until the response status is settled.
    add_action('shutdown', 'geoflow_beacon_report');
});

function geoflow_beacon_report(): void
{
    try {
        if (!defined('GEOFLOW_BEACON_SECRET') || GEOFLOW_BEACON_SECRET === '') {
            return; // secret not configured: silent skip
        }
        $keyId = defined('GEOFLOW_BEACON_KEY_ID') ? GEOFLOW_BEACON_KEY_ID : '';
        $beaconUrl = defined('GEOFLOW_BEACON_URL') ? GEOFLOW_BEACON_URL : '';
        if ($keyId === '' || $beaconUrl === '') {
            return;
        }

        $statusCode = (int) http_response_code();
        $rawPath = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = strtok($rawPath, '?');
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        $visits = [[
            'path' => $path,
            'user_agent' => $userAgent,
            'referer' => $referer,
            'method' => $method,
            'status_code' => $statusCode,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]];

        $body = wp_json_encode(['visits' => $visits]);
        if ($body === false) {
            return;
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', $body);
        $beaconPath = parse_url($beaconUrl, PHP_URL_PATH) ?: '/';
        // canonical 与 SaaS 信标端点逐字节一致：METHOD\nPATH\nTIMESTAMP\nNONCE\nBODY_HASH
        $canonical = implode("\n", ['POST', $beaconPath, $timestamp, $nonce, $bodyHash]);
        $signature = hash_hmac('sha256', $canonical, GEOFLOW_BEACON_SECRET);

        wp_remote_post($beaconUrl, [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-GEOFlow-Key-Id' => $keyId,
                'X-GEOFlow-Timestamp' => $timestamp,
                'X-GEOFlow-Nonce' => $nonce,
                'X-GEOFlow-Body-SHA256' => $bodyHash,
                'X-GEOFlow-Signature' => $signature,
            ],
            'body' => $body,
            'blocking' => false,
            'timeout' => 2,
        ]);
    } catch (\Throwable $e) {
        // Customer site stability first: drop the beacon silently.
    }
}
PHP;

        return $header . $body;
    }

    private function buildInstructions(string $channelName, string $beaconUrl): string
    {
        return <<<TEXT
GEOFlow Beacon Plugin for "{$channelName}"

The plugin reports AI crawler visits on your distributed GEO articles back to GEO SaaS.

1. Download geoflow-beacon.php and upload it to wp-content/plugins/ (or zip the file and install via Plugins → Add New → Upload).
2. Activate "GEOFlow Beacon" in your WordPress admin.
3. Open wp-config.php and add your secret (shown once when you generated this channel's key):

   define('GEOFLOW_BEACON_SECRET', '<your-secret>');

   The key id and beacon URL are already baked into the plugin — do NOT put the secret in the plugin file.
4. Visit a published GEO article on your site (optionally with a GPTBot user-agent) and confirm crawler hits light up in the GEO analytics panel.

Beacon endpoint (already configured in the plugin):
  {$beaconUrl}

Notes:
- Reporting is best-effort and never blocks page rendering (fire-and-forget, 2s timeout).
- Only frontend GET/HEAD requests are reported; admin, login, cron, REST and static assets are skipped.
- AI crawlers do not run JavaScript, so this server-side PHP hook is what captures them.
TEXT;
    }
}
