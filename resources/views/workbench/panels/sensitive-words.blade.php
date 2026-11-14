<section class="panel sensitive-words-panel workspace-view ui-management-panel" id="sensitiveWordsPanel" data-workspace-view="sensitive-words" hidden>
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">运营设置</p>
                            <h2>用词规则</h2>
                        </div>
                        <div class="panel-actions">
                            <button class="button secondary small" id="swReloadButton" type="button">刷新</button>
                        </div>
                    </div>
                    <p class="muted">设置你希望提醒修改或禁止使用的词。规则由你设置，平台在内容检查时自动执行，适用于当前账户下的所有项目。</p>
                    <p class="muted">平台统一规则同时生效，不能在这里关闭或覆盖。下方仅管理你自己添加的规则。</p>
                    <p class="muted" id="swMessage" role="status"></p>
                    <div class="sw-add-form" id="swAddForm">
                        <form class="form-grid" id="swAddFormEl">
                            <label class="span-2">
                                需要检查的词（多个词可用换行、逗号、分号或顿号分隔）
                                <textarea name="words" rows="2" placeholder="例如：旧品牌名、停用产品名"></textarea>
                            </label>
                            <label>
                                处理方式（本次添加的词统一使用）
                                <select name="severity">
                                    <option value="warning">提醒修改 — 提示复核，允许发布</option>
                                    <option value="blocked">禁止发布 — 修改后才能通过发布检查</option>
                                </select>
                            </label>
                            <label>
                                修改建议（可选，发现这些词时显示）
                                <input type="text" name="suggestion" maxlength="500" placeholder="例如：请改用最新的品牌或产品名称">
                            </label>
                            <div class="form-actions span-2">
                                <button class="button primary" type="submit">添加规则</button>
                                <p class="form-message" id="swAddMessage"></p>
                            </div>
                        </form>
                    </div>
                    <p class="muted">添加后，内容中出现这些词时会按所选方式处理。再次添加同一个词，可更新处理方式和修改建议。</p>
                    <div class="sw-list" id="swList"></div>
                </section>