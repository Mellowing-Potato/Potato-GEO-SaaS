<section class="panel categories-panel workspace-view ui-management-panel" id="categoriesPanel" data-workspace-view="categories" hidden>
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">内容</p>
                            <h2>文章分类</h2>
                        </div>
                        <div class="panel-actions">
                            <button class="button secondary small" id="catReloadButton" type="button">刷新</button>
                        </div>
                    </div>
                    <p class="muted">分类用于给当前账户下的文章归类，方便筛选与批量处理。同一账户内名称可重复，标识（slug）不可重复。</p>
                    <p class="muted" id="catMessage" role="status"></p>
                    <div class="panel-head"><h3>新增分类</h3></div>
                    <form class="form-grid" id="categoryForm">
                        <label for="categoryName">
                            <span>分类名称</span>
                            <input id="categoryName" name="name" maxlength="120" required placeholder="例如：行业解读">
                        </label>
                        <label for="categorySlug">
                            <span>标识（可选）</span>
                            <input id="categorySlug" name="slug" maxlength="180" placeholder="例如：industry-notes">
                            <span class="hint">用于链接与接口调用；留空由平台生成。</span>
                        </label>
                        <label class="span-2" for="categoryDescription">
                            <span>说明（可选）</span>
                            <textarea id="categoryDescription" name="description" rows="2" maxlength="2000" placeholder="这个分类收录什么内容"></textarea>
                        </label>
                        <label for="categorySortOrder">
                            <span>排序（可选）</span>
                            <input id="categorySortOrder" name="sort_order" type="number" step="1" placeholder="数字越小越靠前">
                        </label>
                        <div class="form-actions span-2">
                            <button class="button primary" type="submit" id="categorySubmitButton">添加分类</button>
                            <p class="form-message" id="categoryFormMessage"></p>
                        </div>
                    </form>
                    <div class="panel-head"><h3>已有分类</h3></div>
                    <div class="cl-tbl-scroll">
                        <table class="cl-tbl">
                            <thead><tr><th>分类</th><th>标识</th><th>文章数</th><th>排序</th><th class="cl-cell-actions">操作</th></tr></thead>
                            <tbody id="categoryRows"></tbody>
                        </table>
                    </div>
                </section>