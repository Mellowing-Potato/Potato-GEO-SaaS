<section class="panel leads-panel workspace-view" id="leadsPanel" data-workspace-view="leads" hidden>
                    <div class="view-title">
                        <h1>线索表单</h1>
                        <span class="vt-sub">建表单收线索、跟进转化。表单页对访客公开，无需登录；线索只有本租户看得见。</span>
                        <span class="spacer"></span>
                    </div>

                    <div class="panel-head lead-panel-head">
                        <div class="mat-seg-tabs md-tabs" role="tablist" aria-label="线索表单页签" id="leadTabs">
                            <button class="md-tab" role="tab" aria-selected="true" data-lead-tab="submissions">线索</button>
                            <button class="md-tab" role="tab" aria-selected="false" data-lead-tab="forms">表单</button>
                            <button class="md-tab" role="tab" aria-selected="false" data-lead-tab="analytics">转化统计</button>
                        </div>
                        <span class="spacer"></span>
                        <button class="button secondary small" id="leadExportButton" type="button">导出 CSV</button>
                        <button class="button primary small" id="leadAddFormButton" type="button" hidden>＋ 新建表单</button>
                    </div>
                    <div class="callout" id="leadMessage"></div>

                    {{-- ── 页签一：线索（提交记录）────────────────────────────── --}}
                    <div data-lead-pane="submissions">
                        <div class="lead-stat-row" id="leadStatRow"></div>
                        <form class="lead-filter-bar" id="leadFilterForm" novalidate>
                            <select name="status" aria-label="状态筛选">
                                <option value="">全部状态</option>
                                <option value="new">新线索</option>
                                <option value="contacted">已联系</option>
                                <option value="qualified">已确认意向</option>
                                <option value="converted">已成交</option>
                                <option value="invalid">无效</option>
                            </select>
                            <select name="form_id" id="leadFormFilter" aria-label="表单筛选">
                                <option value="">全部表单</option>
                            </select>
                            <input type="date" name="date_from" aria-label="起始日期">
                            <input type="date" name="date_to" aria-label="截止日期">
                            <input type="search" name="search" placeholder="搜内容 / 来源 / 备注" aria-label="搜索">
                            <button class="button secondary small" type="submit">筛选</button>
                            <button class="button ghost small" type="button" id="leadFilterReset">重置</button>
                        </form>

                        <div data-state-host id="leadHost">
                            <div data-state="populated">
                                <div class="cl-tbl-scroll">
                                    <table class="cl-tbl">
                                        <thead><tr><th>提交时间</th><th>表单</th><th>内容</th><th>来源页面</th><th>状态</th><th>通知</th><th>处理人</th><th class="cl-cell-actions">操作</th></tr></thead>
                                        <tbody id="leadRows"></tbody>
                                    </table>
                                </div>
                                <div class="lead-pager" id="leadPager" hidden></div>
                            </div>
                            <div data-state="loading" hidden>
                                <div class="cl-skeleton" role="status" aria-label="加载中"><div class="cl-sk w80"></div><div class="cl-sk w60"></div><div class="cl-sk w80"></div><div class="cl-sk w40"></div></div>
                            </div>
                            <div data-state="empty" hidden>
                                <div class="cl-state-tpl cl-state-empty">
                                    <div class="st-icon">✎</div>
                                    <h3>还没有线索</h3>
                                    <p>先到「表单」页签建一个表单，把公开链接放到你的落地页或推广物料上，访客提交后线索会出现在这里。</p>
                                </div>
                            </div>
                            <div data-state="empty-filtered" hidden>
                                <div class="cl-state-tpl cl-state-empty">
                                    <h3>当前筛选没有线索</h3>
                                    <p>调整状态、表单或日期范围，或清除筛选查看全部提交记录。</p>
                                    <button class="button secondary small" id="leadEmptyReset" type="button">清除筛选</button>
                                </div>
                            </div>
                            <div data-state="error" hidden>
                                <div class="cl-state-tpl cl-state-error">
                                    <div class="st-icon">!</div>
                                    <h3>线索加载失败</h3>
                                    <p>服务暂时不可用，你的线索数据没有丢失。错误编号 <code id="leadErrorNo">—</code>，重试无效时可联系支持并附上编号。</p>
                                    <button class="button primary small" id="leadRetryButton" type="button">重试</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ── 页签二：表单管理 ──────────────────────────────────── --}}
                    <div data-lead-pane="forms" hidden>
                        <div class="panel lead-form-editor" id="leadFormEditorPanel" hidden>
                            <div class="panel-head"><h2 id="leadFormEditorTitle">新建表单</h2><div class="spacer"></div><button class="button ghost small" id="leadFormEditorCancel" type="button">返回表单列表</button></div>
                            <form class="form-sheet" id="leadFormEditor" novalidate>
                                <div class="frow">
                                    <label for="leadFormEditor-name">表单名称 <span class="req">*</span></label>
                                    <div class="fwrap"><input name="name" maxlength="120" required placeholder="例如：免费试用申请" id="leadFormEditor-name" aria-describedby="leadFormEditor-nameHelp"><div class="hint" id="leadFormEditor-nameHelp">会显示在公开页标题上，访客看得见。</div></div>
                                </div>
                                <div class="frow">
                                    <label for="leadFormEditor-slug">链接标识</label>
                                    <div class="fwrap"><input name="slug" maxlength="120" placeholder="留空自动生成，例如 free-trial" id="leadFormEditor-slug" aria-describedby="leadFormEditor-slugHelp"><div class="hint" id="leadFormEditor-slugHelp">只能用小写字母、数字、中划线和下划线。同一个标识在不同租户下互不冲突。</div></div>
                                </div>
                                <div class="frow">
                                    <label for="leadFormEditor-status">状态</label>
                                    <div class="fwrap"><select name="status" id="leadFormEditor-status"><option value="active" selected>启用（公开页可访问）</option><option value="inactive">停用（公开页返回 404）</option></select></div>
                                </div>
                                <div class="frow">
                                    <label for="leadFormEditor-description">说明文字</label>
                                    <div class="fwrap"><textarea name="description" rows="2" maxlength="2000" placeholder="可选，显示在标题下方" id="leadFormEditor-description"></textarea></div>
                                </div>
                                <div class="frow">
                                    <label for="leadFormEditor-submit_button_label">提交按钮文案</label>
                                    <div class="fwrap"><input name="submit_button_label" maxlength="80" placeholder="默认「提交」" id="leadFormEditor-submit_button_label"></div>
                                </div>
                                <div class="frow">
                                    <label for="leadFormEditor-success_message">提交成功提示</label>
                                    <div class="fwrap"><textarea name="success_message" rows="2" maxlength="2000" placeholder="可选，默认「提交成功，我们会尽快与你联系。」" id="leadFormEditor-success_message"></textarea></div>
                                </div>
                                <div class="frow">
                                    <label for="leadFormEditor-notify_webhook_url">新线索通知</label>
                                    <div class="fwrap">
                                        <input name="notify_webhook_url" type="url" maxlength="500" placeholder="https:// 开头的机器人地址，留空＝不通知" autocomplete="off" spellcheck="false" id="leadFormEditor-notify_webhook_url" aria-describedby="leadNotifyHint">
                                        <div class="hint" id="leadNotifyHint">
                                            填写企业微信或钉钉群机器人地址，接收新线索提醒。新建时留空不通知；编辑时留空保留原配置。
                                            <br>仅支持公网 HTTPS 地址。
                                        </div>
                                        <label class="lead-notify-clear" id="leadNotifyClearWrap" hidden>
                                            <input type="checkbox" name="notify_webhook_clear" id="leadNotifyClear">
                                            清除已配置的通知地址（改回「不通知」）
                                        </label>
                                    </div>
                                </div>
                                <div class="frow">
                                    <label for="leadFormEditor-notify_webhook_format">通知报文格式</label>
                                    <div class="fwrap">
                                        <select name="notify_webhook_format" id="leadFormEditor-notify_webhook_format" aria-describedby="leadFormEditor-notify_webhook_formatHelp">
                                            <option value="robot">群机器人（企业微信 / 钉钉）</option>
                                            <option value="json">结构化 JSON（自建接收端）</option>
                                        </select>
                                        <div class="hint" id="leadFormEditor-notify_webhook_formatHelp">企业微信或钉钉选“群机器人”；自建接收服务选“结构化 JSON”。</div>
                                    </div>
                                </div>
                                <div class="frow">
                                    <span class="lead-field-group-label" id="leadFieldsLabel">表单字段 <span class="req">*</span></span>
                                    <div class="fwrap">
                                        <div class="lead-fields-editor" id="leadFieldsEditor" role="group" aria-labelledby="leadFieldsLabel"></div>
                                        <button class="button ghost small" type="button" id="leadAddFieldButton">＋ 加一个字段</button>
                                        <div class="hint">这里配置访客稍后要填写的问题，不是在这里填写访客资料。“字段标题”给访客看；“系统标识”可保持默认或留空自动生成。至少要有一个字段。</div>
                                    </div>
                                </div>
                                <div class="frow">
                                    <div class="fwrap"><button class="button primary" type="submit" id="leadFormEditorSubmit">保存表单</button><span id="leadFormEditorError" class="at-form-error" role="alert"></span></div>
                                </div>
                            </form>
                        </div>

                        <div data-state-host id="leadFormsHost">
                            <div data-state="populated">
                                <div class="cl-tbl-scroll">
                                    <table class="cl-tbl">
                                        <thead><tr><th>表单</th><th>公开链接</th><th>状态</th><th>已收线索</th><th>创建时间</th><th class="cl-cell-actions">操作</th></tr></thead>
                                        <tbody id="leadFormRows"></tbody>
                                    </table>
                                </div>
                            </div>
                            <div data-state="loading" hidden>
                                <div class="cl-skeleton" role="status" aria-label="加载中"><div class="cl-sk w80"></div><div class="cl-sk w60"></div><div class="cl-sk w40"></div></div>
                            </div>
                            <div data-state="empty" hidden>
                                <div class="cl-state-tpl cl-state-empty">
                                    <div class="st-icon">✎</div>
                                    <h3>还没有表单</h3>
                                    <p>点右上角「＋ 新建表单」创建第一个。建好后会给你一条公开链接，访客不用登录就能填。</p>
                                </div>
                            </div>
                            <div data-state="error" hidden>
                                <div class="cl-state-tpl cl-state-error">
                                    <div class="st-icon">!</div>
                                    <h3>表单加载失败</h3>
                                    <p>服务暂时不可用。错误编号 <code id="leadFormsErrorNo">—</code>，重试无效时可联系支持并附上编号。</p>
                                    <button class="button primary small" id="leadFormsRetryButton" type="button">重试</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ── 页签三：转化统计 ──────────────────────────────────── --}}
                    <div data-lead-pane="analytics" hidden>
                        <form class="lead-filter-bar" id="leadAnalyticsFilterForm" novalidate>
                            <select name="lead_preset" id="leadAnalyticsPreset" aria-label="时间范围">
                                <option value="7d">近 7 天</option>
                                <option value="30d" selected>近 30 天</option>
                                <option value="90d">近 90 天</option>
                                <option value="custom">自定义</option>
                            </select>
                            <input type="date" name="lead_date_from" aria-label="起始日期" hidden>
                            <input type="date" name="lead_date_to" aria-label="截止日期" hidden>
                            <select name="lead_form_id" id="leadAnalyticsFormFilter" aria-label="表单筛选">
                                <option value="">全部表单</option>
                            </select>
                            <button class="button secondary small" type="submit">应用</button>
                        </form>

                        <div data-state-host id="leadAnalyticsHost">
                            <div data-state="populated">
                                <div class="lead-stat-row" id="leadAnalyticsKpis"></div>
                                <div class="lead-analytics-grid">
                                    <div class="lead-card">
                                        <h3>转化漏斗</h3>
                                        <div id="leadFunnel"></div>
                                    </div>
                                    <div class="lead-card">
                                        <h3>每日趋势</h3>
                                        <div class="lead-trend" id="leadTrend"></div>
                                    </div>
                                </div>
                                <div class="lead-analytics-grid">
                                    <div class="lead-card">
                                        <h3>来源页面 Top 10</h3>
                                        <div id="leadSources"></div>
                                    </div>
                                    <div class="lead-card">
                                        <h3>最近提交</h3>
                                        <div id="leadRecent"></div>
                                    </div>
                                </div>
                            </div>
                            <div data-state="loading" hidden>
                                <div class="cl-skeleton" role="status" aria-label="加载中"><div class="cl-sk w80"></div><div class="cl-sk w60"></div><div class="cl-sk w40"></div></div>
                            </div>
                            <div data-state="empty" hidden>
                                <div class="cl-state-tpl cl-state-empty">
                                    <div class="st-icon">✎</div>
                                    <h3>这段时间还没有数据</h3>
                                    <p>换个时间范围看看，或先让表单收到第一条线索。</p>
                                </div>
                            </div>
                            <div data-state="error" hidden>
                                <div class="cl-state-tpl cl-state-error">
                                    <div class="st-icon">!</div>
                                    <h3>统计加载失败</h3>
                                    <p>服务暂时不可用。错误编号 <code id="leadAnalyticsErrorNo">—</code>，重试无效时可联系支持并附上编号。</p>
                                    <button class="button primary small" id="leadAnalyticsRetryButton" type="button">重试</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 线索详情抽屉：看完整提交内容、改状态、写备注 --}}
                    <div class="lead-drawer" id="leadDrawer" hidden>
                        <div class="lead-drawer-mask" id="leadDrawerMask"></div>
                        <div class="lead-drawer-body" role="dialog" aria-modal="true" aria-labelledby="leadDrawerTitle">
                            <div class="panel-head"><h2 id="leadDrawerTitle">线索详情</h2><div class="spacer"></div><button class="button ghost small" id="leadDrawerClose" type="button">关闭</button></div>
                            <div class="lead-drawer-content" id="leadDrawerContent"></div>
                            <form class="form-sheet" id="leadDrawerForm" novalidate>
                                <div class="frow">
                                    <label for="leadDrawerForm-status">状态</label>
                                    <div class="fwrap"><select name="status" id="leadDrawerForm-status">
                                        <option value="new">新线索</option>
                                        <option value="contacted">已联系</option>
                                        <option value="qualified">已确认意向</option>
                                        <option value="converted">已成交</option>
                                        <option value="invalid">无效</option>
                                    </select></div>
                                </div>
                                <div class="frow">
                                    <label for="leadDrawerForm-note">跟进备注</label>
                                    <div class="fwrap"><textarea name="note" rows="4" maxlength="5000" placeholder="记录跟进情况" id="leadDrawerForm-note"></textarea></div>
                                </div>
                                <div class="frow">
                                    <div class="fwrap"><button class="button primary" type="submit" id="leadDrawerSubmit">保存</button><span id="leadDrawerError" class="at-form-error" role="alert"></span></div>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>
