{{--
    ⚠️ 团队管理按产品口径**暂时关闭**（用户 2026-08-31 拍板，2026-09-22 复核时再次确认
    「是我故意暂时关闭的，前期不推出」）：
      · 侧栏入口 hidden，`#team` 深链接在 normalizeWorkspaceView 里折回默认视图
        （FROZEN_WORKSPACE_VIEWS，见 public/geo-entry.js 与 tests/js/team-menu-frozen.test.mjs）；
      · 后端 `POST /api/v1/team/invite`、`DELETE /api/v1/team/{user}`、
        `PATCH /api/v1/team/{user}/role` 已改成返回 410「当前产品使用独立工作区」；
      · 面板本体与 `GET /api/v1/team/` 保留不删 —— 手动发布的指派人下拉还在用它
        （删了会连带炸掉那条链路）。
    要开放时：恢复三条路由的实现 + 放开 FROZEN_WORKSPACE_VIEWS + 侧栏链接，
    并同步更新 team-menu-frozen 用例与本节说明。
--}}
<section class="panel team-panel workspace-view" id="teamPanel" data-workspace-view="team" hidden>
                    <p class="muted" id="teamFrozenNotice">团队管理在当前版本暂不开放：每个账号使用独立工作区，邀请成员与角色变更能力保留但未启用。</p>
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">运营设置</p>
                            <h2>团队管理</h2>
                        </div>
                        <div class="panel-actions">
                            <button class="button primary small" id="inviteTeamMemberButton" type="button">邀请成员</button>
                            <button class="button secondary small" id="reloadTeamButton" type="button">刷新</button>
                        </div>
                    </div>
                    <p class="muted" id="teamMessage">管理团队成员和角色权限。</p>
                    <div class="team-invite-form" id="teamInviteForm" hidden>
                        <form class="form-grid" id="inviteForm">
                            <label class="span-2">
                                手机号
                                <input name="phone" required maxlength="20" placeholder="请输入成员手机号">
                            </label>
                            <label>
                                角色
                                <select name="role">
                                    <option value="member">普通成员</option>
                                    <option value="admin">管理员</option>
                                    <option value="editor">编辑</option>
                                </select>
                            </label>
                            <div class="form-actions span-2">
                                <button class="button primary" type="submit">发送邀请</button>
                                <button class="button secondary" type="button" id="cancelInviteButton">取消</button>
                                <p class="form-message" id="inviteMessage"></p>
                            </div>
                        </form>
                    </div>
                    <div class="team-list" id="teamList"></div>
                </section>
