<section class="url-imports-panel workspace-view ui-management-panel md-app" id="urlImportsPanel" data-workspace-view="url-imports" hidden>
                    <div class="view-title">
                        <h1>URL 导入</h1>
                        <span class="vt-sub">粘贴一个公网可打开的网页地址，平台会抓取正文、转成草稿并存进当前项目的文章列表。抓取是异步的，状态会在这里更新。</span>
                        <span class="spacer"></span>
                        <button class="button secondary small" id="uiReloadButton" type="button">刷新</button>
                    </div>
                    <p class="muted" id="uiMessage" role="status"></p>
                    <div class="md-panel">
                    <div class="md-panel-head"><h3>提交新导入</h3></div>
                    <div class="md-panel-body">
                    <form class="form-grid" id="urlImportForm">
                        <label class="span-2" for="urlImportUrl">
                            <span>网页地址</span>
                            <input id="urlImportUrl" name="url" type="url" maxlength="2000" required placeholder="https://example.com/article">
                            <span class="hint" id="urlImportUrlHelp">要能公网直接打开；需要登录或只在站内可见的页面抓不到。</span>
                        </label>
                        <label for="urlImportProject">
                            <span>导入到项目</span>
                            <input id="urlImportProject" name="project_id_display" readonly disabled>
                            <span class="hint">当前项目；切换项目用左上角项目卡。</span>
                        </label>
                        <div class="form-actions span-2">
                            <button class="button primary" type="submit" id="urlImportSubmitButton">开始导入</button>
                            <p class="form-message" id="urlImportFormMessage"></p>
                        </div>
                    </form>
                    </div>
                    </div>
                    <div class="md-panel">
                    <div class="md-panel-head"><h3>导入记录</h3></div>
                    <div class="cl-tbl-scroll">
                        <table class="md-table">
                            <thead><tr><th>网页</th><th>状态</th><th>标题 / 失败原因</th><th>提交时间</th></tr></thead>
                            <tbody id="urlImportRows"></tbody>
                        </table>
                    </div>
                    {{--
                        单条详情：后端 GET /api/v1/url-imports/{id} 一直在，列表此前看不到
                        "抓到了多少字、生成了哪篇草稿"。点列表里的网页地址就地展开（只读）。
                    --}}
                    <hr>
                    <div class="md-panel-body">
                    <h3>导入详情</h3>
                    <p class="muted" id="urlImportDetailMessage">点上面列表中的记录行或「详情」，查看抓取结果与生成的草稿；点网页链接可直达原页面。</p>
                    <div id="urlImportDetail"></div>
                    </div>
                    </div>
                </section>
