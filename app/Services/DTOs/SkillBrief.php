<?php

namespace App\Services\DTOs;

/**
 * Skill 执行简报（用户输入）
 *
 * 用户在触发 Skill 时填写的简报信息，SkillAgentRunner 据此构建 Agent 上下文。
 */
final readonly class SkillBrief
{
    public function __construct(
        /** Skill ID，如 'yao-geo-intent-miner' */
        public string $skillId,

        /** 项目 ID */
        public int $projectId,

        /** 触发用户 ID */
        public int $userId,

        /** 简报字段（自由结构，不同 Skill 字段不同） */
        public array $fields = [],

        /** 用户补充要求，原文进入提示词，同时用于 RAG 检索 */
        public ?string $query = null,

        /** 附加上下文（如上一阶段的输出引用） */
        public array $contextFromPreviousStage = [],

        /** 已存在的执行记录 ID；同步控制器路径用于绑定渲染文件目录 */
        public ?int $executionId = null,

        /**
         * 用户从素材库显式挑选的素材引用（标题/关键词/作者）。
         * 同步路径在控制器内联记录使用；异步路径随 brief 序列化进队列，由 Job 在成功时记录。
         * 每条结构：{ type: 'title'|'keyword'|'author', id: int }
         */
        public array $materialRefs = [],

        /** 原异步入队请求键，仅供执行行防重和回执恢复，不进入模型输入。 */
        public ?string $clientRequestKey = null,
    ) {}

    /**
     * 获取简报字段值，支持默认值
     */
    public function field(string $key, mixed $default = null): mixed
    {
        return $this->fields[$key] ?? $default;
    }

    /**
     * 完整保存用户本次提交的可复现输入。
     *
     * query 会进入提示词和知识库检索，不能只留在队列载荷里；否则任务结束后只能看到
     * fields，无法审计用户写过的“补充说明”是否真正生效。
     *
     * @return array<string, mixed>
     */
    public function auditableInputData(): array
    {
        $input = $this->fields;
        $query = trim((string) ($this->query ?? ''));
        if ($query !== '') {
            $input['supplemental_query'] = $query;
        }
        if ($this->materialRefs !== []) {
            $input['material_refs'] = $this->materialRefs;
        }

        return $input;
    }

    /**
     * 序列化为数组（用于队列 Job 传参）
     */
    public function toArray(): array
    {
        return [
            'skillId' => $this->skillId,
            'projectId' => $this->projectId,
            'userId' => $this->userId,
            'fields' => $this->fields,
            'query' => $this->query,
            'contextFromPreviousStage' => $this->contextFromPreviousStage,
            'executionId' => $this->executionId,
            'materialRefs' => $this->materialRefs,
            'clientRequestKey' => $this->clientRequestKey,
        ];
    }

    /**
     * 从数组反序列化
     */
    public static function fromArray(array $data): self
    {
        return new self(
            skillId: $data['skillId'],
            projectId: (int) $data['projectId'],
            userId: (int) $data['userId'],
            fields: $data['fields'] ?? [],
            query: $data['query'] ?? null,
            contextFromPreviousStage: $data['contextFromPreviousStage'] ?? [],
            executionId: isset($data['executionId']) ? (int) $data['executionId'] : null,
            materialRefs: $data['materialRefs'] ?? [],
            clientRequestKey: $data['clientRequestKey'] ?? null,
        );
    }
}
