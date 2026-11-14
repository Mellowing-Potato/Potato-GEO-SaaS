/* Official WeChat connection and draft delivery. Public publishing stays in WeChat. */
(function () {
  'use strict';
  function node(tag, text, cls) {
    var el = document.createElement(tag);
    if (text != null) el.textContent = text;
    if (cls) el.className = cls;
    return el;
  }
  function field(text, type, value) {
    var label = node('label', null, 'fld fld-wide');
    var input = node('input');
    input.type = type;
    input.value = value || '';
    label.append(node('span', text, 'fld-label'), input);
    return { label: label, input: input };
  }
  function button(text, action) {
    var el = node('button', text, 'btn mini');
    el.type = 'button';
    el.addEventListener('click', action);
    return el;
  }
  function check(text) {
    var f = field(text, 'checkbox');
    f.label.className = 'platform-draft-check';
    f.input.required = true;
    return f;
  }
  function message() {
    var el = node('p', '', 'form-msg');
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    return el;
  }
  function tell(el, text, error) { el.textContent = text; el.className = error ? 'form-msg err' : 'form-msg'; }

  async function mountConnection(options) {
    var host = options.host;
    host.replaceChildren();
    var account = options.account;
    var msg = message();
    var form = node('form', null, 'run-form');
    host.append(node('h3', '连接公众号 · ' + account.account_name), msg, form);
    msg.textContent = '正在读取连接状态…';
    var connection;
    try {
      connection = (await options.apiGet('/api/v1/manual-publications/settings/accounts/' + account.id + '/wechat-connection', true)).data;
      if (!host.isConnected) return;
    } catch (error) { tell(msg, error.message || '连接读取失败，请重试。', true); return; }
    var appId = field('公众号 AppID', 'text', connection?.app_id);
    appId.input.required = true;
    appId.input.maxLength = 32;
    appId.input.autocomplete = 'off';
    appId.input.readOnly = !!connection;
    var secret = field(connection ? 'AppSecret（重新验证时填写，不回显已存密钥）' : '公众号 AppSecret', 'password');
    secret.input.required = true;
    secret.input.maxLength = 32;
    secret.input.autocomplete = 'new-password';
    var author = field('默认作者（可选，最多 16 字）', 'text', connection?.author);
    author.input.maxLength = 16;
    var checked = check('已在微信开发者平台核对：这个 AppID 属于“' + account.account_name + '”');
    var submit = node('button', '验证并保存公众号连接', 'btn primary mini');
    submit.type = 'submit';
    form.append(node('p', '在微信开发者平台的公众号开发信息中查看 AppID / AppSecret。填写现有密钥即可；若已遗失，重置会使其他工具的旧密钥失效。密钥加密保存，只在言及服务器与微信之间使用。', 'hint'),
      appId.label, secret.label, author.label, checked.label,
      node('p', '若微信提示 IP 白名单，按提示添加言及服务器出口 IP。只发送草稿，无需改公众号菜单或推送服务器地址。', 'hint'), submit);
    tell(msg, connection ? '接口凭据已验证 · AppID ' + connection.app_id + '；账号名称由管理员核对。' : '尚未连接公众号。');
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      if (!checked.input.checked || !secret.input.value) return;
      submit.disabled = true;
      tell(msg, '正在验证公众号接口…');
      var value = secret.input.value;
      secret.input.value = '';
      try {
        var result = await options.apiPost('/api/v1/manual-publications/settings/accounts/' + account.id + '/wechat-connection', {
          app_id: appId.input.value.trim(), app_secret: value, author: author.input.value.trim(), account_checked: true
        }, true);
        value = '';
        appId.input.readOnly = true;
        checked.input.checked = false;
        tell(msg, result.message || '连接已验证保存。');
      } catch (error) { value = ''; tell(msg, error.message || '验证失败，请重试。', true); }
      finally { submit.disabled = false; }
    });
  }

  async function mount(options) {
    var p = options.publication;
    var host = options.host;
    if (!host || p.platform !== 'wechat' || p.type !== 'post') return;
    var body = node('div', null, 'v');
    var msg = message();
    var status = node('div');
    host.append(node('div', '发送到公众号草稿箱', 'k'), body);
    body.append(node('p', '通过微信官方接口上传封面、转存正文图片并创建草稿，再读取草稿核对标题与内容。正式发表仍在公众号完成。', 'hint'), status, button('刷新发送状态', refresh), msg);
    var busy = false;
    var pollTimer = null;
    var pollCount = 0;
    var readGeneration = 0;
    var active = function () { return host.isConnected; };
    var base = '/api/v1/manual-publications/' + p.id + '/wechat-draft';
    function render(result) {
      status.replaceChildren();
      var delivery = result.data;
      var connection = result.connection;
      if (delivery) {
        var labels = { queued: '已排队，等待发送', preparing: '正在上传封面与正文图片', sending: '正在发送，暂勿重复操作', saved: '已保存并读回核对公众号草稿', unverified: '微信已返回草稿编号，等待内容核对', unknown: '发送结果待确认，请勿重复发送', failed: '发送未完成，未创建草稿' };
        status.append(node('p', labels[delivery.status] || '请刷新任务状态'), node('p', '发送标题：' + delivery.publication_title + ' · AppID ' + delivery.app_id, 'hint'));
        if (delivery.media_id) status.append(node('p', '微信草稿编号：' + delivery.media_id, 'hint'));
        if (delivery.content_fingerprint !== p.content_fingerprint || delivery.account_id !== p.account_id
          || delivery.article_version !== p.source_snapshot?.article_version || delivery.article_version !== p.article?.version) {
          status.append(node('p', '这是历史版本或原账号的草稿回执，当前发布单已有变更，请核对。', 'form-msg err'));
        }
        if (delivery.last_error) status.append(node('p', delivery.last_error, 'form-msg err'));
        if (options.canTransition && ['unknown', 'unverified', 'saved'].includes(delivery.status)) {
          status.append(button('核对微信草稿结果', function () { mutate('/check'); }));
        }
        if (options.canTransition && delivery.status === 'failed') status.append(button('重试同一草稿任务', function () { mutate('/retry'); }));
        status.append(node('p', '草稿回执不代表公开发布成功；请在公众号草稿箱预览后决定是否发表。', 'hint'));
        if (['queued', 'preparing', 'sending'].includes(delivery.status) && pollCount < 60 && typeof window.setTimeout === 'function') {
          pollCount++;
          pollTimer = window.setTimeout(refresh, 5000);
        }
        return;
      }
      if (!connection) {
        status.append(node('p', '尚未连接公众号，请由工作区管理员在人设与账号中点击“连接公众号”。', 'hint'));
        var link = node('a', '前往人设与账号', 'btn mini');
        link.href = '#sec-settings';
        status.append(link);
        return;
      }
      status.append(node('p', '接口凭据已验证 · ' + p.account?.account_name + ' · AppID ' + connection.app_id, 'hint'));
      if (!options.canTransition || p.status !== 'in_progress' || p.risk_status === 'blocked' || p.draft_receipt) {
        status.append(node('p', p.draft_receipt ? '本发布单已有草稿记录，请先核对已有草稿，避免重复创建。' : '将发布单推进到进行中后，可以发送草稿。', 'hint'));
        return;
      }
      var form = node('form', null, 'run-form');
      var title = field('本次发送标题（微信草稿接口最多 32 个字符）', 'text', p.source_snapshot?.title);
      title.input.required = true;
      title.input.maxLength = 64;
      var count = node('p', '', 'hint');
      var countTitle = function () { count.textContent = title.input.value.length + '/32 个字符；原文章标题保持不变。'; };
      title.input.addEventListener('input', countTitle);
      countTitle();
      var cover = field('封面图片（JPG/PNG，不超过 2MB）', 'file');
      cover.input.accept = 'image/jpeg,image/png';
      cover.input.required = true;
      var checked = check('已核对 AppID 和公众号账号，标题与正文可作为草稿发送');
      var create = check('确认新建一篇草稿；公众号中已有的同名草稿不会覆盖');
      var send = node('button', '发送到公众号草稿箱', 'btn primary mini');
      send.type = 'submit';
      form.append(title.label, count, cover.label, checked.label, create.label, send);
      status.append(form);
      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy) return;
        if (!checked.input.checked || !create.input.checked || !cover.input.files?.[0]) return;
        if (!title.input.value.trim() || title.input.value.trim().length > 32) { tell(msg, '请将本次发送标题调整到 32 个字符以内。', true); return; }
        var data = new FormData();
        data.append('revision', String(p.revision));
        data.append('publication_title', title.input.value.trim());
        data.append('cover', cover.input.files[0]);
        data.append('account_checked', '1');
        data.append('create_new_draft', '1');
        busy = true;
        send.disabled = true;
        tell(msg, '正在创建草稿发送任务…');
        try {
          await options.apiForm(base, data);
          if (active()) { tell(msg, '任务已提交，请刷新发送状态查看微信回执。'); await refresh(); }
        } catch (error) {
          if (active()) tell(msg, (error.message || '请求未完成。') + ' 请先刷新发送状态，确认有无任务后再操作。', true);
        } finally { busy = false; send.disabled = false; }
      });
    }
    async function refresh() {
      if (!active()) return;
      if (pollTimer) { window.clearTimeout(pollTimer); pollTimer = null; }
      var generation = ++readGeneration;
      try {
        var result = await options.apiGet(base);
        if (active() && generation === readGeneration) render(result);
      } catch (error) { if (active()) tell(msg, error.message || '状态读取失败，请重试。', true); }
    }
    async function mutate(suffix) {
      if (busy) return;
      busy = true;
      tell(msg, suffix === '/check' ? '正在读取微信草稿核对…' : '正在重试任务…');
      try {
        await options.apiPost(base + suffix, {});
        if (active()) { tell(msg, '状态已更新。'); await refresh(); }
      } catch (error) { if (active()) tell(msg, error.message || '操作未完成，请刷新状态。', true); }
      finally { busy = false; }
    }
    await refresh();
  }
  window.GEO_WECHAT_DRAFTS = { mount: mount, mountConnection: mountConnection };
})();
