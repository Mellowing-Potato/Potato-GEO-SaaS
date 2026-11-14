<?php

namespace App\Services\Publishing;

use App\Exceptions\WeChatApiException;
use App\Jobs\SendWeChatDraftJob;
use App\Models\ManualPublication;
use App\Models\ManualPublicationTransition;
use App\Models\User;
use App\Models\WeChatDraftDelivery;
use App\Models\WeChatOfficialAccountConnection;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use DomainException;
use DOMDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class WeChatDraftDeliveryService
{
    public function __construct(
        private readonly ManualPublicationService $publications,
        private readonly PlatformPublicationDraftService $preparation,
        private readonly WeChatOfficialAccountClient $client,
        private readonly SafeOutboundHttpClient $outbound,
        private readonly SecureHttpFactory $http,
    ) {}

    public function enqueue(ManualPublication $publication, int $revision, string $title, UploadedFile $cover, User $actor): WeChatDraftDelivery
    {
        $current = $this->publications->assertPlatformDraftReady($publication, $revision, $actor, true);
        if ($current->platform !== 'wechat') {
            throw new DomainException('仅微信公众号支持此草稿连接。');
        }
        if ($current->draft_receipt) {
            throw new DomainException('本发布单已有草稿记录，请先核对已有草稿，避免重复创建。');
        }
        // The official API documents 32 characters, unlike the web editor's 64.
        if (intdiv(strlen(mb_convert_encoding(trim($title), 'UTF-16LE', 'UTF-8')), 2) > 32) {
            throw new DomainException('微信草稿接口标题最多 32 个字符，请修改本次发送标题；原文章保持不变。');
        }
        $prepared = $this->preparation->prepare($current, $revision, $actor, $title);
        if (mb_strlen($prepared['html']) >= 20000 || strlen($prepared['html']) >= 1000000) {
            throw new DomainException('排版后正文超出微信草稿接口限制，请缩短文章后重新审核。');
        }
        if ($prepared['image_count'] > 10) {
            throw new DomainException('本次草稿直发最多支持 10 张正文图片，请减少图片后重新审核或使用人工复制。');
        }
        $connection = WeChatOfficialAccountConnection::query()->where('account_id', $current->account_id)->first();
        if (! $connection?->verified_at) {
            throw new DomainException('请先在人设与账号中连接并验证公众号。');
        }
        $path = $cover->store('wechat-draft-covers/'.$current->tenant_id, 'local');
        if (! is_string($path) || $path === '') {
            throw new DomainException('封面保存失败，请重新选择图片。');
        }
        try {
            $delivery = DB::transaction(function () use ($current, $connection, $prepared, $path, $actor) {
                // Serialize repeated clicks and requests against this publication.
                $locked = ManualPublication::query()->lockForUpdate()->findOrFail($current->id);
                $this->publications->assertPlatformDraftReady($locked, $current->revision, $actor, true);
                $existing = WeChatDraftDelivery::query()->where('publication_id', $current->id)->first();
                if ($existing) {
                    // Keep every attempt immutable; retry only before any draft write could succeed.
                    throw new DomainException('本发布单已有发送任务，请查看发送状态；发送结果待确认时不能再次发送。');
                }

                return WeChatDraftDelivery::query()->create([
                    'tenant_id' => $current->tenant_id, 'publication_id' => $current->id,
                    'account_id' => $current->account_id, 'connection_id' => $connection->id,
                    'created_by' => $actor->id, 'publication_revision' => $current->revision,
                    'connection_revision' => $connection->revision, 'app_id' => $connection->app_id,
                    'publication_title' => $prepared['title'], 'content_fingerprint' => $current->content_fingerprint,
                    'article_version' => $prepared['article_version'], 'payload' => ['title' => $prepared['title'], 'author' => $connection->author, 'content' => $prepared['html']],
                    'cover_path' => $path, 'status' => 'queued',
                ]);
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        try {
            SendWeChatDraftJob::dispatch($delivery->id, $delivery->tenant_id);
        } catch (Throwable) {
            $delivery->refresh();
            if ($delivery->status === 'queued') {
                $delivery->update(['status' => 'failed', 'last_error' => '发送任务入队失败，请稍后重试。']);
            }
        }

        return $delivery->refresh();
    }

    public function retry(ManualPublication $publication, User $actor): WeChatDraftDelivery
    {
        $this->publications->assertCan($actor, $publication, 'transition');
        $delivery = WeChatDraftDelivery::query()->where('publication_id', $publication->id)->firstOrFail();
        WeChatDraftDelivery::query()->whereKey($delivery->id)->where('status', 'preparing')->where('updated_at', '<', now()->subMinutes(6))
            ->update(['status' => 'failed', 'last_error' => '草稿准备任务中断，未创建草稿。']);
        $delivery->refresh();
        $this->assertCurrent($delivery, $actor);
        $connection = WeChatOfficialAccountConnection::query()->findOrFail($delivery->connection_id);
        // After a verified key rotation, a definitively failed request can reuse the same immutable payload.
        $updated = WeChatDraftDelivery::query()->whereKey($delivery->id)->where('status', 'failed')->whereNull('media_id')
            ->update(['status' => 'queued', 'last_error' => null, 'connection_revision' => $connection->revision]);
        if (! $updated) {
            throw new DomainException('只有明确未创建草稿的失败任务可以重试；结果待确认时请先核对。');
        }
        try {
            SendWeChatDraftJob::dispatch($delivery->id, $delivery->tenant_id);
        } catch (Throwable) {
            WeChatDraftDelivery::query()->whereKey($delivery->id)->where('status', 'queued')
                ->update(['status' => 'failed', 'last_error' => '发送任务入队失败，请稍后重试。']);
        }

        return $delivery->refresh();
    }

    public function execute(WeChatDraftDelivery $delivery): void
    {
        // CAS prevents an at-least-once queue redelivery from creating a second remote draft.
        if (! WeChatDraftDelivery::query()->whereKey($delivery->id)->where('status', 'queued')->update(['status' => 'preparing'])) {
            return;
        }
        $delivery->refresh();
        try {
            $actor = User::query()->where('tenant_id', $delivery->tenant_id)->findOrFail($delivery->created_by);
            $this->assertCurrent($delivery, $actor);
            $connection = $this->connection($delivery);
            $payload = $delivery->payload;
            $cover = Storage::disk('local')->get($delivery->cover_path);
            $mime = $this->imageMime($cover, 2 * 1024 * 1024);
            $thumb = $this->client->upload($connection, $cover, $mime, true);
            if (! is_string($thumb['media_id'] ?? null) || $thumb['media_id'] === '' || strlen($thumb['media_id']) > 128) {
                throw new DomainException('微信未返回有效封面素材编号，未创建草稿。');
            }
            $payload['content'] = $this->uploadImages($connection, $payload['content'], microtime(true) + 150);
            $payload['thumb_media_id'] = $thumb['media_id'];
            $payload['article_type'] = 'news';
            // Persist the exact submitted snapshot and unique cover before the non-idempotent write.
            $delivery->update(['payload' => $payload, 'thumb_media_id' => $thumb['media_id']]);
            $this->assertCurrent($delivery, $actor);
            $delivery->update(['status' => 'sending']);
            $mediaId = $this->client->addDraft($connection, $payload);
            $delivery->update(['media_id' => $mediaId, 'status' => 'unverified', 'sent_at' => now(), 'last_error' => null]);
            $this->verify($delivery);
        } catch (Throwable $error) {
            $delivery->refresh();
            if ($delivery->media_id) {
                $delivery->update(['status' => 'unverified', 'last_error' => '微信已返回草稿编号，读取核对未完成。请核对结果，不要再次发送。']);
            } else {
                $uncertain = $delivery->status === 'sending' && (! $error instanceof WeChatApiException || $error->uncertain);
                $delivery->update([
                    'status' => $uncertain ? 'unknown' : 'failed',
                    'last_error' => $error instanceof DomainException ? mb_substr($error->getMessage(), 0, 500)
                        : ($uncertain ? '发送结果待确认，请核对结果，勿重复发送。' : '草稿准备失败，未发起草稿创建，请重试。'),
                ]);
            }
        } finally {
            if ($delivery->fresh()?->media_id && $delivery->cover_path) {
                Storage::disk('local')->delete($delivery->cover_path);
                $delivery->update(['cover_path' => null]);
            }
        }
    }

    public function reconcile(ManualPublication $publication, User $actor): WeChatDraftDelivery
    {
        $this->publications->assertCan($actor, $publication, 'transition');
        $delivery = WeChatDraftDelivery::query()->where('publication_id', $publication->id)->firstOrFail();
        if ($delivery->status === 'sending' && $delivery->updated_at->lt(now()->subMinutes(6))) {
            WeChatDraftDelivery::query()->whereKey($delivery->id)->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(6))
                ->update(['status' => 'unknown', 'last_error' => '发送任务中断，正在核对微信结果。']);
            $delivery->refresh();
        }
        if (! in_array($delivery->status, ['unknown', 'unverified', 'saved'], true)) {
            throw new DomainException('任务仍在准备或发送中，请稍后刷新状态。');
        }

        return Cache::lock('wechat-draft-reconcile:'.$delivery->tenant_id.':'.$delivery->id, 90)->block(1, function () use ($delivery) {
            if ($delivery->media_id) {
                $this->verify($delivery);
            } else {
                $connection = $this->connection($delivery, false);
                $matches = [];
                // Bounded read only lookup. Absence never authorizes resending an uncertain write.
                for ($offset = 0; $offset < 100; $offset += 20) {
                    $page = $this->client->recentDrafts($connection, $offset);
                    foreach ($page['item'] ?? [] as $item) {
                        $articles = data_get($item, 'content.news_item', []);
                        if (count($articles) === 1 && $this->matches($delivery, $articles[0]) && is_string($item['media_id'] ?? null)) {
                            $matches[] = $item['media_id'];
                        }
                    }
                    if (count($page['item'] ?? []) < 20) {
                        break;
                    }
                }
                if (count($matches) === 1) {
                    $delivery->update(['media_id' => $matches[0], 'status' => 'unverified', 'sent_at' => now()]);
                    $this->verify($delivery);
                } else {
                    $delivery->update(['last_error' => '最近 100 篇草稿中未找到唯一匹配项，请在公众号草稿箱人工核对；为避免重复，仍不允许重发。']);
                }
            }

            return $delivery->refresh();
        });
    }

    private function verify(WeChatDraftDelivery $delivery): void
    {
        $remote = $this->client->getDraft($this->connection($delivery, false), $delivery->media_id);
        $items = $remote['news_item'] ?? [];
        if (count($items) !== 1 || ! $this->matches($delivery, $items[0])) {
            $delivery->update(['status' => 'unverified', 'last_error' => '已取得微信草稿编号，但内容核对不一致，请到公众号检查草稿。']);

            return;
        }
        DB::transaction(function () use ($delivery) {
            $locked = WeChatDraftDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $firstVerified = ! $locked->verified_at;
            $locked->update(['status' => 'saved', 'last_error' => null, 'verified_at' => now()]);
            if ($firstVerified) {
                $publication = ManualPublication::query()->findOrFail($delivery->publication_id);
                ManualPublicationTransition::query()->create([
                    'tenant_id' => $delivery->tenant_id, 'manual_publication_id' => $publication->id,
                    'changed_by_user_id' => $delivery->created_by, 'from_status' => $publication->status,
                    'to_status' => $publication->status,
                    'result_note' => '微信官方接口已保存并读回核对草稿；标题：'.$delivery->publication_title.'；文章 v'.$delivery->article_version.'；草稿编号：'.$delivery->media_id.'。尚未公开发布。',
                    'created_at' => now(),
                ]);
            }
        });
    }

    private function matches(WeChatDraftDelivery $delivery, array $article): bool
    {
        $payload = $delivery->payload;

        return ($article['title'] ?? null) === $payload['title']
            && ($article['thumb_media_id'] ?? null) === $delivery->thumb_media_id
            && $this->plain($article['content'] ?? '') === $this->plain($payload['content'])
            && $this->images($article['content'] ?? '') === $this->images($payload['content'])
            && $this->links($article['content'] ?? '') === $this->links($payload['content']);
    }

    private function plain(string $html): string
    {
        return preg_replace('/\s+/u', '', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
    }

    private function images(string $html): array
    {
        $dom = $this->document($html);
        $urls = [];
        foreach ($dom->getElementsByTagName('img') as $image) {
            $urls[] = $image->getAttribute('src');
        }

        return $urls;
    }

    private function links(string $html): array
    {
        $urls = [];
        foreach ($this->document($html)->getElementsByTagName('a') as $link) {
            $urls[] = $link->getAttribute('href');
        }

        return $urls;
    }

    private function assertCurrent(WeChatDraftDelivery $delivery, User $actor): void
    {
        $publication = ManualPublication::query()->findOrFail($delivery->publication_id);
        $current = $this->publications->assertPlatformDraftReady($publication, $delivery->publication_revision, $actor, true);
        if ($current->platform !== 'wechat' || $current->account_id !== $delivery->account_id
            || $current->content_fingerprint !== $delivery->content_fingerprint
            || (int) data_get($current->source_snapshot, 'article_version') !== $delivery->article_version || $current->draft_receipt) {
            throw new DomainException('发布单或账号已变更，未创建新草稿。请重新核对。');
        }
    }

    private function connection(WeChatDraftDelivery $delivery, bool $requireRevision = true): WeChatOfficialAccountConnection
    {
        $connection = WeChatOfficialAccountConnection::query()->where('account_id', $delivery->account_id)->findOrFail($delivery->connection_id);
        if (! $connection->verified_at || $connection->app_id !== $delivery->app_id
            || ($requireRevision && $connection->revision !== $delivery->connection_revision)) {
            throw new DomainException('公众号连接已变更，请核对连接。');
        }

        return $connection;
    }

    private function document(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><section>'.$html.'</section>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($old);

        return $dom;
    }

    private function uploadImages(WeChatOfficialAccountConnection $connection, string $html, float $deadline): string
    {
        $dom = $this->document($html);
        $uploaded = [];
        foreach ($dom->getElementsByTagName('img') as $image) {
            $url = $image->getAttribute('src');
            if (! isset($uploaded[$url])) {
                if (microtime(true) > $deadline - 45) {
                    throw new DomainException('图片准备超时，未创建草稿，请稍后重试。');
                }
                if (! preg_match('~^https?://~i', $url)) {
                    throw new DomainException('正文图片需要可访问的 HTTP/HTTPS 地址，请在文章中补充图片并重新审核。');
                }
                $response = $this->outbound->get($this->http->connectTimeout(5)->timeout(15)->withoutRedirecting(), $url, 1024 * 1024, 3);
                if (! $response->successful()) {
                    throw new DomainException('正文图片下载失败，未创建草稿，请检查原文章图片。');
                }
                $bytes = $response->body();
                $mime = $this->imageMime($bytes, 1024 * 1024 - 1);
                $result = $this->client->upload($connection, $bytes, $mime, false);
                $remoteUrl = $result['url'] ?? '';
                $host = strtolower((string) parse_url($remoteUrl, PHP_URL_HOST));
                if (! preg_match('~^https?://~i', $remoteUrl) || ! ($host === 'qpic.cn' || str_ends_with($host, '.qpic.cn'))) {
                    throw new DomainException('微信未返回有效正文图片地址，未创建草稿。');
                }
                $uploaded[$url] = $remoteUrl;
            }
            $image->setAttribute('src', $uploaded[$url]);
        }
        $root = $dom->getElementsByTagName('section')->item(0);

        return $dom->saveHTML($root) ?: $html;
    }

    private function imageMime(string $bytes, int $max): string
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || strlen($bytes) > $max || ! in_array($info['mime'] ?? '', ['image/png', 'image/jpeg'], true)) {
            throw new DomainException('图片需为有效 JPG/PNG；封面不超过 2MB，正文图片小于 1MB。');
        }

        return $info['mime'];
    }
}
