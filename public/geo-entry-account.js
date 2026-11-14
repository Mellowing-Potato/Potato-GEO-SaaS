/* ============================================================================
   GEO 工作台 · 账户注销申请（geo-entry-account.js）

   计划 §9.5「账户/项目删除的隐私生命周期」里账户那一半；口径由用户 2026-09-21 拍板：
   「账户可以注销，但是要提交申请注销，15 天以内再次登陆就等于恢复账户」。

   为什么单独一个文件：注销不是"再来一个表单"，它有一整套状态要交代 ——
   申请后回到登录页（全部设备退出）、冷静期倒计时、期内登录即恢复、逾期清除身份信息。
   把这套逻辑塞回 1.4 万行的主文件只会又制造一个维护热点（计划 §7 的红线），
   所以按仓库既有先例：主文件暴露 window.GEO，本文件注册自己的视图加载器 + 绑定表单。

   红线：
     - 唯一 API 是 GET/POST /api/v1/account/deletion（路径、字段名与后端逐字对齐）；
     - 二次确认两关（确认弹窗 + 逐字文案；设过密码的账号后端还会验密码），一次误点走不到注销；
     - 出错如实说（读不到状态就撤下表单），不假装成功、不假装"已恢复"。

   判据：tests/Feature/AccountDeletionRequestTest.php（服务端口径）+
   tests/js/account-deletion-client.test.mjs（前端契约：文案与字段名不许漂）+
   scripts/snapshot-ui-dom.mjs（#account-security 的 DOM/API 指纹）。
   ============================================================================ */
