<?php

namespace App\Services\DTOs;

/**
 * 接力执行推进一步之后的进度。
 *
 * ranChunk 是给调用方判断「本进程有没有真的发过模型请求」用的：
 *   true  —— 刚跑完一段，本进程已经烧掉一两分钟，必须交棒给下一个任务；
 *   false —— 没段可跑了，本进程是干净的，正好用来跑收尾（渲染要起 Python，
 *            让它独占一个新进程，别和上一段的模型调用挤同一个 420 秒预算）。
 */
final readonly class RelayProgress
{
    public function __construct(
        public bool $ranChunk,
        public int $completedSteps,
        public int $totalSteps,
        public int $pendingCount,
        public bool $cancelled = false,
    ) {}
}
