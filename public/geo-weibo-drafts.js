(function () {
  'use strict';
  var channel = 'yanji-weibo-draft';
  var errors = {
    wrong_account: '微博登录账号与发布单不同，请切换账号后重试。',
    login_required: '请在新开的微博文章编辑器登录发布单账号，再回言及重试。',
    image_transfer_failed: '正文图片转存失败，已停止创建草稿，请检查图片地址。',
    readback_mismatch: '微博草稿已打开，但标题、正文、链接或图片尚未核对一致，请检查草稿后重新核对。',
    already_running: '这张发布单已有任务运行，请等待当前任务结果。',
    receipt_not_confirmed: '言及未能确认发送步骤，已停止后续写入，请刷新核对已有草稿。',
    platform_rejected: '微博未接受请求，请查看登录状态和平台提示；请先核对已有草稿，勿重复创建。',
    editor_closed: '微博编辑器已关闭，请重新核对发送记录。',
    connection_interrupted: '连接中断，请核对已有草稿；系统不会自动重复创建。'
  };
  function el(tag, text) { var n = document.createElement(tag); if (text != null) n.textContent = text; return n; }
  function mount(o) {
    var box = el('div'); box.className = 'detail-block';
    var heading = el('p', '一键发送到微博草稿箱'); heading.className = 'k';
    var status = el('p', '正在读取微博发送记录…'); status.className = 'hint';
    var message = el('p'); message.className = 'form-msg'; message.setAttribute('role', 'status');
    var link = el('a', '打开微博草稿'); link.hidden = true; link.target = '_blank'; link.rel = 'noopener noreferrer';
    var send = el('button', '发送到微博草稿箱'); send.type = 'button'; send.className = 'md-btn md-btn--primary md-btn--sm'; send.disabled = true;
    var hint = el('p', '需要 Chrome 中的言及发布草稿助手 0.3.1 或更新版本，并登录目标微博账号。仅保存草稿，封面与正式发布请在微博核对完成。'); hint.className = 'hint';
    box.append(heading, status, link, send, hint, message); o.host.append(box);
    var delivery = null, stale = false, busy = false, stop = null;
    var path = '/api/v1/manual-publications/' + o.publication.id + '/weibo-draft';
    function connected() { return box.isConnected; }
    function tell(text, error) { if (!connected()) return; message.textContent = text; message.className = error ? 'form-msg err' : 'form-msg'; }
    function render(d, isStale) {
      delivery = d; stale = isStale;
      var labels = { prepared: '等待助手接收', creating: '已开始创建，结果待确认', created: '已创建草稿，尚未确认内容保存',
        saving: '正在保存内容', verifying: '正在重新打开核对', saved: '助手已保存并回读核对', unknown: '结果待核对，请勿重复创建', failed: '创建前已停止，可重试' };
      status.textContent = d ? (labels[d.status] || '待核对') + ' · ' + d.publication_title + ' · 文章 v' + d.article_version
        + (isStale ? ' · 历史记录：文章、发布单或账号已变化' : '') + (d.verified_at ? ' · 最近核对：' + new Date(d.verified_at).toLocaleString('zh-CN', { hour12: false }) : '')
        : '尚无自动发送记录；已有人工草稿会保留，本次将创建一份新草稿。';
      var valid = d && /^https:\/\/card\.weibo\.com\/article\/v5\/editor#\/draft\/\d+$/.test(d.draft_url || '');
      link.hidden = !valid; if (valid) link.href = d.draft_url;
      send.textContent = valid ? '重新核对已有微博草稿' : '发送到微博草稿箱';
      send.disabled = busy || !!isStale || !o.canTransition || o.publication.status !== 'in_progress'
        || o.publication.risk_status === 'blocked' || (!!d && !valid && !['prepared', 'failed'].includes(d.status));
      if (d) {
        o.titleInput.value = d.publication_title;
        o.titleInput.disabled = true;
        if (o.onTitleSet) o.onTitleSet();
      }
    }
    async function refresh() {
      try { var r = await o.apiGet(path); if (connected()) render(r.data.delivery, r.data.stale); }
      catch (error) { tell(error.message || '发送记录读取失败，请刷新。', true); }
    }
    function post(data) { window.postMessage(Object.assign({ channel: channel }, data), location.origin); }
    function probe() {
      return new Promise(function (resolve, reject) {
        var id = crypto.randomUUID();
        var timer = setTimeout(function () { window.removeEventListener('message', listener); reject(new Error('未检测到新版草稿助手，请从本页使用说明下载更新，安装后刷新言及页面。')); }, 1200);
        function listener(e) {
          if (e.source !== window || e.origin !== location.origin || e.data?.channel !== channel || e.data.type !== 'PROBE_RESULT' || e.data.requestId !== id) return;
          clearTimeout(timer); window.removeEventListener('message', listener);
          var v = String(e.data.version || '').split('.').map(Number);
          if (v.length >= 3 && (v[0] > 0 || (v[0] === 0 && (v[1] > 3 || (v[1] === 3 && v[2] >= 1))))) resolve();
          else reject(new Error('草稿助手版本过旧，请更新到 0.3.1 或更新版本。'));
        }
        window.addEventListener('message', listener); post({ type: 'PROBE', requestId: id });
      });
    }
    send.addEventListener('click', async function () {
      if (busy || stale || !connected()) return;
      busy = true; send.disabled = true; o.titleInput.disabled = true; tell('正在检测草稿助手…');
      try {
        await probe();
        if (!connected()) return;
        var started = await o.apiPost(path + '/start', { revision: o.publication.revision, publication_title: o.titleInput.value.trim() });
        if (!connected()) return;
        render(started.data.delivery, false);
        var requestId = crypto.randomUUID();
        await new Promise(function (resolve) {
          var timer = setTimeout(function () { cleanup(); tell('等待结果超时，请刷新查看已保存的草稿编号；不会自动重复发送。', true); resolve(); }, 590000);
          function cleanup() { clearTimeout(timer); window.removeEventListener('message', listener); stop = null; }
          stop = cleanup;
          async function listener(e) {
            if (e.source !== window || e.origin !== location.origin || e.data?.channel !== channel || e.data.requestId !== requestId) return;
            var m = e.data;
            if (m.type === 'STAGE') {
              if (!connected()) { post({ type: 'STAGE_ACK', stageId: m.stageId, ok: false }); return; }
              try {
                // The server binds every ACK to this delivery, actor, account and reviewed version.
                if (m.data?.delivery_id !== started.data.payload.deliveryId || m.data?.token !== started.data.request_token) throw new Error('发送凭据不一致。');
                var r = await o.apiPost(path + '/progress', m.data);
                if (connected()) render(r.data, false);
                post({ type: 'STAGE_ACK', stageId: m.stageId, ok: true });
              } catch (error) { tell(error.message || '发送记录未确认，已停止写入。', true); post({ type: 'STAGE_ACK', stageId: m.stageId, ok: false }); }
            }
            if (m.type === 'RESULT') {
              cleanup();
              tell(m.ok ? '微博草稿已自动保存并重新打开核对。尚未公开发布。' : (errors[m.code] || '发送未完成，请刷新发送记录并核对已有草稿。'), !m.ok);
              resolve();
            }
          }
          window.addEventListener('message', listener);
          post({ type: 'START', requestId: requestId, payload: started.data.payload });
          tell(started.data.payload.mode === 'verify' ? '正在打开已有微博草稿核对…' : '正在打开微博核对账号并发送草稿…');
        });
      } catch (error) { tell(error.message || '发送未能开始。', true); }
      finally { busy = false; if (connected()) { await refresh(); if (!delivery) o.titleInput.disabled = false; } else if (stop) stop(); }
    });
    refresh();
  }
  window.GEO_WEIBO_DRAFTS = { mount: mount };
})();
