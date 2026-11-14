/* Shared preparation and explicitly human-confirmed drafts. No third-party login or publish API. */
(function () {
  'use strict';
  var targets = {
    wechat: ['微信公众号', 'https://mp.weixin.qq.com/'],
    xiaohongshu: ['小红书', 'https://creator.xiaohongshu.com/publish/publish?from=menu&target=article'],
    weibo: ['微博', 'https://card.weibo.com/article/v5/editor#/draft']
  };
  var titleLimits = { wechat: ['微信公众号', 64], weibo: ['微博头条文章', 32], xiaohongshu: ['小红书长文', 64] };

  function node(tag, text, className) {
    var result = document.createElement(tag);
    if (text != null) result.textContent = text;
    if (className) result.className = className;
    return result;
  }

  function button(text, action) {
    var result = node('button', text, 'md-btn md-btn--secondary md-btn--sm');
    result.type = 'button';
    result.addEventListener('click', action);
    return result;
  }

  function escaped(text) {
    return String(text).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function downloadHtml(payload) {
    var body = payload.html || '<pre>' + escaped(payload.plain_text) + '</pre>';
    var html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
      + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https: http: data:; style-src \'unsafe-inline\'">'
      + '<title>' + escaped(payload.title) + '</title></head><body>'
      + '<p>' + escaped(payload.platform_label + ' · 账号：' + payload.account_name) + '</p>'
      + '<p>核对账号与内容后保存草稿；本文件不代表已在平台保存或公开发布。</p>'
      + (payload.draft_storage_scope === 'browser_local' ? '<p>小红书长文草稿存储在当前浏览器本地，清理浏览器数据会删除。</p>' : '')
      + '<h1>' + escaped(payload.title) + '</h1><article>' + body + '</article></body></html>';
    var url = URL.createObjectURL(new Blob([html], { type: 'text/html;charset=utf-8' }));
    var link = node('a');
    link.href = url;
    link.download = (payload.title.replace(/[<>:"/\\|?*\u0000-\u001f]/g, '-').slice(0, 70) || '文章') + '-发布准备.html';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function mount(options) {
    var host = options.host;
    var publication = options.publication;
    var target = targets[publication.platform];
    if (!host || !target || publication.type !== 'post') return;
    var active = true;
    var prepared = null;
    var inFlight = null;
    var generation = 0;
    var message = node('p', '', 'form-msg');
    message.setAttribute('role', 'status');
    message.setAttribute('aria-live', 'polite');
    var body = node('div', null, 'v');
    host.append(node('div', target[0] + '发布准备', 'k'), body);

    function connected() { return active && host.isConnected; }
    function tell(text, error) {
      if (!connected()) return;
      message.textContent = text;
      message.className = error ? 'form-msg err' : 'form-msg';
    }

    var receipt = publication.draft_receipt;
    if (receipt && receipt.method === 'manual_confirmation') {
      var stale = receipt.content_fingerprint !== publication.content_fingerprint
        || receipt.account_id !== publication.account_id
        || receipt.article_version !== publication.source_snapshot?.article_version
        || receipt.article_version !== publication.article?.version
        || !['approved', 'published'].includes(publication.article?.status)
        || receipt.account_name !== publication.account?.account_name
        || receipt.account_profile_url !== publication.account?.profile_url
        || receipt.persona_id !== publication.account?.persona_id
        || !publication.account?.is_active || !publication.persona?.is_active;
      body.append(node('p', (stale ? '原人工确认已失效，请重新准备并核对。' : '人工确认草稿：' + receipt.draft_label)
        + (receipt.publication_title ? ' · 发布标题：' + receipt.publication_title : '')
        + (receipt.storage_scope === 'browser_local' ? ' · 当前浏览器本地草稿' : '')
        + (receipt.confirmed_at ? ' · ' + new Date(receipt.confirmed_at).toLocaleString('zh-CN', { hour12: false }) : ''), 'hint'));
    }
    if (!options.canTransition || !['ready', 'in_progress'].includes(publication.status)
      || publication.risk_status === 'blocked') return;

    var prepareButton = button('准备发布内容', prepare);
    var controls = node('div', null, 'form-actions');
    var detail = node('div');
    detail.hidden = true;
    var preview = node('p', '', 'hint');
    var confirmation = node('form', null, 'run-form');
    confirmation.hidden = true;
    var titleInput = node('input');
    titleInput.type = 'text';
    titleInput.maxLength = 500;
    titleInput.value = (receipt && !stale && receipt.publication_title) || publication.source_snapshot?.title || '';
    var titleWrap = node('label', null, 'fld fld-wide');
    var titleCount = node('p', '', 'hint');
    titleWrap.append(node('span', '本渠道发布标题', 'fld-label'), titleInput);
    function countTitle() {
      var limit = titleLimits[publication.platform];
      titleCount.textContent = '当前 ' + titleInput.value.length + ' 个字符；'
        + (limit ? limit[0] + '最多 ' + limit[1] + ' 个字符（表情可能计作 2 个）。' : '请核对目标平台的标题要求。')
        + '这里的修改不影响原文章标题或正文。';
    }
    titleInput.addEventListener('input', function () {
      generation++;
      prepared = null;
      inFlight = null;
      detail.hidden = true;
      confirmation.hidden = true;
      checks.forEach(function (input) { input.checked = false; });
      label.value = '';
      prepareButton.disabled = false;
      countTitle();
      tell('发布标题已修改，请重新准备。');
    });
    countTitle();
    var label = node('input');
    label.type = 'text';
    label.maxLength = 120;
    label.required = true;
    label.placeholder = '例如：草稿标题、草稿箱中的日期或位置';
    var labelWrap = node('label', null, 'fld fld-wide');
    labelWrap.append(node('span', '草稿标题或位置（不要填写后台登录网址）', 'fld-label'), label);
    confirmation.append(labelWrap);
    var checks = ['已核对目标平台登录账号', '已核对标题、正文、图片和链接', '已在目标平台保存草稿'].map(function (text) {
      var wrap = node('label', null, 'platform-draft-check');
      var input = node('input');
      input.type = 'checkbox';
      input.required = true;
      wrap.append(input, node('span', text));
      confirmation.append(wrap);
      return input;
    });
    var confirmButton = node('button', '记录人工确认草稿', 'md-btn md-btn--secondary md-btn--sm');
    confirmButton.type = 'submit';
    confirmation.append(confirmButton);
    confirmation.addEventListener('submit', confirm);

    controls.append(button('复制标题', function () { copy('title'); }));
    controls.append(button(publication.platform === 'wechat' ? '复制公众号排版正文' : '复制正文', function () {
      copy(publication.platform === 'wechat' ? 'html' : 'plain_text');
    }));
    controls.append(button('下载发布准备包', async function () {
      try {
        var payload = await fresh();
        if (connected()) downloadHtml(payload);
        tell('已下载发布准备包，请核对后在目标平台保存草稿。');
      } catch (error) { tell(error.message || '下载失败，请重新准备。', true); }
    }));
    var editor = node('a', '打开' + target[0], 'md-btn md-btn--secondary md-btn--sm');
    editor.href = target[1];
    editor.target = '_blank';
    editor.rel = 'noopener noreferrer';
    controls.append(editor);
    detail.append(preview, controls);
    if (publication.status === 'in_progress') detail.append(confirmation);
    else detail.append(node('p', '内容核对好后，将发布单推进到进行中，再登记人工确认草稿。', 'hint'));
    if (publication.platform !== 'weibo') body.append(node('p', '准备内容不会自动保存到外部平台。请粘贴、检查并保存草稿；正式发布由你在平台完成。', 'hint'));
    if (publication.platform === 'xiaohongshu') body.append(node('p', '小红书长文草稿存储在当前浏览器本地，清理浏览器数据会删除。请在草稿位置注明浏览器。', 'hint'));
    body.append(titleWrap, titleCount);
    if (publication.platform === 'weibo' && window.GEO_WEIBO_DRAFTS) {
      window.GEO_WEIBO_DRAFTS.mount({ host: body, titleInput: titleInput, publication: publication,
        canTransition: options.canTransition, apiPost: options.apiPost, apiGet: options.apiGet, onTitleSet: countTitle });
    }
    if (publication.platform === 'weibo') body.append(node('p', '人工备用：以下准备、复制与人工确认不会自动发送。', 'hint'));
    body.append(prepareButton, detail, message);

    function fresh() {
      if (inFlight) return inFlight;
      var requestedTitle = titleInput.value.trim();
      if (!requestedTitle) return Promise.reject(new Error('请先填写本渠道发布标题。'));
      var request = ++generation;
      inFlight = options.apiPost('/api/v1/manual-publications/' + publication.id + '/draft-preparation', {
        revision: publication.revision, publication_title: requestedTitle
      }).then(function (response) {
        var data = response.data;
        if (!connected() || request !== generation) throw new Error('页面已切换，请重新准备。');
        if (!data || data.platform !== publication.platform || data.publication_id !== publication.id
          || data.revision !== publication.revision || !data.preparation_token || !data.title || !data.plain_text
          || data.title !== requestedTitle || data.automatic_remote_save !== false) throw new Error('发布内容未准备完整，请重新准备。');
        prepared = data;
        detail.hidden = false;
        confirmation.hidden = false;
        preview.textContent = '目标账号：' + data.account_name + ' · 文章 v' + data.article_version
          + (data.image_count ? ' · ' + data.image_count + ' 张图片，请在平台检查上传结果' : '');
        return data;
      }).catch(function (error) {
        if (request === generation) {
          prepared = null;
          confirmation.hidden = true;
        }
        throw error;
      }).finally(function () { if (request === generation) inFlight = null; });
      return inFlight;
    }

    async function prepare() {
      prepareButton.disabled = true;
      var operation = fresh();
      var request = generation;
      try { await operation; if (request === generation) tell('发布内容已准备好。复制后请到目标平台粘贴并保存。'); }
      catch (error) { if (request === generation) { prepared = null; detail.hidden = true; tell(error.message || '准备失败。', true); } }
      finally { if (connected() && request === generation) prepareButton.disabled = false; }
    }

    function copy(kind) {
      // Each copy rechecks the reviewed version on the server. Start the ClipboardItem
      // write during the click gesture; its Blob promise waits for that verification.
      var dataPromise = fresh();
      var request = generation;
      tell('正在核对并复制，请稍候…');
      var html = kind === 'html';
      var plain = dataPromise.then(function (data) {
        return new Blob([kind === 'title' ? data.title : data.plain_text], { type: 'text/plain' });
      });
      plain.catch(function () {});
      try {
        if (!navigator.clipboard) throw new Error('此浏览器不能复制，请下载发布准备包。');
        var operation;
        if (!html && navigator.clipboard.writeText) {
          // Keep text-only copies on the standard text path; reserve ClipboardItem
          // for rich HTML and preserve its click-gesture behavior below.
          operation = dataPromise.then(function (data) { return navigator.clipboard.writeText(kind === 'title' ? data.title : data.plain_text); });
        } else if (typeof ClipboardItem === 'function' && navigator.clipboard.write) {
          var items = { 'text/plain': plain };
          if (html) {
            var rich = dataPromise.then(function (data) {
              if (!data.html) throw new Error('公众号排版内容缺失，请重新准备。');
              return new Blob([data.html], { type: 'text/html' });
            });
            rich.catch(function () {});
            items['text/html'] = rich;
          }
          operation = navigator.clipboard.write([new ClipboardItem(items)]);
        } else {
          if (html) throw new Error('此浏览器不能复制富文本，请下载发布准备包。');
          operation = dataPromise.then(function (data) { return navigator.clipboard.writeText(kind === 'title' ? data.title : data.plain_text); });
        }
        Promise.all([operation, dataPromise]).then(function () {
          if (request === generation) tell('已复制' + (html ? '公众号排版正文' : kind === 'title' ? '标题' : '正文') + '，请到目标平台粘贴。');
        }).catch(function (error) { if (request === generation) tell(error.message || '复制失败，请下载发布准备包。', true); });
      } catch (error) {
        dataPromise.catch(function () {});
        plain.catch(function () {});
        tell(error.message || '复制失败，请下载发布准备包。', true);
      }
    }

    async function confirm(event) {
      event.preventDefault();
      if (!prepared || !checks.every(function (input) { return input.checked; }) || !label.value.trim()) {
        tell('请先准备内容，再填写草稿位置并逐项核对。', true);
        return;
      }
      confirmButton.disabled = true;
      titleInput.disabled = true;
      try {
        await options.apiPost('/api/v1/manual-publications/' + publication.id + '/platform-draft', {
          revision: publication.revision, preparation_token: prepared.preparation_token,
          draft_label: label.value.trim(), account_checked: true, content_checked: true, saved: true
        });
        if (!connected()) return;
        active = false;
        options.onChanged();
      } catch (error) { tell(error.message || '草稿确认失败，请重新准备并核对。', true); }
      finally { if (connected()) { confirmButton.disabled = false; titleInput.disabled = false; } }
    }
  }

  window.GEO_PUBLICATION_DRAFTS = { mount: mount };
})();
