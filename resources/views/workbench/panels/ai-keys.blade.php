<section class="panel workspace-view" id="aikeysPanel" data-workspace-view="aikeys" hidden>
    <div class="panel-heading">
        <div><p class="eyebrow">设置</p><h2>模型配置</h2></div>
        <button class="button secondary small" id="reloadAiKeysButton" type="button">刷新</button>
    </div>
    <p class="muted">填写你自己的模型 Key，仅用于当前工作区。模型费用由你的供应商账户承担。</p>
    <p id="aikeysMessage" role="status"></p>
    <div id="aikeyList">
    @foreach (['text' => '文本模型（必填）', 'vision' => '视觉模型（可选）', 'embedding' => '向量模型（使用知识库时必填）', 'visibility' => '豆包监测'] as $slot => $title)
        @php($supportsMagpie = in_array($slot, ['text', 'vision'], true))
        @if ($supportsMagpie)
        <section class="model-source-section" data-magpie-slot="{{ $slot }}" aria-labelledby="model-slot-title-{{ $slot }}">
            <div class="model-config-heading">
                <h3 id="model-slot-title-{{ $slot }}">{{ $title }}</h3>
                <span class="muted" data-active-model-source aria-live="polite">正在读取当前来源…</span>
            </div>
            @if ($slot === 'vision')
                <p class="muted">未配置时，图片材料标记为“未识别”。</p>
            @endif
            <div class="model-sources">
        @endif
        <form class="model-config-form" data-model-slot="{{ $slot }}" autocomplete="off">
            <div class="model-config-heading">
                @if ($supportsMagpie)
                    <h4>第三方直连</h4>
                @else
                    <h3>{{ $title }}</h3>
                @endif
                <p data-model-status class="muted">未配置</p>
            </div>
            @if ($slot === 'embedding')
                <p>选定后，如需更换，需要清空知识库并重新导入。</p>
                <p class="muted">知识库只接受 1024 维向量。更换模型前，请在知识库中自行删除材料。</p>
            @elseif ($slot === 'visibility')
                <p>联网搜索费用按你的火山方舟账户计算。</p>
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
                @if ($supportsMagpie)
                    <button class="button secondary small" type="button" data-use-direct hidden>切回第三方直连</button>
                @endif
            </div>
            <p data-model-message role="status"></p>
        </form>
        @if ($supportsMagpie)
            <div class="model-magpie">
                <h4>Magpie</h4>
                <p class="muted" data-magpie-authorization>正在读取部署授权…</p>
                <form class="model-magpie-form" autocomplete="off" hidden>
                    <p class="muted">模型和上游账号在 Magpie 中管理。请先启动 Magpie，再保存连接并读取模型目录。</p>
                    <label for="magpie-target-{{ $slot }}">部署授权目标
                        <select id="magpie-target-{{ $slot }}" name="target_alias" required></select>
                    </label>
                    <p class="muted" data-magpie-endpoint></p>
                    <label for="magpie-key-{{ $slot }}" data-magpie-key>Gateway Key
                        <input id="magpie-key-{{ $slot }}" name="api_key" type="password" maxlength="4096" autocomplete="new-password" aria-describedby="magpie-key-status-{{ $slot }}">
                        <span class="muted" id="magpie-key-status-{{ $slot }}" data-magpie-key-status></span>
                    </label>
                    <label for="magpie-model-{{ $slot }}">模型
                        <select id="magpie-model-{{ $slot }}" name="model" disabled></select>
                    </label>
                    <p class="muted" data-magpie-model-status></p>
                    <p class="muted">读取目录会检测服务与鉴权，不发送生成请求。保存配置后可单独切换来源。</p>
                    <div class="model-config-actions">
                        <button class="button small" type="submit" data-magpie-action="save">保存 Magpie 配置</button>
                        <button class="button secondary small" type="button" data-magpie-action="directory" disabled>检测服务并读取目录</button>
                        <button class="button secondary small" type="button" data-magpie-action="activate" hidden>设为{{ $slot === 'text' ? '文本' : '视觉' }}模型</button>
                        <button class="button secondary small" type="button" data-magpie-action="toggle" hidden>停用 Magpie 连接</button>
                    </div>
                </form>
                <p class="form-message" data-magpie-message role="status"></p>
            </div>
            </div>
        </section>
        @endif
    @endforeach
    </div>
</section>
