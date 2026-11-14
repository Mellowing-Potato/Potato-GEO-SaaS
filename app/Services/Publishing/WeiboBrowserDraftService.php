<?php

namespace App\Services\Publishing;

use App\Exceptions\ManualPublicationConflictException;
use App\Models\BrowserDraftDelivery;
use App\Models\ManualPublication;
use App\Models\ManualPublicationTransition;
use App\Models\User;
use DomainException;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The browser component owns remote writes; these records serialize and audit its receipts. */
final class WeiboBrowserDraftService
{
    public function __construct(private readonly ManualPublicationService $publications, private readonly PlatformPublicationDraftService $drafts) {}

    public function show(ManualPublication $publication, User $actor): array
    {
        $this->publications->assertCan($actor, $publication, 'view');
        $delivery = BrowserDraftDelivery::query()->where('publication_id', $publication->id)->first();

        return ['delivery' => $delivery?->publicData(), 'stale' => $delivery ? ! $this->matches($delivery, $publication->fresh(['account', 'article'])) : false];
    }

    public function start(ManualPublication $publication, int $revision, string $title, User $actor): array
    {
        return DB::transaction(function () use ($publication, $revision, $title, $actor) {
            $locked = ManualPublication::query()->lockForUpdate()->findOrFail($publication->id);
            $current = $this->publications->assertPlatformDraftReady($locked, $revision, $actor, true);
            if ($current->platform !== 'weibo' || ! preg_match('~^https://(?:www\.)?weibo\.com/u/(\d{4,24})/?$~D', (string) $current->account->profile_url, $match)) {
                throw new DomainException('请在账号设置中填写微博数字主页链接（https://weibo.com/u/账号ID），再发送草稿。');
            }
            $prepared = $this->drafts->prepare($current, $revision, $actor, $title);
            $dom = new DOMDocument('1.0', 'UTF-8');
            @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$prepared['html'], LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
            $links = [];
            foreach ($dom->getElementsByTagName('a') as $a) {
                $links[] = $a->getAttribute('href');
            }
            $payload = ['title' => $prepared['title'], 'html' => $prepared['html'], 'uid' => $match[1],
                'text_hash' => hash('sha256', preg_replace('/[\s\x{00a0}\x{200b}-\x{200d}\x{feff}]+/u', '', $dom->textContent)),
                'links' => array_values(array_unique($links)), 'image_count' => $prepared['image_count'],
                'identity' => $this->identity($current)];
            if (strlen($payload['html']) > 300000 || $payload['image_count'] > 20 || count($payload['links']) > 200) {
                throw new DomainException('微博草稿最多支持 20 张正文图片、200 个引用链接，排版内容不超过 300KB。');
            }
            $delivery = BrowserDraftDelivery::query()->where('publication_id', $current->id)->lockForUpdate()->first();
            if ($delivery && (! $this->matches($delivery, $current) || $delivery->publication_title !== $prepared['title'])) {
                throw new DomainException('已有发送记录对应另一版本、账号或标题，请保留原草稿并为新内容准备新的发布单。');
            }
            if ($delivery && ! $delivery->draft_id && ! in_array($delivery->status, ['prepared', 'failed'], true)) {
                throw new DomainException('上次创建结果尚未确认，请先核对微博草稿箱，系统不会重复创建。');
            }
            $token = (string) Str::uuid();
            if (! $delivery) {
                $delivery = new BrowserDraftDelivery;
                $delivery->fill(['publication_id' => $current->id, 'account_id' => $current->account_id,
                    'created_by' => $actor->id, 'platform' => 'weibo', 'status' => 'prepared',
                    'publication_revision' => $revision, 'content_fingerprint' => $current->content_fingerprint,
                    'article_version' => $prepared['article_version'], 'publication_title' => $prepared['title'],
                    'account_identity' => $match[1], 'payload' => $payload]);
            }
            // A known remote ID is read-only on every later attempt, including failed verification.
            $delivery->fill(['request_hash' => hash('sha256', $token), 'created_by' => $actor->id,
                'expires_at' => now()->addMinutes(10), 'last_error' => null]);
            if (! $delivery->draft_id) {
                $delivery->status = 'prepared';
            }
            $delivery->save();

            return ['delivery' => $delivery->publicData(), 'request_token' => $token,
                'payload' => $delivery->payload + ['deliveryId' => $delivery->id, 'token' => $token,
                    'mode' => $delivery->draft_id ? 'verify' : 'create', 'draftId' => $delivery->draft_id,
                    'expiresAt' => $delivery->expires_at->getTimestamp() * 1000]];
        });
    }

