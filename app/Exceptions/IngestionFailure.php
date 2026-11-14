<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 入库失败，**且这句话是写给客户看的**。
 *
 * 为什么需要一个专门的异常类型：文档入库的失败原因会一路走到客户的浏览器里
 * （markFailed → knowledge_documents.error_message → 文档列表接口 → 页面上的「失败原因：…」）。
 * 但异常原文里什么都有可能：网关的内部域名和端口、容器内路径、数据库报错、栈里的类名。
 * 2026-08-11 关掉 display_errors 堵的就是这类回显，不能从这个口子放回来。
 *
 * 规矩很简单：
 *  - 抛 IngestionFailure = 我确认这句话对客户有用且不含内部信息（体积超限、余额不足、网址打不开…）。
 *  - 抛其它任何异常 = 客户只看到一句通用提示，原文只进日志。
 *
 * 判定统一走 messageFor()，别在各处自己写 instanceof。
 */
class IngestionFailure extends RuntimeException
{
    public const GENERIC = '文档解析失败，请稍后重试；如反复出现请联系我们。';

    /**
     * 取一句能给客户看的失败原因：认识的原样带出，不认识的换成通用提示。
     */
    public static function messageFor(?Throwable $e, string $fallback = self::GENERIC): string
    {
        return $e instanceof self ? $e->getMessage() : $fallback;
    }
}
