<?php

namespace App\Services\Publishing;

use App\Exceptions\WeChatApiException;
use App\Models\WeChatOfficialAccountConnection;
use App\Services\Outbound\SecureHttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Only the public official API. No browser session, cookies, publish or mass-send endpoints. */
class WeChatOfficialAccountClient
{
    private const BASE = 'https://api.weixin.qq.com/cgi-bin/';

    private int $tokenExpiresIn = 0;

    public function __construct(private readonly SecureHttpFactory $http) {}

    public function validate(string $appId, string $secret): void
    {
        $this->token($appId, $secret);
    }

    private function token(string $appId, string $secret): string
    {
        try {
            $response = $this->http->asJson()->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post(self::BASE.'stable_token', ['grant_type' => 'client_credential', 'appid' => $appId, 'secret' => $secret, 'force_refresh' => false]);
        } catch (Throwable) {
            throw new WeChatApiException;
        }
        $data = $this->result($response, false);
        if (! is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            throw new WeChatApiException;
        }

        $this->tokenExpiresIn = max(0, (int) ($data['expires_in'] ?? 0));

        return $data['access_token'];
    }

    private function accessToken(WeChatOfficialAccountConnection $connection): string
    {
        $key = 'wechat-token:'.$connection->tenant_id.':'.$connection->id.':'.$connection->revision;
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return Crypt::decryptString($cached);
        }
        $token = $this->token($connection->app_id, $connection->app_secret);
        // The stable endpoint may return a token close to expiry. Keep our cache brief;
        // normal-mode calls never force invalidation of another connected application.
        if ($this->tokenExpiresIn > 30) {
            Cache::put($key, Crypt::encryptString($token), now()->addSeconds(min(60, $this->tokenExpiresIn - 30)));
        }

        return $token;
    }

    public function upload(WeChatOfficialAccountConnection $connection, string $bytes, string $mime, bool $cover): array
    {
        $endpoint = $cover ? 'material/add_material' : 'media/uploadimg';
        $token = $this->accessToken($connection);
        try {
            $response = $this->http->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->attach('media', $bytes, $mime === 'image/png' ? 'image.png' : 'image.jpg', ['Content-Type' => $mime])
                ->post(self::BASE.$endpoint.'?'.http_build_query(['access_token' => $token] + ($cover ? ['type' => 'image'] : [])));
        } catch (Throwable) {
            throw new WeChatApiException;
        }

        return $this->result($response, false);
    }

    public function addDraft(WeChatOfficialAccountConnection $connection, array $article): string
    {
        $data = $this->call($connection, 'draft/add', ['articles' => [$article]], true);
        if (! is_string($data['media_id'] ?? null) || $data['media_id'] === '' || strlen($data['media_id']) > 128) {
            throw new WeChatApiException(uncertain: true);
        }

        return $data['media_id'];
    }

    public function getDraft(WeChatOfficialAccountConnection $connection, string $mediaId): array
    {
        return $this->call($connection, 'draft/get', ['media_id' => $mediaId]);
    }

    public function recentDrafts(WeChatOfficialAccountConnection $connection, int $offset): array
    {
        return $this->call($connection, 'draft/batchget', ['offset' => $offset, 'count' => 20, 'no_content' => 0]);
    }

    private function call(WeChatOfficialAccountConnection $connection, string $endpoint, array $body, bool $mutation = false): array
    {
        $token = $this->accessToken($connection);
        try {
            $response = $this->http->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->withBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json')
                ->post(self::BASE.$endpoint.'?'.http_build_query(['access_token' => $token]));
        } catch (Throwable) {
            throw new WeChatApiException(uncertain: $mutation);
        }

        return $this->result($response, $mutation);
    }

    private function result(Response $response, bool $mutation): array
    {
        if (! $response->successful() || ! is_array($data = $response->json())) {
            throw new WeChatApiException(uncertain: $mutation);
        }
        if ((int) ($data['errcode'] ?? 0) !== 0) {
            $code = (int) $data['errcode'];
            $ip = null;
            if ($code === 40164 && preg_match('/invalid ip\s+([a-f0-9:.]+)/i', (string) ($data['errmsg'] ?? ''), $match)
                && filter_var($match[1], FILTER_VALIDATE_IP)) {
                $ip = $match[1];
            }
            throw new WeChatApiException($code, $mutation && $code === -1, $ip);
        }

        return $data;
    }
}
