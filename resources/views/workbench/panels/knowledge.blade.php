<section class="panel knowledge-panel workspace-view ui-management-panel" id="knowledgePanel" data-workspace-view="knowledge" hidden>
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">配置</p>
                            <h2>企业知识库</h2>
                        </div>
                        <div class="panel-actions">
                            <div class="cl-seg-tabs kb-seg-tabs md-tabs" role="tablist" aria-label="企业知识库分段" id="kbSegmentTabs">
                                <button role="tab" aria-selected="true" data-kb-seg="bases" class="kb-seg-tab md-tab" type="button">知识库</button>
                                <button role="tab" aria-selected="false" data-kb-seg="enterprise" class="kb-seg-tab md-tab" type="button">企业知识档案</button>
                            </div>
                            <span class="spacer"></span>
                            <button class="button primary small" id="createKnowledgeBaseButton" type="button" data-kb-only="bases">新建知识库</button>
                            <button class="button secondary small" id="reloadKnowledgeBasesButton" type="button" data-kb-only="bases">刷新</button>
                            <button class="button primary small" id="ekNewButton" type="button" data-kb-only="enterprise" hidden>＋ 新建档案</button>
                            <button class="button secondary small" id="ekReloadButton" type="button" data-kb-only="enterprise" hidden>刷新</button>
                        </div>
                    </div>
                    <p class="muted">当前项目：<strong id="knowledgeProjectName"></strong>。这里的资料仅供本项目使用。</p>
                    <p class="muted">上传企业介绍、产品说明、常见问答或网页资料，供本项目的文章生成与相关技能查找参考；企业知识档案可帮助你整理资料，确认后再存入知识库。</p>
                    <p class="muted">入库用于言及工作台内的内容生成，不代表资料已被豆包、DeepSeek 等平台收录。</p>
                    <p class="muted" id="knowledgeMessage"></p>
                    <details id="knowledgeLegacy" hidden><summary class="muted">历史资料待分配项目</summary><p class="muted">未分配的资料暂不参与生成。请确认归属后再使用；已有项目归属的文档会保留在原项目。</p><div id="knowledgeLegacyList"></div></details>

                    <!-- 知识库 segment (A 段) -->
                    <div data-kb-segment="bases">
                    <div class="kb-stats" id="kbStats"></div>
                    <div class="kb-create-form" id="kbCreateForm" hidden>
                        <form class="form-grid" id="createKbForm">
                            <label class="span-2">
                                知识库名称
                                <input name="name" required maxlength="120" placeholder="例如：产品FAQ知识库">
                            </label>
                            <label class="span-2">
                                描述（可选）
                                <textarea name="description" rows="2" maxlength="500" placeholder="知识库用途说明"></textarea>
                            </label>
                            <div class="form-actions span-2">
                                <button class="button primary" type="submit">创建</button>
                                <button class="button secondary" type="button" id="cancelKbButton">取消</button>
                                <p class="form-message" id="kbCreateMessage"></p>
                            </div>
                        </form>
                    </div>
                    <div class="kb-list" id="kbList"></div>
                    <div class="kb-detail" id="kbDetail" hidden>
                        <div class="panel-heading compact-heading">
                            <div>
                                <h3 id="kbDetailTitle">知识库详情</h3>
                            </div>
                            <div class="panel-actions">
                                <button class="button secondary small" id="kbBackButton" type="button">返回列表</button>
                                <button class="button danger small" id="kbDeleteButton" type="button">删除知识库</button>
                            </div>
                        </div>
                        <p class="muted" id="kbDetailMessage"></p>
                        <div class="kb-upload-area">
                            <details class="ui-management-disclosure" name="kb-import-method" open><summary>上传文件</summary>
                            <form class="form-grid" id="kbUploadForm">
                                <label class="span-2">
                                    上传文档
                                    <input name="document" type="file" accept=".pdf,.txt,.md,.docx,.csv">
                                </label>
                                <label class="span-2" id="kbUploadProjectField" hidden>
                                    归属项目（解析费用记到该项目）
                                    <select name="project_id" id="kbUploadProjectSelect"></select>
                                </label>
                                <div class="form-actions span-2">
                                    <button class="button primary" type="submit">上传</button>
                                    <p class="form-message" id="kbUploadMessage"></p>
                                </div>
                            </form>
                            </details>
                            <details class="ui-management-disclosure" name="kb-import-method"><summary>导入网页</summary>
                            <form class="form-grid" id="kbIngestUrlForm">
                                <label class="span-2">
                                    网页地址
                                    <input name="url" type="url" maxlength="2000" placeholder="https://example.com/page">
                                </label>
                                <label class="span-2" id="kbIngestProjectField" hidden>
                                    归属项目
                                    <select name="project_id" id="kbIngestProjectSelect"></select>
                                </label>
                                <div class="form-actions span-2">
                                    <button class="button primary" type="submit">导入</button>
                                    <p class="form-message" id="kbIngestMessage"></p>
                                </div>
                            </form>
                            </details>
                        </div>
                        <div class="kb-documents" id="kbDocuments">
                            <div class="panel-heading compact-heading">
                                <div><h3>文档列表</h3></div>
                                <button class="button secondary small" id="reloadKbDocsButton" type="button">刷新</button>
                                <button class="button primary small" id="kbSearchTestButton" type="button">检索测试</button>
                            </div>
                            <div class="history-list" id="kbDocList"></div>
                        </div>
                    </div>
                    </div>

                    <!-- 企业知识档案 segment (B 段) -->
                    <div data-kb-segment="enterprise" hidden>
                        <p class="muted" id="ekMessage" hidden></p>
                        <div data-state-host id="ekHost">
                            <div data-state="populated">
                                <div class="cl-tbl-scroll">
                                    <table class="cl-tbl">
                                        <thead><tr><th>档案</th><th>来源</th><th>修订</th><th>状态</th><th class="cl-cell-actions">操作</th></tr></thead>
                                        <tbody id="ekRows"></tbody>
                                    </table>
                                </div>
                            </div>
                            <div data-state="loading" hidden>
                                <div class="cl-skeleton" role="status" aria-label="加载中"><div class="cl-sk w80"></div><div class="cl-sk w60"></div><div class="cl-sk w80"></div><div class="cl-sk w40"></div></div>
                            </div>
                            <div data-state="empty" hidden>
                                <div class="cl-state-tpl cl-state-empty">
                                    <div class="st-icon">📚</div>
                                    <h3>还没有企业知识档案</h3>
                                    <p>把产品手册、FAQ、内部规范等整理成一份可被 AI 引用的企业知识档案；可手动录入或粘贴来源，生成后发布到知识库。</p>
                                    <button class="button primary small" id="ekEmptyNewButton" type="button">＋ 新建档案</button>
                                </div>
                            </div>
                            <div data-state="error" hidden>
                                <div class="cl-state-tpl cl-state-error">
                                    <div class="st-icon">!</div>
                                    <h3>企业知识档案加载失败</h3>
                                    <p>服务暂时不可用，你的数据没有丢失。错误编号 <code id="ekErrorNo">—</code>，重试无效时可联系支持并附上编号。</p>
                                    <button class="button primary small" id="ekRetryButton" type="button">重试</button>
                                </div>
                            </div>
                        </div>

                        <div class="ek-create-form" id="ekCreateForm" hidden>
                            <form class="form-grid" id="ekCreateFormEl">
                                <label class="span-2">
                                    档案名称（可选，留空自动推断）
                                    <input name="name" maxlength="191" placeholder="例如：产品FAQ知识档案">
                                </label>
                                <label class="span-2">
                                    描述（可选）
                                    <textarea name="description" rows="2" maxlength="2000" placeholder="档案用途说明"></textarea>
                                </label>
                                <label class="span-2">
                                    草稿内容（与下方来源至少填一项）
                                    <textarea name="content" rows="6" placeholder="直接粘贴企业知识正文，或第一个来源的内容"></textarea>
                                </label>
                                <label class="span-2">
                                    首个来源（与草稿内容至少填一项）
                                    <textarea name="source_content" rows="3" placeholder="粘贴一段用于整理档案的来源内容"></textarea>
                                </label>
                                <div class="form-actions span-2">
                                    <button class="button primary" type="submit">创建档案</button>
                                    <button class="button secondary" type="button" id="ekCancelCreateButton">取消</button>
                                    <p class="form-message" id="ekCreateMessage"></p>
                                </div>
                            </form>
                        </div>

                        <div class="ek-detail" id="ekDetail" hidden>
                            <div class="panel-heading compact-heading">
                                <div>
                                    <h3 id="ekDetailTitle">企业知识档案</h3>
                                    <span class="badge" id="ekDetailStatus" data-variant="neutral">草稿</span>
                                </div>
                                <div class="panel-actions">
                                    <button class="button secondary small" id="ekBackButton" type="button">返回列表</button>
                                    <button class="button danger small" id="ekDeleteButton" type="button">删除档案</button>
                                </div>
                            </div>
                            <p class="muted" id="ekDetailMessage"></p>
                            <div class="ek-detail-grid">
                                <div class="ek-draft-col">
                                    <div class="col-title">草稿编辑 <span class="spacer"></span><span class="ek-saved" id="ekAutosaveStatus"></span></div>
                                    <textarea class="ek-editor" aria-label="企业知识草稿" aria-describedby="ekAutosaveStatus" id="ekDraftEditor" rows="14" placeholder="在此编辑企业知识草稿，输入会自动保存…"></textarea>
                                    <label>目标知识库
                                        <select id="ekTargetKnowledgeBase" aria-label="发布目标知识库"><option value="">正在加载知识库…</option></select>
                                    </label>
                                    <div class="ek-draft-actions">
                                        <button class="button secondary small" id="ekValidateButton" type="button">校验</button>
                                        <button class="button secondary small" id="ekGenerateButton" type="button">生成草稿</button>
                                        <button class="button primary small" id="ekPublishButton" type="button">发布到知识库</button>
                                        <button class="button ghost small" id="ekRevisionsButton" type="button">修订历史</button>
                                    </div>
                                    <div class="ek-published" id="ekPublishedInfo" hidden></div>
                                </div>
                                <div class="ek-validation-col">
                                    <div class="col-title">校验项</div>
                                    <div class="ek-validation" id="ekValidationPanel">
                                        <p class="muted">点击「校验」或保存后，这里会显示校验结果。</p>
                                    </div>
                                    <div class="col-title">来源</div>
                                    <div class="ek-sources" id="ekSourcesPanel">
                                        <p class="muted">还没有来源。</p>
                                    </div>
                                    <form class="form-grid ek-add-source" id="ekAddSourceForm">
                                        <label class="span-2">
                                            追加来源内容
                                            <textarea name="content" rows="3" placeholder="粘贴一段新的来源内容"></textarea>
                                        </label>
                                        <label class="span-2">
                                            来源名称（可选）
                                            <input name="original_name" maxlength="191" placeholder="例如：产品手册第3章">
                                        </label>
                                        <label class="span-2">
                                            来源网址（可选）
                                            <input name="source_url" type="url" maxlength="2000" placeholder="https://example.com/source">
                                        </label>
                                        <div class="form-actions span-2">
                                            <button class="button primary small" type="submit">追加来源</button>
                                            <p class="form-message" id="ekAddSourceMessage"></p>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
