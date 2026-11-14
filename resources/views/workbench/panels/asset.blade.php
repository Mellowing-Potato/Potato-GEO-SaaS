<section class="panel asset-panel workspace-view" id="assetPanel" data-workspace-view="asset" hidden>
                    <div class="callout" id="assetMessage">请选择一个项目，查看诊断与路线产出的报告。</div>
                    <div class="asset-toolbar">
                        <div class="skill-filters" id="assetSourceFilter">
                            <button class="filter-chip" type="button" data-source-type="all" aria-pressed="true">全部</button>
                            <button class="filter-chip" type="button" data-source-type="geo_analysis">综合诊断</button>
                            <button class="filter-chip" type="button" data-source-type="skill_execution">技能报告</button>
                        </div>
                        <form class="asset-search" id="assetSearchForm">
                            <input id="assetSearchInput" aria-label="搜索历史报告" name="search" type="search" placeholder="搜索标题、摘要或正文">
                            <button class="button secondary small" type="submit">搜索</button>
                            <button class="button secondary small" id="clearAssetSearchButton" type="button">清空</button>
                        </form>
                        {{-- 「刷新内容」放在工具条里、但**不放进搜索表单**：.asset-search 是
                             三列定宽栅格（输入框 / 搜索 / 清空），塞第四个进去会被拉成整行宽。 --}}
                        <div class="asset-toolbar-actions">
                            <button class="button secondary small" id="reloadAssetsButton" type="button">刷新内容</button>
                        </div>
                    </div>
                    <div class="report-list-caption"><span>点击报告名称，阅读完整报告</span><span>生成时间 · 从新到旧</span></div>
                    <div class="report-list-columns" aria-hidden="true"><span>报告名称</span><span>类型</span><span>生成时间</span><span>状态</span><span>操作</span></div>
                    <div class="report-history" id="assetHistory" aria-label="历史报告列表"></div>
                </section>