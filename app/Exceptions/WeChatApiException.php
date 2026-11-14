<?php

namespace App\Exceptions;

use DomainException;

/** Never includes raw HTTP messages, URLs, credentials or response bodies. */
class WeChatApiException extends DomainException
{
    public function __construct(public readonly ?int $apiCode = null, public readonly bool $uncertain = false, ?string $ip = null)
    {
        $message = match ($apiCode) {
            40013 => '公众号 AppID 无效，请核对连接配置。',
            40125, 40001, 40014, 42001 => '公众号密钥或调用凭据失效，请重新验证连接。',
            40164 => '请在微信开发者平台的 API IP 白名单添加言及服务器出口 IP'.($ip ? '：'.$ip : '，再验证连接').'。',
            48001, 48002 => '该公众号未授权所需接口，请在微信开发者平台核对草稿箱及素材权限。',
            45009, 45011 => '微信接口调用次数受限，请稍后再试。',
            89503, 89506, 89507 => '微信要求公众号管理员确认本次接口连接，请在微信侧处理后重新验证。',
            default => $uncertain ? '微信发送结果暂时无法确认；请核对结果，勿重复发送。' : '微信接口未成功'.($apiCode !== null ? '（错误码 '.$apiCode.'）' : '，请稍后再试').'。',
        };
        parent::__construct($message);
    }
}
