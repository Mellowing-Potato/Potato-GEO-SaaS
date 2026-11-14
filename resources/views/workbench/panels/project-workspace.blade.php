<section class="workspace-view project-workspace is-active md-app" id="projectWorkspacePanel" data-workspace-view="projects">
            <section class="md-panel project-directory" id="projectDirectoryPanel" hidden>
                <div class="project-directory-heading"><div><h2 tabindex="-1" id="projectDirectoryTitle">项目管理</h2><p>选择项目查看概览、编辑档案或继续工作。</p></div><button type="button" class="md-btn md-btn--primary" id="directoryNewProject">新建项目</button></div>
                <label for="directoryProjectSearch">搜索项目</label><input type="search" class="md-input" id="directoryProjectSearch" placeholder="项目名称或品牌">
                <div class="project-directory-list" id="directoryProjectList"></div>
            </section>
            <section class="project-trash-page" id="projectTrashSection" hidden aria-labelledby="projectTrashTitle">
                <div class="project-directory-heading"><div><h2 tabindex="-1" id="projectTrashTitle">回收站</h2><p>查看已删除的项目和文章；到期但尚未清理的内容也会保留在列表中。</p></div><button type="button" class="md-btn md-btn--secondary" id="projectTrashRefresh">刷新</button></div>
                <p class="project-trash-note">项目和文章保留 <b id="projectTrashRetention">7</b> 天。恢复项目会找回其本地内容和自动化任务；文章会回到删除前的状态。已撤回的外站内容需要重新发布。</p>
                <div class="project-trash-list" id="projectTrashList"><p class="muted">正在读取回收站…</p></div>
                <p class="md-status" id="projectTrashMessage" role="status" aria-live="polite"></p>
            </section>
            <p class="md-status" id="projectDetailMessage"></p>
            <div class="workspace-grid project-detail-grid" id="projectEditorViews">
                <section class="md-panel create-panel" id="createProjectPanel">
                    <div class="pj-sheet">
                        <div class="pj-lead pj-lead-fresh">
                            <p class="md-eyebrow md-eyebrow--lead">开始</p>
                            <h2>建立你的第一个项目</h2>
                            <p>填一张项目档案，之后的诊断、内容和分发都围绕它进行；写得越具体，AI 搜索诊断越准。</p>
                        </div>
                        <div class="pj-lead pj-lead-new">
                            <p class="md-eyebrow md-eyebrow--lead">新建项目</p>
                            <h2>填一张项目档案</h2>
                            <p>写得越具体，AI 搜索诊断越准。</p>
                        </div>
                        <div class="stage-rail pj-create-rail rail-ghost" id="pjCreateRail" aria-hidden="true" hidden>
                            <div class="stage-node"><i></i><b>研究</b></div>
                            <div class="stage-node"><i></i><b>策略</b></div>
                            <div class="stage-node"><i></i><b>内容</b></div>
                            <div class="stage-node"><i></i><b>发布</b></div>
                            <div class="stage-node"><i></i><b>监测</b></div>
                            <div class="stage-node"><i></i><b>迭代</b></div>
                        </div>
                        <div class="rail-caption" id="pjCreateRailCaption" hidden>创建后，项目会沿这条工作流推进</div>
