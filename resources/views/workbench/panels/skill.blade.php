<section class="skill-panel workspace-view" id="skillPanel" data-workspace-view="skill" hidden>
                    <button class="format-link skill-context-back" id="skillContextBackLink" type="button" hidden>← 返回文章列表</button>
                    <div class="skill-workspace-tabs md-tabs" id="skillWorkspaceTabs" role="tablist" aria-label="诊断工作方式">
                        <button class="md-tab" type="button" id="skillDiagnosisTab" role="tab" aria-controls="skillDiagnosisBlock" aria-selected="true" data-skill-workspace-mode="diagnosis">综合诊断</button>
                        <button class="md-tab" type="button" id="skillToolsTab" role="tab" aria-controls="skillToolsPanel" aria-selected="false" tabindex="-1" data-skill-workspace-mode="tools">专项功能</button>
                    </div>
                    <div class="callout" id="skillMessage">请选择一个项目后再执行技能。</div>
                    {{-- 2026-09-04 拆版式 · 步骤 1：「AI 辅助建议」整块搬去项目管理页的
                         「项目档案」列（见 #projectDetail）。两个「填入项目…」按钮走的是
                         PATCH /api/v1/projects/{id}，写的就是项目设置里的 target_keywords /
                         competitors —— 它是建项目时用一次的东西，不是诊断动作，却在这一页
                         占了 21% 的高度。别再搬回来。 --}}
                    {{-- 2026-09-05：这里原来是「推荐动线」四张编号卡（#skillFlowPanel），整块删除。
                         它是前端写死的四步（意图 → 全景 → 路线 → 落地），跟后端六阶段是两套
                         进度模型；第 2 张卡的按钮还只会滚屏、不会执行。进度只由六阶段讲一次。 --}}
                    <div class="skill-diagnosis-block md-panel" id="skillDiagnosisBlock" role="tabpanel" aria-labelledby="skillDiagnosisTab">
                        <div class="md-panel-head">
                            <h3>综合诊断</h3>
                            <button class="md-link" id="openAllAnalysesButton" type="button">全部诊断记录 →</button>
                            <button class="button secondary small" id="runAnalysisPanelButton" type="button">执行综合诊断</button>
                        </div>
                        <div class="md-panel-body">
                            <div class="callout" id="analysisMessage">请选择一个项目，查看分析历史。</div>
                            <article id="analysisResult">
                                <p class="muted">执行或选择一次分析后，报告会显示在这里。</p>
                            </article>
                        </div>
                    </div>
                    {{-- 2026-09-04 拆版式 · 步骤 0：未完成执行窄条。
                         下面那个「技能执行记录」整列要撤到历史报告页，但**归档只在成功时发生**
                         （SkillExecutionController.php:186 的 createOrLinkForSkillExecution 写在
                         `if ($result->success)` 里，GeoAnalysisController.php:309/:404 的
                         attachReportAsset 同样只挂在两条成功分支末尾）——articles 表里只有跑成功的，
                         失败 / 正在跑 / 被取消的执行在历史报告页根本不存在。
                         而「取消」按钮此前也**只长在那个列表的行上**，删了列表 = 删了正在跑的技能
                         执行的唯一取消入口。
                         ⇒ 先给未完成的执行留下这条落脚点，再删列表。
                         ⚠️ 一条都没有时整条隐藏（走查批第 7 条：闲着的框要收起来），
                            所以这里是空的 div，内容全由 renderSkillPendingStrip() 写。 --}}
                    <div class="pending-strip" id="skillPendingStrip" hidden></div>
                    {{-- 2026-09-04 走查补：这一区改前**一个标题都没有** —— 筛选 chip 和一列
                         技能卡凭空出现在综合诊断下面，没有任何东西说明这是什么。
                         眉标 / h3 的分工跟同页另外三区一致（综合诊断·项目 GEO 综合诊断、
                         历史·分析记录、历史·技能执行记录）。「刷新技能」从页顶搬到这里。 --}}
                    {{-- id 是给综合诊断卡里那个「选技能继续执行」当滚动落点用的（geo-entry.js
                         的 scrollToSkillCatalog）。落在标题上而不是 #skillFilters，
                         否则「单项技能」四个字会正好被顶出视口，人到了也不知道到了哪。 --}}
                    <div id="skillToolsPanel" role="tabpanel" aria-labelledby="skillToolsTab" hidden>
                    <div class="panel-heading compact-heading" id="skillCatalogHeading">
                        <div>
                            <p class="eyebrow">技能</p>
                            <h3>单项技能</h3>
                        </div>
                        {{-- 2026-09-04 拆版式 · 步骤 2：「技能执行记录」列表撤到历史报告页。
                             同上，绝不能带 data-view-target。 --}}
                        <div class="heading-actions">
                            <button class="md-link" id="openAllSkillReportsButton" type="button">全部技能报告 →</button>
                            <button class="button secondary small" id="reloadSkillsButton" type="button">刷新技能</button>
                        </div>
                    </div>
                    <div class="skill-filters" id="skillFilters"></div>
                    <div class="skill-layout">
                        <div class="skill-catalog">
                            <div class="skill-list" id="skillList"></div>
                        </div>
                        <div class="skill-runner">
                            <div class="skill-brief-panel md-panel" id="skillBriefPanel">
                                <p class="muted">请选择一个技能，填写执行说明。</p>
                            </div>
                            <div class="skill-result-panel md-panel" id="skillExecutionResult">
                                <p class="muted">执行结果会显示在这里。</p>
                            </div>
                        </div>
                    </div>
                    </div>
                    {{-- 2026-09-04 拆版式 · 步骤 2：这里原来是「历史 · 技能执行记录」整块，
                         已撤到历史报告页（来源筛选选「技能报告」）。跑成功的在那边，
                         没跑完 / 失败 / 被取消的在上面的「未完成执行」窄条里。
                         ⚠️ loadSkillExecutions() 那次请求**必须留着**：上面的「未完成执行」窄条
                            （renderSkillPendingStrip）和右侧「最新结果」都直接读
                            state.skillExecutions，删了请求两处都会空。2026-09-05 之前这里
                            写的理由是「推荐动线要用」，推荐动线已删，理由换成这两处。 --}}
                </section>