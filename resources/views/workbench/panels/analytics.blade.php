<section class="analytics-panel workspace-view md-app" id="analyticsPanel" data-workspace-view="analytics" hidden>
                    <div class="view-title">
                        <h1>项目总览</h1>
                        <span class="vt-sub">当前项目的文章、技能执行与渠道发布统计。</span>
                        <span class="spacer"></span>
                        <select id="analyticsDaysSelect" class="md-input" aria-label="统计时间范围">
                            <option value="7">近 7 天</option>
                            <option value="30" selected>近 30 天</option>
                            <option value="90">近 90 天</option>
                        </select>
                        <button class="md-btn md-btn--secondary" id="reloadAnalyticsButton" type="button">刷新</button>
                    </div>
                    <p class="form-message" id="analyticsMessage"></p>
                    <div class="md-panel">
                        <div class="md-panel-head"><h3>运营统计</h3><div class="analytics-scope"></div></div>
                        <div class="md-panel-body analytics-summary" id="analyticsSummary"></div>
                    </div>
                    <div class="md-panel">
                        <div class="md-panel-head analytics-tabs" id="analyticsTabs">
                            <div class="md-tabs" role="tablist">
                                <button class="md-tab is-on" type="button" role="tab" data-analytics-tab="trends" aria-selected="true">趋势</button>
                                <button class="md-tab" type="button" role="tab" data-analytics-tab="models" aria-selected="false">模型用量</button>
                                <button class="md-tab" type="button" role="tab" data-analytics-tab="projects" aria-selected="false">项目统计</button>
                                <button class="md-tab" type="button" role="tab" data-analytics-tab="distribution" aria-selected="false">分发统计</button>
                            </div>
                        </div>
                        <div class="md-panel-body analytics-content" id="analyticsContent"></div>
                    </div>
                    <div class="md-panel dashboard-activity" id="dashboardActivity">
                        <div class="md-panel-head">
                            <h3>最近动态</h3>
                            <div class="panel-actions">
                                <button class="md-link" type="button" data-analytics-pending-articles>待审核文章 →</button>
                                <button class="md-link" type="button" data-analytics-skill-reports>全部技能报告 →</button>
                            </div>
                        </div>
                        <div class="md-panel-body" id="activityList"></div>
                    </div>
                </section>