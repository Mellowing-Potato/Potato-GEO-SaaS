<section class="workspace-view panel geo-account-panel" data-workspace-view="personal-settings" hidden>
                @if(\App\Support\PortableDesktopAccess::enabled())
                    <p class="hint">这是你的本机工作区，无需设置登录邮箱或密码。</p>
                @else
                    <form id="personalProfileForm"><label for="personalName">昵称</label><input id="personalName" required maxlength="80"><label for="personalEmail">登录邮箱</label><input id="personalEmail" type="email" required maxlength="254"><label for="profileCurrentPassword">当前密码（修改邮箱时必填）</label><input id="profileCurrentPassword" type="password" autocomplete="current-password" maxlength="128"><button class="button primary" id="savePersonalProfile">保存资料</button><p id="personalProfileMessage" role="status"></p></form>
                @endif
                <form id="workspaceNameForm">
                    <label for="workspaceName">工作区名称</label>
                    <input id="workspaceName" maxlength="80" placeholder="例如：示例文旅内容组" disabled>
                    <p class="hint" id="workspaceNameHelp">正在确认是否可以修改名称，请稍候。</p>
                    <button class="button primary" id="saveWorkspaceName" type="submit" disabled>保存工作区名称</button>
                    <p class="form-message" id="workspaceNameMessage" role="status"></p>
                </form>
            </section>
