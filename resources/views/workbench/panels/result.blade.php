<section class="panel result-panel report-reader workspace-view" id="resultPanel" data-workspace-view="result" hidden>
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">分析诊断</p>
                            <h2 id="resultPageTitle">报告</h2>
                            <p class="muted" id="resultPageMeta"></p>
                        </div>
                        <div class="report-actions">
                            {{-- 复制 / 下载要等报告载入才有意义，先藏着；「返回」任何状态下都得在，
                                 包括报告打不开的时候 —— 那正是最需要退出去的时候。 --}}
                            <div id="resultPageActions" hidden>
                                <button class="button secondary small" type="button" data-result-action="edit">编辑报告</button>
                                <button class="button small" type="button" data-result-action="download">下载正文</button>
                                <button class="button secondary small" id="readerFocus" type="button" aria-pressed="false">专注阅读</button>
                                <button class="button secondary small" id="readerOutlineToggle" type="button" hidden aria-expanded="true" aria-controls="readerOutline">收起目录</button>
                                <details class="reader-more" id="readerMore">
                                    <summary>更多操作</summary>
                                    <div class="reader-more-menu">
                                        <button type="button" data-result-action="copy">复制正文</button>
                                        <button type="button" data-result-action="manage">审核与发布</button>
                                    </div>
                                </details>
                            </div>
                            <a class="button secondary small" id="resultPageBack" href="#asset">返回历史报告</a>
                        </div>
                    </div>
                    <div class="callout" id="resultPageMessage"></div>
                    <div class="callout" id="resultPageSnapshotNote" data-variant="warning" hidden></div>
                    <div class="result-tabs" id="resultPageTabs" role="tablist" hidden></div>
                    <div class="reader-layout" id="readerLayout">
                        <article class="result-view reader-document" id="resultPageBody" aria-label="报告正文"></article>
                        <aside class="reader-outline" id="readerOutline" hidden>
                            <details id="readerOutlineDisclosure" open>
                                <summary>本页目录 <span id="readerSectionCount"></span></summary>
                                <nav id="readerToc" aria-label="报告章节目录"></nav>
                            </details>
                        </aside>
                    </div>
                    <section class="report-editor" id="reportEditor" hidden aria-label="编辑报告"></section>
                    <details class="report-detail-options" id="reportUseOptions" hidden>
                        <summary>使用这份报告</summary>
                        <div id="resultPageContinuation"></div>
                        <div class="asset-next-panel" id="assetNextPanel"></div>
                    </details>
                    <details class="report-detail-options" id="reportManagement" hidden>
                        <summary>审核与发布</summary>
                        <article class="result-view" id="assetResult"></article>
                    </details>
                </section>
