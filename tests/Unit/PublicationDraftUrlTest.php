<?php

namespace Tests\Unit;

use App\Services\Publishing\PublicationPlatform;
use PHPUnit\Framework\TestCase;

class PublicationDraftUrlTest extends TestCase
{
    public function test_public_wechat_urls_and_existing_other_platforms_are_accepted(): void
    {
        foreach ([
            ['wechat', 'https://mp.weixin.qq.com/s/Public_Article-123'],
            ['wechat', 'https://mp.weixin.qq.com/s?__biz=Example&mid=123&idx=1&sn=abcd'],
            ['xiaohongshu', 'https://www.xiaohongshu.com/explore/1234567890abcdef12345678?xsec_token=share-token'],
            ['weibo', 'https://weibo.com/1234567890/AbCd1234'],
            ['zhihu', 'https://zhuanlan.zhihu.com/p/123456789'],
            ['custom', 'https://customer.example.test/posts/1'],
        ] as [$platform, $url]) {
            $this->assertTrue(PublicationPlatform::acceptsPublishedUrl($platform, $url), $url);
        }
    }

    public function test_editors_profile_pages_and_session_bearing_urls_are_not_public_evidence(): void
    {
        foreach ([
            ['wechat', 'https://mp.weixin.qq.com/'],
            ['wechat', 'https://mp.weixin.qq.com/cgi-bin/appmsg?action=edit&appmsgid=123'],
            ['wechat', 'https://mp.weixin.qq.com/cgi-bin/home?token=private'],
            ['wechat', 'https://mp.weixin.qq.com/s/Public?token=private'],
            ['wechat', 'https://mp.weixin.qq.com/s?__biz[]=Example&mid[]=123&idx=1&sn=abcd'],
            ['wechat', 'https://mp.weixin.qq.com/s?__biz=Example&mid=123&idx=1&sn=abcd&tempkey=preview'],
            ['xiaohongshu', 'https://creator.xiaohongshu.com/publish/publish'],
            ['xiaohongshu', 'https://www.xiaohongshu.com/user/profile/1234567890abcdef12345678'],
            ['weibo', 'https://weibo.com/u/1234567890'],
            ['weibo', 'https://weibo.com/1234567890'],
            ['weibo', 'https://weibo.com/compose'],
            ['weibo', 'https://card.weibo.com/article/v5/editor#/draft/123456'],
            ['zhihu', 'https://zhuanlan.zhihu.com/p/123456789?access_token=private'],
            ['custom', 'https://customer.example.test/post/1?password=private'],
        ] as [$platform, $url]) {
            $this->assertFalse(PublicationPlatform::acceptsPublishedUrl($platform, $url), $url);
        }
    }
}
