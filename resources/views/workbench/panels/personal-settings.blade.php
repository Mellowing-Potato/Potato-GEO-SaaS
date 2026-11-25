<section class="workspace-view panel geo-account-panel" data-workspace-view="personal-settings" hidden>
                @if(\App\Support\PortableDesktopAccess::enabled())
                    <p class="hint">这是你的本机工作区，无需设置登录邮箱或密码。</p>
                @else
                    <form id="personalProfileForm"><label for="personalName">昵称</label><input id="personalName" required maxlength="80"><label for="personalEmail">登录邮箱</label><input id="personalEmail" type="email" required maxlength="254"><label for="profileCurrentPassword">当前密码（修改邮箱时必填）</label><input id="profileCurrentPassword" type="password" autocomplete="current-password" maxlength="128"><button class="button primary" id="savePersonalProfile">保存资料</button><p id="personalProfileMessage" role="status"></p></form>
                @endif
                {{--
                    工作区名称：后端 PATCH /api/v1/workspace 早就在（can:team:manage），
                    工作台一直没有入口 —— 客户只能让运维改库。这里按权限放出，
                    没有 team:manage 的账号看到的是只读说明，不发写请求。
                --}}
                <form id="workspaceNameForm">
                    <label for="workspaceName">工作区名称</label>
                    <input id="workspaceName" maxlength="80" placeholder="例如：示例文旅内容组">
                    <p class="hint" id="workspaceNameHelp">名称只影响本工作区内的显示名（页面标题、导出文件名），不改变成员、权限、数据归属，也不影响已经发布出去的内容。</p>
                    <button class="button primary" id="saveWorkspaceName" type="submit">保存工作区名称</button>
                    <p class="form-message" id="workspaceNameMessage" role="status"></p>
                </form>
                <h3>我的权限</h3>
                <p class="hint">下面这份清单由服务端实时返回（GET /api/v1/me/permissions），与接口层实际放行的口径同源；界面按钮能点不代表接口一定放行，最终以这里为准。</p>
                <p id="myPermissionsRole" role="status"></p>
                <p id="myPermissionsList"></p>
            </section>
