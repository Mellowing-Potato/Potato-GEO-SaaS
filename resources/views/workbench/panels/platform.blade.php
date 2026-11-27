<section class="panel platform-panel workspace-view" id="platformPanel" data-workspace-view="platform" hidden>
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">平台管理</p>
                            <h2>运营面板</h2>
                        </div>
                        <div class="panel-actions">
                            <select id="platformDaysSelect" class="small" aria-label="平台统计周期">
                                <option value="7">近 7 天</option>
                                <option value="30" selected>近 30 天</option>
                                <option value="90">近 90 天</option>
                            </select>
                            <button class="button secondary small" id="reloadPlatformButton" type="button">刷新</button>
                        </div>
                    </div>
                    <p class="muted" id="platformMessage">业务数据仅限当前工作区；系统健康显示匿名汇总。</p>
                    <form id="instanceRegistrationForm"><label><input type="checkbox" id="instanceRegistrationEnabled" disabled>允许新用户注册（每人独立工作区）</label><button type="submit" id="saveInstanceRegistration" disabled>保存注册设置</button><p id="instanceRegistrationMessage" role="status"></p></form>
                    <div class="platform-tabs" id="platformTabs">
                        <div class="skill-filters">
                            <button class="filter-chip" type="button" data-platform-tab="overview" aria-pressed="true">概览</button>
                            <button class="filter-chip" type="button" data-platform-tab="tenants" aria-pressed="false">当前工作区</button>
                            <button class="filter-chip" type="button" data-platform-tab="health" aria-pressed="false">系统健康</button>
                            <button class="filter-chip" type="button" data-platform-tab="operations" aria-pressed="false">运营看板</button>
                        </div>
                    </div>
                    <div class="platform-content" id="platformContent"></div>
                </section>