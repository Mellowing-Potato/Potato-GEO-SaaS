<?php

namespace App\Services\Publishing;

use App\Exceptions\ManualPublicationConflictException;
use App\Models\ManualPublication;
use App\Models\ManualPublicationTransition;
use App\Models\User;
use App\Services\Content\WeChatArticleExporter;
use DomainException;
use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

/** Prepared content is not evidence of a remote save or a public publication. */
final class PlatformPublicationDraftService
{
    public const PLATFORMS = ['wechat', 'xiaohongshu', 'weibo'];

    private const EDITORS = [
        'wechat' => 'https://mp.weixin.qq.com/',
        'xiaohongshu' => 'https://creator.xiaohongshu.com/publish/publish?from=menu&target=article',
        'weibo' => 'https://card.weibo.com/article/v5/editor#/draft',
    ];

    private const TITLE_LIMITS = [
        'wechat' => ['微信公众号', 64],
        'weibo' => ['微博头条文章', 32],
        'xiaohongshu' => ['小红书长文', 64],
    ];

    public function __construct(
        private readonly ManualPublicationService $publications,
        private readonly WeChatArticleExporter $exporter,
    ) {}

    public function prepare(ManualPublication $publication, int $revision, User $actor, ?string $publicationTitle = null): array
    {
        $current = $this->publications->assertPlatformDraftReady($publication, $revision, $actor);
        $sourceTitle = trim((string) data_get($current->source_snapshot, 'title'));
        $title = $this->publicationTitle($current, $publicationTitle ?? $sourceTitle);
        $markdown = (string) $current->content;
        // The title has its own field in the target editor. Remove only an exact duplicate H1.
        if (preg_match('/\A[ \t]*#[ \t]+([^\r\n]+)(?:\r?\n|$)/u', $markdown, $match)
            && trim($match[1]) === $sourceTitle) {
            $markdown = ltrim(substr($markdown, strlen($match[0])), "\r\n");
        }
        $html = $this->exporter->toHtml($markdown);
        $dom = $this->document($html);
        $images = $dom->getElementsByTagName('img')->length;
        $plain = $this->plainTextWithLinks($dom);
        if ($title === '' || ($plain === '' && $images === 0)) {
            throw new DomainException('文章标题或正文为空，请先核对文章。');
        }
        $expires = now()->addMinutes(15)->timestamp;
        $claims = $this->claims($current, $actor) + ['expires' => $expires, 'publication_title' => $title];

        return [
            'platform' => $current->platform,
            'platform_label' => $current->platform === 'wechat' ? '微信公众号' : PublicationPlatform::label($current->platform),
            'publication_id' => $current->id,
            'revision' => $current->revision,
            'article_version' => (int) data_get($current->source_snapshot, 'article_version'),
            'account_name' => $current->account->account_name,
            'title' => $title,
            'source_title' => $sourceTitle,
            'plain_text' => $plain,
            'html' => in_array($current->platform, ['wechat', 'weibo'], true) ? $html : null,
            'image_count' => $images,
            'editor_url' => self::EDITORS[$current->platform],
            'content_fingerprint' => $current->content_fingerprint,
            'preparation_token' => Crypt::encryptString(json_encode($claims, JSON_THROW_ON_ERROR)),
            'expires_at' => now()->setTimestamp($expires)->toIso8601String(),
            'automatic_remote_save' => false,
            'draft_storage_scope' => $current->platform === 'xiaohongshu' ? 'browser_local' : 'not_verified',
            'instructions' => [
                '核对目标平台当前登录账号与本发布单账号一致。',
                match ($current->platform) {
                    'wechat' => '复制标题和公众号排版正文，粘贴到公众号图文编辑器，检查图片、链接和排版后保存草稿。',
                    'xiaohongshu' => '复制标题和正文到小红书写长文编辑器，检查后暂存；长文草稿存储在当前浏览器，请在草稿位置注明浏览器。',
                    default => '复制标题和正文到平台编辑器，按平台要求补充图片或其他素材，再保存草稿。',
                },
                '保存后回到言及人工确认草稿；正式发布仍需在目标平台完成，再回填真实公开链接。',
            ],
        ];
    }

