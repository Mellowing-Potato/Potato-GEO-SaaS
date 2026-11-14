<section class="materials-panel workspace-view md-app" id="materialsPanel" data-workspace-view="materials" hidden>
                    <div class="view-title">
                        <h1>素材</h1>
                        <span class="vt-sub">管理标题、关键词、图片与作者，供内容生成时选用。</span>
                        <span class="spacer"></span>
                        <button class="md-btn md-btn--ghost" id="matGenerateButton" type="button" hidden>批量生成标题</button>
                        <button class="md-btn md-btn--primary" id="matAddButton" type="button">＋ 新增</button>
                    </div>
                    <div class="md-panel mat-form-panel" id="matFormPanel" hidden>
                        <div class="md-panel-head"><h2 id="matFormTitle">新增素材</h2><div class="spacer"></div><button class="md-btn md-btn--ghost md-btn--sm" id="matCancelFormButton" type="button">收起</button></div>
                        <div class="md-panel-body">
                        <form class="form-sheet" id="matForm" novalidate></form>
                        </div>
                    </div>

                    <div class="md-panel mat-form-panel" id="matGeneratePanel" hidden>
                        <div class="md-panel-head"><h2>批量生成标题</h2><div class="spacer"></div><button class="md-btn md-btn--ghost md-btn--sm" id="matCancelGenerateButton" type="button">收起</button></div>
                        <div class="md-panel-body">
                        <form class="form-sheet" id="matGenerateForm" novalidate>
                            <div class="frow">
                                <label for="materialGenerate-keywords">种子关键词 <span class="req">*</span></label>
                                <div class="fwrap"><textarea aria-describedby="materialGenerate-keywords-help" id="materialGenerate-keywords" name="keywords" rows="3" required placeholder="每行一个关键词，例如：&#10;SaaS 协作&#10;远程办公"></textarea><div class="hint" id="materialGenerate-keywords-help">基于这些关键词让 AI 生成标题，生成结果直接写入标题库。</div></div>
                            </div>
                            <div class="frow">
                                <label for="materialGenerate-count">数量</label>
                                <div class="fwrap"><input aria-describedby="materialGenerate-count-help" id="materialGenerate-count" name="count" type="number" min="1" max="50" value="5"><div class="hint" id="materialGenerate-count-help">1–50，默认 5。</div></div>
                            </div>
                            <div class="frow">
                                <label for="materialGenerate-style">风格</label>
                                <div class="fwrap">
                                    <select id="materialGenerate-style" name="style">
                                        <option value="professional" selected>专业</option>
                                        <option value="attractive">吸引人</option>
                                        <option value="seo">SEO</option>
                                        <option value="creative">创意</option>
                                        <option value="question">疑问</option>
                                    </select>
                                </div>
                            </div>
                            <div class="frow">
                                <label for="materialGenerate-category">分类</label>
                                <div class="fwrap"><input id="materialGenerate-category" name="category" maxlength="120" placeholder="可选，统一打标"></div>
                            </div>
                            <div class="frow">
                                <div class="fwrap"><button class="button primary" type="submit" id="matGenerateSubmit">生成并写入标题库</button><span id="matGenerateError" class="at-form-error" role="alert"></span></div>
                            </div>
                        </form>
                        </div>
                    </div>

                    <div class="md-panel">
                    <div class="md-panel-head mat-panel-head">
                        <div class="mat-seg-tabs md-tabs" role="tablist" aria-label="素材类型" id="matTabs">
                            <button class="md-tab" role="tab" aria-selected="true" data-mat-tab="title">标题库</button>
                            <button class="md-tab" role="tab" aria-selected="false" data-mat-tab="keyword">关键词库</button>
                            <button class="md-tab" role="tab" aria-selected="false" data-mat-tab="image">图片库</button>
                            <button class="md-tab" role="tab" aria-selected="false" data-mat-tab="author">作者库</button>
                        </div>
                    </div>
                    <div class="callout" id="matMessage"></div>
                    <div data-state-host id="matHost">
                        <div data-state="populated">
                            <div class="md-tablewrap">
                                <table class="md-table">
                                    <thead id="matHead"></thead>
                                    <tbody id="matRows"></tbody>
                                </table>
                            </div>
                        </div>
                        <div data-state="loading" hidden>
                            <div class="md-skeleton" role="status" aria-label="加载中"><div class="md-sk md-sk--w80"></div><div class="md-sk md-sk--w60"></div><div class="md-sk md-sk--w80"></div><div class="md-sk md-sk--w40"></div></div>
                        </div>
                        <div data-state="empty" hidden>
                            <div class="md-empty">
                                <div class="em-icon">✎</div>
                                <div class="md-empty-title">素材还是空的</div>
                                <p>点右上角「＋ 新增」把素材写进来；标题库还能用「批量生成标题」从关键词 AI 生成。</p>
                            </div>
                        </div>
                        <div data-state="error" hidden>
                            <div class="md-empty">
                                <div class="em-icon">!</div>
                                <div class="md-empty-title">素材加载失败</div>
                                <p>服务暂时不可用，你的素材数据没有丢失。错误编号 <code id="matErrorNo">—</code>，重试无效时可联系支持并附上编号。</p>
                                <button class="md-btn md-btn--secondary md-btn--sm" id="matRetryButton" type="button">重试</button>
                            </div>
                        </div>
                    </div>
                    </div>
                </section>