<form class="pj-formbody" id="createProjectForm">
                            <div class="md-legend pj-legend">项目与品牌<small>告诉我们这个项目替谁做优化。</small></div>
                            <div class="pj-field">
                                <label class="md-field-label" for="createProject-name">项目名称 <span class="req">*</span></label>
                                <div class="md-field"><input id="createProject-name" aria-describedby="createProject-name-help" class="md-input" name="name" required maxlength="255" placeholder="请输入项目名称，例如：AI 搜索增长测试"><div class="md-field-hint" id="createProject-name-help">给这次优化起个名字。</div></div>
                            </div>
                            <div class="pj-field">
                                <label class="md-field-label" for="createProject-brand_name">品牌名称</label>
                                <div class="md-field"><input id="createProject-brand_name" aria-describedby="createProject-brand_name-help" class="md-input" name="brand_name" maxlength="255" placeholder="请输入要分析的品牌名称"><div class="md-field-hint" id="createProject-brand_name-help">要在 AI 搜索里被找到的品牌。</div></div>
                            </div>
                            <div class="pj-field">
                                <label class="md-field-label" for="createProject-brand_url">官网地址（可选）</label>
                                <div class="md-field"><input id="createProject-brand_url" aria-describedby="createProject-brand_url-help" class="md-input" name="brand_url" type="url" maxlength="500" autocomplete="off" placeholder="没有官网可留空；不要填写测试网址"><div class="md-field-hint" id="createProject-brand_url-help">没有官网可留空；不要填测试网址。</div></div>
                            </div>
                            <div class="pj-field">
                                <label class="md-field-label" for="createProject-target_market">目标市场</label>
                                <div class="md-field"><input id="createProject-target_market" aria-describedby="createProject-target_market-help" class="md-input" name="target_market" maxlength="255" placeholder="请输入目标客户、行业或地区"><div class="md-field-hint" id="createProject-target_market-help">客户群、行业或地区。</div></div>
                            </div>
                            <div class="md-legend pj-legend">给 AI 诊断的输入<small>这四项会直接进入诊断分析——写得越具体，报告越有用。</small></div>
                            <div class="pj-field">
                                <label class="md-field-label" for="createProject-product_description">产品说明</label>
                                <div class="md-field"><textarea id="createProject-product_description" class="md-textarea" name="product_description" maxlength="2000" rows="3" placeholder="请说明产品解决的问题、核心功能和服务对象"></textarea></div>
                            </div>
                            <div class="pj-field">
                                <label class="md-field-label" for="createProject-target_keywords">目标关键词</label>
                                <div class="md-field"><textarea id="createProject-target_keywords" aria-describedby="createProject-target_keywords-help" class="md-textarea" name="target_keywords" rows="3" placeholder="每行一个目标关键词，例如：AI 搜索优化"></textarea><div class="md-field-hint" id="createProject-target_keywords-help">每行一个，例如：AI 搜索优化。</div></div>
                            </div>
                            <div class="pj-field">
                                <label class="md-field-label" for="createProject-competitors">竞品</label>
                                <div class="md-field"><textarea id="createProject-competitors" aria-describedby="createProject-competitors-help" class="md-textarea" name="competitors" rows="3" placeholder="每行一个竞品名称或网址"></textarea><div class="md-field-hint" id="createProject-competitors-help">每行一个名称或网址。</div></div>
                            </div>
                            <div class="pj-field">
                                <label class="md-field-label" for="createProject-goals">目标</label>
                                <div class="md-field"><textarea id="createProject-goals" class="md-textarea" name="goals" maxlength="2000" rows="2" placeholder="请描述希望通过内容优化改善的业务目标"></textarea></div>
                            </div>
                            <div class="md-formfoot pj-formfoot">
                                <button class="md-btn md-btn--primary" type="submit">创建项目</button>
                                <button class="md-btn md-btn--secondary" id="cancelCreateProjectButton" type="button">取消</button>
                                <p class="md-status" id="createProjectMessage"></p>
                            </div>
                        </form>
                    </div>
                </section>

                <section class="detail-panel" id="projectDetail" hidden>
                    <div class="project-home">
                    <header class="ws-head">
                        <div class="ws-head-top">
                            <div class="ws-title">
                                <div class="ws-title-row"><h1 id="detailTitle">项目详情</h1><div class="ws-head-actions"><button class="md-btn md-btn--secondary md-btn--sm" id="reloadProjectsButton" type="button">刷新</button><button class="md-btn md-btn--secondary md-btn--sm" id="projectSettingsButton" type="button">项目设置</button></div></div>
                                <p class="ws-meta" id="projectHomeMeta"></p>
                            </div>
                            <div class="ws-metrics" aria-label="项目数据">
                                <a class="md-panel md-stat ws-metric" id="projectHomeVisibilityLink" href="/visibility"><span class="ws-metric-label">智能回答监测</span><strong class="ws-metric-value" id="projectHomeVisibilityValue">—</strong><span class="ws-metric-delta" id="projectHomeVisibilityDelta"></span></a>
                                <a class="md-panel md-stat ws-metric" id="projectHomeCrawlerLink" href="/analytics"><span class="ws-metric-label">AI 爬虫访问 · 近 7 天 <span class="md-badge md-badge--neutral">全工作区</span></span><strong class="ws-metric-value" id="projectHomeCrawlerValue">—</strong><span class="ws-metric-delta" id="projectHomeCrawlerDelta"></span></a>
                                <a class="md-panel md-stat ws-metric" id="projectHomePublishedLink" href="#content-library"><span class="ws-metric-label">已发布文章</span><strong class="ws-metric-value" id="projectHomePublishedValue">—</strong><span class="ws-metric-delta" id="projectHomePublishedDelta"></span></a>
                            </div>
                        </div>
                    </header>
                    <section class="md-panel ws-stage" aria-label="项目阶段">
                        <div class="stage-rail" id="pjStageRail" aria-label="项目阶段进度"></div>
                        <button class="md-btn md-btn--secondary md-btn--sm" id="openAnalysisButton" type="button">执行综合诊断</button>
                    </section>
                    <section class="md-panel" aria-labelledby="projectHomeQueueTitle">
                        <div class="md-panel-head"><h2 id="projectHomeQueueTitle">接下来做什么</h2><span id="projectHomeQueueCount"></span></div>
                        <p class="project-home-notice" id="projectHomeArticleNotice" hidden></p>
                        <p class="project-home-notice" id="projectHomeStageNotice" hidden></p>
                        <ol class="queue" id="projectHomeQueue"></ol>
                        <div class="md-empty" id="projectHomeQueueEmpty" hidden><div class="md-empty-title">现在没有需要你处理的事</div><p>新的审核、被拦下的文章和该执行的技能会出现在这里。</p></div>
                        <div class="project-home-foot"><button class="md-link" id="projectHomeQueueToggle" type="button" hidden></button></div>
                    </section>
                    <section class="md-panel" aria-labelledby="projectHomeActivityTitle">
                        <div class="md-panel-head"><h2 id="projectHomeActivityTitle">最近动态</h2><a class="md-link" href="#asset">全部报告与文章 →</a></div>
                        <ol class="timeline" id="projectHomeActivity" hidden></ol>
                        <div class="md-empty md-empty--sm" id="projectHomeActivityEmpty"><div class="md-empty-title" id="projectHomeActivityEmptyTitle">正在读取动态…</div></div>
                    </section>
                    </div>
                </section>
            </div>
            <section class="project-settings-page" id="projectSettingsSection" hidden aria-labelledby="projectSettingsTitle">
                <div class="project-directory-heading"><div><h2 id="projectSettingsTitle" tabindex="-1">项目设置</h2><p id="projectSettingsName"></p></div><button class="md-btn md-btn--secondary md-btn--sm" id="projectSettingsBackButton" type="button">返回工作台</button></div>
                <section class="md-panel">
                    <div class="md-panel-head"><h3>项目档案</h3><button class="md-btn md-btn--secondary md-btn--sm" id="editProjectButton" type="button" aria-expanded="false" aria-controls="settingsWrap">编辑</button></div>
                    <div class="md-panel-body">
                            <dl class="dossier" id="pjDossier"></dl>
                            <div class="settings-wrap" id="settingsWrap" hidden>
