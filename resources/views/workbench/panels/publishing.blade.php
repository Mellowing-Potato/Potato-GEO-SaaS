<section class="panel publishing-panel workspace-view" id="publishingPanel" data-workspace-view="publishing" hidden>
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">数据与发布</p>
                            <h2>发布管理</h2>
                        </div>
                        <div class="panel-actions">
                            <a class="button secondary small" id="manualAccountSettingsLink" href="/manual-publish#sec-settings">添加平台账号</a>
                            <button class="button primary small" id="addChannelButton" type="button" aria-expanded="false" aria-controls="channelForm">添加网站接口</button>
                            <button class="button secondary small" id="reloadChannelsButton" type="button">刷新</button>
                        </div>
                    </div>
                    <p class="muted">没有官网也可以使用自己的知乎、微信等平台账号。绑定账号后，从已审核文章创建发布单，在平台发布后回填公开链接。网站接口适用于已有接收端的站点。</p>
                    <p class="muted" id="publishingMessage" role="status">管理发布渠道、查看分发状态和日志。</p>
                    <div class="channel-form" id="channelForm" hidden>
                        <h3>添加发布渠道</h3>
                        <form class="form-grid" id="createChannelForm">
                            <label for="channel-name">
                                渠道名称
                                <input name="name" required maxlength="120" placeholder="例如：公司WordPress" id="channel-name">
                            </label>
                            <label for="channelTypeSelect">
                                渠道类型
                                <select name="type" id="channelTypeSelect">
                                    <option value="wordpress_rest">WordPress REST</option>
                                    <option value="http_webhook">HTTP Webhook</option>
                                    <option value="geoflow_agent">GEOFlow Agent</option>
                                </select>
                            </label>
                            <label class="span-2" data-external-channel-field for="channel-endpoint_url">
                                端点 URL
                                <input name="endpoint_url" type="url" required maxlength="2000" placeholder="https://example.com/wp-json/wp/v2" id="channel-endpoint_url">
                            </label>
                            <label data-external-channel-field for="channel-auth_type">
                                认证方式
                                <select name="auth_type" id="channel-auth_type">
                                    <option value="none">无</option>
                                    <option value="bearer">Bearer Token</option>
                                    <option value="basic">Basic Auth</option>
                                    <option value="wordpress_application_password">WordPress 应用密码</option>
                                    <option value="hmac">HMAC 签名</option>
                                </select>
                            </label>
                            <label data-external-channel-field for="channel-username">
                                用户名
                                <input name="username" maxlength="255" placeholder="可选" id="channel-username">
                            </label>
                            <label class="span-2" data-external-channel-field for="channel-secret">
                                密钥 / Token
                                <input name="secret" type="password" maxlength="2000" placeholder="可选" id="channel-secret">
                            </label>
                            <label data-external-channel-field for="channel-domain">
                                域名（可选）
                                <input name="domain" maxlength="255" placeholder="example.com" id="channel-domain">
                            </label>
                            <label data-external-channel-field for="channel-front_mode">
                                前端模式
                                <select name="front_mode" id="channel-front_mode">
                                    <option value="proxy">代理</option>
                                    <option value="static">静态</option>
                                    <option value="hybrid">混合</option>
                                </select>
                            </label>
                            <div class="form-actions span-2">
                                <button class="button primary" type="submit">保存渠道</button>
                                <button class="button secondary" type="button" id="cancelChannelButton">取消</button>
                                <p class="form-message" id="channelCreateMessage"></p>
                            </div>
                        </form>
                    </div>
                    <div class="publishing-flow">
                        <section aria-labelledby="publishingChannelsTitle">
                            <h3 id="publishingChannelsTitle">网站接口</h3>
                            <p class="muted">只需在使用网站接口分发时配置。没有官网的用户可直接在下方绑定平台账号。</p>
                            <div class="channel-list" id="channelList"></div>
                        </section>
                        <section aria-labelledby="publishingPendingTitle">
                            <h3 id="publishingPendingTitle">待发布文章</h3>
                            <p class="muted">已审核的文章在文章库「待发布」筛选中；发布动作和状态仍在文章库处理。</p>
                            <a class="button secondary small" href="#content-library">查看文章库</a>
                        </section>
                    </div>
                    <div class="channel-binding" id="projectChannelBinding" hidden>
                        <div class="panel-heading compact-heading">
                            <div>
                                <h3>项目渠道绑定</h3>
                            </div>
                            <button class="button secondary small" id="reloadProjectChannelsButton" type="button">刷新</button>
                        </div>
                        <p class="muted" id="projectChannelMessage">选择本项目要使用的网站接口或平台账号。未绑定的目标不会自动接收文章。</p>
                        <div class="channel-binding-list" id="projectChannelList"></div>
                        <div class="form-actions">
                            <button class="button primary small" id="saveProjectChannelsButton" type="button">保存绑定</button>
                            <p class="form-message" id="projectChannelSaveMessage"></p>
                        </div>
                    </div>
                    <div class="channel-detail" id="channelDetail" hidden>
                        <div class="panel-heading compact-heading">
                            <div>
                                <h3 id="channelDetailTitle">渠道详情</h3>
                            </div>
                            <div class="panel-actions">
                                <button class="button secondary small" id="channelHealthButton" type="button">健康检查</button>
                                <button class="button secondary small" id="channelLogsButton" type="button">分发日志</button>
                                <button class="button secondary small" id="channelRewriteButton" type="button">伪静态规则</button>
                                {{-- ⛔ 凭据入口（密钥管理 / Agent 包 / 信标插件）**不在租户侧出现**。
                                     2026-09-22 用户口径：租户只做积分展示，没有 key 与令牌的创建/删除；
                                     渠道密钥的生成、轮换、撤销属于运营动作（避免敏感操作落在客户界面上）。
                                     后端三个端点原样保留，供运营台/API 以运营身份调用 ——
                                     本节只留不需要凭据的能力（健康检查/日志/伪静态/分类标签同步）。 --}}
                                <button class="button secondary small" id="channelTaxonomyButton" type="button" hidden>同步分类标签</button>
                                <button class="button danger small" id="channelDeleteButton" type="button">删除</button>
                                <button class="button secondary small" id="channelBackButton" type="button">返回列表</button>
                            </div>
                        </div>
                        <p class="muted" id="channelDetailMessage"></p>
                        <div class="channel-health-result" id="channelHealthResult" hidden></div>
                        <div class="channel-logs-result" id="channelLogsResult" hidden></div>
                        <div class="channel-rewrite-result" id="channelRewriteResult" hidden></div>
                        <div class="channel-taxonomy-result" id="channelTaxonomyResult" hidden></div>
                    </div>
                </section>
