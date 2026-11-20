<?php

namespace App\Services\AI;

use RuntimeException;

final class MagpieException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'target_unauthorized' => '当前工作区未获得此 Magpie 目标的部署授权。',
            'connection_changed' => 'Magpie 连接配置或部署授权已变更，请重新保存后再试。',
            'connection_disabled' => 'Magpie 连接已停用。',
            'credential_required' => '请填写 Magpie Gateway Key。',
            'invalid_credential' => '请填写有效的 Magpie Gateway Key。应用身份和脱敏预览无法作为 Gateway Key。',
            'unreachable' => '无法连接 Magpie，请检查服务是否已启动以及部署地址是否可达。',
            'invalid_identity' => '该服务未返回有效的 Magpie 标识及版本。',
            'authentication_failed' => 'Magpie 拒绝访问模型目录，请检查 Gateway Key 和网络共享设置。',
            'invalid_catalog' => 'Magpie 返回的模型目录格式无效。',
            'empty_catalog' => 'Magpie 目录中没有可用的对话模型。',
            'model_unavailable' => '选中的模型已从 Magpie 目录下架或当前不可用。',
            'model_required' => '请先选择一个 Magpie 模型。',
            'image_unsupported' => '选中的 Magpie 模型不支持图片输入。',
            'image_unconfirmed' => 'Magpie 目录未确认选中模型支持图片输入。',
            default => 'Magpie 连接验证失败。',
        });
    }
}
