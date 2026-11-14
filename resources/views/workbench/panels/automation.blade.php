<section class="panel automation-panel workspace-view ui-management-panel" id="automationTasksPanel" data-workspace-view="automation" hidden>
                    <div class="view-title">
                        <h1>自动化任务</h1>
                        <span class="vt-sub">到点自动生成内容，可选自动发布与自动分发。</span>
                        <span class="spacer"></span>
                        <button class="button primary small" id="atNewTaskButton" type="button">＋ 新建任务</button>
                    </div>
                    <div class="callout" id="atMessage"></div>

                    <div class="panel automation-list-panel">
                        <div class="panel-head"><h2>任务列表</h2><div class="spacer"></div><span class="hint">时区 Asia/Shanghai</span></div>
                        <div data-state-host id="atHost">
                            <div data-state="populated">
                                <div class="cl-tbl-scroll">
                                    <table class="cl-tbl">
                                        <thead><tr><th>任务</th><th>节奏</th><th>发布链路</th><th>状态</th><th class="cl-cell-actions">操作</th></tr></thead>
                                        <tbody id="atRows"></tbody>
                                    </table>
                                </div>
                            </div>
                            <div data-state="loading" hidden>
                                <div class="cl-skeleton" role="status" aria-label="加载中"><div class="cl-sk w80"></div><div class="cl-sk w60"></div><div class="cl-sk w80"></div><div class="cl-sk w40"></div></div>
                            </div>
                            <div data-state="empty" hidden>
                                <div class="cl-state-tpl cl-state-empty">
                                    <div class="st-icon">⚙</div>
                                    <h3>还没有自动化任务</h3>
                                    <p>建立一条任务，绑定技能、项目与节奏，到点自动生成内容并可选发布分发。点右上角「＋ 新建任务」开始。</p>
                                </div>
                            </div>
                            <div data-state="error" hidden>
                                <div class="cl-state-tpl cl-state-error">
                                    <div class="st-icon">!</div>
                                    <h3>任务列表加载失败</h3>
                                    <p>服务暂时不可用，你的任务数据没有丢失。错误编号 <code id="atErrorNo">—</code>，重试无效时可联系支持并附上编号。</p>
                                    <button class="button primary small" id="atRetryButton" type="button">重试</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel automation-form-panel" id="atFormPanel" hidden>
                        <div class="panel-head"><h2>新建任务</h2><div class="spacer"></div><button class="button ghost small" id="atCancelFormButton" type="button">收起</button></div>
                        <form class="form-sheet" id="atForm" novalidate>
                            <div class="fs-body">
                                <div class="frow">
                                    <label for="automation-name">任务名称 <span class="req">*</span></label>
                                    <div class="fwrap"><input id="automation-name" aria-describedby="automation-name-help" name="name" required maxlength="191" placeholder="例如：每周问答文章"><div class="hint" id="automation-name-help">给这条自动化任务起个名字。</div></div>
                                </div>
                                <div class="frow">
                                    <label for="automation-skill_id">技能 <span class="req">*</span></label>
                                    <div class="fwrap">
                                        <select id="automation-skill_id" aria-describedby="automation-skill_id-help" name="skill_id" required><option value="">请选择技能…</option></select>
                                        <div class="hint" id="automation-skill_id-help">该任务到点执行的技能。</div>
                                    </div>
                                </div>
                                <div class="frow">
                                    <label for="automation-project_id_display">所属项目 <span class="req">*</span></label>
                                    <div class="fwrap"><input id="automation-project_id_display" aria-describedby="automation-project_id_display-help" name="project_id_display" readonly disabled><div class="hint" id="automation-project_id_display-help">当前选中的项目；切换项目用左上角项目卡。</div></div>
                                </div>
                                {{-- 技能动态输入（2026-09-21 工作台功能补齐，计划 §5）。
                                     选定技能后按该技能的 brief_fields 生成输入项，随任务一起存进 input_data
                                     （后端 TaskController::store 接受 input_data 数组，编排时作为简报字段）。
                                     留空的行不提交，避免用空值覆盖技能默认值。 --}}
                                <div class="frow" id="atDynamicFieldRow" hidden>
                                    <span class="ui-field-label" id="automationDynamicLabel">技能输入</span>
                                    <div class="fwrap">
                                        <div class="form-grid" id="atDynamicFields" aria-labelledby="automationDynamicLabel"></div>
                                        <div class="hint" id="automationDynamicHelp">按所选技能的输入要求填写；留空时使用技能默认值。</div>
                                    </div>
                                </div>
                                <div class="frow">
                                    <label for="automation-cadence">节奏</label>
                                    <div class="fwrap">
                                        <select id="automation-cadence" aria-describedby="automation-cadence-help" name="cadence">
                                            <option value="daily" selected>每天</option>
                                            <option value="hourly">每小时</option>
                                            <option value="weekly">每周</option>
                                            <option value="monthly">每月</option>
                                        </select>
                                        <div class="hint" id="automation-cadence-help">默认每天执行一次。</div>
                                    </div>
                                </div>
                                <div class="frow">
                                    <label for="automation-query">主题 / 查询词</label>
                                    <div class="fwrap"><textarea id="automation-query" name="query" rows="2" placeholder="可选；留空则由技能从素材取主题"></textarea></div>
                                </div>
                                <div class="frow">
                                    <span class="ui-field-label" id="automationDeliveryLabel">发布链路</span>
                                    <div class="fwrap at-flags" role="group" aria-labelledby="automationDeliveryLabel" aria-describedby="automationDeliveryHelp">
                                        <label class="at-check"><input type="checkbox" name="auto_publish"> 自动发布</label>
                                        <label class="at-check"><input type="checkbox" name="auto_distribute" checked> 自动分发</label>
                                        <div class="hint" id="automationDeliveryHelp">自动分发默认开启，可按需要关闭。</div>
                                    </div>
                                </div>
                                <div class="frow">
                                    <div class="fwrap"><button class="button primary" type="submit" id="atSubmitButton">创建任务</button><span id="atFormError" class="at-form-error" role="alert"></span></div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="panel automation-runs-panel">
                        <div class="panel-head"><h2>最近运行</h2><div class="spacer"></div><span class="hint">生成 → 发布 → 分发，每步结果单独记录</span></div>
                        <div data-state-host id="atRunsHost">
                            <div data-state="populated">
                                <div class="cl-tbl-scroll">
                                    <table class="cl-tbl">
                                        <thead><tr><th>任务</th><th>结果</th><th>产出</th><th>Tokens</th><th>时间</th></tr></thead>
                                        <tbody id="atRunsRows"></tbody>
                                    </table>
                                </div>
                            </div>
                            <div data-state="loading" hidden>
                                <div class="cl-skeleton" role="status" aria-label="加载中"><div class="cl-sk w80"></div><div class="cl-sk w60"></div><div class="cl-sk w40"></div></div>
                            </div>
                            <div data-state="empty" hidden>
                                <div class="cl-state-tpl cl-state-empty">
                                    <div class="st-icon">✓</div>
                                    <h3>还没有运行记录</h3>
                                    <p>任务到点执行或你点「立即运行」后，真实运行流水会出现在这里。</p>
                                </div>
                            </div>
                            <div data-state="placeholder" hidden>
                                <div class="cl-state-tpl cl-state-todo">
                                    <div class="st-icon">⏳</div>
                                    <h3>运行记录暂不可用</h3>
                                    <p>当前服务暂不支持汇总运行记录。功能可用后，这里将显示各任务的运行结果。</p>
                                </div>
                            </div>
                            <div data-state="error" hidden>
                                <div class="cl-state-tpl cl-state-error">
                                    <div class="st-icon">!</div>
                                    <h3>运行记录加载失败</h3>
                                    <p>错误编号 <code id="atRunsErrorNo">—</code>。重试无效时可联系支持。</p>
                                    <button class="button primary small" id="atRunsRetryButton" type="button">重试</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>