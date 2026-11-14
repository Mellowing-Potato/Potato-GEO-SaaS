/* Browser regression for the isolated preview, at #result/101.
   PowerShell: Get-Content -Raw tests/fixtures/ui/report-reader-check.js |
     npx agent-browser --session geo-reader eval --stdin
   Run at both 1440px and 390px. No production requests or form submissions. */
(async () => {
  if (!['127.0.0.1', 'localhost'].includes(location.hostname) || !window.__uiPreviewRequests) throw Error('Local UI fixture required');
  const checks = [];
  const assert = (name, condition) => { if (!condition) throw Error(name); checks.push(name); };
  const frame = () => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  const body = document.getElementById('resultPageBody');
  const full = document.querySelector('[data-result-zone="full"]');
  full.click();
  await frame();
  const route = location.hash;
  const requestCount = window.__uiPreviewRequests.length;
  const text = body.textContent;
  const headingCount = body.querySelectorAll('h2,h3,h4,h5').length;
  let links = [...document.querySelectorAll('.reader-toc-link')];
  assert('Every section, including sections[] and unknown keys, has a directory entry', links.length === headingCount && headingCount >= 14);
  assert('Unknown report content remains visible', body.textContent.includes('额外字段也必须能查看'));
  assert('Long report is continuous without slides or folded sections', !body.querySelector('.report-slider,.report-fold'));
  assert('Each directory target resolves inside the report', links.every(link => body.contains(document.getElementById(link.getAttribute('aria-controls')))));
  links[5].click();
  await frame();
  assert('Chapter jump preserves the application route and moves keyboard focus', location.hash === route && document.activeElement.id === links[5].getAttribute('aria-controls'));
  assert('Active chapter tracks the jump', links[5].getAttribute('aria-current') === 'location');
  assert('Jump clears the fixed header', document.activeElement.getBoundingClientRect().top >= 80);
  assert('Reading navigation makes no API request', window.__uiPreviewRequests.length === requestCount);
  assert('Reading enhancement retains every character', body.textContent === text);
  assert('Table remains keyboard reachable', body.querySelector('.report-table-wrap').tabIndex === 0);
  assert('No page-level horizontal overflow', document.documentElement.scrollWidth <= innerWidth);
  assert('Reading column never exceeds 860px', body.clientWidth <= 860);
  const firstChapter = body.querySelector('.report-section h4');
  assert('Main chapters have Chinese numbering in the body', firstChapter.textContent.startsWith('一、'));
  assert('Directory numbering matches the displayed heading', links.every(link => link.textContent === document.getElementById(link.getAttribute('aria-controls')).textContent));
  const prose = body.querySelector('.report-section > p');
  assert('Prose has a two-character first-line indent', getComputedStyle(prose).textIndent === `${parseFloat(getComputedStyle(prose).fontSize) * 2}px`);
  assert('Summary and table stay aligned without paragraph indentation', getComputedStyle(body.querySelector('.report-lead')).textIndent === '0px' && getComputedStyle(body.querySelector('td')).textIndent === '0px');
  if (innerWidth <= 1200) assert('Narrow directory closes after a jump', !document.getElementById('readerOutlineDisclosure').open);

  document.querySelector('[data-result-zone="overview"]').click();
  await frame();
  assert('Switching zones rebuilds the directory without stale entries', document.querySelectorAll('.reader-toc-link').length < headingCount);
  full.click();
  await frame();
  const menu = document.getElementById('readerMore');
  menu.querySelector('summary').click();
  assert('Secondary actions are available', menu.open && !!menu.querySelector('[data-result-action="copy"]'));
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
  assert('Escape closes the menu and returns focus', !menu.open && document.activeElement === menu.querySelector('summary'));
  menu.querySelector('summary').click();
  menu.querySelector('[data-result-action="manage"]').click();
  await new Promise(resolve => setTimeout(resolve, 80));
  assert('Management still opens and renders original edit and publishing controls', document.getElementById('reportManagement').open && !!document.querySelector('#assetResult [data-asset-action="edit"]') && !!document.getElementById('articlePublishPanel'));
  document.getElementById('reportManagement').open = false;

  // Exercise heading safety, repeated mount and empty-state cleanup without touching persisted content.
  const original = body.innerHTML;
  try {
    const heading = document.createElement('h3');
    heading.textContent = '<img src=x onerror=alert(1)> 标题';
    body.replaceChildren(heading);
    window.GeoReportReader.mount();
    assert('Heading labels are text, never injected markup', !document.querySelector('#readerToc img'));
    window.GeoReportReader.mount();
    assert('Repeated mount does not duplicate entries', document.querySelectorAll('.reader-toc-link').length === 1);
    assert('Repeated mount does not duplicate heading numbers', heading.querySelectorAll('.reader-heading-number').length === 1 && heading.textContent === '一、<img src=x onerror=alert(1)> 标题');
    body.innerHTML = '<div class="report-section"><h4>二、<em>研究依据与方法升级</em></h4><p>正文</p><h5>（三）子节</h5><ul><li><p>条目</p></li></ul></div><div class="report-section"><h4>30/60/90 天落地路线</h4></div><div class="report-section"><h4>2. 测试场景与事实校准</h4></div>';
    window.GeoReportReader.mount();
    assert('Existing prefixes normalize without changing title markup or numeric content', body.querySelector('h4').textContent === '一、研究依据与方法升级' && !!body.querySelector('h4 em') && body.querySelectorAll('h4')[1].textContent === '二、30/60/90 天落地路线' && body.querySelectorAll('h4')[2].textContent === '三、测试场景与事实校准');
    assert('Subsection numbering and typography have their own level', body.querySelector('h5').textContent === '（一）子节' && getComputedStyle(body.querySelector('h5')).fontSize === '16px' && !!document.querySelector('.reader-toc-subsection'));
    assert('List paragraphs do not inherit prose indentation', getComputedStyle(body.querySelector('li p')).textIndent === '0px');
    const numberedText = body.textContent;
    window.GeoReportReader.mount();
    assert('Number normalization is idempotent', body.textContent === numberedText);
    body.replaceChildren();
    window.GeoReportReader.mount();
    assert('Empty report clears stale navigation', document.getElementById('readerOutline').hidden && !document.querySelector('.reader-toc-link'));
  } finally {
    body.innerHTML = original;
    window.GeoReportReader.mount();
    window.scrollTo({ top: 0, behavior: 'instant' });
  }
  return { passed: checks.length, viewport: innerWidth, checks };
})();