(() => {
  const GEO = window.GEO;
  if (!GEO) {
    console.error('[geo-entry-account] window.GEO 未就绪：请确认本文件在 geo-entry.js 之后加载');
    return;
  }

  const { api, setMessage, validationMessage, withBusyButton } = GEO;

  let deletionBusy = false;

  // 不叫 el()：那个短名字已经被别的拆出块用了，跨文件同名会让 §7 的作用域护栏判成裸引用。
  const deletionEl = (id) => document.getElementById(id);

  // ═══════════════════════════════════════════════
  // 状态：读注销申请状态，渲染"未申请 / 冷静期中 / 读不到"三种页面
  // ═══════════════════════════════════════════════

  /**
   * 读注销状态：pending 时显示倒计时，否则显示可提交的表单。
   *
   * 拿不到状态时**不显示表单**：注销入口宁可暂时不可用，也不能让客户填完才失败
   *（他会以为已经提交了）。
   */
  async function loadAccountDeletion() {
    const form = deletionEl('geoDeletionForm');
    const pending = deletionEl('geoDeletionPending');
    const unavailable = deletionEl('geoDeletionUnavailable');
    const badge = deletionEl('geoDeletionStatus');
    if (!form || !pending || !badge) return true;

    try {
      const body = await api('/api/v1/account/deletion');
      const data = body.data || {};
      // 冷静期天数只认服务端给的数：前端写死一个 15，改口径那天就会两处不一致。
      const grace = Number(data.grace_days) || 0;
      const graceDays = deletionEl('geoDeletionGraceDays');
      if (graceDays && grace > 0) graceDays.textContent = String(grace);

      if (data.pending) {
        badge.textContent = '注销申请中';
        badge.className = 'md-badge md-badge--warning';
        form.hidden = true;
        pending.hidden = false;
        unavailable.hidden = true;
        setMessage(
          deletionEl('geoDeletionPendingMessage'),
          `已提交注销申请，还有 ${Number(data.days_left) || 0} 天生效（${formatDateTime(data.purge_at)} 起）。现在用本账号重新登录一次即可恢复账户。`,
          'warning',
        );
      } else {
        badge.textContent = '未申请';
        badge.className = 'md-badge md-badge--neutral';
        form.hidden = false;
        pending.hidden = true;
        unavailable.hidden = true;
      }
    } catch (error) {
      badge.textContent = '读取失败';
      badge.className = 'md-badge md-badge--neutral';
      form.hidden = true;
      pending.hidden = true;
      if (unavailable) unavailable.hidden = false;
      setMessage(deletionEl('geoDeletionMessage'), validationMessage(error) || '注销状态读取失败，请稍后重试。', 'danger');
    }

    return true;
  }

  // ═══════════════════════════════════════════════
  // 提交：确认弹窗 + 逐字文案（+ 设过密码的账号验密码），失败如实说
  // ═══════════════════════════════════════════════

  async function submitDeletion(event) {
    event.preventDefault();
    if (deletionBusy) return;

    const confirmInput = deletionEl('geoDeletionConfirm');
    const passwordInput = deletionEl('geoDeletionPassword');
    const message = deletionEl('geoDeletionMessage');

    // 密码框常显、留空即不发送：只有"设过密码的账号"后端才会拿它做校验。
    // 不做"先查有没有密码再决定显示哪个框"是因为那要多打一次 profile 接口，
    // 而判断权本来就在后端（前端猜错了反而更难懂）。
    const password = passwordInput?.value || '';

    // 前端先拦一道：文案不对就别打扰后端（后端照样会再校验一遍，两处都要在）。
    if ((confirmInput?.value || '').trim() !== '注销账户') {
      setMessage(message, '请逐字输入「注销账户」四个字确认。', 'danger');
      confirmInput?.focus();
      return;
    }

    const confirmed = await GEO.confirmWorkspaceAction?.(
      `提交注销申请吗？\n\n提交后所有设备都会退出登录；${graceDays()} 天以内重新登录一次即可恢复账户，逾期将清除身份信息，无法恢复。`,
    );
    if (confirmed === false) return;

    deletionBusy = true;
    await withBusyButton(deletionEl('geoDeletionSubmit'), '提交中…', async () => {
      setMessage(message, '正在提交注销申请…');
      try {
        const payload = { confirm_text: '注销账户' };
        if (password !== '') payload.password = password;

        const body = await api('/api/v1/account/deletion', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });

        // 后端这一步已经把这个账号的全部登录撤销了；所以这里回读状态，
        // 页面自然切到"注销申请中"三态里的冷静期视图（不假装还登录着）。
        setMessage(message, body.message || '注销申请已提交。', 'success');
        if (confirmInput) confirmInput.value = '';
        if (passwordInput) passwordInput.value = '';
        await loadAccountDeletion();
        GEO.toast?.('注销申请已提交，已退出登录。冷静期内重新登录即可恢复账户。', 'warning');
      } catch (error) {
        setMessage(message, validationMessage(error) || '提交失败，请稍后重试。', 'danger');
      } finally {
        deletionBusy = false;
      }
    });
  }

  // ═══════════════════════════════════════════════
  // 注册与绑定
  // ═══════════════════════════════════════════════

  /**
   * 「本次登录撤销了注销申请」的落地提示（计划 §9.5）。
   *
   * 两条登录路径（登录页 /api/v1/account/login、工作台自愈与启动时的
   * /api/v1/auth/session-login）都在响应里带 `account_restored`，各自把事实写进
   * sessionStorage.geo_account_restored；这里读一次、提示一次、然后清掉 ——
   * 不清就会出现"刷新一下又弹一遍"，那是假的"刚刚恢复"。
   */
  const RESTORED_NOTICE_KEY = 'geo_account_restored';

  function consumeAccountRestoredNotice() {
    let stamp = null;
    try {
      stamp = sessionStorage.getItem(RESTORED_NOTICE_KEY);
      sessionStorage.removeItem(RESTORED_NOTICE_KEY);
    } catch {
      return;
    }
    if (!stamp) return;

    GEO.toast?.('注销申请已撤销，账户已恢复。', 'success');
    const pendingMessage = deletionEl('geoDeletionPendingMessage');
    if (pendingMessage) {
      setMessage(pendingMessage, '账户已恢复：刚才的注销申请被本次登录撤销了，账号与内容都还在。', 'success');
    }
  }

  /** 当前页面上的冷静期天数（读 DOM，与用户看到的那个数字一定是同一个）。 */
  function graceDays() {
    return Number(deletionEl('geoDeletionGraceDays')?.textContent) || 15;
  }

  function formatDateTime(value) {
    const date = new Date(value);
    if (!value || !Number.isFinite(date.getTime())) return '时间暂不可用';

    return new Intl.DateTimeFormat('zh-CN', {
      year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false,
    }).format(date);
  }

  deletionEl('geoDeletionForm')?.addEventListener('submit', submitDeletion);

  // 进"账号安全"这一页就回读注销状态：冷静期状态必须每次进页面都是新的，
  // 在内存里缓存一份会出现"已经恢复了还显示申请中"这种自相矛盾的页面。
  GEO.registerViewLoader('account-security', loadAccountDeletion);

  // 进工作台就查一次"刚恢复过"。放在加载器注册之外：客户登录后落地的可能是任意一屏
  // （项目、报告…），提示不能等他点进账号安全页才出现。
  consumeAccountRestoredNotice();

  Object.assign(GEO, { loadAccountDeletion });
})();
