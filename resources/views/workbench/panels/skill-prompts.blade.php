<section class="panel skill-prompts-panel workspace-view ui-management-panel" id="skillPromptsPanel" data-workspace-view="skill-prompts" hidden>
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">运营设置</p>
                            <h2>生成偏好</h2>
                        </div>
                        <div class="panel-actions">
                            <button class="button secondary small" id="spReloadButton" type="button">刷新</button>
                        </div>
                    </div>
                    <p class="muted">当前项目：<strong id="preferenceProjectName"></strong>。偏好只用于本项目，不影响其他项目。</p>
                    <p class="muted">按功能设置语气、结构、篇幅和特别要求。不设置也可以直接使用，平台会采用默认方式。</p>
                    <p class="muted">偏好从下次执行起生效，已有内容不变。平台的事实核实、引用和输出格式要求始终保留。</p>
                    <p class="muted" id="spMessage" role="status"></p>
                    <div class="sp-list" id="spList"></div>
                </section>