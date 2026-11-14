<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;

/**
 * 把日志脱敏处理器挂到通道上的"挂钩"（计划 §9.7）。
 *
 * Laravel 的 `LogManager::get()` 在解析完通道之后会把 tap 类 `__invoke($logger)` 一次 ——
 * **它自己就是这么挂 `ContextLogProcessor` 的**（见 `LogManager::get()` 里那句
 * `$loggerWithContext->pushProcessor(...)`）。这里照同一个约定办，不另造机制。
 *
 * 为什么是 tap 而不是别的：
 *   - `single` / `daily` 这类文件通道的配置里没有 `processors` 项（那只有 `monolog` 驱动认），
 *     所以只能走 tap；
 *   - 配在子通道上，`stack` 会把子通道的处理器一起收集（`createStackDriver` 会 flatMap
 *     `getProcessors()`），所以 `LOG_CHANNEL=stack` 默认部署也自动生效，不用改 .env。
 */
final class RedactSensitiveContextTap
{
    public function __invoke(Logger $logger): void
    {
        // 判一下再挂：拿不到底层 Monolog 时不该抛异常把日志通道整个搞挂。
        if (method_exists($logger->getLogger(), 'pushProcessor')) {
            $logger->pushProcessor(new RedactSensitiveContext);
        }
    }
}
