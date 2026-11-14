(() => {
  const GEO = window.GEO;
  if (!GEO) return;
  const { api, escapeHtml, formatNumber, formatDate, setMessage } = GEO;
  const message = document.getElementById('usageMessage');
  const usageSummaryNode = document.getElementById('usageSummary');
  const calls = document.getElementById('usageCalls');
  const period = document.getElementById('usagePeriod');
  const previous = document.getElementById('usagePrevious');
  const next = document.getElementById('usageNext');
  const pageLabel = document.getElementById('usagePage');
  let request = 0;
  let page = 1;
  async function loadUsage() {
    const current = ++request;
    previous.disabled = next.disabled = true;
    setMessage(message, '正在读取用量…');
    try {
      const data = await api(`/api/v1/usage?granularity=${encodeURIComponent(period.value)}&page=${page}`);
      if (current !== request) return;
      usageSummaryNode.innerHTML = [['调用次数', data.summary.total_calls], ['已知 Tokens', data.summary.total_tokens], ['未提供用量的调用', data.summary.unknown_usage_calls || 0]]
        .map(([label, value]) => `<div class="usage-card"><span>${label}</span><strong>${formatNumber(value)}</strong></div>`).join('');
      const records = data.records;
      const statuses = { completed: '成功', failed: '失败', unknown: '待核对（结果未知）', pending: '待核对' };
      calls.innerHTML = records.data.length ? `<div class="md-tablewrap"><table class="md-table"><thead><tr><th>项目</th><th>供应商 / 模型</th><th>状态</th><th>Tokens</th><th>耗时（秒）</th><th>时间</th></tr></thead><tbody>${records.data.map(row =>
        `<tr><td>${escapeHtml(row.project_name || '—')}</td><td>${escapeHtml(row.provider)} / ${escapeHtml(row.model)}</td><td>${escapeHtml(statuses[row.status] || row.status)}</td><td>${row.total_tokens == null ? '未提供' : formatNumber(row.total_tokens)}</td><td>${escapeHtml(String(row.duration_seconds ?? '—'))}</td><td>${escapeHtml(formatDate(row.created_at))}</td></tr>`).join('')}</tbody></table></div>`
        : '<p class="muted">所选期间暂无模型调用记录。</p>';
      pageLabel.textContent = `${records.page} / ${records.last_page}`;
      previous.disabled = records.page <= 1;
      next.disabled = records.page >= records.last_page;
      setMessage(message, `${data.period.from || '全部记录'} 至 ${data.period.to}`);
    } catch (error) {
      if (current !== request) return;
      usageSummaryNode.replaceChildren();
      calls.replaceChildren();
      pageLabel.textContent = '';
      setMessage(message, error.message || '用量读取失败，请重试。', 'danger');
    }
  }
  GEO.registerViewLoader('usage', loadUsage);
  document.getElementById('reloadUsageButton')?.addEventListener('click', loadUsage);
  period.addEventListener('change', () => { page = 1; void loadUsage(); });
  previous.addEventListener('click', () => { if (page > 1) { page--; void loadUsage(); } });
  next.addEventListener('click', () => { page++; void loadUsage(); });
})();
