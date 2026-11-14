/* Load reviewed article content through the product's ordinary authenticated API. */
(function () {
  'use strict';

  function mount(options) {
    var article = options.article;
    var type = options.type;
    var content = options.content;
    var host = options.host;
    var generation = 0;
    var current = null;
    var busy = false;
    var lastApplied = '';
    var message = document.createElement('p');
    message.className = 'form-msg';
    message.setAttribute('role', 'status');
    message.setAttribute('aria-live', 'polite');
    var replace = document.createElement('button');
    replace.type = 'button';
    replace.className = 'md-btn md-btn--secondary md-btn--sm';
    replace.hidden = true;
    host.append(message, replace);

    function selectedId() { return Number(article.value) || 0; }
    function valid() { return type.value === 'post' && current && current.id === selectedId(); }
    function tell(text, error) {
      host.hidden = type.value !== 'post' || !selectedId();
      message.textContent = text;
      message.className = error ? 'form-msg err' : 'form-msg';
    }
    function apply() {
      if (!valid()) return;
      content.value = current.body_markdown;
      lastApplied = content.value;
      replace.hidden = true;
      options.onChange();
      tell('已带入文章 v' + current.version + ' 的正文，请核对后创建发布单。');
    }
    function reset() {
      generation++;
      current = null;
      busy = false;
      lastApplied = '';
      replace.hidden = true;
      host.hidden = true;
      message.textContent = '';
    }
    async function load() {
      var id = selectedId();
      var request = ++generation;
      current = null;
      busy = false;
      replace.hidden = true;
      if (type.value !== 'post' || !id) { host.hidden = true; return; }
      busy = true;
      tell('正在读取已审核文章正文…');
      try {
        var response = await options.apiGet('/api/v1/articles/' + id);
        if (request !== generation || type.value !== 'post' || selectedId() !== id) return;
        var data = response && (response.data || response);
        if (!data || Number(data.id) !== id || !['approved', 'published'].includes(data.status)
          || !Number.isInteger(data.version) || data.version < 1
          || typeof data.body_markdown !== 'string' || !data.body_markdown.trim()) {
          throw new Error('文章未审核通过、正文为空或版本无效，请重新核对文章。');
        }
        current = { id: id, version: data.version, body_markdown: data.body_markdown };
        if (!content.value.trim() || content.value === lastApplied) apply();
        else {
          replace.textContent = '用关联文章正文替换当前内容';
          replace.hidden = false;
          replace.disabled = false;
          tell('已读取文章 v' + current.version + '。当前正文已有修改，未自动覆盖；需要时可点击替换。');
        }
      } catch (error) {
        if (request !== generation || type.value !== 'post' || selectedId() !== id) return;
        current = null;
        replace.textContent = '重试读取文章正文';
        replace.hidden = false;
        replace.disabled = false;
        tell(error.message || '文章正文读取失败，请重试。', true);
      } finally {
        if (request === generation) busy = false;
      }
    }
    replace.addEventListener('click', function () { if (valid()) apply(); else return load(); });
    article.addEventListener('change', load);
    type.addEventListener('change', function () {
      if (type.value === 'post') load();
      else reset();
    });
    options.form.addEventListener('reset', reset);
    content.addEventListener('input', function () {
      if (!valid()) return;
      replace.textContent = '用关联文章正文替换当前内容';
      replace.hidden = content.value === current.body_markdown;
    });
    reset();
    return {
      versionForSubmission: function () {
        if (busy) throw new Error('正在读取关联文章正文，请稍候再创建。');
        if (!valid()) throw new Error('请重新选择已审核文章，并等待读取正文成功。');
        return current.version;
      }
    };
  }

  window.GEO_PUBLICATION_ARTICLE_SOURCE = { mount: mount };
})();
