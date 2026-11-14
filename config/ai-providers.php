<?php

// Official preset documentation checked on 2026-11-13. Keys are workspace-owned.
return [
    'deepseek' => [
        // https://api-docs.deepseek.com/guides/json_mode/
        // https://api-docs.deepseek.com/api/list-models/ (text output, no embedding API)
        'name' => 'DeepSeek', 'base_url' => 'https://api.deepseek.com',
        'json_mode' => true, 'embeddings' => false, 'embedding_models' => [],
    ],
    'dashscope' => [
        // https://help.aliyun.com/zh/model-studio/qwen-structured-output
        // https://help.aliyun.com/en/model-studio/embedding-interfaces-compatible-with-openai
        // Shared Beijing URL still works; workspace-specific URLs can also be entered.
        // v3/v4 support dimensions=1024.
        'name' => '阿里云百炼', 'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
        'json_mode' => true, 'embeddings' => true,
        'embedding_models' => ['text-embedding-v3', 'text-embedding-v4'],
    ],
    'volces' => [
        // https://docs.volcengine.com/docs/ark/structured-output-beta?lang=en
        // https://docs.volcengine.com/docs/ark/multimodal-vectorization-api?lang=zh
        // Different embedding payload; test the chosen model/endpoint ID at 1024 dimensions.
        'name' => '火山方舟（豆包）', 'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        'json_mode' => true, 'embeddings' => true, 'embedding_models' => [],
        'embedding_protocol' => 'ark_multimodal',
    ],
    'moonshot' => [
        // https://platform.kimi.com/docs/guide/use-json-mode-feature-of-kimi-api.md
        // https://platform.kimi.com/docs/llms.txt (no embedding API in official catalog)
        'name' => 'Moonshot（Kimi）', 'base_url' => 'https://api.moonshot.cn/v1',
        'json_mode' => true, 'embeddings' => false, 'embedding_models' => [],
    ],
    'bigmodel' => [
        // https://docs.bigmodel.cn/cn/guide/capabilities/struct-output
        // https://docs.bigmodel.cn/cn/guide/models/embedding/embedding-3
        'name' => '智谱', 'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
        'json_mode' => true, 'embeddings' => true, 'embedding_models' => ['embedding-3'],
    ],
    'openai' => [
        // https://developers.openai.com/api/docs/guides/structured-outputs
        // https://developers.openai.com/api/reference/resources/embeddings/methods/create
        'name' => 'OpenAI', 'base_url' => 'https://api.openai.com/v1',
        'json_mode' => true, 'embeddings' => true,
        'embedding_models' => ['text-embedding-3-small', 'text-embedding-3-large'],
    ],
    'custom' => [
        // Compatibility is tested against the supplied endpoint, not assumed.
        'name' => '自定义 OpenAI 兼容地址', 'base_url' => '',
        'json_mode' => false, 'embeddings' => true, 'embedding_models' => [],
    ],
];
