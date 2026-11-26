<section class="panel workspace-view" id="aikeysPanel" data-workspace-view="aikeys" hidden>
    <div class="panel-heading">
        <div>
            <p class="eyebrow">设置</p>
            <h2>模型配置</h2>
        </div>
        <button class="button secondary small" id="reloadAiKeysButton" type="button">刷新</button>
    </div>
    <p class="muted aikeys-lead">填写你自己的模型 Key 或连接本机 Magpie，仅用于当前工作区。模型费用由你的供应商账户承担；只需配好文本模型即可建立项目并运行核心诊断。</p>
    <p id="aikeysMessage" role="status"></p>
    <div class="aikeys-next-banner" id="aikeysNextBanner" hidden>
        <div class="aikeys-next-copy">
            <strong id="aikeysNextTitle">文本模型已就绪</strong>
            <p class="muted" id="aikeysNextDesc">现在可以建立第一个项目，开始你的首次 AI 搜索诊断。</p>
        </div>
        <button class="button primary small" id="aikeysNextAction" type="button" data-open-workspace-view="projects">下一步：建立第一个项目 →</button>
    </div>
    <div id="aikeyList" class="aikeys-list">
    @php
        $desktopMagpie = \App\Support\PortableDesktopAccess::enabled();
        $slots = [
            'text' => [
                'title' => '文本模型（必填）',
                'required' => true,
                'supportsMagpie' => true,
                'summary' => '驱动意图挖掘、综合诊断与文章生成；完成此项即可开始使用工作台。',
            ],
            'embedding' => [
                'title' => '向量模型（使用知识库时必填）',
                'required' => false,
                'supportsMagpie' => false,
                'summary' => '导入知识库文档与检索切片时使用，仅接受 1024 维向量。',
            ],
            'vision' => [
                'title' => '视觉模型（可选）',
                'required' => false,
                'supportsMagpie' => true,
                'summary' => '识别图片素材内容；未配置时图片材料标记为“未识别”。',
            ],
            'visibility' => [
                'title' => '豆包监测',
                'required' => false,
                'supportsMagpie' => false,
                'summary' => '运行豆包联网搜索可见性监测时使用，费用按火山方舟账户计算。',
            ],
        ];
        $optionalStarted = false;
    @endphp
    @foreach ($slots as $slot => $meta)
        @php
            $supportsMagpie = $meta['supportsMagpie'];
            $isRequired = $meta['required'];
        @endphp
        @if (!$isRequired && !$optionalStarted)
            @php($optionalStarted = true)
            <div class="model-optional-header">
                <h3>可选能力（按需配置）</h3>
                <p class="muted">初次上手可以先跳过，等用到知识库、图片识别或豆包监测时再展开填写。</p>
            </div>
            <div class="model-optional-group">
        @endif
        @if (!$isRequired)
        <details class="model-optional-card" data-optional-slot="{{ $slot }}">
            <summary class="model-optional-summary">
                <span class="model-optional-meta">
                    <span class="model-optional-title">{{ $meta['title'] }}</span>
                    <span class="model-optional-desc">{{ $meta['summary'] }}</span>
                </span>
                <span class="model-optional-right">
                    <span class="md-badge md-badge--neutral" data-optional-status="{{ $slot }}">未配置</span>
                    <span class="model-optional-chevron" aria-hidden="true">展开</span>
                </span>
            </summary>
            <div class="model-optional-body">
        @endif
        @if ($supportsMagpie)
        <section class="model-source-section {{ $isRequired ? 'model-slot--required' : 'model-slot--optional' }}" data-magpie-slot="{{ $slot }}" aria-labelledby="model-slot-title-{{ $slot }}">
            <div class="model-config-heading">
                <div class="model-slot-title-row">
                    @if ($isRequired)
                        <span class="md-badge md-badge--primary">必填</span>
                    @endif
                    <h3 id="model-slot-title-{{ $slot }}">{{ $meta['title'] }}</h3>
                </div>
                <span class="muted model-active-source" data-active-model-source aria-live="polite">正在读取当前来源…</span>
            </div>
            <p class="muted model-slot-desc">{{ $meta['summary'] }}</p>
            <div class="model-source-switcher">
                <div class="md-segmented" role="group" aria-label="{{ $meta['title'] }}连接方式" data-source-tabs="{{ $slot }}">
                    <button type="button" class="md-seg" data-source-tab="direct" aria-pressed="true">第三方 API 直连</button>
                    <button type="button" class="md-seg" data-source-tab="magpie" aria-pressed="false">{{ $desktopMagpie ? '本机 Magpie' : 'Magpie 网关' }}</button>
                </div>
            </div>
            <div class="model-sources" data-active-pane="direct">
        @endif
        <form class="model-config-form" data-model-slot="{{ $slot }}" autocomplete="off">
            <div class="model-config-heading">
                @if ($supportsMagpie)
                    <h4>第三方直连</h4>
                @else
                    <h3>{{ $meta['title'] }}</h3>
                @endif
                <p data-model-status class="muted">未配置</p>
            </div>
            @if ($slot === 'embedding')
                <p>选定后，如需更换，需要清空知识库并重新导入。</p>
                <p class="muted">知识库只接受 1024 维向量。更换模型前，请在知识库中自行删除材料。</p>
            @elseif ($slot === 'visibility')
                <p class="muted">联网搜索费用按你的火山方舟账户计算。</p>
            @endif
            <div class="model-field-grid">
                <label for="model-provider-{{ $slot }}">供应商
                    <select id="model-provider-{{ $slot }}" name="provider" required>
                    @foreach (config('ai-providers') as $provider => $preset)
                        @if (($slot !== 'visibility' || $provider === 'volces') && ($slot !== 'embedding' || $preset['embeddings']))
                            <option value="{{ $provider }}">{{ $preset['name'] }}</option>
                        @endif
                    @endforeach
                    </select>
                </label>
                <label for="model-name-{{ $slot }}">{{ $slot === 'visibility' ? '方舟模型 ID' : '模型名' }}
                    <input id="model-name-{{ $slot }}" name="model" required maxlength="100" placeholder="{{ $slot === 'visibility' ? '填写方舟控制台的模型 ID 或推理接入点 ID' : '服务商提供的模型名称' }}" aria-describedby="model-name-help-{{ $slot }}">
                    <span id="model-name-help-{{ $slot }}" class="muted model-field-help">这里填写模型的名称或 ID；API Key 请填在专用输入框。</span>
                </label>
                <label class="model-field--wide" for="model-url-{{ $slot }}">Base URL
                    <input id="model-url-{{ $slot }}" name="base_url" type="url" required maxlength="2048" placeholder="服务商提供的 API 地址">
                </label>
                <label for="model-key-{{ $slot }}">API Key
                    <input id="model-key-{{ $slot }}" name="api_key" type="password" maxlength="500" autocomplete="new-password" placeholder="在这里填写 API Key；已配置时留空保留">
                </label>
                <label for="model-label-{{ $slot }}">备注（可选）
                    <input id="model-label-{{ $slot }}" name="label" maxlength="100" placeholder="例如：主力 DeepSeek">
                </label>
            </div>
            <label data-custom-json hidden><input type="checkbox" name="json_mode">服务支持 JSON 模式</label>
            <div class="model-config-foot">
                <div class="model-config-actions">
                    <button class="button primary small" type="submit">保存配置</button>
                    <button class="button secondary small" type="button" data-test-model>测试连接</button>
                    <button class="button secondary small" type="button" data-aikey-action="toggle" hidden>停用</button>
                    <button class="button danger small" type="button" data-aikey-action="delete" hidden>删除 Key</button>
                    @if ($supportsMagpie)
                        <button class="button secondary small" type="button" data-use-direct hidden>切回第三方直连</button>
                    @endif
                </div>
                <p class="muted model-cost-note">测试连接会消耗少量 tokens。</p>
            </div>
            <p data-model-message role="status"></p>
        </form>
        @if ($supportsMagpie)
            <div class="model-magpie">
                <h4>Magpie</h4>
                <p class="muted">可选的本机模型入口。<a href="https://github.com/yetone/magpie" target="_blank" rel="noopener noreferrer">Magpie 项目与安装说明</a>；第三方直连配置会继续保留。</p>
                <p class="muted" data-magpie-authorization>{{ $desktopMagpie ? '正在读取本机连接状态…' : '正在读取部署授权…' }}</p>
                @if($desktopMagpie)
                    <p class="muted">先启动这台电脑上的 Magpie，再点击连接。此操作只检测并允许本机连接，不会发送生成请求或切换当前模型来源。</p>
                    <div class="model-config-actions">
                        <button class="button secondary small" type="button" data-magpie-local-connect disabled>连接本机 Magpie</button>
                    </div>
                @endif
                <form class="model-magpie-form" autocomplete="off" hidden>
                    <p class="muted">{{ $desktopMagpie ? '模型和上游账号在 Magpie 中管理。连接后请从目录选择模型，保存后再设为当前用途。' : '模型和上游账号在 Magpie 中管理。请先启动 Magpie，再保存连接并读取模型目录。' }}</p>
                    <div class="model-field-grid">
                        <label for="magpie-target-{{ $slot }}">{{ $desktopMagpie ? '连接目标' : '部署授权目标' }}
                            <select id="magpie-target-{{ $slot }}" name="target_alias" required></select>
                        </label>
                        <label for="magpie-model-{{ $slot }}">模型
                            <select id="magpie-model-{{ $slot }}" name="model" disabled></select>
                        </label>
                    </div>
                    <p class="muted" data-magpie-endpoint></p>
                    <label for="magpie-key-{{ $slot }}" data-magpie-key>Gateway Key
                        <input id="magpie-key-{{ $slot }}" name="api_key" type="password" maxlength="4096" autocomplete="new-password" aria-describedby="magpie-key-status-{{ $slot }}">
                        <span class="muted model-field-help" id="magpie-key-status-{{ $slot }}" data-magpie-key-status></span>
                    </label>
                    <p class="muted" data-magpie-model-status></p>
                    <p class="muted">读取目录会检测服务与鉴权，不发送生成请求。保存配置后可单独切换来源。</p>
                    <div class="model-config-actions">
                        <button class="button primary small" type="submit" data-magpie-action="save">保存 Magpie 配置</button>
                        <button class="button secondary small" type="button" data-magpie-action="directory" disabled>检测服务并读取目录</button>
                        <button class="button primary small" type="button" data-magpie-action="activate" hidden>设为{{ $slot === 'text' ? '文本' : '视觉' }}模型</button>
                        <button class="button secondary small" type="button" data-magpie-action="toggle" hidden>停用 Magpie 连接</button>
                    </div>
                </form>
                <p class="form-message" data-magpie-message role="status"></p>
            </div>
            </div>
        </section>
        @endif
        @if (!$isRequired)
            </div>
        </details>
        @endif
    @endforeach
    @if ($optionalStarted)
        </div>
    @endif
    </div>
</section>