    public function confirm(ManualPublication $publication, array $data, User $actor): ManualPublication
    {
        $this->publications->assertCan($actor, $publication, 'transition');
        try {
            $claims = json_decode(Crypt::decryptString($data['preparation_token']), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new DomainException('发布内容准备凭据无效，请重新准备。');
        }
        if (! is_array($claims) || ! is_int($claims['expires'] ?? null) || $claims['expires'] <= now()->timestamp) {
            throw new DomainException('发布内容准备已过期，请重新准备后核对。');
        }
        // Never accept a login/session URL as a draft reference.
        $label = trim((string) $data['draft_label']);
        if ($label === '' || preg_match('~https?://|(?:access_token|token|cookie|password)\s*=~i', $label)) {
            throw new DomainException('请填写草稿标题或草稿箱位置，不要粘贴含登录凭据的后台网址。');
        }
        if (! ($data['account_checked'] ?? false) || ! ($data['content_checked'] ?? false) || ! ($data['saved'] ?? false)) {
            throw new DomainException('请先核对平台账号、正文并确认已在平台保存草稿。');
        }

        return DB::transaction(function () use ($publication, $data, $actor, $claims, $label) {
            $locked = ManualPublication::query()->lockForUpdate()->findOrFail($publication->id);
            $current = $this->publications->assertPlatformDraftReady($locked, (int) $data['revision'], $actor, true);
            $expected = $this->claims($current, $actor);
            foreach ($expected as $key => $value) {
                if (($claims[$key] ?? null) !== $value) {
                    throw new ManualPublicationConflictException;
                }
            }
            $title = $this->publicationTitle($current, (string) ($claims['publication_title'] ?? data_get($current->source_snapshot, 'title')));
            $current->forceFill([
                'draft_receipt' => [
                    'method' => 'manual_confirmation',
                    'platform' => $current->platform,
                    'draft_label' => $label,
                    'publication_title' => $title,
                    'storage_scope' => $current->platform === 'xiaohongshu' ? 'browser_local' : 'not_verified',
                    'account_id' => $current->account_id,
                    'account_name' => $current->account->account_name,
                    'account_profile_url' => $current->account->profile_url,
                    'persona_id' => $current->account->persona_id,
                    'content_fingerprint' => $current->content_fingerprint,
                    'article_version' => (int) data_get($current->source_snapshot, 'article_version'),
                    'confirmed_by' => $actor->id,
                    'confirmed_at' => now()->toIso8601String(),
                ],
                'revision' => $current->revision + 1,
            ])->save();
            ManualPublicationTransition::query()->create([
                'tenant_id' => $current->tenant_id,
                'manual_publication_id' => $current->id,
                'changed_by_user_id' => $actor->id,
                'from_status' => $current->status,
                'to_status' => $current->status,
                'result_note' => '人工确认草稿：'.$label.'；发布标题：'.$title.'；账号：'.$current->account->account_name
                    .'；文章 v'.data_get($current->source_snapshot, 'article_version')
                    .'；内容指纹：'.$current->content_fingerprint.'。未自动核验外站保存。',
                'created_at' => now(),
            ]);

            return $current->refresh();
        });
    }

    private function publicationTitle(ManualPublication $publication, string $title): string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 500 || preg_match('/[\x00-\x1f\x7f]/u', $title)) {
            throw new DomainException('请填写有效的单行发布标题，最多 500 字。');
        }
        // Limits observed in the live editors, including the user's WeChat screenshot.
        // Count UTF-16 units conservatively; never silently truncate a reviewed title.
        $limit = self::TITLE_LIMITS[$publication->platform] ?? null;
        if ($limit && intdiv(strlen(mb_convert_encoding($title, 'UTF-16LE', 'UTF-8')), 2) > $limit[1]) {
            throw new DomainException($limit[0].'标题最多 '.$limit[1].' 个字符，请在发布标题中缩短后重新准备；原文章不会被修改。');
        }

        return $title;
    }

    private function claims(ManualPublication $publication, User $actor): array
    {
        return [
            'publication' => (int) $publication->id,
            'tenant' => (int) $publication->tenant_id,
            'actor' => (int) $actor->id,
            'account' => (int) $publication->account_id,
            'identity' => hash('sha256', json_encode([
                $publication->account->account_name, $publication->account->profile_url, $publication->account->persona_id,
            ], JSON_THROW_ON_ERROR)),
            'revision' => (int) $publication->revision,
            'platform' => (string) $publication->platform,
            'fingerprint' => (string) $publication->content_fingerprint,
            'article_version' => (int) data_get($publication->source_snapshot, 'article_version'),
        ];
    }

    private function document(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    private function plainTextWithLinks(DOMDocument $dom): string
    {
        // Keep reference URLs when a target platform only accepts plain text.
        foreach (iterator_to_array($dom->getElementsByTagName('a')) as $anchor) {
            $href = $anchor->getAttribute('href');
            if (preg_match('~^https?://~i', $href) && trim($anchor->textContent) !== $href) {
                $anchor->appendChild($dom->createTextNode('（'.$href.'）'));
            }
        }
        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $image) {
            if ($image instanceof DOMElement) {
                $image->parentNode?->replaceChild($dom->createTextNode('【图片'.($image->getAttribute('alt') ? '：'.$image->getAttribute('alt') : '').'，请在平台补充】'), $image);
            }
        }

        return $this->exporter->toPlainText($dom->saveHTML() ?: '');
    }
}
