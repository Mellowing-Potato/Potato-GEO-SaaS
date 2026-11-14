<?php

namespace App\Services\SkillAgent;

/**
 * 一次页面抓取的结果 —— 页面审计能拿到的**全部事实**。
 *
 * 存在的理由：在这个类出现之前，页面审计根本不抓网页。客户填一个 URL，我们把这串字符
 * 原样塞进提示词，模型凭它对这个域名的印象写报告。2026-09-06 生产 execution 52 的后果是
 * 客户填了一个能打开的官网（实测 HTTP 200、有 canonical、有 JSON-LD），报告通篇结论
 * 却是「目标站点没有可用页面，官网事实源缺失」—— 站点是活的，报告说它不存在。
 *
 * 所以这个对象只装**观察到的**东西，不装推断。模型只许从这里下「观察到的」结论；
 * 这里没有的项，它必须写「未获取」。
 */
final readonly class PageObservation
{
    private function __construct(
        /** 客户填的原始地址 */
        public string $requestedUrl,
        /** 抓到了没有。false 时执行必须判失败，绝不允许退回让模型凭印象编 */
        public bool $ok,
        /** 跟完跳转之后的最终地址 */
        public ?string $finalUrl,
        public ?int $statusCode,
        /** 结构化观察清单，键含义见 PageFactExtractor */
        public array $facts,
        /** 失败分类，进日志用 */
        public ?string $failureCode,
        /** 失败原因的对客文案，要能让客户自己判断下一步怎么办 */
        public ?string $failureMessage,
    ) {}

    /** @param array<string, mixed> $facts */
    public static function success(string $requestedUrl, string $finalUrl, int $statusCode, array $facts): self
    {
        return new self($requestedUrl, true, $finalUrl, $statusCode, $facts, null, null);
    }

    public static function failure(
        string $requestedUrl,
        string $failureCode,
        string $failureMessage,
        ?int $statusCode = null,
    ): self {
        return new self($requestedUrl, false, null, $statusCode, [], $failureCode, $failureMessage);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'requested_url' => $this->requestedUrl,
            'ok' => $this->ok,
            'final_url' => $this->finalUrl,
            'status_code' => $this->statusCode,
            'facts' => $this->facts,
            'failure_code' => $this->failureCode,
            'failure_message' => $this->failureMessage,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            requestedUrl: (string) ($data['requested_url'] ?? ''),
            ok: (bool) ($data['ok'] ?? false),
            finalUrl: isset($data['final_url']) ? (string) $data['final_url'] : null,
            statusCode: isset($data['status_code']) ? (int) $data['status_code'] : null,
            facts: is_array($data['facts'] ?? null) ? $data['facts'] : [],
            failureCode: isset($data['failure_code']) ? (string) $data['failure_code'] : null,
            failureMessage: isset($data['failure_message']) ? (string) $data['failure_message'] : null,
        );
    }

    /**
     * 渲染成提示词里的「页面实测观察」段。
     *
     * ⚠️ 这段在提示词里必须排在「前置阶段输出」**之前**，并且带着「与前置冲突时以本段为准」
     *    的话。execution 52 栽的就是这里：页面审计拿到了客户填的 URL，但抓不了，于是沿用了
     *    上一步全景审计那句「官网缺失」—— 而那句话只说明**项目里没填官网地址**，
     *    不说明**客户没有官网**。
     */
    public function toPromptSection(): string
    {
        $lines = [];
        $lines[] = '## 页面实测观察（本次抓取，事实来源）';
        $lines[] = '';
        $lines[] = '以下是本次执行**真实抓取**目标页面得到的事实。凡是标注「观察到」的结论，'
            .'只能出自这份清单；清单里没有的项一律写「未获取」，不得推测、不得凭印象补写。';
        $lines[] = '**本段与「前置阶段输出」冲突时，以本段为准**，并在报告里说明冲突之处。';
        $lines[] = '';
        $lines[] = '- 请求地址：'.$this->requestedUrl;
        $lines[] = '- 最终地址：'.($this->finalUrl ?? '（未跟随跳转）');
        $lines[] = '- HTTP 状态码：'.($this->statusCode ?? '未获取');
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = json_encode($this->facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $lines[] = '```';

        return implode("\n", $lines);
    }
}
