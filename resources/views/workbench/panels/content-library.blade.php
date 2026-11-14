<section class="content-library-panel workspace-view md-app" id="contentLibraryPanel" data-workspace-view="content-library" hidden>
                    <div class="view-title">
                        <h1>文章</h1>
                        <span class="vt-sub">管理当前项目的文章，优先显示需要处理的内容。</span>
                        <span class="spacer"></span>
                        <button class="md-btn md-btn--ghost" id="clManageCategoriesButton" type="button">管理分类</button>
                        <button class="md-btn md-btn--primary" id="clNewArticleButton" type="button">写新文章</button>
                    </div>
                    <div class="md-panel">
                    <div class="md-panel-head cl-panel-head cl2-panel-head">
                        {{-- md-tabs / md-tab / md-tab-count：零件层里此前引用 0 次，这里是第一个真实消费者。
                             ⚠️ 选中态样式挂 .is-on，不是 [aria-selected="true"]。aria-selected 仍要写
                                （无障碍要它），但两个必须同时改，见 geo-entry.js 的 initContentLibrary。 --}}
                        <div class="md-tabs" role="tablist" aria-label="按状态筛选" id="clTabs">
                            <button class="md-tab is-on" role="tab" aria-selected="true" data-tab="all">全部<span class="md-tab-count"></span></button>
                            <button class="md-tab" role="tab" aria-selected="false" data-tab="pending">待审核<span class="md-tab-count"></span></button>
                            <button class="md-tab" role="tab" aria-selected="false" data-tab="editing">待修改<span class="md-tab-count"></span></button>
                            <button class="md-tab" role="tab" aria-selected="false" data-tab="approved">待发布<span class="md-tab-count"></span></button>
                            <button class="md-tab" role="tab" aria-selected="false" data-tab="draft">草稿<span class="md-tab-count"></span></button>
                            <button class="md-tab" role="tab" aria-selected="false" data-tab="published">已发布<span class="md-tab-count"></span></button>
                            <button class="md-tab" role="tab" aria-selected="false" data-tab="recycled">已废弃<span class="md-tab-count"></span></button>
                        </div>
                    </div>
                    <div class="cl-list-filters">
                        <input class="md-input md-input--search cl2-search" type="search" placeholder="搜索标题或摘要" aria-label="搜索文章" id="clSearchInput">
                        <select id="clContentType" class="md-input" aria-label="文章类型">
                            <option value="">全部文章类型</option>
                            <option value="explainer">解释型文章</option>
                            <option value="comparison">对比型文章</option>
                            <option value="ranking">排名型文章</option>
                            <option value="general">通用文章</option>
                        </select>
                        <select id="clCategoryFilter" class="md-input" aria-label="文章分类" hidden><option value="">全部分类</option></select>
                        <button class="md-link" id="clClearFilters" type="button" hidden>清除筛选</button>
                    </div>
                    <div class="callout" id="clMessage"></div>
                    {{-- 翻页上限提示。列表最多翻 5 页 × 100 条 = 500 篇，到顶了必须说出来：
                         静默截断（原本只取后端第一页 20 条）就是本批要修的毛病本身。 --}}
                    <div class="md-alert md-alert--info cl2-trunc" id="clTruncNote" role="status" hidden></div>
                    <div data-state-host id="clHost">
                        <div data-state="populated">
                            <div class="md-tablewrap">
                                <table class="md-table cl2-table">
                                    <thead>
                                        <tr><th>文章</th><th>字数</th><th>流转</th><th class="cl2-col-status">状态</th><th>更新</th><th class="md-col-act">操作</th></tr>
                                    </thead>
                                    <tbody id="clRows"></tbody>
                                </table>
                            </div>
                        </div>
                        <div data-state="loading" hidden>
                            <div class="md-skeleton" role="status" aria-label="加载中"><div class="md-sk md-sk--w80"></div><div class="md-sk md-sk--w60"></div><div class="md-sk md-sk--w80"></div><div class="md-sk md-sk--w40"></div></div>
                        </div>
                        <div data-state="empty" hidden>
                            <div class="md-empty">
                                <div class="md-empty-title">这个项目还没有可管理的文章</div>
                                <p>文章由写作技能产出：点「写新文章」进入写作流程，完成的文章会自动进入这里。</p>
                                <p><button class="md-btn md-btn--secondary md-btn--sm" id="clEmptyGoSkill" type="button">去执行技能</button></p>
                            </div>
                        </div>
                        {{-- 已废弃为空是个「好」状态，不是筛选没命中，文案不能跟下面那个共用。 --}}
                        <div data-state="recycled-empty" hidden>
                            <div class="md-empty md-empty--sm">
                                <div class="md-empty-title">没有已废弃的文章</div>
                                <p>废弃文章会留在这里，不会按回收站保留期限清除；重新使用时可转为草稿并重新审核。</p>
                            </div>
                        </div>
                        <div data-state="empty-filtered" hidden>
                            <div class="md-empty md-empty--sm">
                                <div class="md-empty-title">当前筛选没有结果</div>
                                <p>切回「全部」，或清空搜索词，看看完整的文章队列。</p>
                                <p><button class="md-btn md-btn--secondary md-btn--sm" id="clEmptyBackAll" type="button">切回全部</button></p>
                            </div>
                        </div>
                        <div data-state="error" hidden>
                            <div class="md-empty">
                                <div class="md-empty-title">文章列表加载失败</div>
                                <p>服务暂时不可用，你的文章数据没有丢失。错误编号 <code class="md-code" id="clErrorNo">—</code>，重试无效时可联系支持并附上编号。</p>
                                <p><button class="md-btn md-btn--secondary md-btn--sm" id="clRetryButton" type="button">重试</button></p>
                            </div>
                        </div>
                    </div>
                    </div>
                </section>