<form class="pj-formbody settings-form" id="settingsForm">
                                    <div class="md-legend pj-legend">项目与品牌</div>
                                    <div class="pj-field">
                                        <label class="md-field-label" for="settingsProject-name">项目名称 <span class="req">*</span></label>
                                        <div class="md-field"><input id="settingsProject-name" class="md-input" name="name" required maxlength="255" placeholder="请输入项目名称"></div>
                                    </div>
                                    <div class="pj-field">
                                        <label class="md-field-label" for="settingsProject-brand_name">品牌名称</label>
                                        <div class="md-field"><input id="settingsProject-brand_name" class="md-input" name="brand_name" maxlength="255" placeholder="请输入要分析的品牌名称"></div>
                                    </div>
                                    <div class="pj-field">
                                        <label class="md-field-label" for="settingsProject-brand_url">官网地址（可选）</label>
                                        <div class="md-field"><input id="settingsProject-brand_url" class="md-input" name="brand_url" type="url" maxlength="500" autocomplete="off" placeholder="没有官网可留空；补真实官网后可重新分析"></div>
                                    </div>
                                    <div class="pj-field">
                                        <label class="md-field-label" for="settingsProject-target_market">目标市场</label>
                                        <div class="md-field"><input id="settingsProject-target_market" class="md-input" name="target_market" maxlength="255" placeholder="请输入目标客户、行业或地区"></div>
                                    </div>
                                    <div class="md-legend pj-legend">给 AI 诊断的输入</div>
                                    <div class="pj-field">
                                        <label class="md-field-label" for="settingsProject-product_description">产品说明</label>
                                        <div class="md-field"><textarea id="settingsProject-product_description" class="md-textarea" name="product_description" maxlength="2000" rows="3" placeholder="请说明产品解决的问题、核心功能和服务对象"></textarea></div>
                                    </div>
                                    <div class="pj-field">
                                        <label class="md-field-label" for="settingsProject-target_keywords">目标关键词</label>
                                        <div class="md-field"><textarea id="settingsProject-target_keywords" class="md-textarea" name="target_keywords" rows="3" placeholder="每行一个目标关键词"></textarea></div>
                                    </div>
                                    <div class="pj-field">
                                        <label class="md-field-label" for="settingsProject-competitors">竞品</label>
                                        <div class="md-field"><textarea id="settingsProject-competitors" class="md-textarea" name="competitors" rows="3" placeholder="每行一个竞品名称或网址"></textarea></div>
                                    </div>
                                    <div class="pj-field">
                                        <label class="md-field-label" for="settingsProject-goals">目标</label>
                                        <div class="md-field"><textarea id="settingsProject-goals" class="md-textarea" name="goals" maxlength="2000" rows="2" placeholder="请描述希望通过内容优化改善的业务目标"></textarea></div>
                                    </div>
                                    <div class="md-formfoot pj-formfoot">
                                        <button class="md-btn md-btn--primary md-btn--sm" type="submit">保存设置</button>
                                        <button class="md-btn md-btn--secondary md-btn--sm" id="cancelEditProjectButton" type="button">取消</button>
                                        <p class="md-status" id="settingsMessage"></p>
                                    </div>
                                </form>
                            </div>
                </div></section>
                            {{-- 2026-09-04 拆版式 · 步骤 1：从「诊断与路线」搬过来的「AI 辅助建议」。
                                 落点选这里是因为它写的就是上面这张档案里的「目标关键词」和「竞品」
                                 两栏（两个「填入项目…」按钮走 PATCH /api/v1/projects/{id}）。
                                 ⚠️ 零件全部换成本页的共用件（geo-base.css 的 .md-*）：项目管理页
                                    09-03 已经整页迁过去了，混两套按钮就是老板 09-04 说的
                                    「大的大小的小」。
                                 ⚠️ 标签走 .md-field 的原生竖排（标签在输入上面），不套本页的
                                    .pj-field 横排行：这一列实宽只有 ~314px，130px 的标签列会把
                                    输入框压到 170px，一个网址都看不全。
                                 ⚠️ 所有 id 一个不改 —— geo-entry.js 全部按 id 取元素。 --}}
                <section class="md-panel" id="projectSettingsAiPanel">
                    <div class="md-panel-head"><h3>AI 辅助 · 让 AI 推荐关键词与竞品</h3></div>
                    <div class="md-panel-body">
                            <div class="skill-suggest-section" id="skillSuggestSection">
                                <div class="suggest-forms">
                                    <div>
                                    <h4>推荐关键词</h4>
                                    <form id="suggestKeywordsForm">
                                        <div class="md-field">
                                            <label class="md-field-label" for="suggestKeywordDomain">业务领域 / 官网</label>
                                            <input id="suggestKeywordDomain" class="md-input" name="domain" maxlength="500" placeholder="例如：https://example.com 或 SaaS 协作工具">
                                        </div>
                                        <div class="md-formfoot suggest-foot">
                                            <button class="md-btn md-btn--secondary md-btn--sm" type="submit">推荐关键词</button>
                                            <p class="md-status" id="suggestKeywordsMessage"></p>
                                        </div>
                                    </form>
                                    </div>
                                    <div>
                                    <h4>推荐竞品</h4>
                                    <form id="suggestCompetitorsForm">
                                        <div class="md-field">
                                            <label class="md-field-label" for="suggestCompetitorBrand">品牌名称</label>
                                            <input id="suggestCompetitorBrand" class="md-input" name="brand_name" maxlength="200" placeholder="例如：示例科技">
                                        </div>
                                        <div class="md-field">
                                            <label class="md-field-label" for="suggestCompetitorUrl">官网（可选）</label>
                                            <input id="suggestCompetitorUrl" class="md-input" name="brand_url" type="url" maxlength="500" placeholder="https://example.com">
                                        </div>
                                        <div class="md-field">
                                            <label class="md-field-label" for="suggestCompetitorDescription">业务描述（可选）</label>
                                            <textarea id="suggestCompetitorDescription" class="md-textarea" name="description" rows="2" maxlength="2000" placeholder="一句话描述业务"></textarea>
                                        </div>
                                        <input type="hidden" name="project_id" value="">
                                        <div class="md-formfoot suggest-foot">
                                            <button class="md-btn md-btn--secondary md-btn--sm" type="submit">推荐竞品</button>
                                            <p class="md-status" id="suggestCompetitorsMessage"></p>
                                        </div>
                                    </form>
                                    </div>
                                </div>
                                {{-- 两个推荐各有自己的结果槽。改前两个函数写的是同一个容器，
                                     先推荐关键词、再推荐竞品，关键词那张卡连同「填入项目关键词」
                                     按钮会被整块抹掉，用户刚拿到的结果就没了。
                                     ⚠️ 外层容器 id 保留：换项目时的整体重置仍然按它来找槽位清空。 --}}
                                <div class="skill-suggest-result" id="skillSuggestResult">
                                    <div id="skillSuggestKeywordsResult"></div>
                                    <div id="skillSuggestCompetitorsResult"></div>
                                </div>
                            </div>
                    </div>
                </section>
                <section class="md-panel" id="projectSettingsDangerPanel"><div class="md-panel-head"><h3>删除项目</h3></div><div class="md-panel-body">
                            <div class="dossier-foot">
                                {{-- 2026-09-21 起：删除 = 进回收站（软删），文章/分析报告原地保留 7 天可恢复，
                                     期满由 geo:projects-purge 才连坐真删；调用流水照旧不删。
                                     客户真正要问的两件事——「文章会不会跟着没」「还有没有后悔药」——
                                     这句话必须同时答上（原版本只说"永久删除不能恢复"，现在不成立了）。 --}}
                                <p>删除后，这个项目会进回收站保留 <b id="deleteProjectRetention">7</b> 天，期内可恢复本地项目、文章、分析报告和自动化任务；已撤回的外站内容需要重新发布。超过保留期后无法恢复，调用流水会保留。</p>
                                <button class="md-btn md-btn--danger md-btn--sm pj-btn-danger" id="deleteProjectButton" type="button" hidden>删除项目</button>
                                <button class="md-btn md-btn--secondary md-btn--sm" id="openProjectTrash" type="button">查看全局回收站</button>
                            </div>
                </div></section>
            </section>
            </section>