    public function progress(ManualPublication $publication, array $data, User $actor): array
    {
        return DB::transaction(function () use ($publication, $data, $actor) {
            $locked = ManualPublication::query()->lockForUpdate()->findOrFail($publication->id);
            $delivery = BrowserDraftDelivery::query()->where('publication_id', $locked->id)->lockForUpdate()->firstOrFail();
            $this->publications->assertCan($actor, $locked, 'transition');
            if (! hash_equals($delivery->request_hash, hash('sha256', $data['token'])) || $delivery->created_by !== $actor->id
                || $delivery->expires_at->isPast() || (int) $data['delivery_id'] !== $delivery->id) {
                throw new DomainException('发送凭据已失效，请回到发布单重新核对草稿。');
            }
            $stage = $data['stage'];
            if ($stage === 'error') {
                $delivery->last_error = $data['code'] ?? 'helper_error';
                $delivery->status = $delivery->status === 'prepared' ? 'failed' : 'unknown';
                $delivery->save();

                return $delivery->publicData();
            }
            $current = $this->publications->assertPlatformDraftReady($locked, $delivery->publication_revision, $actor, true);
            if (! $this->matches($delivery, $current) || ($data['uid'] ?? null) !== $delivery->account_identity) {
                throw new ManualPublicationConflictException;
            }
            $transitions = ['creating' => ['prepared'], 'created' => ['creating'], 'saving' => ['created'],
                'verifying' => ['saving'], 'saved' => ['verifying', 'saved', 'unknown', 'created', 'saving']];
            if (! in_array($delivery->status, $transitions[$stage] ?? [], true)) {
                throw new DomainException('发送步骤已变化，停止重复请求并核对已有草稿。');
            }
            if ($stage === 'created') {
                $delivery->draft_id = $data['draft_id'];
            } elseif ($stage !== 'creating' && (! $delivery->draft_id || ($data['draft_id'] ?? null) !== $delivery->draft_id)) {
                throw new DomainException('草稿编号不一致，已停止写入。');
            }
            if ($stage === 'verifying') {
                $images = $data['images'] ?? [];
                if (count($images) !== $delivery->payload['image_count']) {
                    throw new DomainException('图片转存结果不完整。');
                }
                foreach ($images as $image) {
                    if (! preg_match('~^https://(?:[a-z0-9-]+\.)*sinaimg\.cn/[^?#]+$~iD', $image)) {
                        throw new DomainException('图片未完整转存至微博。');
                    }
                }
                $delivery->payload = $delivery->payload + ['remote_images' => $images];
            }
            if ($stage === 'saved') {
                $payload = $delivery->payload;
                $links = array_values(array_unique($data['links'] ?? []));
                sort($links);
                $expected = $payload['links'];
                sort($expected);
                if (($data['text_hash'] ?? '') !== $payload['text_hash'] || ($data['title'] ?? '') !== $payload['title']
                    || ($data['image_count'] ?? -1) !== $payload['image_count'] || $links !== $expected
                    || ! preg_match('/^\d+\.\d+\.\d+$/D', $data['helper_version'] ?? '')
                    || version_compare($data['helper_version'], '0.3.1', '<')) {
                    throw new DomainException('微博草稿回读与发送内容不一致，请打开草稿检查。');
                }
                if (! $delivery->verified_at) {
                    ManualPublicationTransition::query()->create(['tenant_id' => $current->tenant_id,
                        'manual_publication_id' => $current->id, 'changed_by_user_id' => $actor->id,
                        'from_status' => $current->status, 'to_status' => $current->status,
                        'result_note' => '浏览器助手已保存并回读微博草稿 '.$delivery->draft_id.'；标题：'.$payload['title']
                            .'；文章 v'.$delivery->article_version.'；仅草稿，尚未公开发布。', 'created_at' => now()]);
                }
                $delivery->verified_at = now();
            }
            $delivery->status = $stage;
            $delivery->save();

            return $delivery->publicData();
        });
    }

    private function identity(ManualPublication $publication): string
    {
        return hash('sha256', json_encode([$publication->account_id, $publication->account?->account_name,
            $publication->account?->profile_url, $publication->account?->persona_id], JSON_THROW_ON_ERROR));
    }

    private function matches(BrowserDraftDelivery $delivery, ManualPublication $publication): bool
    {
        return $publication->platform === 'weibo' && $publication->account_id === $delivery->account_id
            && $publication->revision === $delivery->publication_revision
            && $publication->content_fingerprint === $delivery->content_fingerprint
            && (int) data_get($publication->source_snapshot, 'article_version') === $delivery->article_version
            && $publication->article?->version === $delivery->article_version
            && in_array($publication->article?->status, ['approved', 'published'], true)
            && $publication->account?->is_active && $publication->persona?->is_active
            && $this->identity($publication) === ($delivery->payload['identity'] ?? '');
    }
}
