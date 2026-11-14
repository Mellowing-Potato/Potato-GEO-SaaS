<section class="panel workspace-view" id="aikeysPanel" data-workspace-view="aikeys" hidden>
    <div class="panel-heading">
        <div><p class="eyebrow">设置</p><h2>模型配置</h2></div>
        <button class="button secondary small" id="reloadAiKeysButton" type="button">刷新</button>
    </div>
    <p class="muted">填写你自己的模型 Key，仅用于当前工作区。模型费用由你的供应商账户承担。</p>
    <p id="aikeysMessage" role="status"></p>
    <div id="aikeyList">
    @foreach (['text' => '文本模型（必填）', 'vision' => '视觉模型（可选）', 'embedding' => '向量模型（使用知识库时必填）', 'visibility' => '豆包监测'] as $slot => $title)
        <form class="model-config-form" data-model-slot="{{ $slot }}" autocomplete="off">
            <div class="model-config-heading">
                <h3>{{ $title }}</h3>
                <p data-model-status class="muted">未配置</p>
            </div>
            @if ($slot === 'embedding')
                <p>选定后，如需更换，需要清空知识库并重新导入。</p>
                <p class="muted">知识库只接受 1024 维向量。更换模型前，请在知识库中自行删除材料。</p>
            @elseif ($slot === 'visibility')
                <p>联网搜索费用按你的火山方舟账户计算。</p>
            @elseif ($slot === 'vision')
                <p class="muted">未配置时，图片材料标记为“未识别”。</p>
            @endif
            <label for="model-provider-{{ $slot }}">供应商
                <select id="model-provider-{{ $slot }}" name="provider" required>
                @foreach (config('ai-providers') as $provider => $preset)
                    @if (($slot !== 'visibility' || $provider === 'volces') && ($slot !== 'embedding' || $preset['embeddings']))
                        <option value="{{ $provider }}">{{ $preset['name'] }}</option>
                    @endif
                @endforeach
                </select>
            </label>
            <label for="model-url-{{ $slot }}">Base URL
                <input id="model-url-{{ $slot }}" name="base_url" type="url" required maxlength="2048" placeholder="服务商提供的 API 地址">
            </label>
            <label for="model-name-{{ $slot }}">{{ $slot === 'visibility' ? '方舟模型 ID' : '模型名' }}
                <input id="model-name-{{ $slot }}" name="model" required maxlength="100" placeholder="{{ $slot === 'visibility' ? '填写方舟控制台的模型 ID 或推理接入点 ID' : '服务商提供的模型名称' }}" aria-describedby="model-name-help-{{ $slot }}">
                <span id="model-name-help-{{ $slot }}" class="muted">这里填写模型的名称或 ID；API Key 请填在下方的专用输入框。</span>
            </label>
            <label for="model-key-{{ $slot }}">API Key
                <input id="model-key-{{ $slot }}" name="api_key" type="password" maxlength="500" autocomplete="new-password" placeholder="在这里填写 API Key；已配置时留空保留">
            </label>
            <label for="model-label-{{ $slot }}">备注（可选）
                <input id="model-label-{{ $slot }}" name="label" maxlength="100">
            </label>
            <label data-custom-json hidden><input type="checkbox" name="json_mode">服务支持 JSON 模式</label>
            <p class="muted">测试连接会消耗少量 tokens。</p>
            <div class="model-config-actions">
                <button class="button secondary small" type="button" data-test-model>测试连接</button>
                <button class="button small" type="submit">保存配置</button>
                <button class="button secondary small" type="button" data-aikey-action="toggle" hidden>停用</button>
                <button class="button danger small" type="button" data-aikey-action="delete" hidden>删除 Key</button>
            </div>
            <p data-model-message role="status"></p>
        </form>
    @endforeach
    </div>
</section>
