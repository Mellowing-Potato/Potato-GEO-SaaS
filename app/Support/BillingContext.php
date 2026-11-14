<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * 计费主体上下文 —— 「这次模型调用花的是谁的钱」。
 *
 * 为什么需要它：花钱的出口只有三个（AiProviderServiceImpl 的 generate / embed /
 * embedBatch），但它们的签名里没有用户，而且深层调用方（TitleAiGenerationService::
 * requestTitlesFromModel、VisionPageExtractor::transcribe）作用域里根本没有用户。
 * 把 user 一路透传下去要改十几个签名，代价太大；所以照 TenantContext 的老办法，
 * 用请求内的环境上下文。
 *
 * HTTP 请求下不需要显式设置：兜底读 Auth::id()，控制器里的调用天然对得上人。
 * 队列任务里 Auth 是空的，必须由任务自己 set()——那正是「谁付钱」唯一还知道的地方
 * （SkillExecution::user_id、KnowledgeDocument::uploaded_by 等）。
 *
 * ⚠️ 这是进程内静态状态，队列 worker 一个进程连跑多个任务。所以设置一律用
 * scoped()，别用裸 set()——否则上一个任务的用户会漏给下一个任务，等于扣错人的钱。
 */
class BillingContext
{
    protected static ?int $userId = null;

    protected static string $label = '';

    /**
     * 在回调内绑定计费主体，结束后一定还原（含抛异常）。
     *
     * 用法：
     *   BillingContext::scoped($execution->user_id, 'skill:'.$execution->skill_id, function () {
     *       return $this->runTheThingThatCallsAi();
     *   });
     */
    public static function scoped(?int $userId, string $label, callable $callback): mixed
    {
        $previousUserId = static::$userId;
        $previousLabel = static::$label;

        static::$userId = $userId;
        static::$label = $label;

        try {
            return $callback();
        } finally {
            static::$userId = $previousUserId;
            static::$label = $previousLabel;
        }
    }

    /**
     * 当前该扣谁的钱。显式设置优先；没设置就退回登录用户（HTTP 请求场景）。
     * 两者都没有＝这次调用没人认领，余额闸按 config 决定拦还是只告警。
     */
    public static function userId(): ?int
    {
        if (static::$userId !== null) {
            return static::$userId;
        }

        $authId = Auth::id();

        return is_numeric($authId) ? (int) $authId : null;
    }

    /** 日志标识，例如 'skill:geo-keyword-research'。只给日志用，不参与判定。 */
    public static function label(): string
    {
        return static::$label;
    }

    public static function forget(): void
    {
        static::$userId = null;
        static::$label = '';
    }
}
