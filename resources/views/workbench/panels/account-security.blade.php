<section class="workspace-view account-security md-app" id="accountSecurityPanel" data-workspace-view="account-security" hidden>
                <section class="md-panel account-card" aria-labelledby="passwordCardTitle">
                    <div class="account-card-heading"><div><h3 id="passwordCardTitle">登录密码</h3><p>使用邮箱和密码登录工作台。</p></div><span class="md-badge md-badge--neutral" id="geoPasswordStatus">正在读取</span></div>
                    <form class="account-password-form" id="geoPasswordForm" hidden>
                        <p class="account-password-hint" id="geoPasswordHint"></p>
                        <div class="md-field" id="geoCurrentPasswordField"><label class="md-field-label" for="geoCurrentPassword">当前密码</label><div class="account-password-input"><input class="md-input" id="geoCurrentPassword" type="password" autocomplete="current-password" placeholder="请输入当前密码" required><button class="account-password-visibility" type="button" data-password-toggle="geoCurrentPassword" aria-controls="geoCurrentPassword" aria-label="显示当前密码" aria-pressed="false" title="显示当前密码"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="account-eye-slash" d="m3 3 18 18"/></svg></button></div></div>
                        <div class="md-field"><label class="md-field-label" for="geoNewPassword">新密码</label><div class="account-password-input"><input class="md-input" id="geoNewPassword" type="password" autocomplete="new-password" placeholder="至少 8 位" minlength="8" maxlength="128" required><button class="account-password-visibility" type="button" data-password-toggle="geoNewPassword" aria-controls="geoNewPassword" aria-label="显示新密码" aria-pressed="false" title="显示新密码"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="account-eye-slash" d="m3 3 18 18"/></svg></button></div></div>
                        <div class="md-field"><label class="md-field-label" for="geoConfirmPassword">确认新密码</label><div class="account-password-input"><input class="md-input" id="geoConfirmPassword" type="password" autocomplete="new-password" placeholder="再次输入新密码" minlength="8" maxlength="128" required><button class="account-password-visibility" type="button" data-password-toggle="geoConfirmPassword" aria-controls="geoConfirmPassword" aria-label="显示确认新密码" aria-pressed="false" title="显示确认新密码"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="account-eye-slash" d="m3 3 18 18"/></svg></button></div></div>
                        <div class="account-form-actions"><button class="md-btn md-btn--primary" id="saveGeoPassword" type="submit">设置密码</button><button class="account-text-link" id="toggleGeoPasswordReset" type="button" hidden>忘记密码？</button></div>
                    </form>
                    <p class="account-message" id="geoPasswordMessage" role="status" aria-live="polite"></p>
                </section>
                <section class="md-panel account-card" aria-labelledby="sessionCardTitle">
                    <div class="account-card-heading"><div><h3 id="sessionCardTitle">登录管理</h3><p id="geoSessionSummary">查看当前账号的有效登录记录。</p></div><button type="button" class="md-btn md-btn--secondary" id="revokeGeoSessions" hidden>退出其他登录</button></div>
                    <div class="account-session-list" id="geoSessions" aria-live="polite"><p class="account-muted">正在读取登录记录…</p></div>
                    <p class="account-footnote">登录有效期结束后需重新登录，不影响账号。时间按当前设备时区显示。</p>
                    <p class="account-message" id="geoSecurityMessage" role="status" aria-live="polite"></p>
                </section>
                {{-- 账户注销（计划 §9.5；用户口径 2026-09-21：「账户可以注销，但是要提交申请注销，
                     15 天以内再次登陆就等于恢复账户」）。所以这里**不是**一个"立即删除"按钮：
                     提交后进入冷静期、全部设备退出登录，重新登录一次就自动恢复。
                     到期清理的对应命令是 geo:account-purge（服务端每天跑）。 --}}
                <section class="md-panel account-card" aria-labelledby="deletionCardTitle">
                    <div class="account-card-heading"><div><h3 id="deletionCardTitle">注销账户</h3><p>注销需要先提交申请；<b>15 天以内重新登录即自动恢复账户</b>。</p></div><span class="md-badge md-badge--neutral" id="geoDeletionStatus">正在读取</span></div>
                    <div class="account-deletion-pending" id="geoDeletionPending" hidden>
                        <p class="account-message" id="geoDeletionPendingMessage" role="status" aria-live="polite"></p>
                        <p class="account-footnote">这期间只要用本账号登录一次，申请就会自动撤销、账户恢复原样。逾期未登录将清除身份信息，无法恢复。</p>
                    </div>
                    <form class="account-deletion-form" id="geoDeletionForm" hidden>
                        <p class="account-footnote">提交后会发生三件事：① 全部设备退出登录；② 进入 <b id="geoDeletionGraceDays">15</b> 天冷静期，其间登录一次即恢复；③ 期满清除手机号、邮箱与登录密码等身份信息，并清空这个工作区的项目内容（调用记录按保留规则处理）。</p>
                        <div class="md-field" id="geoDeletionPasswordField">
                            <label class="md-field-label" for="geoDeletionPassword">当前登录密码</label>
                            <input class="md-input" id="geoDeletionPassword" type="password" autocomplete="current-password" placeholder="设过密码的账号请填写" aria-describedby="geoDeletionPasswordHelp">
                            <div class="md-field-hint" id="geoDeletionPasswordHelp">设过登录密码的账号必填；请填写本地账号的当前密码。</div>
                        </div>
                        <div class="md-field">
                            <label class="md-field-label" for="geoDeletionConfirm">请输入「注销账户」四个字确认</label>
                            <input class="md-input" id="geoDeletionConfirm" required autocomplete="off" placeholder="注销账户" aria-describedby="geoDeletionConfirmHelp">
                            <div class="md-field-hint" id="geoDeletionConfirmHelp">必须逐字填写，避免误触。</div>
                        </div>
                        <div class="account-form-actions"><button class="md-btn md-btn--danger" id="geoDeletionSubmit" type="submit">提交注销申请</button></div>
                        <p class="account-message" id="geoDeletionMessage" role="status" aria-live="polite"></p>
                    </form>
                    <p class="account-footnote" id="geoDeletionUnavailable" hidden>注销入口暂时不可用，请稍后重试或联系支持。</p>
                </section>
            </section>
